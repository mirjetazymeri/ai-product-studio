<?php

return [

    // Largest accepted upload, in kilobytes, and the most data rows read from one file.
    'max_file_kb' => (int) env('CATALOG_MAX_FILE_KB', 10240),
    'max_rows' => (int) env('CATALOG_MAX_ROWS', 10000),

    // Up to this many products are processed during the request. Larger jobs use the queue.
    'import_sync_limit' => (int) env('CATALOG_IMPORT_SYNC_LIMIT', 200),
    'export_sync_limit' => (int) env('CATALOG_EXPORT_SYNC_LIMIT', 1000),
    'chunk_size' => (int) env('CATALOG_CHUNK_SIZE', 100),

    // Uploaded files, generated exports and staged rows are removed after this many days.
    'retention_days' => (int) env('CATALOG_RETENTION_DAYS', 7),

    'bulk_generation_max_products' => (int) env('AI_BULK_MAX_PRODUCTS', 200),

];
