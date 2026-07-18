<?php

declare(strict_types=1);

return [
    'timeout' => (int) env('OAST_TIMEOUT', 120),

    // The api.* subdomain the REST API is served on.
    'api_domain' => env('OAST_API_DOMAIN', 'api.oast.test'),

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

    'bootstrap_secret' => env('OAST_BOOTSTRAP_SECRET'),
    'enforce_email_verification' => (bool) env('OAST_ENFORCE_EMAIL_VERIFICATION', false),
    'invitation_ttl_hours' => (int) env('OAST_INVITATION_TTL_HOURS', 72),
    'max_active_reviews' => (int) env('OAST_MAX_ACTIVE_REVIEWS', 10),
    // Matches the 5 MB web upload cap (spec_file max:5120).
    'max_spec_bytes' => (int) env('OAST_MAX_SPEC_BYTES', 5 * 1024 * 1024),
    'max_concurrent_streams' => (int) env('OAST_MAX_CONCURRENT_STREAMS', 5),
];
