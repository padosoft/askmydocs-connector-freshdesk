<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorFreshdesk\CaseStudies;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $tenant_id
 * @property int $installation_id
 * @property string $ticket_id
 * @property string $source_fingerprint
 * @property string $status
 * @property bool $private_notes_included
 * @property array<string,mixed> $snapshot
 * @property array<string,mixed> $checkpoint
 * @property array<string,mixed> $result
 */
final class CaseStudy extends Model
{
    protected $table = 'freshdesk_case_studies';

    protected $guarded = [];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['snapshot' => 'array', 'checkpoint' => 'array', 'result' => 'array', 'private_notes_included' => 'boolean'];
    }
}
