<?php

return [
    'arco' => env('FEATURE_ARCO_ENABLED', true),
    'arco_types' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ARCO_ENABLED_TYPES', 'access')),
    ))),
];
