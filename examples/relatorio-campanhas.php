<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use GsFacil\GoogleAds\GoogleAdsApiException;
use GsFacil\GoogleAds\GoogleAdsService;

try {
    $relatorio = GoogleAdsService::fromEnvironment()->relatorioDesempenho();
    echo json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
} catch (GoogleAdsApiException $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    foreach ($exception->errors() as $error) {
        fwrite(STDERR, sprintf(
            "- %s: %s (local: %s)\n",
            $error['code'],
            $error['message'],
            $error['location'] ?? 'nenhum'
        ));
    }
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
