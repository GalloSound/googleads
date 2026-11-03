<?php

declare(strict_types=1);

namespace GsFacil\GoogleAds;

use DateTimeImmutable;
use DateTimeZone;
use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsClient;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsClientBuilder;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsException;
use Google\Ads\GoogleAds\V26\Common\Metrics;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V26\Enums\KeywordMatchTypeEnum\KeywordMatchType;
use Google\Ads\GoogleAds\V26\Enums\QualityScoreBucketEnum\QualityScoreBucket;
use Google\Ads\GoogleAds\V26\Errors\GoogleAdsError;
use Google\Ads\GoogleAds\V26\Errors\GoogleAdsFailure;
use Google\Ads\GoogleAds\V26\Services\GoogleAdsRow;
use Google\Ads\GoogleAds\V26\Services\SearchGoogleAdsStreamRequest;
use Google\ApiCore\ApiException;
use RuntimeException;

final class GoogleAdsService
{
    private const GAQL_CAMPANHAS_ATIVAS = <<<'GAQL'
        SELECT
          campaign.id,
          campaign.name,
          campaign.status,
          metrics.impressions,
          metrics.clicks
        FROM campaign
        WHERE campaign.status = 'ENABLED'
          AND segments.date DURING LAST_30_DAYS
        GAQL;

    private const GAQL_CAMPANHA = <<<'GAQL'
        SELECT
          campaign.id,
          campaign.name,
          campaign.status,
          metrics.impressions,
          metrics.clicks,
          metrics.ctr,
          metrics.cost_micros,
          metrics.average_cpc,
          metrics.conversions,
          metrics.cost_per_conversion
        FROM campaign
        WHERE campaign.status = 'ENABLED'
          AND segments.date BETWEEN '{{INICIO}}' AND '{{FIM}}'
        GAQL;

    private const GAQL_SERIE = <<<'GAQL'
        SELECT
          campaign.id,
          campaign.name,
          campaign.status,
          segments.date,
          metrics.impressions,
          metrics.clicks,
          metrics.ctr,
          metrics.cost_micros,
          metrics.average_cpc,
          metrics.conversions,
          metrics.cost_per_conversion
        FROM campaign
        WHERE campaign.status = 'ENABLED'
          AND segments.date BETWEEN '{{INICIO}}' AND '{{FIM}}'
        GAQL;

    private const GAQL_PALAVRAS = <<<'GAQL'
        SELECT
          campaign.id,
          campaign.name,
          ad_group.id,
          ad_group.name,
          ad_group_criterion.criterion_id,
          ad_group_criterion.keyword.text,
          ad_group_criterion.keyword.match_type,
          metrics.impressions,
          metrics.clicks,
          metrics.cost_micros,
          metrics.average_cpc,
          metrics.conversions
        FROM keyword_view
        WHERE campaign.status = 'ENABLED'
          AND ad_group.status = 'ENABLED'
          AND ad_group_criterion.status = 'ENABLED'
          AND ad_group_criterion.negative = FALSE
          AND segments.date BETWEEN '{{INICIO}}' AND '{{FIM}}'
        GAQL;

    private const GAQL_QUALIDADE = <<<'GAQL'
        SELECT
          campaign.id,
          ad_group.id,
          ad_group_criterion.criterion_id,
          ad_group_criterion.keyword.text,
          ad_group_criterion.keyword.match_type,
          ad_group_criterion.quality_info.quality_score,
          ad_group_criterion.quality_info.search_predicted_ctr,
          ad_group_criterion.quality_info.creative_quality_score,
          ad_group_criterion.quality_info.post_click_quality_score
        FROM keyword_view
        WHERE campaign.status = 'ENABLED'
          AND ad_group.status = 'ENABLED'
          AND ad_group_criterion.status = 'ENABLED'
          AND ad_group_criterion.negative = FALSE
        GAQL;

    private const GAQL_NEGATIVAS_CAMPANHA = <<<'GAQL'
        SELECT
          campaign.id,
          campaign.name,
          campaign_criterion.keyword.text,
          campaign_criterion.keyword.match_type
        FROM campaign_criterion
        WHERE campaign.status = 'ENABLED'
          AND campaign_criterion.negative = TRUE
          AND campaign_criterion.type = KEYWORD
        GAQL;

    private const GAQL_NEGATIVAS_GRUPO = <<<'GAQL'
        SELECT
          campaign.id,
          campaign.name,
          ad_group.id,
          ad_group.name,
          ad_group_criterion.keyword.text,
          ad_group_criterion.keyword.match_type
        FROM ad_group_criterion
        WHERE campaign.status = 'ENABLED'
          AND ad_group_criterion.negative = TRUE
          AND ad_group_criterion.type = KEYWORD
          AND ad_group_criterion.status != 'REMOVED'
        GAQL;

    private const GAQL_URLS = <<<'GAQL'
        SELECT
          campaign.id,
          campaign.name,
          ad_group_ad.ad.final_urls
        FROM ad_group_ad
        WHERE campaign.status = 'ENABLED'
          AND ad_group_ad.status = 'ENABLED'
        GAQL;

    private const METRICAS_CAMPANHA = [
        'impressions',
        'clicks',
        'ctr',
        'cost',
        'average_cpc',
        'conversions',
        'cost_per_conversion',
    ];

    private const METRICAS_PALAVRA = [
        'impressions',
        'clicks',
        'cost',
        'average_cpc',
        'conversions',
    ];

    private const GAQL_ACOES = <<<'GAQL'
        SELECT
          campaign.id,
          campaign.name,
          segments.conversion_action_name,
          metrics.conversions
        FROM campaign
        WHERE campaign.status = 'ENABLED'
          AND segments.date BETWEEN '{{INICIO}}' AND '{{FIM}}'
        GAQL;

    public function __construct(
        private readonly GoogleAdsClient $client,
        private readonly string $customerId,
    ) {
    }

    public static function fromEnvironment(): self
    {
        if (!extension_loaded('grpc')) {
            throw new RuntimeException(
                'Extensão PHP grpc ausente. Reconstrua a imagem do runtime php_global.'
            );
        }

        $oauth = (new OAuth2TokenBuilder())
            ->withClientId(self::requiredEnv('GOOGLE_ADS_CLIENT_ID'))
            ->withClientSecret(self::requiredEnv('GOOGLE_ADS_CLIENT_SECRET'))
            ->withRefreshToken(self::requiredEnv('GOOGLE_ADS_REFRESH_TOKEN'))
            ->build();

        $builder = (new GoogleAdsClientBuilder())
            ->withOAuth2Credential($oauth)
            ->withTransport('grpc');

        $developerToken = self::optionalEnv('GOOGLE_ADS_DEVELOPER_TOKEN');
        if ($developerToken !== null) {
            $builder->withDeveloperToken($developerToken);
        }

        $loginCustomerId = self::optionalEnv('GOOGLE_ADS_LOGIN_CUSTOMER_ID');
        if ($loginCustomerId !== null) {
            $builder->withLoginCustomerId(
                (int) self::customerDigits('GOOGLE_ADS_LOGIN_CUSTOMER_ID', $loginCustomerId)
            );
        }

        return new self(
            $builder->build(),
            self::customerDigits('GOOGLE_ADS_CUSTOMER_ID', self::requiredEnv('GOOGLE_ADS_CUSTOMER_ID'))
        );
    }

    /**
     * Campanhas ENABLED com impressões e cliques agregados nos últimos 30 dias.
     * O intervalo é obrigatório na GAQL quando a consulta pede metrics.*.
     *
     * @return list<array{id: int, name: string, status: string, impressions: int, clicks: int}>
     */
    public function buscarCampanhasAtivas(): array
    {
        $googleAdsServiceClient = $this->client->getGoogleAdsServiceClient();

        try {
            $stream = $googleAdsServiceClient->searchStream(
                SearchGoogleAdsStreamRequest::build($this->customerId, self::GAQL_CAMPANHAS_ATIVAS)
            );

            $campaigns = [];
            foreach ($stream->iterateAllElements() as $row) {
                /** @var GoogleAdsRow $row */
                if (!$row->hasCampaign() || !$row->hasMetrics()) {
                    continue;
                }

                $campaign = $row->getCampaign();
                $metrics = $row->getMetrics();
                $campaigns[] = [
                    'id' => (int) $campaign->getId(),
                    'name' => $campaign->getName(),
                    'status' => CampaignStatus::name($campaign->getStatus()),
                    'impressions' => (int) $metrics->getImpressions(),
                    'clicks' => (int) $metrics->getClicks(),
                ];
            }

            return $campaigns;
        } catch (GoogleAdsException $exception) {
            throw self::fromGoogleAdsException($exception);
        } catch (ApiException $exception) {
            throw new RuntimeException('Falha de transporte na Google Ads API.', 0, $exception);
        } finally {
            $googleAdsServiceClient->close();
        }
    }

    /**
     * Relatório somente leitura. A variação é (atual - anterior) / anterior.
     * Base zero ou campo ausente na GAQL vira null e entra em campos_ausentes.
     *
     * @return array<string, mixed>
     */
    public function relatorioDesempenho(): array
    {
        $periodos = self::periodos();
        $atual = $this->consultar('campanhas_atual', self::gaqlCampanha($periodos['atual']));
        $anterior = $this->consultar('campanhas_anterior', self::gaqlCampanha($periodos['anterior']));
        $serie = $this->consultar('serie_diaria', self::gaqlSerie($periodos['atual']));
        $palavras = $this->consultar('palavras_chave', self::gaqlPalavras($periodos['atual']));
        $qualidade = $this->consultar('indice_qualidade', self::GAQL_QUALIDADE);
        $negativasCampanha = $this->consultar('negativas_campanha', self::GAQL_NEGATIVAS_CAMPANHA);
        $negativasGrupo = $this->consultar('negativas_grupo', self::GAQL_NEGATIVAS_GRUPO);
        $urls = $this->consultar('urls_finais', self::GAQL_URLS);
        $acoes = $this->consultar('conversoes_por_acao', self::gaqlAcoes($periodos['atual']));

        $campanhas = [];
        foreach ([$atual, $anterior] as $bloco) {
            foreach ($bloco['linhas'] as $row) {
                if (!$row->hasCampaign()) {
                    continue;
                }
                $id = (int) $row->getCampaign()->getId();
                $campanhas[$id] ??= $this->campanhaBase($row);
            }
        }

        foreach ($atual['linhas'] as $row) {
            if (!$row->hasCampaign() || !$row->hasMetrics()) {
                continue;
            }
            $id = (int) $row->getCampaign()->getId();
            $campanhas[$id]['atual'] = $this->metricas($row->getMetrics(), $campanhas[$id]['campos_ausentes'], 'atual', self::METRICAS_CAMPANHA);
        }
        foreach ($anterior['linhas'] as $row) {
            if (!$row->hasCampaign() || !$row->hasMetrics()) {
                continue;
            }
            $id = (int) $row->getCampaign()->getId();
            $campanhas[$id]['anterior'] = $this->metricas($row->getMetrics(), $campanhas[$id]['campos_ausentes'], 'anterior', self::METRICAS_CAMPANHA);
        }

        foreach ($campanhas as &$campanha) {
            $campanha['variacao'] = self::variacao($campanha['atual'], $campanha['anterior'], $campanha['campos_ausentes']);
        }
        unset($campanha);

        foreach ($serie['linhas'] as $row) {
            if (!$row->hasCampaign() || !$row->hasSegments() || $row->getSegments()->getDate() === '') {
                continue;
            }
            $id = (int) $row->getCampaign()->getId();
            if (!isset($campanhas[$id])) {
                $campanhas[$id] = $this->campanhaBase($row);
            }
            if (!$row->hasMetrics()) {
                $campanhas[$id]['campos_ausentes'][] = 'serie_diaria';
                continue;
            }
            $dia = $this->metricas($row->getMetrics(), $campanhas[$id]['campos_ausentes'], 'serie_diaria', self::METRICAS_CAMPANHA);
            $dia['date'] = $row->getSegments()->getDate();
            $campanhas[$id]['serie_diaria'][] = $dia;
        }

        $this->anexarPalavras($campanhas, $palavras['linhas'], $qualidade['linhas']);
        $this->anexarNegativas($campanhas, $negativasCampanha['linhas'], $negativasGrupo['linhas']);
        $this->anexarUrls($campanhas, $urls['linhas']);
        $this->anexarAcoes($campanhas, $acoes['linhas']);

        foreach ($campanhas as &$campanha) {
            $campanha['campos_ausentes'] = array_values(array_unique($campanha['campos_ausentes']));
        }
        unset($campanha);

        $lista = array_values($campanhas);
        usort($lista, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        $falhas = [];
        foreach ([$atual, $anterior, $serie, $palavras, $qualidade, $negativasCampanha, $negativasGrupo, $urls, $acoes] as $bloco) {
            if ($bloco['erro'] !== null) {
                $falhas[] = $bloco['erro'];
            }
        }

        return [
            'periodos' => [
                'atual' => ['inicio' => $periodos['atual'][0], 'fim' => $periodos['atual'][1]],
                'anterior' => ['inicio' => $periodos['anterior'][0], 'fim' => $periodos['anterior'][1]],
            ],
            'campanhas' => $lista,
            'consultas_indisponiveis' => $falhas,
        ];
    }

    private static function fromGoogleAdsException(GoogleAdsException $exception): GoogleAdsApiException
    {
        /** @var GoogleAdsFailure $failure */
        $failure = $exception->getGoogleAdsFailure();
        $errors = [];

        foreach ($failure->getErrors() as $error) {
            /** @var GoogleAdsError $error */
            $location = $error->getLocation();
            $errors[] = [
                'code' => (string) $error->getErrorCode()->getErrorCode(),
                'message' => $error->getMessage(),
                'location' => $location === null ? null : $location->serializeToJsonString(),
            ];
        }

        return new GoogleAdsApiException($exception->getRequestId(), $errors, $exception);
    }

    /**
     * @return array{atual: array{0: string, 1: string}, anterior: array{0: string, 1: string}}
     */
    private static function periodos(): array
    {
        $ontem = new DateTimeImmutable('yesterday', new DateTimeZone('America/Sao_Paulo'));
        $atualInicio = $ontem->modify('-29 days');
        $anteriorFim = $atualInicio->modify('-1 day');
        $anteriorInicio = $anteriorFim->modify('-29 days');

        return [
            'atual' => [$atualInicio->format('Y-m-d'), $ontem->format('Y-m-d')],
            'anterior' => [$anteriorInicio->format('Y-m-d'), $anteriorFim->format('Y-m-d')],
        ];
    }

    /**
     * @param array{0: string, 1: string} $periodo
     */
    private static function gaqlCampanha(array $periodo): string
    {
        return self::comPeriodo(self::GAQL_CAMPANHA, $periodo);
    }

    /**
     * @param array{0: string, 1: string} $periodo
     */
    private static function gaqlSerie(array $periodo): string
    {
        return self::comPeriodo(self::GAQL_SERIE, $periodo);
    }

    /**
     * @param array{0: string, 1: string} $periodo
     */
    private static function gaqlPalavras(array $periodo): string
    {
        return self::comPeriodo(self::GAQL_PALAVRAS, $periodo);
    }

    /**
     * @param array{0: string, 1: string} $periodo
     */
    private static function gaqlAcoes(array $periodo): string
    {
        return self::comPeriodo(self::GAQL_ACOES, $periodo);
    }

    /**
     * @param array{0: string, 1: string} $periodo
     */
    private static function comPeriodo(string $query, array $periodo): string
    {
        return str_replace(['{{INICIO}}', '{{FIM}}'], [$periodo[0], $periodo[1]], $query);
    }

    /**
     * @return array{linhas: list<GoogleAdsRow>, erro: ?array<string, mixed>}
     */
    private function consultar(string $nome, string $query): array
    {
        $googleAdsServiceClient = $this->client->getGoogleAdsServiceClient();

        try {
            $stream = $googleAdsServiceClient->searchStream(
                SearchGoogleAdsStreamRequest::build($this->customerId, $query)
            );
            $linhas = [];
            foreach ($stream->iterateAllElements() as $row) {
                $linhas[] = $row;
            }

            return ['linhas' => $linhas, 'erro' => null];
        } catch (GoogleAdsException $exception) {
            $erro = self::fromGoogleAdsException($exception);

            return [
                'linhas' => [],
                'erro' => [
                    'consulta' => $nome,
                    'request_id' => $erro->requestId(),
                    'erros' => $erro->errors(),
                ],
            ];
        } catch (ApiException $exception) {
            return [
                'linhas' => [],
                'erro' => [
                    'consulta' => $nome,
                    'request_id' => null,
                    'erros' => [[
                        'code' => 'transport',
                        'message' => 'Falha de transporte na Google Ads API.',
                        'location' => null,
                    ]],
                ],
            ];
        } finally {
            $googleAdsServiceClient->close();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function campanhaBase(GoogleAdsRow $row): array
    {
        $campaign = $row->getCampaign();

        return [
            'id' => (int) $campaign->getId(),
            'name' => $campaign->getName(),
            'status' => CampaignStatus::name($campaign->getStatus()),
            'atual' => [],
            'anterior' => [],
            'variacao' => [],
            'serie_diaria' => [],
            'palavras_chave' => [],
            'negativas_campanha' => [],
            'negativas_grupo' => [],
            'urls_finais' => [],
            'conversoes_por_acao' => [],
            'campos_ausentes' => [],
        ];
    }

    /**
     * @param list<string> $ausentes
     * @param list<string> $pedidos
     * @return array<string, int|float>
     */
    private function metricas(Metrics $metrics, array &$ausentes, string $origem, array $pedidos): array
    {
        $valores = [];
        $campos = [
            'impressions' => $metrics->hasImpressions() ? (int) $metrics->getImpressions() : null,
            'clicks' => $metrics->hasClicks() ? (int) $metrics->getClicks() : null,
            'ctr' => $metrics->hasCtr() ? round((float) $metrics->getCtr() * 100, 2) : null,
            'cost' => $metrics->hasCostMicros() ? self::moeda($metrics->getCostMicros()) : null,
            'average_cpc' => $metrics->hasAverageCpc() ? self::moeda($metrics->getAverageCpc()) : null,
            'conversions' => $metrics->hasConversions() ? round((float) $metrics->getConversions(), 2) : null,
            'cost_per_conversion' => $metrics->hasCostPerConversion() ? self::moeda($metrics->getCostPerConversion()) : null,
        ];

        foreach ($pedidos as $campo) {
            $valor = $campos[$campo] ?? null;
            if ($valor === null) {
                $ausentes[] = $origem . '.' . $campo;
                continue;
            }
            $valores[$campo] = $valor;
        }

        return $valores;
    }

    /**
     * @param array<string, int|float> $atual
     * @param array<string, int|float> $anterior
     * @param list<string> $ausentes
     * @return array<string, float|null>
     */
    private static function variacao(array $atual, array $anterior, array &$ausentes): array
    {
        $resultado = [];
        foreach (['impressions', 'clicks', 'ctr', 'cost', 'average_cpc', 'conversions', 'cost_per_conversion'] as $campo) {
            if (!array_key_exists($campo, $atual) || !array_key_exists($campo, $anterior)) {
                $ausentes[] = 'variacao.' . $campo;
                $resultado[$campo] = null;
                continue;
            }
            if ((float) $anterior[$campo] == 0.0) {
                $ausentes[] = 'variacao.' . $campo . ': base zero';
                $resultado[$campo] = null;
                continue;
            }
            $resultado[$campo] = round((((float) $atual[$campo] - (float) $anterior[$campo]) / (float) $anterior[$campo]) * 100, 2);
        }

        return $resultado;
    }

    /**
     * @param array<int, array<string, mixed>> $campanhas
     * @param list<GoogleAdsRow> $metricas
     * @param list<GoogleAdsRow> $qualidades
     */
    private function anexarPalavras(array &$campanhas, array $metricas, array $qualidades): void
    {
        $porChave = [];
        foreach ($metricas as $row) {
            if (!$row->hasCampaign() || !$row->hasAdGroup() || !$row->hasAdGroupCriterion()) {
                continue;
            }
            $criterio = $row->getAdGroupCriterion();
            if (!$criterio->hasKeyword()) {
                continue;
            }
            $id = (int) $row->getCampaign()->getId();
            $chave = $id . ':' . $row->getAdGroup()->getId() . ':' . $criterio->getCriterionId();
            $ausentes = [];
            $item = [
                'campaign_id' => $id,
                'ad_group_id' => (int) $row->getAdGroup()->getId(),
                'ad_group' => $row->getAdGroup()->getName(),
                'text' => $criterio->getKeyword()->getText(),
                'match_type' => KeywordMatchType::name($criterio->getKeyword()->getMatchType()),
            ];
            $item += $this->metricas($row->getMetrics(), $ausentes, 'palavra', self::METRICAS_PALAVRA);
            if ($ausentes !== []) {
                $item['campos_ausentes'] = array_values(array_unique($ausentes));
            }
            $porChave[$chave] = $item;
            $campanhas[$id] ??= $this->campanhaBase($row);
        }

        foreach ($qualidades as $row) {
            if (!$row->hasCampaign() || !$row->hasAdGroup() || !$row->hasAdGroupCriterion()) {
                continue;
            }
            $criterio = $row->getAdGroupCriterion();
            if (!$criterio->hasKeyword()) {
                continue;
            }
            $id = (int) $row->getCampaign()->getId();
            $chave = $id . ':' . $row->getAdGroup()->getId() . ':' . $criterio->getCriterionId();
            $campanhas[$id] ??= $this->campanhaBase($row);
            $porChave[$chave] ??= [
                'campaign_id' => $id,
                'ad_group_id' => (int) $row->getAdGroup()->getId(),
                'ad_group' => $row->hasAdGroup() ? $row->getAdGroup()->getName() : '',
                'text' => $criterio->getKeyword()->getText(),
                'match_type' => KeywordMatchType::name($criterio->getKeyword()->getMatchType()),
                'campos_ausentes' => ['palavra.metricas'],
            ];
            $this->anexarQualidade($porChave[$chave], $criterio);
        }

        foreach ($porChave as $item) {
            $campanhas[$item['campaign_id']]['palavras_chave'][] = $item;
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private function anexarQualidade(array &$item, \Google\Ads\GoogleAds\V26\Resources\AdGroupCriterion $criterio): void
    {
        $ausentes = $item['campos_ausentes'] ?? [];
        if (!$criterio->hasQualityInfo()) {
            foreach (['quality_score', 'search_predicted_ctr', 'creative_quality_score', 'post_click_quality_score'] as $campo) {
                $ausentes[] = 'qualidade.' . $campo;
            }
            $item['campos_ausentes'] = array_values(array_unique($ausentes));

            return;
        }

        $info = $criterio->getQualityInfo();
        if ($info->hasQualityScore()) {
            $item['quality_score'] = (int) $info->getQualityScore();
        } else {
            $ausentes[] = 'qualidade.quality_score';
        }

        $componentes = [
            'search_predicted_ctr' => $info->getSearchPredictedCtr(),
            'creative_quality_score' => $info->getCreativeQualityScore(),
            'post_click_quality_score' => $info->getPostClickQualityScore(),
        ];
        foreach ($componentes as $campo => $valor) {
            $nome = QualityScoreBucket::name($valor);
            if ($nome === 'UNSPECIFIED' || $nome === 'UNKNOWN') {
                $ausentes[] = 'qualidade.' . $campo;
                continue;
            }
            $item[$campo] = $nome;
        }

        if ($ausentes !== []) {
            $item['campos_ausentes'] = array_values(array_unique($ausentes));
        }
    }

    /**
     * @param array<int, array<string, mixed>> $campanhas
     * @param list<GoogleAdsRow> $deCampanha
     * @param list<GoogleAdsRow> $deGrupo
     */
    private function anexarNegativas(array &$campanhas, array $deCampanha, array $deGrupo): void
    {
        foreach ($deCampanha as $row) {
            if (!$row->hasCampaign() || !$row->hasCampaignCriterion() || !$row->getCampaignCriterion()->hasKeyword()) {
                continue;
            }
            $id = (int) $row->getCampaign()->getId();
            $campanhas[$id] ??= $this->campanhaBase($row);
            $keyword = $row->getCampaignCriterion()->getKeyword();
            $campanhas[$id]['negativas_campanha'][] = [
                'text' => $keyword->getText(),
                'match_type' => KeywordMatchType::name($keyword->getMatchType()),
            ];
        }

        foreach ($deGrupo as $row) {
            if (!$row->hasCampaign() || !$row->hasAdGroup() || !$row->hasAdGroupCriterion()) {
                continue;
            }
            $criterio = $row->getAdGroupCriterion();
            if (!$criterio->hasKeyword()) {
                continue;
            }
            $id = (int) $row->getCampaign()->getId();
            $campanhas[$id] ??= $this->campanhaBase($row);
            $keyword = $criterio->getKeyword();
            $campanhas[$id]['negativas_grupo'][] = [
                'ad_group_id' => (int) $row->getAdGroup()->getId(),
                'ad_group' => $row->getAdGroup()->getName(),
                'text' => $keyword->getText(),
                'match_type' => KeywordMatchType::name($keyword->getMatchType()),
            ];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $campanhas
     * @param list<GoogleAdsRow> $linhas
     */
    private function anexarUrls(array &$campanhas, array $linhas): void
    {
        foreach ($linhas as $row) {
            if (!$row->hasCampaign() || !$row->hasAdGroupAd() || !$row->getAdGroupAd()->hasAd()) {
                continue;
            }
            $id = (int) $row->getCampaign()->getId();
            $campanhas[$id] ??= $this->campanhaBase($row);
            foreach ($row->getAdGroupAd()->getAd()->getFinalUrls() as $url) {
                if (!is_string($url) || $url === '') {
                    continue;
                }
                $campanhas[$id]['urls_finais'][$url] = $url;
            }
        }

        foreach ($campanhas as &$campanha) {
            $campanha['urls_finais'] = array_values($campanha['urls_finais']);
            if ($campanha['urls_finais'] === []) {
                $campanha['campos_ausentes'][] = 'urls_finais';
            }
        }
        unset($campanha);
    }

    /**
     * @param array<int, array<string, mixed>> $campanhas
     * @param list<GoogleAdsRow> $linhas
     */
    private function anexarAcoes(array &$campanhas, array $linhas): void
    {
        foreach ($linhas as $row) {
            if (!$row->hasCampaign() || !$row->hasSegments()) {
                continue;
            }
            $nome = $row->getSegments()->getConversionActionName();
            if ($nome === '') {
                continue;
            }
            $id = (int) $row->getCampaign()->getId();
            $campanhas[$id] ??= $this->campanhaBase($row);
            if (!$row->hasMetrics() || !$row->getMetrics()->hasConversions()) {
                $campanhas[$id]['campos_ausentes'][] = 'conversoes_por_acao.' . $nome;
                continue;
            }
            $campanhas[$id]['conversoes_por_acao'][] = [
                'conversion_action' => $nome,
                'conversions' => round((float) $row->getMetrics()->getConversions(), 2),
            ];
        }
    }

    private static function moeda(int|float|string $micros): float
    {
        return round(((float) $micros) / 1000000, 2);
    }

    private static function requiredEnv(string $key): string
    {
        $value = self::optionalEnv($key);
        if ($value === null) {
            throw new RuntimeException('Configuração obrigatória ausente: ' . $key);
        }

        return $value;
    }

    private static function optionalEnv(string $key): ?string
    {
        $value = getenv($key);
        if (!is_string($value) || trim($value) === '') {
            $fromEnv = $_ENV[$key] ?? null;
            $value = is_string($fromEnv) ? $fromEnv : '';
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function customerDigits(string $key, string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) !== 10) {
            throw new RuntimeException('ID Google Ads inválido em ' . $key . '. Use 10 dígitos, sem traços.');
        }

        return $digits;
    }
}
