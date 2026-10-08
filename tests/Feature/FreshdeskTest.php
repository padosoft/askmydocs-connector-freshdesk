<?php

namespace Padosoft\AskMyDocsConnectorFreshdesk\Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\ConnectorRegistry;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorFreshdesk\Exceptions\FreshdeskApiException;
use Padosoft\AskMyDocsConnectorFreshdesk\FreshdeskConnector;
use Padosoft\AskMyDocsConnectorFreshdesk\Http\FreshdeskClient;
use Padosoft\AskMyDocsConnectorFreshdesk\Http\UrlPolicy;
use Padosoft\AskMyDocsConnectorFreshdesk\Sync\DocumentImporter;
use Padosoft\AskMyDocsConnectorFreshdesk\Sync\ProcessSyncBatch;
use Padosoft\AskMyDocsConnectorFreshdesk\Sync\SourceState;
use Padosoft\AskMyDocsConnectorFreshdesk\Sync\SyncManager;
use Padosoft\AskMyDocsConnectorFreshdesk\Sync\SyncRun;
use Padosoft\AskMyDocsConnectorFreshdesk\Tests\TestCase;
use Padosoft\AskMyDocsConnectorFreshdesk\Tools\FreshdeskTools;

final class FreshdeskTest extends TestCase
{
    private function authorized(array $config = [])
    {
        $installation = $this->installation($config);
        app(OAuthCredentialVault::class)->setCredentials($installation->id, accessToken: 'secret-key');

        return $installation;
    }

    private function sourceFixture(bool $attachments = false): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($attachments) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $rows = match ($path) {
                '/api/v2/tickets' => ($request['filter'] ?? null) === 'deleted' ? [] : [['id' => 1, 'updated_at' => now()->subDay()->toIso8601ZuluString()]],
                '/api/v2/tickets/1' => ['id' => 1, 'subject' => 'Help', 'description_text' => 'sensitive@example.com initial issue', 'updated_at' => now()->subDay()->toIso8601ZuluString(), 'attachments' => $attachments ? [['id' => 8, 'name' => 'info.txt', 'content_type' => 'text/plain', 'file_size' => 5, 'attachment_url' => 'https://cdn.freshdesk.com/file?signature=secret']] : []],
                '/api/v2/tickets/1/conversations' => [['id' => 11, 'private' => false, 'body_text' => 'Public reply'], ['id' => 12, 'private' => true, 'body_text' => 'Private note']],
                '/api/v2/solutions/categories' => [['id' => 2]],
                '/api/v2/solutions/categories/2/folders' => [['id' => 3]],
                '/api/v2/solutions/folders/3/subfolders' => [['id' => 4]],
                '/api/v2/solutions/folders/4/subfolders' => [],
                '/api/v2/solutions/folders/3/articles' => [],
                '/api/v2/solutions/folders/4/articles' => [['id' => 5]],
                '/api/v2/solutions/articles/5' => ['id' => 5, 'title' => 'Guide', 'description' => '<p>Useful guide</p>', 'status' => 2],
                '/file' => 'hello world',
                default => throw new \RuntimeException('Unexpected fixture path: '.$path),
            };

            return Http::response($rows, 200, $path === '/file' ? ['Content-Type' => 'text/plain'] : []);
        });
    }

    private function finish(SyncRun $run): void
    {
        for ($batch = 0; $batch < 40; $batch++) {
            if (app(SyncManager::class)->batch($run->id) === null) {
                return;
            }
        }
        $this->fail('Sync did not complete.');
    }

    public function test_package_discovery_and_credential_state_verifies_before_vault(): void
    {
        $i = $this->installation();
        Http::fake(['*/agents/me' => Http::response(['id' => 1])]);
        $connector = app(FreshdeskConnector::class);
        $this->assertInstanceOf(FreshdeskConnector::class, app(ConnectorRegistry::class)->get('freshdesk'));
        parse_str(parse_url($connector->initiateOAuth($i->id), PHP_URL_QUERY), $query);
        $connector->handleOAuthCallback($i->id, Request::create('/', 'POST', ['state' => $query['state'], 'api_key' => 'secret-key']));
        $this->assertSame('secret-key', app(OAuthCredentialVault::class)->getAccessToken($i->id));
        $this->assertStringNotContainsString('secret-key', json_encode($i->fresh()->config_json));
        $this->expectException(ConnectorAuthException::class);
        $connector->handleOAuthCallback($i->id, Request::create('/', 'POST', ['state' => $query['state'], 'api_key' => 'secret-key']));
    }

    public function test_failed_reconfiguration_keeps_the_old_secret(): void
    {
        $i = $this->authorized();
        Http::fake(['*' => Http::response([], 401)]);
        $c = app(FreshdeskConnector::class);
        parse_str(parse_url($c->initiateOAuth($i->id), PHP_URL_QUERY), $query);
        try {
            $c->handleOAuthCallback($i->id, Request::create('/', 'POST', ['state' => $query['state'], 'api_key' => 'bad-key']));
            $this->fail();
        } catch (ConnectorAuthException $e) {
            $this->assertSame(401, $e->getPrevious()->status);
        }
        $this->assertSame('secret-key', app(OAuthCredentialVault::class)->getAccessToken($i->id));
    }

    public function test_window_notes_nested_articles_and_idempotency(): void
    {
        $this->sourceFixture();
        $i = $this->authorized();
        $run = app(SyncManager::class)->start($i);
        $this->assertSame(90, $run->checkpoint['window_days']);
        $this->assertNull($i->fresh()->last_sync_at);
        $this->finish($run);
        $this->assertCount(2, $this->ingestion->documents);
        $body = Storage::disk('local')->get($this->ingestion->documents[0]['relativePath']);
        $this->assertStringContainsString('Private note', $body);
        $this->assertStringContainsString('[redacted]', $body);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertNotNull($i->fresh()->last_sync_at);
        $this->finish(app(SyncManager::class)->start($i));
        $this->assertCount(2, $this->ingestion->documents);
    }

    public function test_private_notes_can_be_excluded_from_ingestion_and_tools(): void
    {
        $this->sourceFixture();
        $i = $this->authorized(['include_private_notes' => false]);
        $this->finish(app(SyncManager::class)->start($i));
        $body = Storage::disk('local')->get($this->ingestion->documents[0]['relativePath']);
        $this->assertStringNotContainsString('Private note', $body);
        $result = app(FreshdeskTools::class)->execute('freshdesk_'.$i->id.'_list_conversations', ['id' => 1], 'support');
        $this->assertCount(1, $result['data']['records']);
    }

    public function test_download_has_no_api_key_and_no_signed_url_is_persisted(): void
    {
        $this->sourceFixture(true);
        $i = $this->authorized();
        $this->finish(app(SyncManager::class)->start($i));
        $this->assertCount(3, $this->ingestion->documents);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'cdn.freshdesk.com') && ! $r->hasHeader('Authorization'));
        $this->assertStringNotContainsString('signature', json_encode($this->ingestion->documents));
        $this->assertSame('hello world', Storage::disk('local')->get($this->ingestion->documents[1]['relativePath']));
    }

    public function test_fetch_all_is_idempotent_and_preserves_window(): void
    {
        $i = $this->authorized(['date_window_days' => 45]);
        $manager = app(SyncManager::class);
        $one = $manager->start($i, true);
        $two = $manager->start($i, true);
        $this->assertSame($one->id, $two->id);
        $this->assertSame('history', $one->mode);
        $this->assertSame(45, $i->fresh()->config_json['date_window_days']);
        $this->assertSame('1970-01-01T00:00:00Z', $one->checkpoint['since']);
    }

    public function test_checkpoint_resumes_after_failure_without_early_watermark(): void
    {
        $this->sourceFixture();
        $i = $this->authorized();
        $run = app(SyncManager::class)->start($i);
        Http::swap(new Factory);
        Http::fake(['*' => Http::response([], 503)]);
        try {
            app(SyncManager::class)->batch($run->id);
            $this->fail();
        } catch (FreshdeskApiException) {
        }
        $this->assertNull($i->fresh()->last_sync_at);
        $this->assertSame('failed', $run->fresh()->status);
        $this->sourceFixture();
        $resumed = app(SyncManager::class)->start($i);
        $this->assertSame($run->id, $resumed->id);
        $this->finish($resumed);
        $this->assertCount(2, $this->ingestion->documents);
    }

    public function test_rate_limit_obeys_retry_after_and_preserves_checkpoint(): void
    {
        $i = $this->authorized();
        $run = app(SyncManager::class)->start($i);
        Http::fake(['*' => Http::response([], 429, ['Retry-After' => '34'])]);
        $this->assertSame(34, app(SyncManager::class)->batch($run->id));
        $this->assertSame('queued', $run->fresh()->status);
        $this->assertSame(1, $run->fresh()->checkpoint['page']);
        Http::assertSentCount(1);
    }

    public function test_parallel_batch_is_deferred_by_installation_lock(): void
    {
        $i = $this->authorized();
        $run = app(SyncManager::class)->start($i);
        $lock = Cache::lock('freshdesk-sync:tenant-a:'.$i->id, 660);
        $lock->get();
        $this->assertSame(30, app(SyncManager::class)->batch($run->id));
        $lock->release();
    }

    public function test_worker_timeout_keeps_checkpoint_and_can_be_resumed_without_secret_leaks(): void
    {
        $installation = $this->authorized();
        $manager = app(SyncManager::class);
        $run = $manager->start($installation, true);
        $checkpoint = $run->checkpoint;
        (new ProcessSyncBatch($run->id, 'tenant-a'))->failed(new \RuntimeException('https://cdn.freshdesk.com/?signature=secret-key'));
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame($checkpoint, $run->fresh()->checkpoint);
        $this->assertStringNotContainsString('secret-key', $run->fresh()->error);
        $this->assertSame('errored', $installation->fresh()->status);
        $this->assertSame($run->id, $manager->start($installation, true)->id);
        $this->sourceFixture();
        $this->finish($run);
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_pausing_installation_during_batch_cancels_continuation(): void
    {
        $installation = $this->authorized();
        $run = app(SyncManager::class)->start($installation);
        Http::fake(function () use ($installation) {
            $installation->update(['status' => 'paused']);

            return Http::response([]);
        });
        $this->assertNull(app(SyncManager::class)->batch($run->id));
        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertSame('paused', $installation->fresh()->status);
        $this->assertNull($installation->fresh()->last_sync_at);
        Http::assertSentCount(1);
    }

    public function test_tool_catalog_is_scoped_and_names_are_distinct(): void
    {
        $a = $this->authorized();
        $b = $this->authorized();
        $other = $this->installation([], 'tenant-b');
        $tools = app(FreshdeskTools::class);
        $this->assertCount(12, $tools->catalog('support'));
        $this->assertSame([], $tools->catalog('other'));
        $names = array_column($tools->catalog('support'), 'name');
        $this->assertCount(12, array_unique($names));
        $this->expectException(\RuntimeException::class);
        $tools->execute('freshdesk_'.$other->id.'_get_ticket', ['id' => 1], 'support');
    }

    public function test_tool_revocation_is_rechecked_and_unknown_arguments_rejected(): void
    {
        $i = $this->authorized();
        $tools = app(FreshdeskTools::class);
        try {
            $tools->execute('freshdesk_'.$i->id.'_get_ticket', ['id' => 1, 'url' => 'https://evil.example'], 'support');
            $this->fail();
        } catch (\InvalidArgumentException) {
        }
        $i->forceFill(['status' => 'disabled'])->save();
        $this->expectException(\RuntimeException::class);
        $tools->execute('freshdesk_'.$i->id.'_get_ticket', ['id' => 1], 'support');
    }

    public function test_http_retry_counts_and_budget_interruptions(): void
    {
        Http::fake(['*' => Http::sequence()->push([], 503)->push(['id' => 1])]);
        $count = 0;
        $client = new FreshdeskClient('example.freshdesk.com', 'secret', function () use (&$count) {
            $count++;
        });
        $this->assertSame(['id' => 1], $client->get('/agents/me'));
        $this->assertSame(2, $count);
        $this->expectException(\DomainException::class);
        (new FreshdeskClient('example.freshdesk.com', 'secret', fn () => throw new \DomainException('budget')))->get('/agents/me');
    }

    public function test_tenant_mismatch_is_rejected_by_manager_and_vault(): void
    {
        $i = $this->authorized();
        app(TenantContext::class)->set('tenant-b');
        $this->expectException(\RuntimeException::class);
        app(SyncManager::class)->client($i);
    }

    public function test_attachment_redirect_and_size_are_guarded(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/private'])]);
        try {
            (new FreshdeskClient('example.freshdesk.com', 'secret'))->download('https://cdn.freshdesk.com/file', 5);
            $this->fail();
        } catch (\RuntimeException) {
        }
        Http::swap(new Factory);
        Http::fake(['*' => Http::response('123456')]);
        $this->expectException(\RuntimeException::class);
        (new FreshdeskClient('example.freshdesk.com', 'secret'))->download('https://cdn.freshdesk.com/file', 5);
    }

    public function test_custom_domains_and_attachment_hosts_are_rejected(): void
    {
        try {
            UrlPolicy::domain('https://example.freshdesk.com@evil.example');
            $this->fail();
        } catch (\InvalidArgumentException) {
        }
        $this->expectException(\InvalidArgumentException::class);
        UrlPolicy::options('https://evil.example/file', true);
    }

    public function test_deletion_is_installation_scoped_and_out_of_window_documents_are_kept(): void
    {
        $this->sourceFixture();
        $i = $this->authorized();
        $this->finish(app(SyncManager::class)->start($i));
        $this->assertCount(2, $this->ingestion->documents);
        Http::swap(new Factory);
        Http::fake(fn ($r) => Http::response(str_contains($r->url(), '/solutions/articles/5') ? ['id' => 5, 'status' => 2, 'title' => 'Guide'] : (str_contains($r->url(), '/tickets?') && ($r['filter'] ?? null) === 'deleted' ? [['id' => 1, 'updated_at' => now()->subMinute()->toIso8601ZuluString()]] : [])));
        $this->finish(app(SyncManager::class)->start($i));
        $this->assertSame([['tenant-a', $i->id.':ticket:1']], $this->ingestion->deleted);
        $this->assertSame(1, SourceState::query()->where('kind', 'article')->count());
    }

    public function test_pagination_rollover_and_non_advancing_boundary(): void
    {
        $i = $this->authorized();
        $run = app(SyncManager::class)->start($i, true);
        $cp = $run->checkpoint;
        $cp['page'] = 300;
        $cp['since'] = '2020-01-01T00:00:00Z';
        $run->checkpoint = $cp;
        $run->save();
        config(['connector-freshdesk.sync.batch_items' => 1]);
        Http::fake(['*' => Http::response(array_fill(0, 100, ['id' => 1, 'updated_at' => '2021-01-01T00:00:00Z']))]);
        app(SyncManager::class)->batch($run->id);
        $this->assertSame(1, $run->fresh()->checkpoint['page']);
        $cp = $run->fresh()->checkpoint;
        $cp['pending'] = [];
        $cp['page'] = 300;
        $cp['since'] = '2021-01-01T00:00:00Z';
        $run->checkpoint = $cp;
        $run->save();
        $this->expectException(\RuntimeException::class);
        app(SyncManager::class)->batch($run->id);
    }

    public function test_private_note_setting_refreshes_previously_imported_history(): void
    {
        $this->sourceFixture();
        $i = $this->authorized();
        $this->finish(app(SyncManager::class)->start($i, true));
        $i->update(['config_json' => ['connection' => ['domain' => 'example.freshdesk.com'], 'include_private_notes' => false]]);
        Http::swap(new Factory);
        Http::fake(fn ($r) => Http::response(match (parse_url($r->url(), PHP_URL_PATH)) {
            '/api/v2/tickets/1' => ['id' => 1, 'subject' => 'Old ticket', 'description_text' => 'Historical issue'],
            '/api/v2/tickets/1/conversations' => [['id' => 11, 'private' => false, 'body_text' => 'Public reply'], ['id' => 12, 'private' => true, 'body_text' => 'Private note']],
            '/api/v2/solutions/articles/5' => ['id' => 5, 'status' => 2],
            default => [],
        }));
        $this->finish(app(SyncManager::class)->start($i));
        $path = SourceState::where('kind', 'ticket')->firstOrFail()->relative_path;
        $this->assertStringNotContainsString('Private note', Storage::disk('local')->get($path));
        $this->assertStringContainsString('Public reply', Storage::disk('local')->get($path));
    }

    public function test_confirmed_article_deletion_and_drafts_are_removed(): void
    {
        $this->sourceFixture();
        $i = $this->authorized();
        $this->finish(app(SyncManager::class)->start($i));
        Http::swap(new Factory);
        Http::fake(fn ($r) => Http::response([], str_contains($r->url(), '/solutions/articles/5') ? 404 : 200));
        $this->finish(app(SyncManager::class)->start($i));
        $this->assertSame([['tenant-a', $i->id.':article:5']], $this->ingestion->deleted);
        $this->assertSame(1, SourceState::count());
        Http::swap(new Factory);
        Http::fake(fn () => Http::response(['id' => 5, 'title' => 'Draft', 'status' => 1]));
        $result = app(FreshdeskTools::class)->execute('freshdesk_'.$i->id.'_get_article', ['id' => 5], 'support');
        $this->assertSame([], $result['data']['records']);
    }

    public function test_attachment_count_filename_and_mime_validation(): void
    {
        $i = $this->authorized(['attachments' => ['max_per_ticket' => 2]]);
        $run = app(SyncManager::class)->start($i);
        Http::fake(fn ($r) => Http::response(str_contains($r->url(), '/conversations') ? [] : 'plain text'));
        $attachments = array_map(fn ($id) => ['id' => $id, 'name' => '../../unsafe.txt', 'content_type' => 'text/plain', 'attachment_url' => 'https://cdn.freshdesk.com/file/'.$id], [1, 2, 3]);
        app(DocumentImporter::class)->ticket($i, $run, app(SyncManager::class)->client($i), ['id' => 1, 'attachments' => $attachments]);
        $this->assertSame(2, SourceState::where('kind', 'attachment')->count());
        $this->assertSame(1, $run->counts['attachments_skipped']);
        $this->assertSame('unsafe.txt', $this->ingestion->documents[1]['title']);
        Http::swap(new Factory);
        Http::fake(fn ($r) => Http::response(str_contains($r->url(), '/conversations') ? [] : '<html>fake PDF</html>'));
        $this->expectException(\RuntimeException::class);
        app(DocumentImporter::class)->ticket($i, $run, app(SyncManager::class)->client($i), ['id' => 2, 'attachments' => [['id' => 4, 'name' => 'fake.pdf', 'content_type' => 'application/pdf', 'attachment_url' => 'https://cdn.freshdesk.com/fake']]]);
    }

    public function test_images_require_host_ocr_and_actual_bytes(): void
    {
        $i = $this->authorized();
        $run = app(SyncManager::class)->start($i);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQAAAABJRU5ErkJggg==');
        Http::fake(fn ($r) => Http::response(str_contains($r->url(), '/conversations') ? [] : $png));
        $ticket = ['id' => 1, 'attachments' => [['id' => 9, 'name' => 'diagram.png', 'content_type' => 'image/png', 'attachment_url' => 'https://cdn.freshdesk.com/image']]];
        $importer = app(DocumentImporter::class);
        $importer->ticket($i, $run, app(SyncManager::class)->client($i), $ticket);
        $this->assertSame(0, SourceState::where('kind', 'attachment')->count());
        config()->set('kb.ocr.enabled', true);
        $importer->ticket($i, $run, app(SyncManager::class)->client($i), $ticket);
        $this->assertSame(1, SourceState::where('kind', 'attachment')->count());
    }

    public function test_docx_container_requires_word_content(): void
    {
        $i = $this->authorized();
        $run = app(SyncManager::class)->start($i);
        $path = tempnam(sys_get_temp_dir(), 'freshdesk-test-');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('unrelated.txt', 'hello');
        $zip->close();
        $bytes = file_get_contents($path);
        unlink($path);
        Http::fake(fn ($r) => Http::response(str_contains($r->url(), '/conversations') ? [] : $bytes));
        $this->expectException(\RuntimeException::class);
        app(DocumentImporter::class)->ticket($i, $run, app(SyncManager::class)->client($i), ['id' => 1, 'attachments' => [['id' => 1, 'name' => 'fake.docx', 'attachment_url' => 'https://cdn.freshdesk.com/doc']]]);
    }

    public function test_pdf_docx_and_markdown_are_delivered_to_the_ingestion_pipeline(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'freshdesk-test-');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>');
        $zip->close();
        $docx = file_get_contents($path);
        unlink($path);
        $files = ['report.pdf' => "%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF", 'report.docx' => $docx, 'notes.markdown' => "# Useful note\n"];
        $mimes = ['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'text/plain'];
        Http::fake(fn ($request) => Http::response(str_contains($request->url(), '/conversations') ? [] : $files[basename(parse_url($request->url(), PHP_URL_PATH))]));
        $installation = $this->authorized();
        $run = app(SyncManager::class)->start($installation);
        $attachments = [];
        foreach (array_keys($files) as $index => $name) {
            $attachments[] = ['id' => $index + 1, 'name' => $name, 'content_type' => $mimes[$index], 'attachment_url' => 'https://cdn.freshdesk.com/'.$name];
        }
        app(DocumentImporter::class)->ticket($installation, $run, app(SyncManager::class)->client($installation), ['id' => 1, 'attachments' => $attachments]);
        $this->assertSame(['text/markdown', 'application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'text/markdown'], array_column($this->ingestion->documents, 'mimeType'));
        $this->assertSame(3, SourceState::where('kind', 'attachment')->count());
    }

    public function test_live_search_filters_published_articles_and_caps_response(): void
    {
        $i = $this->authorized();
        Http::fake(fn () => Http::response([['id' => 1, 'status' => 1, 'title' => 'Draft'], ['id' => 2, 'status' => 2, 'title' => 'Public', 'description_text' => 'Safe answer']]));
        $tools = app(FreshdeskTools::class);
        $result = $tools->execute('freshdesk_'.$i->id.'_search_articles', ['term' => 'answer'], 'support');
        $this->assertSame([2], array_column($result['data']['records'], 'id'));
        config()->set('connector-freshdesk.chat_tools.max_result_bytes', 60);
        $result = $tools->execute('freshdesk_'.$i->id.'_search_articles', ['term' => 'answer'], 'support');
        $this->assertTrue($result['data']['truncated']);
        $this->assertSame([], $result['data']['records']);
    }

    public function test_forbidden_response_is_sanitized_and_not_retried(): void
    {
        Http::fake(fn () => Http::response(['message' => 'secret-key and signed URL'], 403));
        $client = new FreshdeskClient('example.freshdesk.com', 'secret-key');
        try {
            $client->get('/agents/me');
            $this->fail();
        } catch (FreshdeskApiException $exception) {
            $this->assertSame(403, $exception->status);
            $this->assertStringNotContainsString('secret-key', $exception->getMessage());
            $this->assertSame(1, $client->physicalRequests);
        }
    }
}
