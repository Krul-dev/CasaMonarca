<?php

return [
    'arco' => env('FEATURE_ARCO_ENABLED', true),
    'arco_types' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ARCO_ENABLED_TYPES', 'access')),
    ))),
    'migrant_documents' => env('FEATURE_MIGRANT_DOCUMENTS_ENABLED', true),
    'migrant_documents_max_per_entry' => max(1, (int) env('MIGRANT_DOCUMENTS_MAX_PER_ENTRY', 10)),
];
