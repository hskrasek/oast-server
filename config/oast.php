<?php

declare(strict_types=1);

return [
    'timeout' => (int) env('OAST_TIMEOUT', 120),

    // The api.* subdomain the REST API is served on.
    'api_domain' => env('OAST_API_DOMAIN', 'api.oast.test'),

    // Gate the entire api surface behind a feature flag.
    'api_enabled' => (bool) env('OAST_API_ENABLED', true),

    // Panelist model slugs (OpenRouter). Hardcoded for M0; config-driven roster in M1.
    // Confirm exact OpenRouter slugs before the first live run.
    'panelists' => [
        '~anthropic/claude-sonnet-latest',
        'openai/gpt-5.5',
        // GLM 5.2 over Gemini: flash-tier critiques ran shallow (9k chars in 15s),
        // and the only frontier Gemini slug is a -preview; GLM adds real lineage diversity.
        'z-ai/glm-5.2',
    ],

    // Dedicated strong judge — never a panelist.
    'judge' => 'anthropic/claude-opus-4.8',

    // Baseline single model; null => first panelist.
    'baseline' => null,

    'quorum' => 2,

    // Seconds to wait for a straggling panelist after quorum before the judge starts.
    'quorum_grace' => (int) env('OAST_QUORUM_GRACE', 60),
];
