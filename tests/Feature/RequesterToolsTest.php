<?php

namespace Padosoft\AskMyDocsConnectorFreshdesk\Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorFreshdesk\Tests\TestCase;
use Padosoft\AskMyDocsConnectorFreshdesk\Tools\FreshdeskArguments;
use Padosoft\AskMyDocsConnectorFreshdesk\Tools\FreshdeskTools;

final class RequesterToolsTest extends TestCase
{
    private function executeTool(string $action, array $arguments): array
    {
        $installation = $this->installation();
        app(OAuthCredentialVault::class)->setCredentials($installation->id, accessToken: 'secret-key');

        return app(FreshdeskTools::class)->execute('freshdesk_'.$installation->id.'_'.$action, $arguments, 'support');
    }

    public function test_name_lookup_has_real_identity_and_requires_selection_for_partial_or_duplicate_names(): void
    {
        Http::fake(['*/contacts/autocomplete*' => fn () => Http::response([['id' => 42, 'name' => 'Anne Richard']])]);
        $result = $this->executeTool('search_contacts', ['term' => 'anne richard']);
        $this->assertSame(42, $result['data']['records'][0]['id']);
        $this->assertSame('Anne Richard', $result['data']['records'][0]['name']);
        $this->assertFalse($result['data']['meta']['ambiguous']);
        $partial = $this->executeTool('search_contacts', ['term' => 'Anne']);
        $this->assertArrayHasKey('data', $partial, json_encode($partial));
        $this->assertTrue($partial['data']['meta']['ambiguous']);
        Http::swap(new Factory);
        Http::fake(['*/contacts/autocomplete*' => fn () => Http::response([['id' => 42, 'name' => 'Anne Richard'], ['id' => 43, 'name' => 'Anne Richard']])]);
        $this->assertTrue($this->executeTool('search_contacts', ['term' => 'Anne Richard'])['data']['meta']['ambiguous']);
    }

    public function test_contact_detail_and_agent_lookup_preserve_names_and_email(): void
    {
        Http::fake(['*/contacts/42' => fn () => Http::response(['id' => 42, 'name' => 'Anne Richard', 'email' => 'anne@example.test']),
            '*/agents/autocomplete*' => fn () => Http::response([['id' => 7, 'name' => 'Anne Richard', 'email' => 'agent@example.test']])]);
        $this->assertSame('anne@example.test', $this->executeTool('get_contact', ['id' => 42])['data']['records'][0]['email']);
        $this->assertFalse($this->executeTool('search_agents', ['term' => 'agent@example.test'])['data']['meta']['ambiguous']);
    }

    public function test_requester_lookup_includes_old_closed_tickets_and_wires_the_filter(): void
    {
        Http::fake(['*/tickets*' => fn () => Http::response([['id' => 9, 'requester_id' => 42, 'responder_id' => 7, 'status' => 5, 'created_at' => '2020-01-01']])]);
        $result = $this->executeTool('list_tickets', ['requester_id' => 42]);
        Http::assertSent(fn ($request) => $request['requester_id'] === 42 && $request['updated_since'] === '1970-01-01T00:00:00Z');
        $this->assertSame(42, $result['data']['records'][0]['requester_id']);
        $this->assertSame(5, $result['data']['records'][0]['status']);
        $this->assertTrue($result['data']['coverage']['complete']);
        $this->assertSame(1, $result['physical_request_count']);
    }

    public function test_email_lookup_and_generic_window_are_distinct_and_mutually_exclusive(): void
    {
        Http::fake(['*' => fn () => Http::response([])]);
        $email = $this->executeTool('list_tickets', ['email' => 'anne@example.test']);
        $this->assertSame('1970-01-01T00:00:00Z', $email['data']['coverage']['updated_since']);
        $generic = $this->executeTool('list_tickets', []);
        $this->assertArrayHasKey('data', $generic, json_encode($generic));
        $this->assertSame(now()->subDays(90)->toIso8601ZuluString(), $generic['data']['coverage']['updated_since']);
        $invalid = $this->executeTool('list_tickets', ['requester_id' => 42, 'email' => 'anne@example.test']);
        $this->assertSame('invalid_query', $invalid['error_code']);
        $this->assertSame(0, $invalid['physical_request_count']);
        Http::assertSentCount(2);
    }

    public function test_pagination_and_size_limits_never_claim_complete_coverage(): void
    {
        Http::fake(['*' => fn () => Http::response([['id' => 9, 'requester_id' => 42]])]);
        $result = $this->executeTool('list_tickets', ['requester_id' => 42, 'per_page' => 1]);
        $this->assertTrue($result['data']['has_more']);
        $this->assertFalse($result['data']['coverage']['complete']);
        config(['connector-freshdesk.chat_tools.max_result_bytes' => 60]);
        $limited = $this->executeTool('list_tickets', ['requester_id' => 42]);
        $this->assertArrayHasKey('data', $limited, json_encode($limited));
        $this->assertTrue($limited['data']['truncated']);
        $this->assertFalse($limited['data']['coverage']['complete']);
    }

    public function test_api_page_limits_body_truncation_and_transient_retries_are_visible(): void
    {
        Http::fake(['*' => fn () => Http::response(['total' => 301, 'results' => [['id' => 9, 'status' => 5]]])]);
        $last = $this->executeTool('search_tickets', ['query' => 'agent_id:7', 'page' => 10]);
        $this->assertTrue($last['data']['has_more']);
        $this->assertContains('pagination_limit', $last['data']['coverage']['limitations']);
        $invalid = $this->executeTool('search_tickets', ['query' => 'agent_id:7', 'page' => 11]);
        $this->assertSame(0, $invalid['physical_request_count']);
        Http::assertSentCount(1);
        Http::swap(new Factory);
        Http::fake(['*' => fn () => Http::response([['id' => 9, 'description_text' => str_repeat('x', 17000)]])]);
        $large = $this->executeTool('list_tickets', ['requester_id' => 42]);
        $this->assertTrue($large['data']['truncated']);
        $this->assertFalse($large['data']['coverage']['complete']);
        Http::swap(new Factory);
        Http::fake(['*' => fn () => Http::response([], 503)]);
        $failed = $this->executeTool('list_tickets', ['requester_id' => 42]);
        $this->assertTrue($failed['retryable']);
        $this->assertSame(503, $failed['http_status']);
        $this->assertSame(3, $failed['physical_request_count']);
        Http::assertSentCount(3);
    }

    public function test_invalid_trace_queries_are_rejected_before_http_and_valid_filters_keep_their_grammar(): void
    {
        Http::fake();
        foreach (['requester:Anne Richard', '"Anne Richard"', 'status:2 garbage', '(status:2', 'status:2 AND', "subject:'Anne Richard'"] as $query) {
            $result = $this->executeTool('search_tickets', ['query' => $query]);
            $this->assertSame('invalid_query', $result['error_code'], $query);
        }
        Http::assertNothingSent();
        foreach (['agent_id:7', '(status:2 OR status:5) AND priority:>2', "created_at:>'2020-01-01'", "cf_reason:'Delivery delay'"] as $query) {
            $this->assertTrue(FreshdeskArguments::validQuery($query), $query);
        }
        $this->assertSame(FreshdeskArguments::normalize('search_tickets', ['query' => '"agent_id:7"']),
            FreshdeskArguments::normalize('search_tickets', ['query' => 'agent_id:7', 'page' => 1]));
    }

    public function test_http_failures_are_structured_sanitized_and_not_retried_for_bad_requests(): void
    {
        foreach ([400 => 'invalid_query', 401 => 'authentication_failed', 403 => 'permission_denied', 429 => 'rate_limited'] as $status => $code) {
            Http::swap(new Factory);
            Http::fake(['*' => fn () => Http::response(['message' => 'secret-key', 'errors' => [['field' => 'query', 'code' => 'invalid_value', 'message' => 'secret-key']]], $status)]);
            $result = $this->executeTool('search_tickets', ['query' => 'status:2']);
            $this->assertSame($code, $result['error_code']);
            $this->assertSame($status, $result['http_status']);
            $this->assertSame($status === 429, $result['retryable']);
            $this->assertSame(1, $result['physical_request_count']);
            $this->assertStringNotContainsString('secret-key', json_encode($result));
        }
    }
}
