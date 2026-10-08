<?php

declare(strict_types=1);

return [
    'http' => ['timeout' => 15, 'connect_timeout' => 5, 'max_json_bytes' => 2_000_000, 'resolve_dns' => true, 'attempts' => 3],
    'attachments' => ['allowed_hosts' => ['*.freshdesk.com', '*.freshworks.com', 's3.amazonaws.com', '*.s3.amazonaws.com', '*.s3.*.amazonaws.com']],
    'sync' => ['batch_items' => 5, 'batch_seconds' => 60, 'queue' => env('CONNECTOR_FRESHDESK_QUEUE', 'freshdesk'), 'connection' => env('CONNECTOR_FRESHDESK_CONNECTION'), 'overlap_seconds' => 120, 'middleware' => []],
    'chat_tools' => ['enabled' => true, 'max_result_bytes' => 65_536],
];
