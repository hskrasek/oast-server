<?php

declare(strict_types=1);

return [
    // The self-host image sets this to /var/lib/oast/publications.
    'publications_path' => env('SITE_PUBLICATIONS_PATH', base_path('database/publications')),
];
