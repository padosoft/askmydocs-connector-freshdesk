<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorFreshdesk\Sync;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\Metadata\SourceAwareMetadataBuilder;
use Padosoft\AskMyDocsConnectorFreshdesk\Http\FreshdeskClient;
use Padosoft\AskMyDocsConnectorFreshdesk\Http\UrlPolicy;
use Padosoft\AskMyDocsConnectorFreshdesk\Support\Markdown;

final readonly class DocumentImporter
{
    public function __construct(private ConnectorIngestionContract $ingestion) {}

    /**
     * @param  array<string|int,mixed>  $ticket
     */
    public function ticket(ConnectorInstallation $installation, SyncRun $run, FreshdeskClient $client, array $ticket): void
    {
        $private = (bool) data_get($installation->config_json, 'include_private_notes', true);
        $conversations = [];
        for ($page = 1; $page <= 300; $page++) {
            $rows = $client->get('/tickets/'.(int) $ticket['id'].'/conversations', ['page' => $page, 'per_page' => 100]);
            $conversations = array_merge($conversations, $rows);
            if (count($rows) < 100) {
                break;
            }
            if ($page === 300) {
                throw new \RuntimeException('Freshdesk conversations exceeded the pagination limit.');
            }
        }
        $visible = array_values(array_filter($conversations, fn ($row) => $private || ! ($row['private'] ?? false)));
        $body = Markdown::ticket($ticket, $visible, $private);
        $this->write($installation, $run, 'ticket', (string) $ticket['id'], (string) ($ticket['subject'] ?? 'Ticket'), $body, 'text/markdown', null, [
            'created_at' => $ticket['created_at'] ?? null, 'updated_at' => $ticket['updated_at'] ?? null, 'status' => $ticket['status'] ?? null,
            'priority' => $ticket['priority'] ?? null, 'tags' => $ticket['tags'] ?? [], 'private_notes_included' => $private,
        ]);
        $attachments = (array) ($ticket['attachments'] ?? []);
        foreach ($visible as $row) {
            $attachments = array_merge($attachments, (array) ($row['attachments'] ?? []));
        }
        $accepted = [];
        if (data_get($installation->config_json, 'attachments.enabled', true)) {
            $limit = max(1, min(100, (int) data_get($installation->config_json, 'attachments.max_per_ticket', 20)));
            foreach ($attachments as $attachment) {
                if (count($accepted) >= $limit) {
                    $counts = $run->counts;
                    $counts['attachments_skipped'] = ($counts['attachments_skipped'] ?? 0) + 1;
                    $run->counts = $counts;

                    continue;
                }
                if ($this->attachment($installation, $run, $client, $attachment, (string) $ticket['id'])) {
                    $accepted[] = (string) $attachment['id'];
                }
            }
        }
        // Removing private notes also removes their previously imported attachments.
        $states = $this->states($installation)->where('kind', 'attachment')->where('parent_id', (string) $ticket['id'])->get();
        foreach ($states as $state) {
            if (! in_array((string) $state->remote_id, $accepted, true)) {
                // A disabled import/size limit is not evidence of remote deletion.
                $remoteVisible = array_column($attachments, 'id');
                if (! in_array((int) $state->remote_id, $remoteVisible, true)) {
                    $this->remove($installation, $run, $state);
                }
            }
        }
    }

    /**
     * @param  array<string|int,mixed>  $article
     */
    public function article(ConnectorInstallation $installation, SyncRun $run, array $article): void
    {
        $id = (string) $article['id'];
        if ((int) ($article['status'] ?? 1) !== 2) {
            $this->delete($installation, $run, 'article', $id);

            return;
        }
        $this->write($installation, $run, 'article', $id, (string) ($article['title'] ?? 'Article'), '# '.($article['title'] ?? 'Article')."\n\n".Markdown::body($article)."\n", 'text/markdown', null, [
            'created_at' => $article['created_at'] ?? null, 'updated_at' => $article['updated_at'] ?? null, 'category_id' => $article['category_id'] ?? null, 'folder_id' => $article['folder_id'] ?? null, 'tags' => $article['tags'] ?? [],
        ]);
    }

    /**
     * @param  array<string|int,mixed>  $attachment
     */
    private function attachment(ConnectorInstallation $installation, SyncRun $run, FreshdeskClient $client, array $attachment, string $ticketId): bool
    {
        $name = basename(str_replace('\\', '/', (string) ($attachment['name'] ?? '')));
        $name = preg_replace('/[\x00-\x1f\x7f]/', '', $name) ?? '';
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mimes = ['pdf' => 'application/pdf', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'txt' => 'text/plain', 'md' => 'text/markdown', 'markdown' => 'text/markdown'];
        if (config('kb.ocr.enabled', false)) {
            $mimes += ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'tif' => 'image/tiff', 'tiff' => 'image/tiff', 'webp' => 'image/webp'];
        }
        $maxBytes = max(1, min(100, (int) data_get($installation->config_json, 'attachments.max_size_mb', 25))) * 1024 * 1024;
        if (! isset($mimes[$extension]) || (int) ($attachment['file_size'] ?? 0) > $maxBytes || ! isset($attachment['id'], $attachment['attachment_url'])) {
            $counts = $run->counts;
            $counts['attachments_skipped'] = ($counts['attachments_skipped'] ?? 0) + 1;
            $run->counts = $counts;

            return false;
        }
        $mime = $mimes[$extension];
        $bytes = $client->download((string) $attachment['attachment_url'], $maxBytes);
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $compatible = $detected === $mime
            || ($mime === 'text/markdown' && $detected === 'text/plain')
            || ($extension === 'docx' && in_array($detected, ['application/zip', 'application/octet-stream'], true) && $this->isDocx($bytes));
        if (! $compatible || (isset($attachment['content_type']) && strtolower(explode(';', (string) $attachment['content_type'])[0]) !== $mime && ! (in_array($extension, ['md', 'markdown'], true) && $attachment['content_type'] === 'text/plain'))) {
            throw new \RuntimeException('Freshdesk attachment MIME does not match its file type.');
        }
        $this->write($installation, $run, 'attachment', (string) $attachment['id'], $name, $bytes, $mime, $ticketId, ['filename' => $name], $extension);

        return true;
    }

    private function isDocx(string $bytes): bool
    {
        $path = tempnam(sys_get_temp_dir(), 'freshdesk-docx-');
        if ($path === false) {
            throw new \RuntimeException('Unable to validate Freshdesk DOCX attachment.');
        }
        try {
            file_put_contents($path, $bytes);
            $zip = new \ZipArchive;
            if ($zip->open($path) !== true) {
                return false;
            }
            try {
                return $zip->locateName('[Content_Types].xml') !== false && $zip->locateName('word/document.xml') !== false;
            } finally {
                $zip->close();
            }
        } finally {
            unlink($path);
        }
    }

    /**
     * @param  array<string|int,mixed>  $fields
     */
    private function write(ConnectorInstallation $installation, SyncRun $run, string $kind, string $id, string $title, string $body, string $mime, ?string $parent, array $fields, string $extension = 'md'): void
    {
        if (preg_match('/^[1-9][0-9]*$/D', $id) !== 1) {
            throw new \RuntimeException('Freshdesk returned an invalid remote identifier.');
        }
        if (in_array($mime, ['text/markdown', 'text/plain'], true)) {
            $body = $this->ingestion->redactContent($body);
        }
        $identity = ['tenant_id' => $installation->tenant_id, 'installation_id' => $installation->id, 'kind' => $kind, 'remote_id' => $id];
        $state = SourceState::query()->where($identity)->first();
        $fingerprint = hash('sha256', $body."\0".json_encode($fields));
        if ($state !== null && $state->fingerprint === $fingerprint) {
            $state->forceFill(['last_seen_run_id' => $run->id])->save();

            return;
        }
        $project = $installation->project_key ?: data_get($installation->config_json, 'project_key', config('kb.ingest.default_project', 'default'));
        $project = is_string($project) && $project !== '' ? $project : 'default';
        if (preg_match('/^[a-zA-Z0-9_-]+$/D', $project) !== 1) {
            throw new \RuntimeException('Freshdesk project key is not a safe directory name.');
        }
        $relative = $project.'/connectors/freshdesk/tenant-'.hash('sha256', $installation->tenant_id).'/installation-'.$installation->id.'/'.$kind.'/'.$id.'.'.$extension;
        $paths = $this->ingestion->resolveKbSourcePath($relative);
        if (! Storage::disk($paths['disk'])->put($paths['absolute'], $body)) {
            throw new \RuntimeException('Unable to write the Freshdesk source file.');
        }
        $url = 'https://'.UrlPolicy::domain((string) data_get($installation->config_json, 'connection.domain'));
        $url .= $kind === 'article' ? '/support/solutions/articles/'.$id : '/a/tickets/'.($parent ?? $id);
        $metadata = (new SourceAwareMetadataBuilder)->build([
            'connector' => 'freshdesk', 'installation_id' => $installation->id, 'freshdesk_remote_id' => $installation->id.':'.$kind.':'.$id,
            'remote_id' => $id, 'source_url' => $url, 'source_kind' => $kind,
        ], 'freshdesk', $fields + ['id' => $id, 'kind' => $kind, 'ticket_id' => $parent], (array) ($fields['tags'] ?? []), lastModified: $fields['updated_at'] ?? null);
        $this->ingestion->dispatchIngestion($project, $paths['relative'], $paths['disk'], $title, $metadata, $mime, $installation->tenant_id);
        SourceState::query()->updateOrCreate($identity, ['parent_id' => $parent, 'fingerprint' => $fingerprint, 'relative_path' => $relative, 'last_seen_run_id' => $run->id]);
        $counts = $run->counts;
        $counter = $state === null ? 'documents_added' : 'documents_updated';
        $counts[$counter] = ($counts[$counter] ?? 0) + 1;
        $counts[$kind.'s'] = ($counts[$kind.'s'] ?? 0) + 1;
        $run->counts = $counts;
    }

    public function delete(ConnectorInstallation $installation, SyncRun $run, string $kind, string $id): void
    {
        foreach ($this->states($installation)->where(function ($query) use ($kind, $id): void {
            $query->where(fn ($q) => $q->where('kind', $kind)->where('remote_id', $id));
            if ($kind === 'ticket') {
                $query->orWhere(fn ($q) => $q->where('kind', 'attachment')->where('parent_id', $id));
            }
        })->get() as $state) {
            $this->remove($installation, $run, $state);
        }
    }

    private function remove(ConnectorInstallation $installation, SyncRun $run, SourceState $state): void
    {
        $this->ingestion->softDeleteByRemoteId($installation, 'freshdesk_remote_id', $installation->id.':'.$state->kind.':'.$state->remote_id);
        $state->delete();
        $counts = $run->counts;
        $counts['documents_removed'] = ($counts['documents_removed'] ?? 0) + 1;
        $run->counts = $counts;
    }

    /** @return Builder<SourceState> */
    private function states(ConnectorInstallation $installation): Builder
    {
        return SourceState::query()->where('tenant_id', $installation->tenant_id)->where('installation_id', $installation->id);
    }
}
