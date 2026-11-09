<?php

return [

    'connect_timeout' => (int) env('WOOCOMMERCE_CONNECT_TIMEOUT', 5),
    'timeout' => (int) env('WOOCOMMERCE_TIMEOUT', 20),

    // Applies to read requests only. A write is never repeated automatically.
    'read_retries' => (int) env('WOOCOMMERCE_READ_RETRIES', 1),

    'requests_per_minute' => (int) env('WOOCOMMERCE_REQUESTS_PER_MINUTE', 60),
    'max_response_bytes' => (int) env('WOOCOMMERCE_MAX_RESPONSE_BYTES', 20971520),

    'per_page' => (int) env('WOOCOMMERCE_PER_PAGE', 50),

    // Work up to these sizes happens during the request. Larger jobs use the queue.
    'import_inline_pages' => (int) env('WOOCOMMERCE_IMPORT_INLINE_PAGES', 2),
    'import_inline_rows' => (int) env('WOOCOMMERCE_IMPORT_INLINE_ROWS', 100),
    'sync_inline_items' => (int) env('WOOCOMMERCE_SYNC_INLINE_ITEMS', 10),

    'sync_max_products' => (int) env('WOOCOMMERCE_SYNC_MAX_PRODUCTS', 50),

];
