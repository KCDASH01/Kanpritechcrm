<?php

return [
    'max_kb' => (int) env('LEAD_IMPORT_MAX_KB', 10240),
    'max_rows' => (int) env('LEAD_IMPORT_MAX_ROWS', 10000),
    'batch_size' => (int) env('LEAD_IMPORT_BATCH_SIZE', 500),
    'max_columns' => 80,
    'max_unpacked_bytes' => 64 * 1024 * 1024,
    'queue_connection' => env('LEAD_IMPORT_QUEUE_CONNECTION', 'lead-imports'),
    'retention_days' => (int) env('LEAD_IMPORT_RETENTION_DAYS', 7),
];
