<?php

declare(strict_types=1);

namespace GsFacil\GoogleAds;

use RuntimeException;
use Throwable;

final class GoogleAdsApiException extends RuntimeException
{
    /**
     * @param list<array{code: string, message: string, location: ?string}> $errors
     */
    public function __construct(
        private readonly ?string $requestId,
        private readonly array $errors,
        ?Throwable $previous = null,
    ) {
        $summary = $errors === []
            ? 'erro sem detalhes'
            : implode('; ', array_map(
                static fn (array $error): string => $error['code'] . ': ' . $error['message'],
                $errors
            ));

        $message = $requestId === null || $requestId === ''
            ? 'Google Ads API: ' . $summary
            : 'Google Ads API (' . $requestId . '): ' . $summary;

        parent::__construct($message, 0, $previous);
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    /**
     * @return list<array{code: string, message: string, location: ?string}>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
