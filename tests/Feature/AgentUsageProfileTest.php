<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorFreshdesk\Tests\Feature;

use Padosoft\AskMyDocsConnectorFreshdesk\Tests\TestCase;
use Padosoft\AskMyDocsConnectorFreshdesk\Tools\FreshdeskTools;

final class AgentUsageProfileTest extends TestCase
{
    public function test_published_contract_covers_the_live_catalog_and_declares_retrieval_strategies(): void
    {
        $root = dirname(__DIR__, 2);
        $manifest = json_decode(file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $profile = json_decode(file_get_contents($root.'/'.$manifest['extra']['askmydocs']['agent_usage']['freshdesk']), true, flags: JSON_THROW_ON_ERROR);
        $this->installation();
        $actions = array_column(app(FreshdeskTools::class)->catalog('support'), 'action');
        $declared = $profile['tools'];
        sort($actions);
        sort($declared);
        $this->assertSame($actions, $declared);
        $this->assertSame(1, $profile['schema_version']);
        $routes = array_column($profile['routing'], null, 'id');
        $this->assertSame('live', $routes['current_ticket']['mode']);
        $this->assertSame('live', $routes['requester_tickets']['mode']);
        $this->assertSame('indexed', $routes['documented_solutions']['mode']);
        $this->assertSame('combined', $routes['ticket_with_procedure']['mode']);
        $rules = array_column($profile['rules'], 'instruction', 'id');
        $this->assertStringContainsString('not assigned to an agent', $rules['requester_semantics']);
        $this->assertStringContainsString('meta.ambiguous=true', $rules['verified_identity']);
        $this->assertStringContainsString('including closed tickets', $rules['requester_history']);
        $this->assertStringContainsString('has_more=true', $rules['pagination']);
        $this->assertStringContainsString('same account live', $rules['freshness']);
    }
}
