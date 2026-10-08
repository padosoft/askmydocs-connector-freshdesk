<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorFreshdesk;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Padosoft\AskMyDocsConnectorBase\BaseConnector;
use Padosoft\AskMyDocsConnectorBase\Contracts\SupportsConnectionSettings;
use Padosoft\AskMyDocsConnectorBase\Contracts\SupportsCredentialForm;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Padosoft\AskMyDocsConnectorBase\HealthStatus;
use Padosoft\AskMyDocsConnectorBase\Support\CredentialField;
use Padosoft\AskMyDocsConnectorBase\SyncResult;
use Padosoft\AskMyDocsConnectorFreshdesk\Http\FreshdeskClient;
use Padosoft\AskMyDocsConnectorFreshdesk\Sync\SyncManager;

final class FreshdeskConnector extends BaseConnector implements SupportsConnectionSettings, SupportsCredentialForm
{
    public function key(): string
    {
        return 'freshdesk';
    }

    public function displayName(): string
    {
        return 'Freshdesk';
    }

    /**
     * @return array<string|int,mixed>
     */
    public function credentialFormSchema(): array
    {
        return [
            (new CredentialField('domain', 'Freshdesk domain', 'text', 'connection', required: true, help: 'For example company.freshdesk.com'))->toArray(),
            (new CredentialField('api_key', 'API key', 'password', 'secret', required: true, secret: true))->toArray(),
        ];
    }

    /**
     * @return array<string|int,mixed>
     */
    public function connectionSettingsSchema(): array
    {
        return [
            (new CredentialField('date_window_days', 'Ticket history (days)', 'number', 'config', default: 90, help: 'Tickets updated within this period. Use Fetch all for a one-time historical import.', group: 'Sync'))->toArray(),
            (new CredentialField('include_private_notes', 'Include private notes', 'checkbox', 'config', default: true, group: 'Content'))->toArray(),
            (new CredentialField('attachments.enabled', 'Import attachments', 'checkbox', 'config', default: true, group: 'Attachments'))->toArray(),
            (new CredentialField('attachments.max_size_mb', 'Maximum file size (MiB)', 'number', 'config', default: 25, group: 'Attachments'))->toArray(),
            (new CredentialField('attachments.max_per_ticket', 'Maximum files per ticket', 'number', 'config', default: 20, group: 'Attachments'))->toArray(),
        ];
    }

    public function initiateOAuth(int $installationId): string
    {
        $this->loadInstallation($installationId);

        return '/connectors/freshdesk/credentials?state='.urlencode($this->issueOAuthState($installationId));
    }

    public function handleOAuthCallback(int $installationId, Request $request): void
    {
        $installation = $this->loadInstallation($installationId);
        if (! $this->consumeOAuthState($installationId, (string) $request->input('state'))) {
            throw new ConnectorAuthException('Invalid Freshdesk credential state.');
        }
        $secret = (string) $request->input('api_key');
        if (trim($secret) === '') {
            throw new ConnectorAuthException('Enter a Freshdesk API key.');
        }
        try {
            (new FreshdeskClient((string) data_get($installation->config_json, 'connection.domain'), $secret))->get('/agents/me');
        } catch (\Throwable $exception) {
            throw new ConnectorAuthException($exception->getMessage(), previous: $exception);
        }
        $this->vault->setCredentials($installationId, accessToken: $secret);
        $this->emitAudit('installed', installationId: $installationId);
    }

    public function health(int $installationId): HealthStatus
    {
        try {
            app(SyncManager::class)->client($this->loadInstallation($installationId))->get('/agents/me');

            return HealthStatus::healthy();
        } catch (\Throwable $exception) {
            return HealthStatus::errored($exception->getMessage());
        }
    }

    public function syncFull(int $installationId): SyncResult
    {
        return $this->enqueue($installationId);
    }

    public function syncIncremental(int $installationId, ?Carbon $since): SyncResult
    {
        // Durable package checkpoints are authoritative; the base job may finish
        // its scheduling pass before the queued import has completed.
        return $this->enqueue($installationId);
    }

    private function enqueue(int $installationId): SyncResult
    {
        $installation = $this->loadInstallation($installationId);
        app(SyncManager::class)->start($installation);

        return new SyncResult(0, 0, 0, [], Carbon::parse($installation->last_sync_at ?? '1970-01-01T00:00:00Z'));
    }

    public function disconnect(int $installationId): void
    {
        $installation = $this->loadInstallation($installationId);
        app(SyncManager::class)->cancel($installation);
        $this->vault->clearCredentials($installationId);
        $this->emitAudit('disconnected', installationId: $installationId);
    }
}
