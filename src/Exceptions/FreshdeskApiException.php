<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorFreshdesk\Exceptions;

use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorApiException;

final class FreshdeskApiException extends ConnectorApiException
{
    /** @param list<array{field:string,code:string}> $validationFields */
    public function __construct(public readonly int $status, public readonly int $retryAfter = 0, public readonly array $validationFields = [])
    {
        parent::__construct(match ($status) {
            401 => 'Freshdesk rejected the API key.',
            403 => 'The Freshdesk account does not permit this operation.',
            404 => 'Freshdesk resource not found.',
            429 => 'Freshdesk rate limit reached. The import will resume after Retry-After.',
            default => 'Freshdesk request failed (HTTP '.$status.').',
        });
    }

    /** @return array<string,mixed> */
    public function diagnostics(): array
    {
        return ['error_code' => match ($this->status) {
            400 => 'invalid_query', 401 => 'authentication_failed', 403 => 'permission_denied',
            404 => 'resource_not_found', 429 => 'rate_limited', default => 'request_failed',
        }, 'http_status' => $this->status, 'retryable' => $this->status === 429 || $this->status >= 500,
            'validation_fields' => $this->validationFields];
    }
}
