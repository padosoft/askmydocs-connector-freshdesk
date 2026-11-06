<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorFreshdesk\Support;

use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorFreshdesk\Exceptions\IngestionDisabled;

/** Per-account preferences; host switches remain authoritative. */
final class Features
{
    public static function ingestion(ConnectorInstallation $installation): bool
    {
        return self::enabled($installation, 'ingestion.enabled');
    }

    public static function live(ConnectorInstallation $installation): bool
    {
        return config('connector-freshdesk.chat_tools.enabled', true) && self::enabled($installation, 'chat_tools.enabled');
    }

    public static function caseStudies(ConnectorInstallation $installation): bool
    {
        return config('connector-freshdesk.case_studies.enabled', false) && self::enabled($installation, 'case_studies.enabled');
    }

    public static function generateCases(ConnectorInstallation $installation): bool
    {
        return self::ingestion($installation) && self::caseStudies($installation);
    }

    public static function assertIngestion(ConnectorInstallation $installation): void
    {
        if (! self::ingestion($installation)) {
            throw new IngestionDisabled('Freshdesk ingestion is disabled for this account. Enable it in connection settings before importing.');
        }
    }

    private static function enabled(ConnectorInstallation $installation, string $path): bool
    {
        return filter_var(data_get($installation->config_json, $path, true), FILTER_VALIDATE_BOOLEAN);
    }
}
