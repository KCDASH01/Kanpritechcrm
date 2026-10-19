<?php

return [
    'pdf' => [
        'node_binary' => env('BILLING_PDF_NODE_BINARY', 'node'),
        'renderer' => env('BILLING_PDF_RENDERER', base_path('../billing-pdf-renderer/dist/render.mjs')),
        'timeout_seconds' => (int) env('BILLING_PDF_TIMEOUT', 45),
        'disk' => env('BILLING_PDF_DISK', 'local'),
    ],
];
