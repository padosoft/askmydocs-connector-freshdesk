<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorFreshdesk\Exceptions;

use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorApiException;

final class FreshdeskApiException extends ConnectorApiException
{
    public function __construct(public readonly int $status, public readonly int $retryAfter = 0)
    {
        parent::__construct(match ($status) {
            401 => 'Freshdesk rejected the API key.',
            403 => 'The Freshdesk account does not permit this operation.',
            404 => 'Freshdesk resource not found.',
            429 => 'Freshdesk rate limit reached. The import will resume after Retry-After.',
            default => 'Freshdesk request failed (HTTP '.$status.').',
        });
    }
}
