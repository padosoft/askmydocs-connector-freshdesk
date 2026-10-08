<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorFreshdesk\Sync;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $kind
 * @property string $remote_id
 * @property ?string $parent_id
 * @property string $fingerprint
 * @property string $relative_path
 */
final class SourceState extends Model
{
    protected $table = 'freshdesk_source_states';

    protected $guarded = [];
}
