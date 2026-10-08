<?php

namespace Padosoft\AskMyDocsConnectorFreshdesk\Tests;

use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;

final class FakeIngestion implements ConnectorIngestionContract
{
    public array $documents = [];

    public array $deleted = [];

    public function dispatchIngestion(string $projectKey, string $relativePath, string $disk, string $title, array $metadata, string $mimeType, string $tenantId): void
    {
        $this->documents[] = compact('projectKey', 'relativePath', 'disk', 'title', 'metadata', 'mimeType', 'tenantId');
    }

    public function resolveKbSourcePath(string $relativePath): array
    {
        return ['relative' => $relativePath, 'absolute' => $relativePath, 'disk' => 'local'];
    }

    public function redactContent(string $content): string
    {
        return str_replace('sensitive@example.com', '[redacted]', $content);
    }

    public function emitAudit(string $connectorKey, string $eventType, ?int $installationId = null, ?array $metadata = null): void {}

    public function softDeleteByRemoteId(ConnectorInstallation $installation, string $metadataKey, string $remoteId): bool
    {
        $this->deleted[] = [$installation->tenant_id, $remoteId];

        return true;
    }
}
