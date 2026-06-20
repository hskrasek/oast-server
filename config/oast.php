<?php

return [
    'timeout' => (int) env('OAST_TIMEOUT', 120),

    // The api.* subdomain the REST API is served on.
    'api_domain' => env('OAST_API_DOMAIN', 'api.oast.test'),

    // Panelist model slugs (OpenRouter). Hardcoded for M0; config-driven roster in M1.
    // Confirm exact OpenRouter slugs before the first live run.
    'panelists' => [
        '~anthropic/claude-sonnet-latest',
        'openai/gpt-5.5',
        'google/gemini-3.5-flash',
    ],

    // Dedicated strong judge — never a panelist.
    'judge' => 'anthropic/claude-opus-4.8',

    // Baseline single model; null => first panelist.
    'baseline' => null,

    'quorum' => 2,
];
