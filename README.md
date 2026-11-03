# Google Ads

Satélite PHP 8.2 no runtime compartilhado (`php/compose.yaml`). Não tem alias
HTTP: a árvore inteira responde 403. O mesmo código roda no Mac e na VPS; só
o `.env` muda.

Biblioteca: `googleads/google-ads-php` ^35.3 (SDK oficial). A API usada no
código é a V26, a mais nova empacotada nessa linha. Não há `google_ads_php.ini`.

## Variáveis de ambiente

Definidas em `php/.env` e injetadas no serviço `php`:

- `GOOGLE_ADS_CLIENT_ID`
- `GOOGLE_ADS_CLIENT_SECRET`
- `GOOGLE_ADS_DEVELOPER_TOKEN` (opcional; token legado da Central de API)
- `GOOGLE_ADS_REFRESH_TOKEN`
- `GOOGLE_ADS_LOGIN_CUSTOMER_ID` (opcional; ID da MCC, 10 dígitos)
- `GOOGLE_ADS_CUSTOMER_ID` (conta de anúncios consultada, 10 dígitos)

O startup do container não exige essas chaves. A chamada falha se client ID,
client secret, refresh token ou customer ID estiverem vazios. IDs aceitam
traços; o serviço envia só os dígitos.

## Composer

Na pasta `php/`, com o lock já gerado:

```bash
php/scripts/install-composer-deps.sh --apply
```

Para atualizar o lock deste satélite:

```bash
docker run --rm --user "$(id -u):$(id -g)" \
  -v "$PWD/googleads:/app" -w /app \
  composer:2 \
  update --no-dev --prefer-dist --no-interaction --optimize-autoloader
```

## Extensões no runtime

A imagem é Debian (`php:8.2-apache`). O `php/Dockerfile` instala `grpc-1.85.0`
e `protobuf-5.36.2` via PECL, as mesmas versões do `composer.lock`
(`grpc/grpc` e `google/protobuf`). Sem `grpc`, `GoogleAdsService::fromEnvironment()`
recusa a chamada. Reconstrua a imagem depois de mudar o Dockerfile:

```bash
docker compose -f php/compose.yaml build php
docker compose -f php/compose.yaml up -d php
```

Alpine (não é a base desta infra), se um dia a imagem mudar:

```dockerfile
RUN apk add --no-cache $PHPIZE_DEPS zlib-dev linux-headers \
    && pecl install grpc \
    && pecl install protobuf \
    && docker-php-ext-enable grpc protobuf \
    && apk del $PHPIZE_DEPS zlib-dev linux-headers
```

## Uso

```bash
docker compose -f php/compose.yaml exec php \
  php /var/www/html/googleads/examples/listar-campanhas.php
```

A consulta GAQL traz id, nome, status, impressões e cliques das campanhas
`ENABLED`, agregados em `LAST_30_DAYS` (a API exige intervalo para `metrics.*`).
