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
use Google\Ads\GoogleAds\V26\Enums\AdGroupCriterionStatusEnum\AdGroupCriterionStatus;
use Google\Ads\GoogleAds\V26\Enums\AdNetworkTypeEnum\AdNetworkType;
use Google\Ads\GoogleAds\V26\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V26\Enums\AgeRangeTypeEnum\AgeRangeType;
use Google\Ads\GoogleAds\V26\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Google\Ads\GoogleAds\V26\Enums\BudgetDeliveryMethodEnum\BudgetDeliveryMethod;
use Google\Ads\GoogleAds\V26\Enums\BudgetPeriodEnum\BudgetPeriod;
use Google\Ads\GoogleAds\V26\Enums\CampaignCriterionStatusEnum\CampaignCriterionStatus;
use Google\Ads\GoogleAds\V26\Enums\CampaignKeywordMatchTypeEnum\CampaignKeywordMatchType;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V26\Enums\ChangeClientTypeEnum\ChangeClientType;
use Google\Ads\GoogleAds\V26\Enums\ChangeEventResourceTypeEnum\ChangeEventResourceType;
use Google\Ads\GoogleAds\V26\Enums\ConversionActionCategoryEnum\ConversionActionCategory;
use Google\Ads\GoogleAds\V26\Enums\ConversionActionCountingTypeEnum\ConversionActionCountingType;
use Google\Ads\GoogleAds\V26\Enums\ConversionActionStatusEnum\ConversionActionStatus;
use Google\Ads\GoogleAds\V26\Enums\ConversionActionTypeEnum\ConversionActionType;
use Google\Ads\GoogleAds\V26\Enums\ConversionTrackingStatusEnum\ConversionTrackingStatus;
use Google\Ads\GoogleAds\V26\Enums\DayOfWeekEnum\DayOfWeek;
use Google\Ads\GoogleAds\V26\Enums\DeviceEnum\Device;
use Google\Ads\GoogleAds\V26\Enums\EnhancedConversionsConfigEnum\EnhancedConversionsConfig;
use Google\Ads\GoogleAds\V26\Enums\GenderTypeEnum\GenderType;
use Google\Ads\GoogleAds\V26\Enums\GeoTargetingTypeEnum\GeoTargetingType;
use Google\Ads\GoogleAds\V26\Enums\IncomeRangeTypeEnum\IncomeRangeType;
use Google\Ads\GoogleAds\V26\Enums\KeywordMatchTypeEnum\KeywordMatchType;
use Google\Ads\GoogleAds\V26\Enums\MinuteOfHourEnum\MinuteOfHour;
use Google\Ads\GoogleAds\V26\Enums\NegativeGeoTargetTypeEnum\NegativeGeoTargetType;
use Google\Ads\GoogleAds\V26\Enums\PositiveGeoTargetTypeEnum\PositiveGeoTargetType;
use Google\Ads\GoogleAds\V26\Enums\QualityScoreBucketEnum\QualityScoreBucket;
use Google\Ads\GoogleAds\V26\Enums\RecommendationSubscriptionStatusEnum\RecommendationSubscriptionStatus;
use Google\Ads\GoogleAds\V26\Enums\RecommendationTypeEnum\RecommendationType;
use Google\Ads\GoogleAds\V26\Enums\ResourceChangeOperationEnum\ResourceChangeOperation;
use Google\Ads\GoogleAds\V26\Enums\SearchTermMatchSourceEnum\SearchTermMatchSource;
use Google\Ads\GoogleAds\V26\Enums\SearchTermMatchTypeEnum\SearchTermMatchType;
use Google\Ads\GoogleAds\V26\Enums\SearchTermTargetingStatusEnum\SearchTermTargetingStatus;
use Google\Ads\GoogleAds\V26\Enums\TargetImpressionShareLocationEnum\TargetImpressionShareLocation;
use Google\Ads\GoogleAds\V26\Errors\GoogleAdsError;
use Google\Ads\GoogleAds\V26\Errors\GoogleAdsFailure;
use Google\Ads\GoogleAds\V26\Resources\AdGroupCriterion;
use Google\Ads\GoogleAds\V26\Resources\Campaign;
use Google\Ads\GoogleAds\V26\Services\GoogleAdsRow;
use Google\Ads\GoogleAds\V26\Services\SearchGoogleAdsStreamRequest;
use Google\ApiCore\ApiException;
use RuntimeException;
use UnexpectedValueException;

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
          metrics.conversions_value,
          metrics.all_conversions,
          metrics.all_conversions_value,
          metrics.cost_per_conversion,
          metrics.conversions_from_interactions_rate,
          metrics.value_per_conversion
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
          metrics.conversions_value,
          metrics.all_conversions,
          metrics.all_conversions_value,
          metrics.cost_per_conversion,
          metrics.conversions_from_interactions_rate,
          metrics.value_per_conversion
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
          metrics.ctr,
          metrics.cost_micros,
          metrics.average_cpc,
          metrics.conversions,
          metrics.conversions_value,
          metrics.all_conversions,
          metrics.conversions_from_interactions_rate
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
        'conversions_value',
        'all_conversions',
        'all_conversions_value',
        'cost_per_conversion',
        'conversion_rate',
        'value_per_conversion',
    ];

    private const METRICAS_PALAVRA = [
        'impressions',
        'clicks',
        'ctr',
        'cost',
        'average_cpc',
        'conversions',
        'conversions_value',
        'all_conversions',
        'conversion_rate',
    ];

    private const HISTORICO_INICIO = '2020-01-01';

    private const LIMITE_TERMOS = 500;

    private const ORDEM_DIA = [
        'MONDAY' => 1,
        'TUESDAY' => 2,
        'WEDNESDAY' => 3,
        'THURSDAY' => 4,
        'FRIDAY' => 5,
        'SATURDAY' => 6,
        'SUNDAY' => 7,
    ];

    private const MINUTOS = [
        'ZERO' => 0,
        'FIFTEEN' => 15,
        'THIRTY' => 30,
        'FORTY_FIVE' => 45,
    ];

    private const GAQL_ACOES = <<<'GAQL'
        SELECT
          campaign.id,
          segments.conversion_action_name,
          segments.conversion_action_category,
          metrics.conversions,
          metrics.all_conversions,
          metrics.conversions_value
        FROM campaign
        WHERE campaign.status = 'ENABLED'
          AND segments.date BETWEEN '{{INICIO}}' AND '{{FIM}}'
        GAQL;

    private const GAQL_CONFIG = <<<'GAQL'
        SELECT
          campaign.id,
          campaign.name,
          campaign.status,
          campaign.advertising_channel_type,
          campaign.bidding_strategy_type,
          campaign.start_date_time,
          campaign.optimization_score,
          campaign.keyword_match_type,
          campaign.ai_max_setting.enable_ai_max,
          campaign.broad_match_migration_date_time,
          campaign_budget.id,
          campaign_budget.amount_micros,
          campaign_budget.period,
          campaign_budget.delivery_method,
          campaign_budget.explicitly_shared,
          campaign.network_settings.target_google_search,
          campaign.network_settings.target_search_network,
          campaign.network_settings.target_content_network,
          campaign.network_settings.target_partner_search_network,
          campaign.network_settings.target_youtube,
          campaign.geo_target_type_setting.positive_geo_target_type,
          campaign.geo_target_type_setting.negative_geo_target_type
        FROM campaign
        WHERE campaign.status = 'ENABLED'
        GAQL;

    private const GAQL_LANCES = <<<'GAQL'
        SELECT
          campaign.id,
          campaign.bidding_strategy_type,
          campaign.maximize_conversions.target_cpa_micros,
          campaign.maximize_conversions.cpc_bid_ceiling_micros,
          campaign.maximize_conversion_value.target_roas,
          campaign.maximize_conversion_value.cpc_bid_ceiling_micros,
          campaign.target_cpa.target_cpa_micros,
          campaign.target_cpa.cpc_bid_ceiling_micros,
          campaign.target_roas.target_roas,
          campaign.target_roas.cpc_bid_ceiling_micros,
          campaign.target_spend.cpc_bid_ceiling_micros,
          campaign.target_impression_share.location,
          campaign.target_impression_share.location_fraction_micros,
          campaign.target_impression_share.cpc_bid_ceiling_micros,
          campaign.manual_cpc.enhanced_cpc_enabled
        FROM campaign
        WHERE campaign.status = 'ENABLED'
        GAQL;

    private const GAQL_LEILAO = <<<'GAQL'
        SELECT
          campaign.id,
          metrics.search_impression_share,
          metrics.search_budget_lost_impression_share,
          metrics.search_rank_lost_impression_share,
          metrics.search_top_impression_share,
          metrics.search_absolute_top_impression_share,
          metrics.search_click_share
        FROM campaign
        WHERE campaign.status = 'ENABLED'
          AND segments.date BETWEEN '{{INICIO}}' AND '{{FIM}}'
        GAQL;

    private const GAQL_LEILAO_PALAVRA = <<<'GAQL'
        SELECT
          campaign.id,
          ad_group.id,
          ad_group_criterion.criterion_id,
          metrics.search_impression_share,
          metrics.search_rank_lost_impression_share
        FROM keyword_view
        WHERE campaign.status = 'ENABLED'
          AND ad_group.status = 'ENABLED'
          AND ad_group_criterion.status = 'ENABLED'
          AND ad_group_criterion.negative = FALSE
          AND segments.date BETWEEN '{{INICIO}}' AND '{{FIM}}'
        GAQL;

    private const GAQL_HISTORICO = <<<'GAQL'
        SELECT
          campaign.id,
          campaign.name,
          campaign.status,
          campaign.advertising_channel_type,
          campaign.bidding_strategy_type,
          segments.month,
          metrics.impressions,
          metrics.clicks,
          metrics.cost_micros,
          metrics.conversions,
          metrics.conversions_value,
          metrics.all_conversions,
          metrics.all_conversions_value
        FROM campaign
        WHERE segments.date BETWEEN '{{INICIO}}' AND '{{FIM}}'
          AND metrics.impressions > 0
        GAQL;

    private const GAQL_HISTORICO_ACOES = <<<'GAQL'
        SELECT
          segments.month,
          segments.conversion_action_name,
          segments.conversion_action_category,
          metrics.conversions,
          metrics.all_conversions,
          metrics.conversions_value
        FROM campaign
        WHERE segments.date BETWEEN '{{INICIO}}' AND '{{FIM}}'
        GAQL;

    private const GAQL_CONTA = <<<'GAQL'
        SELECT
          customer.descriptive_name,
          customer.currency_code,
          customer.time_zone,
          customer.auto_tagging_enabled,
          customer.conversion_tracking_setting.conversion_tracking_status,
          customer.conversion_tracking_setting.accepted_customer_data_terms,
          customer.conversion_tracking_setting.google_ads_conversion_customer,
          customer.conversion_tracking_setting.enablement_setting,
          customer.conversion_tracking_setting.effective_enablement_setting
        FROM customer
        GAQL;

    private const GAQL_CATALOGO_ACOES = <<<'GAQL'
        SELECT
          conversion_action.id,
          conversion_action.name,
          conversion_action.status,
          conversion_action.type,
          conversion_action.category,
          conversion_action.primary_for_goal,
          conversion_action.include_in_conversions_metric,
          conversion_action.counting_type,
          conversion_action.value_settings.default_value,
          conversion_action.value_settings.default_currency_code,
          conversion_action.value_settings.always_use_default_value,
          conversion_action.click_through_lookback_window_days,
          conversion_action.view_through_lookback_window_days
        FROM conversion_action
        GAQL;

    private const GAQL_RECOMENDACOES = <<<'GAQL'
        SELECT
          recommendation_subscription.type,
          recommendation_subscription.status,
          recommendation_subscription.create_date_time
        FROM recommendation_subscription
        GAQL;

    private const GAQL_ALTERACOES = <<<'GAQL'
        SELECT
          change_event.change_date_time,
          change_event.change_resource_type,
          change_event.client_type,
          change_event.user_email,
          change_event.resource_change_operation
        FROM change_event
        WHERE change_event.change_date_time DURING LAST_14_DAYS
          AND change_event.client_type IN (
            'GOOGLE_ADS_RECOMMENDATIONS',
            'GOOGLE_ADS_RECOMMENDATIONS_SUBSCRIPTION',
            'GOOGLE_ADS_AUTOMATED_RULE',
            'GOOGLE_ADS_SCRIPTS'
          )
        ORDER BY change_event.change_date_time DESC
        LIMIT 1000
        GAQL;

    private const GAQL_TERMOS = <<<'GAQL'
        SELECT
          campaign.id,
          ad_group.id,
          ad_group.name,
          search_term_view.search_term,
          search_term_view.status,
          segments.keyword.info.text,
          segments.keyword.info.match_type,
          segments.search_term_match_type,
          segments.search_term_match_source,
          metrics.impressions,
          metrics.clicks,
          metrics.cost_micros,
          metrics.conversions,
          metrics.conversions_value,
          metrics.all_conversions,
          metrics.all_conversions_value
        FROM search_term_view
        WHERE campaign.status = 'ENABLED'
          AND segments.date BETWEEN '{{INICIO}}' AND '{{FIM}}'
          AND metrics.cost_micros > 0
        ORDER BY metrics.cost_micros DESC
        GAQL;

    private const GAQL_DISPOSITIVOS = <<<'GAQL'
        SELECT
          campaign.id,
          segments.device,
          metrics.impressions,
          metrics.clicks,
          metrics.cost_micros,
          metrics.conversions,
          metrics.conversions_value,
          metrics.all_conversions,
          metrics.all_conversions_value
        FROM campaign
        WHERE campaign.status = 'ENABLED'
          AND segments.date BETWEEN '{{INICIO}}' AND '{{FIM}}'
        GAQL;

    private const GAQL_REDES = <<<'GAQL'
        SELECT
          campaign.id,
          segments.ad_network_type,
          metrics.impressions,
          metrics.clicks,
          metrics.cost_micros,
          metrics.conversions,
          metrics.conversions_value,
          metrics.all_conversions,
          metrics.all_conversions_value
        FROM campaign
        WHERE campaign.status = 'ENABLED'
          AND segments.date BETWEEN '{{INICIO}}' AND '{{FIM}}'
        GAQL;

    private const GAQL_HORARIOS = <<<'GAQL'
        SELECT
          campaign.id,
          segments.day_of_week,
          segments.hour,
          metrics.impressions,
          metrics.clicks,
          metrics.cost_micros,
          metrics.conversions,
          metrics.conversions_value,
          metrics.all_conversions,
          metrics.all_conversions_value
        FROM campaign
        WHERE campaign.status = 'ENABLED'
          AND segments.date BETWEEN '{{INICIO}}' AND '{{FIM}}'
          AND metrics.cost_micros > 0
        GAQL;

    private const GAQL_PROGRAMACAO = <<<'GAQL'
        SELECT
          campaign.id,
          campaign_criterion.status,
          campaign_criterion.bid_modifier,
          campaign_criterion.ad_schedule.day_of_week,
          campaign_criterion.ad_schedule.start_hour,
          campaign_criterion.ad_schedule.start_minute,
          campaign_criterion.ad_schedule.end_hour,
          campaign_criterion.ad_schedule.end_minute
        FROM campaign_criterion
        WHERE campaign.status = 'ENABLED'
          AND campaign_criterion.type = AD_SCHEDULE
        GAQL;

    private const GAQL_IDADES = <<<'GAQL'
        SELECT
          campaign.id,
          ad_group_criterion.age_range.type,
          metrics.impressions,
          metrics.clicks,
          metrics.cost_micros,
          metrics.conversions,
          metrics.conversions_value,
          metrics.all_conversions,
          metrics.all_conversions_value
        FROM age_range_view
        WHERE campaign.status = 'ENABLED'
          AND segments.date BETWEEN '{{INICIO}}' AND '{{FIM}}'
          AND metrics.cost_micros > 0
        GAQL;

    private const GAQL_GENEROS = <<<'GAQL'
        SELECT
          campaign.id,
          ad_group_criterion.gender.type,
          metrics.impressions,
          metrics.clicks,
          metrics.cost_micros,
          metrics.conversions,
          metrics.conversions_value,
          metrics.all_conversions,
          metrics.all_conversions_value
        FROM gender_view
        WHERE campaign.status = 'ENABLED'
          AND segments.date BETWEEN '{{INICIO}}' AND '{{FIM}}'
          AND metrics.cost_micros > 0
        GAQL;

    private const GAQL_RENDAS = <<<'GAQL'
        SELECT
          campaign.id,
          ad_group_criterion.income_range.type,
          metrics.impressions,
          metrics.clicks,
          metrics.cost_micros,
          metrics.conversions,
          metrics.conversions_value,
          metrics.all_conversions,
          metrics.all_conversions_value
        FROM income_range_view
        WHERE campaign.status = 'ENABLED'
          AND segments.date BETWEEN '{{INICIO}}' AND '{{FIM}}'
          AND metrics.cost_micros > 0
        GAQL;

    private const GAQL_LOCALIZACOES = <<<'GAQL'
        SELECT
          campaign.id,
          geographic_view.location_type,
          geographic_view.country_criterion_id,
          segments.geo_target_most_specific_location,
          metrics.impressions,
          metrics.clicks,
          metrics.cost_micros,
          metrics.conversions,
          metrics.conversions_value,
          metrics.all_conversions,
          metrics.all_conversions_value
        FROM geographic_view
        WHERE campaign.status = 'ENABLED'
          AND segments.date BETWEEN '{{INICIO}}' AND '{{FIM}}'
          AND metrics.cost_micros > 0
        GAQL;

    private const GAQL_LOCAIS = <<<'GAQL'
        SELECT
          campaign.id,
          campaign_criterion.status,
          campaign_criterion.negative,
          campaign_criterion.bid_modifier,
          campaign_criterion.location.geo_target_constant
        FROM campaign_criterion
        WHERE campaign.status = 'ENABLED'
          AND campaign_criterion.type = LOCATION
        GAQL;

    private const GAQL_PUBLICOS = <<<'GAQL'
        SELECT
          campaign.id,
          ad_group.id,
          ad_group.name,
          ad_group_criterion.display_name,
          ad_group_criterion.status,
          ad_group_criterion.bid_modifier,
          ad_group_criterion.user_list.user_list,
          metrics.impressions,
          metrics.clicks,
          metrics.cost_micros,
          metrics.conversions,
          metrics.conversions_value,
          metrics.all_conversions,
          metrics.all_conversions_value
        FROM ad_group_audience_view
        WHERE campaign.status = 'ENABLED'
          AND segments.date BETWEEN '{{INICIO}}' AND '{{FIM}}'
          AND metrics.cost_micros > 0
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
     * Relatório somente leitura para auditoria de desperdício.
     * A variação é (atual - anterior) / anterior. Base zero ou campo ausente
     * na GAQL vira null e entra em campos_ausentes.
     *
     * O histórico mensal começa em 2020-01-01 e inclui campanha pausada ou
     * removida. A estratégia de lance é a configuração atual.
     *
     * @return array<string, mixed>
     */
    public function relatorioDesempenho(): array
    {
        $periodos = self::periodos();
        $fim = $periodos['atual'][1];
        $consultas = $this->consultasDesempenho($periodos);
        $historico = $this->consultarAnos('historico_campanha', self::GAQL_HISTORICO, $fim);
        $historicoAcoes = $this->consultarAnos('historico_acoes', self::GAQL_HISTORICO_ACOES, $fim);

        $campanhas = $this->montarCampanhas(
            $consultas['campanhas_atual'],
            $consultas['campanhas_anterior'],
            $consultas['serie_diaria']
        );
        $this->anexarConfiguracao($campanhas, $consultas['configuracao']['linhas']);
        $this->anexarLances($campanhas, $consultas['lances']['linhas']);
        $this->anexarHistorico($campanhas, $historico['linhas']);
        $this->anexarPalavras(
            $campanhas,
            $consultas['palavras_chave']['linhas'],
            $consultas['indice_qualidade']['linhas'],
            $consultas['leilao_palavras']['linhas']
        );
        $this->anexarNegativas(
            $campanhas,
            $consultas['negativas_campanha']['linhas'],
            $consultas['negativas_grupo']['linhas']
        );
        $this->anexarUrls($campanhas, $consultas['urls_finais']['linhas']);
        $this->anexarAcoes($campanhas, $consultas['conversoes_por_acao']['linhas']);
        $this->anexarLeilao($campanhas, $consultas['leilao_atual']['linhas'], 'leilao');
        $this->anexarLeilao($campanhas, $consultas['leilao_anterior']['linhas'], 'leilao_anterior');
        $this->anexarTermos($campanhas, $consultas['termos_pesquisa']['linhas']);
        $this->anexarQuebra($campanhas, $consultas['dispositivos']['linhas'], 'dispositivos', static function (GoogleAdsRow $row): ?array {
            if (!$row->hasSegments()) {
                return null;
            }
            $device = self::enumNome(Device::class, $row->getSegments()->getDevice());

            return $device === null ? null : ['device' => $device];
        });
        $this->anexarQuebra($campanhas, $consultas['redes_desempenho']['linhas'], 'redes_desempenho', static function (GoogleAdsRow $row): ?array {
            if (!$row->hasSegments()) {
                return null;
            }
            $rede = self::enumNome(AdNetworkType::class, $row->getSegments()->getAdNetworkType());

            return $rede === null ? null : ['network' => $rede];
        });
        $this->anexarQuebra($campanhas, $consultas['horarios']['linhas'], 'horarios', static function (GoogleAdsRow $row): ?array {
            if (!$row->hasSegments() || !$row->getSegments()->hasHour()) {
                return null;
            }
            $dia = self::enumNome(DayOfWeek::class, $row->getSegments()->getDayOfWeek());
            if ($dia === null) {
                return null;
            }

            return ['day_of_week' => $dia, 'hour' => (int) $row->getSegments()->getHour()];
        });
        $this->anexarQuebra($campanhas, $consultas['idades']['linhas'], 'idades', static function (GoogleAdsRow $row): ?array {
            if (!$row->hasAdGroupCriterion() || !$row->getAdGroupCriterion()->hasAgeRange()) {
                return null;
            }
            $faixa = self::enumNome(AgeRangeType::class, $row->getAdGroupCriterion()->getAgeRange()->getType());

            return $faixa === null ? null : ['age_range' => $faixa];
        });
        $this->anexarQuebra($campanhas, $consultas['generos']['linhas'], 'generos', static function (GoogleAdsRow $row): ?array {
            if (!$row->hasAdGroupCriterion() || !$row->getAdGroupCriterion()->hasGender()) {
                return null;
            }
            $genero = self::enumNome(GenderType::class, $row->getAdGroupCriterion()->getGender()->getType());

            return $genero === null ? null : ['gender' => $genero];
        });
        $this->anexarQuebra($campanhas, $consultas['faixas_renda']['linhas'], 'faixas_renda', static function (GoogleAdsRow $row): ?array {
            if (!$row->hasAdGroupCriterion() || !$row->getAdGroupCriterion()->hasIncomeRange()) {
                return null;
            }
            $renda = self::enumNome(IncomeRangeType::class, $row->getAdGroupCriterion()->getIncomeRange()->getType());

            return $renda === null ? null : ['income_range' => $renda];
        });
        $this->anexarProgramacao($campanhas, $consultas['programacao']['linhas']);
        $this->marcarProgramacao($campanhas, $consultas['programacao']['erro'] === null);
        $this->anexarLocais($campanhas, $consultas['locais_configurados']['linhas']);
        $this->anexarLocalizacoes($campanhas, $consultas['localizacoes']['linhas']);
        $this->anexarPublicos($campanhas, $consultas['publicos']['linhas']);
        $geoErros = $this->aplicarGeografia($campanhas);

        $falhas = self::coletarFalhas($consultas);
        array_push($falhas, ...$historico['erros'], ...$historicoAcoes['erros'], ...$geoErros);

        $termos = count($consultas['termos_pesquisa']['linhas']);

        return [
            'periodos' => [
                'atual' => ['inicio' => $periodos['atual'][0], 'fim' => $fim],
                'anterior' => ['inicio' => $periodos['anterior'][0], 'fim' => $periodos['anterior'][1]],
                'historico' => [
                    'inicio' => self::HISTORICO_INICIO,
                    'fim' => $fim,
                    'granularidade' => 'month',
                ],
            ],
            'limites' => [
                'termos_pesquisa' => self::LIMITE_TERMOS,
                'termos_pesquisa_retornados' => $termos,
                'termos_pesquisa_limite_atingido' => $termos >= self::LIMITE_TERMOS,
                'termos_pesquisa_ordem' => 'cost DESC na conta, nao por campanha',
                'horario_ausente' => 'sem custo no periodo atual',
                'programacao_vazia' => 'campanha ENABLED sem horario anuncia 24h quando a consulta programacao nao esta em consultas_indisponiveis',
                'parcela_impressao' => 'faixa abaixo_de_10 ou acima_de_90 nao traz o percentual exato',
                'historico_lance' => 'estrategia_lance e a configuracao atual; o mes nao reconstrui o lance da epoca',
                'historico_inclui' => 'campanhas ENABLED, PAUSED e REMOVED com impressao',
                'alteracoes_automaticas' => 'ultimos 14 dias, so recomendacao, assinatura, regra e script',
                'demografia_vazia' => 'pesquisa sem relatorio demografico nao significa que toda faixa converte',
            ],
            'conta' => $this->montarConta($consultas['conta']['linhas']),
            'acoes_conversao' => $this->catalogoAcoes($consultas['acoes_conversao']['linhas']),
            'recomendacoes_automaticas' => $this->recomendacoes($consultas['recomendacoes_automaticas']['linhas']),
            'alteracoes_automaticas' => $this->alteracoes($consultas['alteracoes_automaticas']['linhas']),
            'historico_mensal' => $this->historicoConta($campanhas),
            'historico_mensal_acoes' => $this->historicoAcoes($historicoAcoes['linhas']),
            'campanhas' => $this->organizar($campanhas),
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
     * @return array<int, array{0: string, 1: string}>
     */
    private static function anosHistorico(string $fim): array
    {
        $anoInicio = (int) substr(self::HISTORICO_INICIO, 0, 4);
        $anoFim = (int) substr($fim, 0, 4);
        $intervalos = [];
        for ($ano = $anoInicio; $ano <= $anoFim; $ano++) {
            $inicio = sprintf('%d-01-01', $ano);
            $limite = $ano === $anoFim ? $fim : sprintf('%d-12-31', $ano);
            if ($inicio > $limite) {
                continue;
            }
            $intervalos[$ano] = [$inicio, $limite];
        }

        return $intervalos;
    }

    /**
     * @param array{atual: array{0: string, 1: string}, anterior: array{0: string, 1: string}} $periodos
     * @return array<string, array{linhas: list<GoogleAdsRow>, erro: ?array<string, mixed>}>
     */
    private function consultasDesempenho(array $periodos): array
    {
        $atual = $periodos['atual'];
        $anterior = $periodos['anterior'];

        return [
            'campanhas_atual' => $this->consultar('campanhas_atual', self::gaqlCampanha($atual)),
            'campanhas_anterior' => $this->consultar('campanhas_anterior', self::gaqlCampanha($anterior)),
            'serie_diaria' => $this->consultar('serie_diaria', self::gaqlSerie($atual)),
            'palavras_chave' => $this->consultar('palavras_chave', self::gaqlPalavras($atual)),
            'indice_qualidade' => $this->consultar('indice_qualidade', self::GAQL_QUALIDADE),
            'leilao_palavras' => $this->consultar('leilao_palavras', self::comPeriodo(self::GAQL_LEILAO_PALAVRA, $atual)),
            'negativas_campanha' => $this->consultar('negativas_campanha', self::GAQL_NEGATIVAS_CAMPANHA),
            'negativas_grupo' => $this->consultar('negativas_grupo', self::GAQL_NEGATIVAS_GRUPO),
            'urls_finais' => $this->consultar('urls_finais', self::GAQL_URLS),
            'conversoes_por_acao' => $this->consultar('conversoes_por_acao', self::gaqlAcoes($atual)),
            'configuracao' => $this->consultar('configuracao', self::GAQL_CONFIG),
            'lances' => $this->consultar('lances', self::GAQL_LANCES),
            'leilao_atual' => $this->consultar('leilao_atual', self::comPeriodo(self::GAQL_LEILAO, $atual)),
            'leilao_anterior' => $this->consultar('leilao_anterior', self::comPeriodo(self::GAQL_LEILAO, $anterior)),
            'conta' => $this->consultar('conta', self::GAQL_CONTA),
            'acoes_conversao' => $this->consultar('acoes_conversao', self::GAQL_CATALOGO_ACOES),
            'recomendacoes_automaticas' => $this->consultar('recomendacoes_automaticas', self::GAQL_RECOMENDACOES),
            'alteracoes_automaticas' => $this->consultar('alteracoes_automaticas', self::GAQL_ALTERACOES),
            'termos_pesquisa' => $this->consultar(
                'termos_pesquisa',
                self::comPeriodo(self::GAQL_TERMOS, $atual) . "\nLIMIT " . self::LIMITE_TERMOS
            ),
            'dispositivos' => $this->consultar('dispositivos', self::comPeriodo(self::GAQL_DISPOSITIVOS, $atual)),
            'redes_desempenho' => $this->consultar('redes_desempenho', self::comPeriodo(self::GAQL_REDES, $atual)),
            'horarios' => $this->consultar('horarios', self::comPeriodo(self::GAQL_HORARIOS, $atual)),
            'programacao' => $this->consultar('programacao', self::GAQL_PROGRAMACAO),
            'idades' => $this->consultar('idades', self::comPeriodo(self::GAQL_IDADES, $atual)),
            'generos' => $this->consultar('generos', self::comPeriodo(self::GAQL_GENEROS, $atual)),
            'faixas_renda' => $this->consultar('faixas_renda', self::comPeriodo(self::GAQL_RENDAS, $atual)),
            'localizacoes' => $this->consultar('localizacoes', self::comPeriodo(self::GAQL_LOCALIZACOES, $atual)),
            'locais_configurados' => $this->consultar('locais_configurados', self::GAQL_LOCAIS),
            'publicos' => $this->consultar('publicos', self::comPeriodo(self::GAQL_PUBLICOS, $atual)),
        ];
    }

    /**
     * @return array{linhas: list<GoogleAdsRow>, erros: list<array<string, mixed>>}
     */
    private function consultarAnos(string $nome, string $query, string $fim): array
    {
        $linhas = [];
        $erros = [];
        foreach (self::anosHistorico($fim) as $ano => $periodo) {
            $bloco = $this->consultar($nome . '_' . $ano, self::comPeriodo($query, $periodo));
            array_push($linhas, ...$bloco['linhas']);
            if ($bloco['erro'] !== null) {
                $erros[] = $bloco['erro'];
            }
        }

        return ['linhas' => $linhas, 'erros' => $erros];
    }

    /**
     * @param array<string, array{linhas: list<GoogleAdsRow>, erro: ?array<string, mixed>}> $consultas
     * @return list<array<string, mixed>>
     */
    private static function coletarFalhas(array $consultas): array
    {
        $falhas = [];
        foreach ($consultas as $bloco) {
            if ($bloco['erro'] !== null) {
                $falhas[] = $bloco['erro'];
            }
        }

        return $falhas;
    }

    /**
     * @param array{linhas: list<GoogleAdsRow>, erro: ?array<string, mixed>} $atual
     * @param array{linhas: list<GoogleAdsRow>, erro: ?array<string, mixed>} $anterior
     * @param array{linhas: list<GoogleAdsRow>, erro: ?array<string, mixed>} $serie
     * @return array<int, array<string, mixed>>
     */
    private function montarCampanhas(array $atual, array $anterior, array $serie): array
    {
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
            $campanhas[$id] ??= $this->campanhaBase($row);
            if (!$row->hasMetrics()) {
                $campanhas[$id]['campos_ausentes'][] = 'serie_diaria';
                continue;
            }
            $dia = $this->metricas($row->getMetrics(), $campanhas[$id]['campos_ausentes'], 'serie_diaria', self::METRICAS_CAMPANHA);
            $dia['date'] = $row->getSegments()->getDate();
            $campanhas[$id]['serie_diaria'][] = $dia;
        }

        return $campanhas;
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
            'canal' => null,
            'estrategia_lance' => null,
            'inicio' => null,
            'optimization_score' => null,
            'orcamento' => null,
            'redes' => null,
            'geo_alvo' => null,
            'lances' => null,
            'automacao' => null,
            'atual' => [],
            'anterior' => [],
            'variacao' => [],
            'leilao' => [],
            'leilao_anterior' => [],
            'serie_diaria' => [],
            'historico_mensal' => [],
            'cost_since_2020' => 0.0,
            'palavras_chave' => [],
            'termos_pesquisa' => [],
            'negativas_campanha' => [],
            'negativas_grupo' => [],
            'urls_finais' => [],
            'conversoes_por_acao' => [],
            'dispositivos' => [],
            'redes_desempenho' => [],
            'horarios' => [],
            'programacao' => [],
            'programacao_24h' => null,
            'idades' => [],
            'generos' => [],
            'faixas_renda' => [],
            'localizacoes' => [],
            'locais_configurados' => [],
            'publicos' => [],
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
            'conversions_value' => $metrics->hasConversionsValue() ? round((float) $metrics->getConversionsValue(), 2) : null,
            'all_conversions' => $metrics->hasAllConversions() ? round((float) $metrics->getAllConversions(), 2) : null,
            'all_conversions_value' => $metrics->hasAllConversionsValue() ? round((float) $metrics->getAllConversionsValue(), 2) : null,
            'cost_per_conversion' => $metrics->hasCostPerConversion() ? self::moeda($metrics->getCostPerConversion()) : null,
            'conversion_rate' => $metrics->hasConversionsFromInteractionsRate()
                ? round((float) $metrics->getConversionsFromInteractionsRate() * 100, 2)
                : null,
            'value_per_conversion' => $metrics->hasValuePerConversion() ? round((float) $metrics->getValuePerConversion(), 2) : null,
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
        foreach (self::METRICAS_CAMPANHA as $campo) {
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
     * @param list<GoogleAdsRow> $leiloes
     */
    private function anexarPalavras(array &$campanhas, array $metricas, array $qualidades, array $leiloes): void
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
                'criterion_id' => (int) $criterio->getCriterionId(),
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
                'criterion_id' => (int) $criterio->getCriterionId(),
                'text' => $criterio->getKeyword()->getText(),
                'match_type' => KeywordMatchType::name($criterio->getKeyword()->getMatchType()),
                'campos_ausentes' => ['palavra.metricas'],
            ];
            $this->anexarQualidade($porChave[$chave], $criterio);
        }

        foreach ($leiloes as $row) {
            if (!$row->hasCampaign() || !$row->hasAdGroup() || !$row->hasAdGroupCriterion() || !$row->hasMetrics()) {
                continue;
            }
            $id = (int) $row->getCampaign()->getId();
            $chave = $id . ':' . $row->getAdGroup()->getId() . ':' . $row->getAdGroupCriterion()->getCriterionId();
            if (!isset($porChave[$chave])) {
                continue;
            }
            $parcelas = $this->parcelasPalavra($row->getMetrics());
            if ($parcelas !== []) {
                $porChave[$chave] += $parcelas;
            }
        }

        foreach ($porChave as $item) {
            $campanhas[$item['campaign_id']]['palavras_chave'][] = $item;
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private function anexarQualidade(array &$item, AdGroupCriterion $criterio): void
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
            if ($campanha['status'] === 'ENABLED' && $campanha['urls_finais'] === []) {
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
            if (!$row->hasMetrics()) {
                $campanhas[$id]['campos_ausentes'][] = 'conversoes_por_acao.' . $nome;
                continue;
            }
            $bruto = $this->brutos($row->getMetrics());
            if ($bruto['conversions'] <= 0.0 && $bruto['all_conversions'] <= 0.0 && $bruto['conversions_value'] <= 0.0) {
                continue;
            }
            $fechado = self::fecharMetricas($bruto);
            $campanhas[$id]['conversoes_por_acao'][] = [
                'conversion_action' => $nome,
                'category' => self::enumNome(
                    ConversionActionCategory::class,
                    $row->getSegments()->getConversionActionCategory()
                ),
                'conversions' => $fechado['conversions'],
                'all_conversions' => $fechado['all_conversions'],
                'conversions_value' => $fechado['conversions_value'],
            ];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $campanhas
     * @param list<GoogleAdsRow> $linhas
     */
    private function anexarConfiguracao(array &$campanhas, array $linhas): void
    {
        foreach ($linhas as $row) {
            if (!$row->hasCampaign()) {
                continue;
            }
            $id = (int) $row->getCampaign()->getId();
            $campanhas[$id] ??= $this->campanhaBase($row);
            $campaign = $row->getCampaign();
            $estrategia = self::enumNome(BiddingStrategyType::class, $campaign->getBiddingStrategyType());
            $campanhas[$id]['canal'] = self::enumNome(AdvertisingChannelType::class, $campaign->getAdvertisingChannelType());
            $campanhas[$id]['estrategia_lance'] = $estrategia;
            $inicio = $campaign->hasStartDateTime() ? $campaign->getStartDateTime() : '';
            $campanhas[$id]['inicio'] = strlen($inicio) >= 10 ? substr($inicio, 0, 10) : null;
            $campanhas[$id]['optimization_score'] = $campaign->hasOptimizationScore()
                ? round((float) $campaign->getOptimizationScore() * 100, 1)
                : null;
            $campanhas[$id]['orcamento'] = $this->extrairOrcamento($row);
            $campanhas[$id]['redes'] = $this->extrairRedes($campaign);
            $campanhas[$id]['geo_alvo'] = $this->extrairGeoAlvo($campaign);
            $campanhas[$id]['automacao'] = $this->extrairAutomacao($campaign);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $campanhas
     * @param list<GoogleAdsRow> $linhas
     */
    private function anexarLances(array &$campanhas, array $linhas): void
    {
        foreach ($linhas as $row) {
            if (!$row->hasCampaign()) {
                continue;
            }
            $id = (int) $row->getCampaign()->getId();
            $campanhas[$id] ??= $this->campanhaBase($row);
            $campaign = $row->getCampaign();
            $estrategia = self::enumNome(BiddingStrategyType::class, $campaign->getBiddingStrategyType());
            if ($campanhas[$id]['estrategia_lance'] === null) {
                $campanhas[$id]['estrategia_lance'] = $estrategia;
            }
            $campanhas[$id]['lances'] = $this->extrairLances($campaign, $estrategia);
        }
    }

    private function extrairOrcamento(GoogleAdsRow $row): ?array
    {
        if (!$row->hasCampaignBudget()) {
            return null;
        }
        $budget = $row->getCampaignBudget();

        return [
            'id' => $budget->hasId() ? (int) $budget->getId() : null,
            'amount' => $budget->hasAmountMicros() ? self::moeda($budget->getAmountMicros()) : null,
            'period' => self::enumNome(BudgetPeriod::class, $budget->getPeriod()),
            'delivery_method' => self::enumNome(BudgetDeliveryMethod::class, $budget->getDeliveryMethod()),
            'shared' => $budget->hasExplicitlyShared() ? $budget->getExplicitlyShared() : null,
        ];
    }

    /**
     * @return array<string, bool>|null
     */
    private function extrairRedes(Campaign $campaign): ?array
    {
        if (!$campaign->hasNetworkSettings()) {
            return null;
        }
        $redes = $campaign->getNetworkSettings();

        return [
            'google_search' => $redes->getTargetGoogleSearch(),
            'search_partners' => $redes->getTargetSearchNetwork(),
            'display' => $redes->getTargetContentNetwork(),
            'partner_search_network' => $redes->getTargetPartnerSearchNetwork(),
            'youtube' => $redes->getTargetYoutube(),
        ];
    }

    /**
     * @return array<string, string|null>|null
     */
    private function extrairGeoAlvo(Campaign $campaign): ?array
    {
        if (!$campaign->hasGeoTargetTypeSetting()) {
            return null;
        }
        $geo = $campaign->getGeoTargetTypeSetting();

        return [
            'positive_geo_target_type' => self::enumNome(PositiveGeoTargetType::class, $geo->getPositiveGeoTargetType()),
            'negative_geo_target_type' => self::enumNome(NegativeGeoTargetType::class, $geo->getNegativeGeoTargetType()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extrairLances(Campaign $campaign, ?string $estrategia): array
    {
        $targetCpa = null;
        $targetRoas = null;
        $teto = null;
        $enhanced = null;
        $parcelaAlvo = null;

        if ($campaign->hasMaximizeConversions()) {
            $item = $campaign->getMaximizeConversions();
            $targetCpa = self::moedaPositiva($item->getTargetCpaMicros());
            $teto = self::moedaPositiva($item->getCpcBidCeilingMicros());
        }
        if ($campaign->hasMaximizeConversionValue()) {
            $item = $campaign->getMaximizeConversionValue();
            $roas = (float) $item->getTargetRoas();
            $targetRoas = $roas > 0 ? round($roas, 2) : null;
            $teto ??= self::moedaPositiva($item->getCpcBidCeilingMicros());
        }
        if ($campaign->hasTargetCpa()) {
            $item = $campaign->getTargetCpa();
            if ($item->hasTargetCpaMicros()) {
                $targetCpa = self::moedaPositiva($item->getTargetCpaMicros());
            }
            if ($item->hasCpcBidCeilingMicros()) {
                $teto = self::moedaPositiva($item->getCpcBidCeilingMicros());
            }
        }
        if ($campaign->hasTargetRoas()) {
            $item = $campaign->getTargetRoas();
            if ($item->hasTargetRoas()) {
                $targetRoas = round((float) $item->getTargetRoas(), 2);
            }
            if ($item->hasCpcBidCeilingMicros()) {
                $teto = self::moedaPositiva($item->getCpcBidCeilingMicros());
            }
        }
        if ($campaign->hasTargetSpend() && $campaign->getTargetSpend()->hasCpcBidCeilingMicros()) {
            $teto = self::moedaPositiva($campaign->getTargetSpend()->getCpcBidCeilingMicros());
        }
        if ($campaign->hasTargetImpressionShare()) {
            $item = $campaign->getTargetImpressionShare();
            $parcelaAlvo = [
                'location' => self::enumNome(TargetImpressionShareLocation::class, $item->getLocation()),
                'percent' => $item->hasLocationFractionMicros()
                    ? round(((float) $item->getLocationFractionMicros()) / 10000, 2)
                    : null,
                'cpc_bid_ceiling' => $item->hasCpcBidCeilingMicros()
                    ? self::moedaPositiva($item->getCpcBidCeilingMicros())
                    : null,
            ];
            if ($parcelaAlvo['cpc_bid_ceiling'] !== null) {
                $teto = $parcelaAlvo['cpc_bid_ceiling'];
            }
        }
        if ($campaign->hasManualCpc() && $campaign->getManualCpc()->hasEnhancedCpcEnabled()) {
            $enhanced = $campaign->getManualCpc()->getEnhancedCpcEnabled();
        }

        return [
            'target_cpa' => $targetCpa,
            'target_roas' => $targetRoas,
            'cpc_bid_ceiling' => $teto,
            'enhanced_cpc' => $enhanced,
            'sem_teto_cpa' => $targetCpa === null && in_array($estrategia, ['MAXIMIZE_CONVERSIONS', 'TARGET_SPEND'], true),
            'sem_teto_roas' => $targetRoas === null && $estrategia === 'MAXIMIZE_CONVERSION_VALUE',
            'target_impression_share' => $parcelaAlvo,
        ];
    }

    /**
     * @return array<string, bool|string|null>
     */
    private function extrairAutomacao(Campaign $campaign): array
    {
        $aiMax = null;
        if ($campaign->hasAiMaxSetting() && $campaign->getAiMaxSetting()->hasEnableAiMax()) {
            $aiMax = $campaign->getAiMaxSetting()->getEnableAiMax();
        }
        $migracao = $campaign->hasBroadMatchMigrationDateTime() ? $campaign->getBroadMatchMigrationDateTime() : null;

        return [
            'keyword_match_type' => self::enumNome(CampaignKeywordMatchType::class, $campaign->getKeywordMatchType()),
            'ai_max' => $aiMax,
            'broad_match_migration_at' => $migracao !== '' ? $migracao : null,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $campanhas
     * @param list<GoogleAdsRow> $linhas
     */
    private function anexarHistorico(array &$campanhas, array $linhas): void
    {
        $vistos = [];
        foreach ($linhas as $row) {
            if (!$row->hasCampaign() || !$row->hasSegments() || !$row->getSegments()->hasMonth() || !$row->hasMetrics()) {
                continue;
            }
            $id = (int) $row->getCampaign()->getId();
            $campanhas[$id] ??= $this->campanhaBase($row);
            if ($campanhas[$id]['canal'] === null) {
                $campanhas[$id]['canal'] = self::enumNome(
                    AdvertisingChannelType::class,
                    $row->getCampaign()->getAdvertisingChannelType()
                );
            }
            if ($campanhas[$id]['estrategia_lance'] === null) {
                $campanhas[$id]['estrategia_lance'] = self::enumNome(
                    BiddingStrategyType::class,
                    $row->getCampaign()->getBiddingStrategyType()
                );
            }
            $month = $row->getSegments()->getMonth();
            $chave = $id . '|' . $month;
            $vistos[$chave] ??= ['id' => $id, 'month' => $month, 'bruto' => self::brutoVazio()];
            self::somarBruto($vistos[$chave]['bruto'], $this->brutos($row->getMetrics()));
        }

        foreach ($vistos as $item) {
            $campanhas[$item['id']]['historico_mensal'][] = ['month' => $item['month']] + self::fecharMetricas($item['bruto']);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $campanhas
     * @return list<array<string, mixed>>
     */
    private function historicoConta(array $campanhas): array
    {
        $porMes = [];
        foreach ($campanhas as $campanha) {
            foreach ($campanha['historico_mensal'] as $mes) {
                $porMes[$mes['month']][] = $mes;
            }
        }
        ksort($porMes);
        $saida = [];
        foreach ($porMes as $month => $linhas) {
            $saida[] = ['month' => $month] + self::somarFechadas($linhas);
        }

        return $saida;
    }

    /**
     * @param list<GoogleAdsRow> $linhas
     * @return list<array<string, mixed>>
     */
    private function historicoAcoes(array $linhas): array
    {
        $vistos = [];
        foreach ($linhas as $row) {
            if (!$row->hasSegments() || !$row->getSegments()->hasMonth() || !$row->hasMetrics()) {
                continue;
            }
            $nome = $row->getSegments()->getConversionActionName();
            if ($nome === '') {
                continue;
            }
            $bruto = $this->brutos($row->getMetrics());
            if ($bruto['conversions'] <= 0.0 && $bruto['all_conversions'] <= 0.0 && $bruto['conversions_value'] <= 0.0) {
                continue;
            }
            $month = $row->getSegments()->getMonth();
            $chave = $month . "\0" . $nome;
            if (!isset($vistos[$chave])) {
                $vistos[$chave] = [
                    'month' => $month,
                    'conversion_action' => $nome,
                    'category' => self::enumNome(
                        ConversionActionCategory::class,
                        $row->getSegments()->getConversionActionCategory()
                    ),
                    'bruto' => self::brutoVazio(),
                ];
            }
            self::somarBruto($vistos[$chave]['bruto'], $bruto);
        }

        $saida = [];
        foreach ($vistos as $item) {
            $fechado = self::fecharMetricas($item['bruto']);
            $saida[] = [
                'month' => $item['month'],
                'conversion_action' => $item['conversion_action'],
                'category' => $item['category'],
                'conversions' => $fechado['conversions'],
                'all_conversions' => $fechado['all_conversions'],
                'conversions_value' => $fechado['conversions_value'],
            ];
        }
        usort($saida, static function (array $a, array $b): int {
            $mes = strcmp((string) $a['month'], (string) $b['month']);

            return $mes !== 0 ? $mes : ($b['conversions'] <=> $a['conversions']);
        });

        return $saida;
    }

    /**
     * @param array<int, array<string, mixed>> $campanhas
     * @param list<GoogleAdsRow> $linhas
     */
    private function anexarLeilao(array &$campanhas, array $linhas, string $destino): void
    {
        foreach ($linhas as $row) {
            if (!$row->hasCampaign() || !$row->hasMetrics()) {
                continue;
            }
            $id = (int) $row->getCampaign()->getId();
            $campanhas[$id] ??= $this->campanhaBase($row);
            $parcelas = $this->parcelas($row->getMetrics());
            $campanhas[$id][$destino] = $parcelas;
            if ($parcelas === [] && $campanhas[$id]['canal'] === 'SEARCH') {
                $campanhas[$id]['campos_ausentes'][] = $destino;
            }
        }
    }

    /**
     * @return array<string, array{percent: float|null, faixa: string}>
     */
    private function parcelas(Metrics $metrics): array
    {
        $saida = [];
        if ($metrics->hasSearchImpressionShare()) {
            $saida['search_impression_share'] = self::parcela((float) $metrics->getSearchImpressionShare());
        }
        if ($metrics->hasSearchBudgetLostImpressionShare()) {
            $saida['search_budget_lost_impression_share'] = self::parcela((float) $metrics->getSearchBudgetLostImpressionShare());
        }
        if ($metrics->hasSearchRankLostImpressionShare()) {
            $saida['search_rank_lost_impression_share'] = self::parcela((float) $metrics->getSearchRankLostImpressionShare());
        }
        if ($metrics->hasSearchTopImpressionShare()) {
            $saida['search_top_impression_share'] = self::parcela((float) $metrics->getSearchTopImpressionShare());
        }
        if ($metrics->hasSearchAbsoluteTopImpressionShare()) {
            $saida['search_absolute_top_impression_share'] = self::parcela((float) $metrics->getSearchAbsoluteTopImpressionShare());
        }
        if ($metrics->hasSearchClickShare()) {
            $saida['search_click_share'] = self::parcela((float) $metrics->getSearchClickShare());
        }

        return $saida;
    }

    /**
     * @return array<string, array{percent: float|null, faixa: string}>
     */
    private function parcelasPalavra(Metrics $metrics): array
    {
        $saida = [];
        if ($metrics->hasSearchImpressionShare()) {
            $saida['search_impression_share'] = self::parcela((float) $metrics->getSearchImpressionShare());
        }
        if ($metrics->hasSearchRankLostImpressionShare()) {
            $saida['search_rank_lost_impression_share'] = self::parcela((float) $metrics->getSearchRankLostImpressionShare());
        }

        return $saida;
    }

    /**
     * @return array{percent: float|null, faixa: string}
     */
    private static function parcela(float $ratio): array
    {
        if ($ratio < 0.1) {
            return ['percent' => null, 'faixa' => 'abaixo_de_10'];
        }
        if ($ratio > 0.9) {
            return ['percent' => null, 'faixa' => 'acima_de_90'];
        }

        return ['percent' => round($ratio * 100, 2), 'faixa' => 'exata'];
    }

    /**
     * @param array<int, array<string, mixed>> $campanhas
     * @param list<GoogleAdsRow> $linhas
     */
    private function anexarTermos(array &$campanhas, array $linhas): void
    {
        foreach ($linhas as $row) {
            if (!$row->hasCampaign() || !$row->hasSearchTermView() || !$row->hasMetrics()) {
                continue;
            }
            $termo = $row->getSearchTermView()->getSearchTerm();
            if ($termo === '') {
                continue;
            }
            $id = (int) $row->getCampaign()->getId();
            $campanhas[$id] ??= $this->campanhaBase($row);
            $segments = $row->hasSegments() ? $row->getSegments() : null;
            $keyword = null;
            $keywordMatch = null;
            if ($segments !== null && $segments->hasKeyword()) {
                $info = $segments->getKeyword();
                $keyword = $info->hasText() ? $info->getText() : null;
                $keywordMatch = self::enumNome(KeywordMatchType::class, $info->getMatchType());
            }
            $campanhas[$id]['termos_pesquisa'][] = [
                'ad_group_id' => $row->hasAdGroup() ? (int) $row->getAdGroup()->getId() : null,
                'ad_group' => $row->hasAdGroup() ? $row->getAdGroup()->getName() : null,
                'search_term' => $termo,
                'status' => self::enumNome(SearchTermTargetingStatus::class, $row->getSearchTermView()->getStatus()),
                'keyword' => $keyword,
                'keyword_match_type' => $keywordMatch,
                'search_term_match_type' => $segments === null
                    ? null
                    : self::enumNome(SearchTermMatchType::class, $segments->getSearchTermMatchType()),
                'match_source' => $segments === null
                    ? null
                    : self::enumNome(SearchTermMatchSource::class, $segments->getSearchTermMatchSource()),
            ] + self::fecharMetricas($this->brutos($row->getMetrics()));
        }
    }

    /**
     * @param array<int, array<string, mixed>> $campanhas
     * @param list<GoogleAdsRow> $linhas
     */
    private function anexarQuebra(array &$campanhas, array $linhas, string $destino, callable $dimensao): void
    {
        $acumulado = [];
        foreach ($linhas as $row) {
            if (!$row->hasCampaign() || !$row->hasMetrics()) {
                continue;
            }
            $dim = $dimensao($row);
            if (!is_array($dim)) {
                continue;
            }
            $id = (int) $row->getCampaign()->getId();
            $campanhas[$id] ??= $this->campanhaBase($row);
            $chave = $id . "\0" . json_encode($dim, JSON_UNESCAPED_UNICODE);
            $acumulado[$chave] ??= ['id' => $id, 'dim' => $dim, 'bruto' => self::brutoVazio()];
            self::somarBruto($acumulado[$chave]['bruto'], $this->brutos($row->getMetrics()));
        }

        foreach ($acumulado as $item) {
            $campanhas[$item['id']][$destino][] = $item['dim'] + self::fecharMetricas($item['bruto']);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $campanhas
     * @param list<GoogleAdsRow> $linhas
     */
    private function anexarProgramacao(array &$campanhas, array $linhas): void
    {
        foreach ($linhas as $row) {
            if (!$row->hasCampaign() || !$row->hasCampaignCriterion() || !$row->getCampaignCriterion()->hasAdSchedule()) {
                continue;
            }
            $criterio = $row->getCampaignCriterion();
            if (self::enumNome(CampaignCriterionStatus::class, $criterio->getStatus()) === 'REMOVED') {
                continue;
            }
            $agenda = $criterio->getAdSchedule();
            $dia = self::enumNome(DayOfWeek::class, $agenda->getDayOfWeek());
            if ($dia === null) {
                continue;
            }
            $id = (int) $row->getCampaign()->getId();
            $campanhas[$id] ??= $this->campanhaBase($row);
            $inicio = self::enumNome(MinuteOfHour::class, $agenda->getStartMinute());
            $fim = self::enumNome(MinuteOfHour::class, $agenda->getEndMinute());
            $campanhas[$id]['programacao'][] = [
                'day_of_week' => $dia,
                'start_hour' => $agenda->hasStartHour() ? (int) $agenda->getStartHour() : null,
                'start_minute' => $inicio !== null && isset(self::MINUTOS[$inicio]) ? self::MINUTOS[$inicio] : null,
                'end_hour' => $agenda->hasEndHour() ? (int) $agenda->getEndHour() : null,
                'end_minute' => $fim !== null && isset(self::MINUTOS[$fim]) ? self::MINUTOS[$fim] : null,
                'bid_modifier' => $criterio->hasBidModifier() ? round((float) $criterio->getBidModifier(), 2) : null,
            ];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $campanhas
     */
    private function marcarProgramacao(array &$campanhas, bool $consultaOk): void
    {
        if (!$consultaOk) {
            return;
        }
        foreach ($campanhas as &$campanha) {
            if ($campanha['status'] === 'ENABLED') {
                $campanha['programacao_24h'] = $campanha['programacao'] === [];
            }
        }
        unset($campanha);
    }

    /**
     * @param array<int, array<string, mixed>> $campanhas
     * @param list<GoogleAdsRow> $linhas
     */
    private function anexarLocais(array &$campanhas, array $linhas): void
    {
        foreach ($linhas as $row) {
            if (!$row->hasCampaign() || !$row->hasCampaignCriterion() || !$row->getCampaignCriterion()->hasLocation()) {
                continue;
            }
            $criterio = $row->getCampaignCriterion();
            if (self::enumNome(CampaignCriterionStatus::class, $criterio->getStatus()) === 'REMOVED') {
                continue;
            }
            $geoId = self::idRecurso($criterio->getLocation()->getGeoTargetConstant());
            if ($geoId === null) {
                continue;
            }
            $id = (int) $row->getCampaign()->getId();
            $campanhas[$id] ??= $this->campanhaBase($row);
            $campanhas[$id]['locais_configurados'][] = [
                'negative' => $criterio->hasNegative() ? $criterio->getNegative() : false,
                'bid_modifier' => $criterio->hasBidModifier() ? round((float) $criterio->getBidModifier(), 2) : null,
                'geo_id' => $geoId,
                'name' => null,
                'canonical_name' => null,
                'target_type' => null,
                'country_code' => null,
            ];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $campanhas
     * @param list<GoogleAdsRow> $linhas
     */
    private function anexarLocalizacoes(array &$campanhas, array $linhas): void
    {
        $acumulado = [];
        foreach ($linhas as $row) {
            if (!$row->hasCampaign() || !$row->hasGeographicView() || !$row->hasMetrics()) {
                continue;
            }
            $view = $row->getGeographicView();
            $locationId = null;
            if ($row->hasSegments() && $row->getSegments()->hasGeoTargetMostSpecificLocation()) {
                $locationId = self::idRecurso($row->getSegments()->getGeoTargetMostSpecificLocation());
            }
            $countryId = $view->hasCountryCriterionId() ? (int) $view->getCountryCriterionId() : null;
            $id = (int) $row->getCampaign()->getId();
            $campanhas[$id] ??= $this->campanhaBase($row);
            $dim = [
                'location_type' => self::enumNome(GeoTargetingType::class, $view->getLocationType()),
                'country_id' => $countryId,
                'location_id' => $locationId,
                'country' => null,
                'country_code' => null,
                'location' => null,
                'target_type' => null,
            ];
            $chave = $id . "\0" . json_encode($dim, JSON_UNESCAPED_UNICODE);
            $acumulado[$chave] ??= ['id' => $id, 'dim' => $dim, 'bruto' => self::brutoVazio()];
            self::somarBruto($acumulado[$chave]['bruto'], $this->brutos($row->getMetrics()));
        }

        foreach ($acumulado as $item) {
            $campanhas[$item['id']]['localizacoes'][] = $item['dim'] + self::fecharMetricas($item['bruto']);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $campanhas
     * @param list<GoogleAdsRow> $linhas
     */
    private function anexarPublicos(array &$campanhas, array $linhas): void
    {
        foreach ($linhas as $row) {
            if (!$row->hasCampaign() || !$row->hasAdGroupCriterion() || !$row->hasMetrics()) {
                continue;
            }
            $criterio = $row->getAdGroupCriterion();
            $nome = $criterio->getDisplayName();
            $lista = $criterio->hasUserList() ? $criterio->getUserList()->getUserList() : '';
            if ($nome === '') {
                $nome = $lista !== '' ? $lista : 'sem_nome';
            }
            $id = (int) $row->getCampaign()->getId();
            $campanhas[$id] ??= $this->campanhaBase($row);
            $campanhas[$id]['publicos'][] = [
                'ad_group_id' => $row->hasAdGroup() ? (int) $row->getAdGroup()->getId() : null,
                'ad_group' => $row->hasAdGroup() ? $row->getAdGroup()->getName() : null,
                'name' => $nome,
                'status' => self::enumNome(AdGroupCriterionStatus::class, $criterio->getStatus()),
                'bid_modifier' => $criterio->hasBidModifier() ? round((float) $criterio->getBidModifier(), 2) : null,
                'user_list' => $lista !== '' ? $lista : null,
            ] + self::fecharMetricas($this->brutos($row->getMetrics()));
        }
    }

    /**
     * @param array<int, array<string, mixed>> $campanhas
     * @return list<array<string, mixed>>
     */
    private function aplicarGeografia(array &$campanhas): array
    {
        $ids = [];
        foreach ($campanhas as $campanha) {
            foreach ($campanha['localizacoes'] as $linha) {
                foreach (['country_id', 'location_id'] as $campo) {
                    if (($linha[$campo] ?? null) !== null) {
                        $ids[] = (int) $linha[$campo];
                    }
                }
            }
            foreach ($campanha['locais_configurados'] as $linha) {
                if (($linha['geo_id'] ?? null) !== null) {
                    $ids[] = (int) $linha['geo_id'];
                }
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        [$nomes, $erros] = $this->resolverGeo($ids);
        foreach ($campanhas as &$campanha) {
            foreach ($campanha['localizacoes'] as &$linha) {
                $pais = self::geoInfo($nomes, $linha['country_id']);
                $lugar = self::geoInfo($nomes, $linha['location_id']);
                $linha['country'] = $pais === null || ($pais['name'] ?? '') === '' ? null : $pais['name'];
                $linha['country_code'] = $pais === null || ($pais['country_code'] ?? '') === '' ? null : $pais['country_code'];
                $linha['location'] = self::rotuloGeo($lugar);
                $linha['target_type'] = $lugar === null || ($lugar['target_type'] ?? '') === '' ? null : $lugar['target_type'];
            }
            unset($linha);
            foreach ($campanha['locais_configurados'] as &$local) {
                $info = self::geoInfo($nomes, $local['geo_id']) ?? [];
                $local['name'] = ($info['name'] ?? '') !== '' ? $info['name'] : null;
                $local['canonical_name'] = ($info['canonical_name'] ?? '') !== '' ? $info['canonical_name'] : null;
                $local['target_type'] = ($info['target_type'] ?? '') !== '' ? $info['target_type'] : null;
                $local['country_code'] = ($info['country_code'] ?? '') !== '' ? $info['country_code'] : null;
            }
            unset($local);
        }
        unset($campanha);

        return $erros;
    }

    /**
     * @param list<int> $ids
     * @return array{0: array<int, array<string, string>>, 1: list<array<string, mixed>>}
     */
    private function resolverGeo(array $ids): array
    {
        $nomes = [];
        $erros = [];
        foreach (array_chunk($ids, 200) as $indice => $lote) {
            $query = 'SELECT geo_target_constant.id, geo_target_constant.name, '
                . 'geo_target_constant.canonical_name, geo_target_constant.country_code, '
                . 'geo_target_constant.target_type FROM geo_target_constant '
                . 'WHERE geo_target_constant.id IN (' . implode(',', $lote) . ')';
            $bloco = $this->consultar('geo_nomes_' . $indice, $query);
            if ($bloco['erro'] !== null) {
                $erros[] = $bloco['erro'];
                continue;
            }
            foreach ($bloco['linhas'] as $row) {
                if (!$row->hasGeoTargetConstant() || !$row->getGeoTargetConstant()->hasId()) {
                    continue;
                }
                $geo = $row->getGeoTargetConstant();
                $nomes[(int) $geo->getId()] = [
                    'name' => $geo->getName(),
                    'canonical_name' => $geo->getCanonicalName(),
                    'country_code' => $geo->getCountryCode(),
                    'target_type' => $geo->getTargetType(),
                ];
            }
        }

        return [$nomes, $erros];
    }

    /**
     * @param array<int, array<string, string>> $nomes
     * @return array<string, string>|null
     */
    private static function geoInfo(array $nomes, mixed $id): ?array
    {
        if (!is_int($id) || !isset($nomes[$id])) {
            return null;
        }

        return $nomes[$id];
    }

    /**
     * @param array<string, string>|null $info
     */
    private static function rotuloGeo(?array $info): ?string
    {
        if ($info === null) {
            return null;
        }
        if (($info['canonical_name'] ?? '') !== '') {
            return $info['canonical_name'];
        }

        return ($info['name'] ?? '') !== '' ? $info['name'] : null;
    }

    /**
     * @param list<GoogleAdsRow> $linhas
     * @return array<string, mixed>|null
     */
    private function montarConta(array $linhas): ?array
    {
        foreach ($linhas as $row) {
            if (!$row->hasCustomer()) {
                continue;
            }
            $customer = $row->getCustomer();
            $rastreamento = $customer->hasConversionTrackingSetting()
                ? $customer->getConversionTrackingSetting()
                : null;
            $dono = $rastreamento === null ? '' : $rastreamento->getGoogleAdsConversionCustomer();

            return [
                'name' => $customer->hasDescriptiveName() ? $customer->getDescriptiveName() : null,
                'currency' => $customer->hasCurrencyCode() ? $customer->getCurrencyCode() : null,
                'time_zone' => $customer->hasTimeZone() ? $customer->getTimeZone() : null,
                'auto_tagging_enabled' => $customer->hasAutoTaggingEnabled() ? $customer->getAutoTaggingEnabled() : null,
                'conversion_tracking_status' => $rastreamento === null
                    ? null
                    : self::enumNome(ConversionTrackingStatus::class, $rastreamento->getConversionTrackingStatus()),
                'conversion_customer' => $dono !== '' ? $dono : null,
                'accepted_customer_data_terms' => $rastreamento === null
                    ? null
                    : $rastreamento->getAcceptedCustomerDataTerms(),
                'enhanced_conversions' => $rastreamento !== null && $rastreamento->hasEnablementSetting()
                    ? self::enumNome(EnhancedConversionsConfig::class, $rastreamento->getEnablementSetting())
                    : null,
                'enhanced_conversions_effective' => $rastreamento !== null && $rastreamento->hasEffectiveEnablementSetting()
                    ? self::enumNome(EnhancedConversionsConfig::class, $rastreamento->getEffectiveEnablementSetting())
                    : null,
            ];
        }

        return null;
    }

    /**
     * @param list<GoogleAdsRow> $linhas
     * @return list<array<string, mixed>>
     */
    private function catalogoAcoes(array $linhas): array
    {
        $acoes = [];
        foreach ($linhas as $row) {
            if (!$row->hasConversionAction()) {
                continue;
            }
            $acao = $row->getConversionAction();
            $valores = $acao->hasValueSettings() ? $acao->getValueSettings() : null;
            $acoes[] = [
                'id' => (int) $acao->getId(),
                'name' => $acao->getName(),
                'status' => self::enumNome(ConversionActionStatus::class, $acao->getStatus()),
                'type' => self::enumNome(ConversionActionType::class, $acao->getType()),
                'category' => self::enumNome(ConversionActionCategory::class, $acao->getCategory()),
                'primary_for_goal' => $acao->hasPrimaryForGoal() ? $acao->getPrimaryForGoal() : null,
                'include_in_conversions_metric' => $acao->hasIncludeInConversionsMetric()
                    ? $acao->getIncludeInConversionsMetric()
                    : null,
                'counting_type' => self::enumNome(ConversionActionCountingType::class, $acao->getCountingType()),
                'default_value' => $valores !== null && $valores->hasDefaultValue()
                    ? round((float) $valores->getDefaultValue(), 2)
                    : null,
                'default_currency' => $valores !== null && $valores->hasDefaultCurrencyCode()
                    ? $valores->getDefaultCurrencyCode()
                    : null,
                'always_use_default_value' => $valores !== null && $valores->hasAlwaysUseDefaultValue()
                    ? $valores->getAlwaysUseDefaultValue()
                    : null,
                'click_through_lookback_days' => $acao->hasClickThroughLookbackWindowDays()
                    ? (int) $acao->getClickThroughLookbackWindowDays()
                    : null,
                'view_through_lookback_days' => $acao->hasViewThroughLookbackWindowDays()
                    ? (int) $acao->getViewThroughLookbackWindowDays()
                    : null,
            ];
        }
        usort($acoes, static function (array $a, array $b): int {
            $primaria = ((int) ($b['primary_for_goal'] ?? false)) <=> ((int) ($a['primary_for_goal'] ?? false));

            return $primaria !== 0 ? $primaria : strcmp((string) $a['name'], (string) $b['name']);
        });

        return $acoes;
    }

    /**
     * @param list<GoogleAdsRow> $linhas
     * @return list<array<string, mixed>>
     */
    private function recomendacoes(array $linhas): array
    {
        $saida = [];
        foreach ($linhas as $row) {
            if (!$row->hasRecommendationSubscription()) {
                continue;
            }
            $item = $row->getRecommendationSubscription();
            $saida[] = [
                'type' => self::enumNome(RecommendationType::class, $item->getType()),
                'status' => $item->hasStatus()
                    ? self::enumNome(RecommendationSubscriptionStatus::class, $item->getStatus())
                    : null,
                'created_at' => $item->hasCreateDateTime() ? $item->getCreateDateTime() : null,
            ];
        }
        usort($saida, static function (array $a, array $b): int {
            $ativa = (($b['status'] === 'ENABLED') <=> ($a['status'] === 'ENABLED'));

            return $ativa !== 0 ? $ativa : strcmp((string) $a['type'], (string) $b['type']);
        });

        return $saida;
    }

    /**
     * @param list<GoogleAdsRow> $linhas
     * @return list<array<string, mixed>>
     */
    private function alteracoes(array $linhas): array
    {
        $saida = [];
        foreach ($linhas as $row) {
            if (!$row->hasChangeEvent()) {
                continue;
            }
            $evento = $row->getChangeEvent();
            $email = $evento->getUserEmail();
            $saida[] = [
                'changed_at' => $evento->getChangeDateTime(),
                'resource_type' => self::enumNome(ChangeEventResourceType::class, $evento->getChangeResourceType()),
                'operation' => self::enumNome(ResourceChangeOperation::class, $evento->getResourceChangeOperation()),
                'client_type' => self::enumNome(ChangeClientType::class, $evento->getClientType()),
                'user_email' => $email !== '' ? $email : null,
            ];
        }

        return $saida;
    }

    /**
     * @param array<int, array<string, mixed>> $campanhas
     * @return list<array<string, mixed>>
     */
    private function organizar(array $campanhas): array
    {
        foreach ($campanhas as &$campanha) {
            $campanha['campos_ausentes'] = array_values(array_unique($campanha['campos_ausentes']));
            $campanha['cost_since_2020'] = round(array_sum(array_map(
                static fn (array $mes): float => (float) ($mes['cost'] ?? 0),
                $campanha['historico_mensal']
            )), 2);
            usort(
                $campanha['historico_mensal'],
                static fn (array $a, array $b): int => strcmp((string) $a['month'], (string) $b['month'])
            );
            usort($campanha['serie_diaria'], static fn (array $a, array $b): int => strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? '')));
            usort($campanha['horarios'], static function (array $a, array $b): int {
                $dia = (self::ORDEM_DIA[$a['day_of_week']] ?? 9) <=> (self::ORDEM_DIA[$b['day_of_week']] ?? 9);

                return $dia !== 0 ? $dia : ($a['hour'] <=> $b['hour']);
            });
            usort($campanha['programacao'], static function (array $a, array $b): int {
                $dia = (self::ORDEM_DIA[$a['day_of_week']] ?? 9) <=> (self::ORDEM_DIA[$b['day_of_week']] ?? 9);

                return $dia !== 0 ? $dia : (($a['start_hour'] ?? 0) <=> ($b['start_hour'] ?? 0));
            });
            foreach (['termos_pesquisa', 'dispositivos', 'redes_desempenho', 'idades', 'generos', 'faixas_renda', 'localizacoes', 'publicos', 'palavras_chave', 'conversoes_por_acao'] as $campo) {
                usort($campanha[$campo], static fn (array $a, array $b): int => ((float) ($b['cost'] ?? $b['conversions'] ?? 0)) <=> ((float) ($a['cost'] ?? $a['conversions'] ?? 0)));
            }
        }
        unset($campanha);

        $lista = array_values($campanhas);
        usort($lista, static function (array $a, array $b): int {
            $custo = ((float) ($b['atual']['cost'] ?? 0)) <=> ((float) ($a['atual']['cost'] ?? 0));
            if ($custo !== 0) {
                return $custo;
            }
            $historico = ((float) $b['cost_since_2020']) <=> ((float) $a['cost_since_2020']);

            return $historico !== 0 ? $historico : strcmp((string) $a['name'], (string) $b['name']);
        });

        return $lista;
    }

    /**
     * @return array<string, int|float>
     */
    private function brutos(Metrics $metrics): array
    {
        return [
            'impressions' => $metrics->hasImpressions() ? (int) $metrics->getImpressions() : 0,
            'clicks' => $metrics->hasClicks() ? (int) $metrics->getClicks() : 0,
            'cost_micros' => $metrics->hasCostMicros() ? (float) $metrics->getCostMicros() : 0.0,
            'conversions' => $metrics->hasConversions() ? (float) $metrics->getConversions() : 0.0,
            'conversions_value' => $metrics->hasConversionsValue() ? (float) $metrics->getConversionsValue() : 0.0,
            'all_conversions' => $metrics->hasAllConversions() ? (float) $metrics->getAllConversions() : 0.0,
            'all_conversions_value' => $metrics->hasAllConversionsValue() ? (float) $metrics->getAllConversionsValue() : 0.0,
        ];
    }

    /**
     * @return array<string, int|float>
     */
    private static function brutoVazio(): array
    {
        return [
            'impressions' => 0,
            'clicks' => 0,
            'cost_micros' => 0.0,
            'conversions' => 0.0,
            'conversions_value' => 0.0,
            'all_conversions' => 0.0,
            'all_conversions_value' => 0.0,
        ];
    }

    /**
     * @param array<string, int|float> $total
     * @param array<string, int|float> $parte
     */
    private static function somarBruto(array &$total, array $parte): void
    {
        foreach ($total as $campo => $valor) {
            $total[$campo] = $valor + ($parte[$campo] ?? 0);
        }
    }

    /**
     * @param array<string, int|float> $brutos
     * @return array<string, int|float|null>
     */
    private static function fecharMetricas(array $brutos): array
    {
        $impressions = (int) $brutos['impressions'];
        $clicks = (int) $brutos['clicks'];
        $cost = self::moeda($brutos['cost_micros']);
        $conversions = round((float) $brutos['conversions'], 2);

        return [
            'impressions' => $impressions,
            'clicks' => $clicks,
            'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0.0,
            'cost' => $cost,
            'average_cpc' => $clicks > 0 ? round($cost / $clicks, 2) : 0.0,
            'conversions' => $conversions,
            'all_conversions' => round((float) $brutos['all_conversions'], 2),
            'conversions_value' => round((float) $brutos['conversions_value'], 2),
            'all_conversions_value' => round((float) $brutos['all_conversions_value'], 2),
            'cost_per_conversion' => $conversions > 0 ? round($cost / $conversions, 2) : null,
            'conversion_rate' => $clicks > 0 ? round(($conversions / $clicks) * 100, 2) : 0.0,
        ];
    }

    /**
     * @param list<array<string, mixed>> $linhas
     * @return array<string, int|float|null>
     */
    private static function somarFechadas(array $linhas): array
    {
        $bruto = self::brutoVazio();
        foreach ($linhas as $linha) {
            $bruto['impressions'] += (int) ($linha['impressions'] ?? 0);
            $bruto['clicks'] += (int) ($linha['clicks'] ?? 0);
            $bruto['cost_micros'] += ((float) ($linha['cost'] ?? 0)) * 1000000;
            $bruto['conversions'] += (float) ($linha['conversions'] ?? 0);
            $bruto['conversions_value'] += (float) ($linha['conversions_value'] ?? 0);
            $bruto['all_conversions'] += (float) ($linha['all_conversions'] ?? 0);
            $bruto['all_conversions_value'] += (float) ($linha['all_conversions_value'] ?? 0);
        }

        return self::fecharMetricas($bruto);
    }

    private static function enumNome(string $enumClass, int $valor): ?string
    {
        try {
            $nome = $enumClass::name($valor);
        } catch (UnexpectedValueException) {
            return null;
        }
        if ($nome === 'UNSPECIFIED' || $nome === 'UNKNOWN') {
            return null;
        }

        return $nome;
    }

    private static function idRecurso(string $resource): ?int
    {
        if ($resource === '' || preg_match('/(\d+)$/', $resource, $encontrado) !== 1) {
            return null;
        }

        return (int) $encontrado[1];
    }

    private static function moeda(int|float|string $micros): float
    {
        return round(((float) $micros) / 1000000, 2);
    }

    private static function moedaPositiva(int|float|string $micros): ?float
    {
        if ((float) $micros <= 0) {
            return null;
        }

        return self::moeda($micros);
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
