# oast.sh Pre-Launch Site (Laravel) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The public pre-launch site inside oast-server: publications rendered from committed JSON, an SES-backed newsletter with signed double-opt-in, API gating for prod, and the four launch reviews published.

**Architecture:** A `PublicationRepository` reads `database/publications/*.json` (content-as-code; no DB). Blade + Tailwind 4 pages render it. `POST /subscribe` writes to an SES v2 contact list through a `NewsletterContacts` interface (real SDK client in prod, fake in tests) and sends a signed confirm link. The `api.*` routes 404 behind `OAST_API_ENABLED`.

**Tech Stack:** PHP 8.5, Laravel 13, Pest 4, Tailwind 4 via Vite Plus (`vp`), `aws/aws-sdk-php` (new dep).

## Global Constraints

- 100% line AND type coverage (`composer test`); PHPStan level max; Pint PER; Rector. Post-edit hook enforces per-file.
- `declare(strict_types=1)`; final classes; invokable single-action controllers (ADR house style).
- Prod is stateless: no queue, no DB writes — mail sends synchronously; publications are read-only JSON.
- Site routes live on the ROOT domain (`routes/web.php`), not the `api.*` subdomain.
- Views are structure-first (semantic HTML, light Tailwind); the visual design pass happens later from Claude-design prototypes — do NOT invent heavy styling.
- Severity order everywhere: blocker, should-fix, consider. Confidence order: consensus, majority, split, lone-flag.
- New dependency allowed: `aws/aws-sdk-php` only.

---

### Task 1: Publication DTO + PublicationRepository

**Files:**
- Create: `app/Site/Publication.php`, `app/Site/PublicationRepository.php`
- Create: `tests/fixtures/publications/valid-review.json`, `tests/fixtures/publications/malformed.json`
- Test: `tests/Unit/Site/PublicationRepositoryTest.php`

**Interfaces:**
- Produces: `Publication` readonly DTO: `slug, headline, commentaryMd, specName, specSourceUrl, specLicense, dimension, panelists (list<string>), judge, findings (array), metrics (array), reviewedAt (CarbonImmutable), publishedAt (CarbonImmutable)`; static `fromArray(array $data): self`; helper `findingCounts(): array{blocker: int, should-fix: int, consider: int}`; `totalCostUsd(): ?float` (reads the trailing `total_cost_usd` metrics element).
- Produces: `PublicationRepository::__construct(?string $path = null)` (default `base_path('database/publications')`); `all(): list<Publication>` (sorted `publishedAt` desc, memoized per instance); `find(string $slug): ?Publication`. Malformed JSON → `report()` + skip, never throw.

- [ ] **Step 1: Write fixtures and failing tests**

`tests/fixtures/publications/valid-review.json` (trimmed but structurally complete):

```json
{
  "slug": "train-travel-domain-modeling",
  "headline": "The Council vs. a well-designed spec",
  "commentary_md": "Phil Sturgeon's Train Travel API is deliberately good. The panel still found real gaps.",
  "spec_name": "Train Travel API",
  "spec_source_url": "https://github.com/bump-sh-examples/train-travel-api",
  "spec_license": "CC-BY-SA 4.0",
  "dimension": "domain-modeling",
  "panelists": ["~anthropic/claude-sonnet-latest", "openai/gpt-5.5", "z-ai/glm-5.2"],
  "judge": "anthropic/claude-opus-4.8",
  "findings": [
    {"dimension": "domain-modeling", "title": "Booking lifecycle never modeled as data", "severity": "blocker", "confidence": "consensus", "location": "#/components/schemas/Booking", "finding": "…", "why_it_matters": "…", "suggested_change": "…"},
    {"dimension": "domain-modeling", "title": "Extras reduced to booleans", "severity": "consider", "confidence": "lone-flag", "location": "#/components/schemas/Booking/properties/has_bicycle", "finding": "…", "why_it_matters": "…", "suggested_change": "…"}
  ],
  "metrics": [
    {"model": "openai/gpt-5.5", "ms": 43920, "usage": {"prompt_tokens": 9893}, "cost_usd": 0.191215},
    {"total_cost_usd": 0.62}
  ],
  "reviewed_at": "2026-07-04T21:30:00Z",
  "published_at": "2026-07-05T12:00:00Z"
}
```

`tests/fixtures/publications/malformed.json`: the literal content `{ not json`.

```php
// tests/Unit/Site/PublicationRepositoryTest.php
<?php

declare(strict_types=1);

use App\Site\PublicationRepository;

function fixtureRepo(): PublicationRepository
{
    return new PublicationRepository(base_path('tests/fixtures/publications'));
}

it('loads publications sorted by published_at desc and skips malformed files', function (): void {
    $all = fixtureRepo()->all();

    expect($all)->toHaveCount(1)
        ->and($all[0]->slug)->toBe('train-travel-domain-modeling')
        ->and($all[0]->findingCounts())->toBe(['blocker' => 1, 'should-fix' => 0, 'consider' => 1])
        ->and($all[0]->totalCostUsd())->toBe(0.62);
});

it('finds by slug and returns null for unknown slugs', function (): void {
    expect(fixtureRepo()->find('train-travel-domain-modeling'))->not->toBeNull()
        ->and(fixtureRepo()->find('nope'))->toBeNull();
});

it('memoizes the directory scan per instance', function (): void {
    $repo = fixtureRepo();
    expect($repo->all())->toBe($repo->all()); // same array instance contents; second call must not rescan
});

it('returns an empty list when the directory does not exist', function (): void {
    expect(new PublicationRepository(base_path('nope'))->all())->toBe([]);
});
```

- [ ] **Step 2: Run to verify failure** — `vendor/bin/pest tests/Unit/Site/` → FAIL (class not found).

- [ ] **Step 3: Implement**

```php
// app/Site/Publication.php
<?php

declare(strict_types=1);

namespace App\Site;

use Carbon\CarbonImmutable;

final readonly class Publication
{
    /**
     * @param  list<string>  $panelists
     * @param  array<array-key, mixed>  $findings
     * @param  array<array-key, mixed>  $metrics
     */
    private function __construct(
        public string $slug,
        public string $headline,
        public string $commentaryMd,
        public string $specName,
        public string $specSourceUrl,
        public string $specLicense,
        public string $dimension,
        public array $panelists,
        public string $judge,
        public array $findings,
        public array $metrics,
        public CarbonImmutable $reviewedAt,
        public CarbonImmutable $publishedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            slug: (string) $data['slug'],
            headline: (string) $data['headline'],
            commentaryMd: (string) ($data['commentary_md'] ?? ''),
            specName: (string) $data['spec_name'],
            specSourceUrl: (string) ($data['spec_source_url'] ?? ''),
            specLicense: (string) ($data['spec_license'] ?? ''),
            dimension: (string) $data['dimension'],
            panelists: array_values(array_map(strval(...), (array) $data['panelists'])),
            judge: (string) $data['judge'],
            findings: (array) $data['findings'],
            metrics: (array) $data['metrics'],
            reviewedAt: CarbonImmutable::parse((string) $data['reviewed_at']),
            publishedAt: CarbonImmutable::parse((string) $data['published_at']),
        );
    }

    /**
     * @return array{blocker: int, should-fix: int, consider: int}
     */
    public function findingCounts(): array
    {
        $counts = ['blocker' => 0, 'should-fix' => 0, 'consider' => 0];

        foreach ($this->findings as $finding) {
            $severity = is_array($finding) ? ($finding['severity'] ?? null) : null;

            if (is_string($severity) && array_key_exists($severity, $counts)) {
                $counts[$severity]++;
            }
        }

        return $counts;
    }

    public function totalCostUsd(): ?float
    {
        foreach ($this->metrics as $metric) {
            if (is_array($metric) && is_numeric($metric['total_cost_usd'] ?? null)) {
                return (float) $metric['total_cost_usd'];
            }
        }

        return null;
    }
}
```

```php
// app/Site/PublicationRepository.php
<?php

declare(strict_types=1);

namespace App\Site;

use Throwable;

final class PublicationRepository
{
    /** @var list<Publication>|null */
    private ?array $loaded = null;

    private readonly string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? base_path('database/publications');
    }

    /**
     * @return list<Publication>
     */
    public function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        $publications = [];

        foreach (glob($this->path . '/*.json') ?: [] as $file) {
            try {
                $data = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
                $publications[] = Publication::fromArray(is_array($data) ? $data : []);
            } catch (Throwable $exception) {
                report($exception); // a bad publication must never 500 the site
            }
        }

        usort($publications, fn (Publication $a, Publication $b): int => $b->publishedAt <=> $a->publishedAt);

        return $this->loaded = array_values($publications);
    }

    public function find(string $slug): ?Publication
    {
        return array_find($this->all(), fn (Publication $p): bool => $p->slug === $slug);
    }
}
```

(PHP 8.5 has `array_find`. If PHPStan complains about `strval(...)` first-class callable in `fromArray`, use `fn (mixed $v): string => (string) $v`.)

- [ ] **Step 4: Run to verify pass** — `vendor/bin/pest tests/Unit/Site/` → 4 passing.
- [ ] **Step 5: Commit** — `git add app/Site tests && git commit -m "feat: Add publication content-as-code repository"`

---

### Task 2: `site:publish` artisan command

**Files:**
- Create: `app/Console/Commands/PublishReviewCommand.php`
- Test: `tests/Feature/PublishReviewCommandTest.php`

**Interfaces:**
- Consumes: `Review` model (findings/metrics/panelists/dimension/spec_ref casts), `config('oast.judge')`.
- Produces: `site:publish {review} {slug} {--headline=} {--commentary=} {--spec-name=} {--spec-url=} {--spec-license=}` → writes `database/publications/{slug}.json` matching the Task 1 shape. Refuses non-complete reviews and existing slugs (exit FAILURE). `--commentary` is a path to a markdown file.

- [ ] **Step 1: Write failing tests**

```php
// tests/Feature/PublishReviewCommandTest.php
<?php

declare(strict_types=1);

use App\Models\Review;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::deleteDirectory(base_path('database/publications-test'));
    config()->set('site.publications_path', base_path('database/publications-test'));
});

it('exports a complete review to a publication json', function (): void {
    $review = Review::factory()->create([
        'status' => 'complete',
        'dimension' => 'domain-modeling',
        'panelists' => ['a-model', 'b-model'],
        'findings' => [validFinding()],
        'metrics' => [['model' => 'a-model', 'ms' => 5], ['total_cost_usd' => 0.5]],
    ]);
    $commentary = tempnam(sys_get_temp_dir(), 'md');
    file_put_contents((string) $commentary, 'Why this spec.');

    $this->artisan('site:publish', [
        'review' => $review->id,
        'slug' => 'test-slug',
        '--headline' => 'A headline',
        '--commentary' => $commentary,
        '--spec-name' => 'Test Spec',
        '--spec-url' => 'https://example.com/spec',
        '--spec-license' => 'CC0',
    ])->assertExitCode(0);

    $json = json_decode(File::get(base_path('database/publications-test/test-slug.json')), true);
    expect($json['slug'])->toBe('test-slug')
        ->and($json['headline'])->toBe('A headline')
        ->and($json['commentary_md'])->toBe('Why this spec.')
        ->and($json['judge'])->toBe(config('oast.judge'))
        ->and($json['findings'])->toHaveCount(1)
        ->and($json['published_at'])->not->toBeEmpty();
});

it('refuses an incomplete review', function (): void {
    $review = Review::factory()->create(['status' => 'error']);

    $this->artisan('site:publish', ['review' => $review->id, 'slug' => 'x'])
        ->assertExitCode(1);
});

it('refuses an existing slug', function (): void {
    File::ensureDirectoryExists(base_path('database/publications-test'));
    File::put(base_path('database/publications-test/taken.json'), '{}');
    $review = Review::factory()->create(['status' => 'complete']);

    $this->artisan('site:publish', ['review' => $review->id, 'slug' => 'taken'])
        ->assertExitCode(1);
});
```

- [ ] **Step 2: Run to verify failure** — `vendor/bin/pest tests/Feature/PublishReviewCommandTest.php` → FAIL.

- [ ] **Step 3: Implement**

Add `config/site.php`:

```php
<?php

declare(strict_types=1);

return [
    'publications_path' => env('SITE_PUBLICATIONS_PATH', base_path('database/publications')),
];
```

```php
// app/Console/Commands/PublishReviewCommand.php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Review;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Override;

final class PublishReviewCommand extends Command
{
    #[Override]
    protected $signature = 'site:publish {review : Review id} {slug : URL slug}
        {--headline= : Publication headline}
        {--commentary= : Path to a markdown commentary file}
        {--spec-name= : Human name of the reviewed spec}
        {--spec-url= : Source URL of the reviewed spec}
        {--spec-license= : License of the reviewed spec}';

    #[Override]
    protected $description = 'Export a completed review as a publication JSON file.';

    public function handle(): int
    {
        $review = Review::query()->find((int) $this->argument('review'));

        if ($review === null || $review->status !== 'complete') {
            $this->error('Review not found or not complete.');

            return self::FAILURE;
        }

        $dir = config()->string('site.publications_path');
        $slug = (string) $this->argument('slug');
        $target = $dir . '/' . $slug . '.json';

        if (File::exists($target)) {
            $this->error('Slug already published: ' . $slug);

            return self::FAILURE;
        }

        $commentaryPath = $this->option('commentary');
        $commentary = is_string($commentaryPath) && is_file($commentaryPath)
            ? (string) file_get_contents($commentaryPath)
            : '';

        File::ensureDirectoryExists($dir);
        File::put($target, (string) json_encode([
            'slug' => $slug,
            'headline' => (string) ($this->option('headline') ?? $slug),
            'commentary_md' => $commentary,
            'spec_name' => (string) ($this->option('spec-name') ?? ($review->spec_ref ?? 'Unknown spec')),
            'spec_source_url' => (string) ($this->option('spec-url') ?? ''),
            'spec_license' => (string) ($this->option('spec-license') ?? ''),
            'dimension' => $review->dimension,
            'panelists' => $review->panelists,
            'judge' => config()->string('oast.judge'),
            'findings' => $review->findings,
            'metrics' => $review->metrics,
            'reviewed_at' => $review->created_at?->toIso8601String(),
            'published_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info('Published ' . $target);

        return self::SUCCESS;
    }
}
```

Also update `PublicationRepository`'s default path to `config()->string('site.publications_path')` instead of the hard-coded `base_path(...)` (keep the constructor override for tests).

- [ ] **Step 4: Run to verify pass** — `vendor/bin/pest tests/Feature/PublishReviewCommandTest.php tests/Unit/Site/` → PASS.
- [ ] **Step 5: Commit** — `git add app config tests && git commit -m "feat: Add site:publish export command"`

---

### Task 3: NewsletterContacts — interface, SES implementation, fake

**Files:**
- Create: `app/Site/Newsletter/NewsletterContacts.php` (interface), `app/Site/Newsletter/SesNewsletterContacts.php`, `tests/Support/FakeNewsletterContacts.php`
- Modify: `app/Providers/AppServiceProvider.php` (bind interface), `config/services.php` (ses contact list block), `composer.json` (`composer require aws/aws-sdk-php`)
- Test: `tests/Unit/Site/SesNewsletterContactsTest.php`

**Interfaces:**
- Produces:
  ```php
  interface NewsletterContacts {
      public function create(string $email): void;   // idempotent — re-subscribe is not an error
      public function confirm(string $email): void;
  }
  ```
  `SesNewsletterContacts::__construct(private SesV2Client $client, private string $listName)`. `create` calls `createContact` with `AttributesData: {"confirmed": false}` and swallows `AlreadyExistsException`; `confirm` calls `updateContact` with `AttributesData: {"confirmed": true}`.
- Config: `services.ses_contacts = ['list' => env('OAST_SES_CONTACT_LIST', 'oast-launch'), 'region' => env('AWS_DEFAULT_REGION', 'us-east-1')]`.
- Container: bind `NewsletterContacts` → `SesNewsletterContacts` (built with a `SesV2Client` from config) in `AppServiceProvider::register()`.

- [ ] **Step 1: Write failing tests** (Mockery on the SDK client — no live AWS)

```php
// tests/Unit/Site/SesNewsletterContactsTest.php
<?php

declare(strict_types=1);

use App\Site\Newsletter\SesNewsletterContacts;
use Aws\SesV2\Exception\SesV2Exception;
use Aws\SesV2\SesV2Client;

it('creates an unconfirmed contact', function (): void {
    $client = Mockery::mock(SesV2Client::class);
    $client->shouldReceive('createContact')->once()->with(Mockery::on(
        fn (array $args): bool => $args['ContactListName'] === 'test-list'
            && $args['EmailAddress'] === 'a@b.test'
            && $args['AttributesData'] === '{"confirmed":false}'
    ));

    new SesNewsletterContacts($client, 'test-list')->create('a@b.test');
});

it('treats an already-existing contact as success', function (): void {
    $client = Mockery::mock(SesV2Client::class);
    $command = new Aws\Command('CreateContact');
    $client->shouldReceive('createContact')->once()->andThrow(
        new SesV2Exception('exists', $command, ['code' => 'AlreadyExistsException'])
    );

    new SesNewsletterContacts($client, 'test-list')->create('a@b.test');
    expect(true)->toBeTrue();
});

it('rethrows other SES failures', function (): void {
    $client = Mockery::mock(SesV2Client::class);
    $command = new Aws\Command('CreateContact');
    $client->shouldReceive('createContact')->once()->andThrow(
        new SesV2Exception('nope', $command, ['code' => 'BadRequestException'])
    );

    new SesNewsletterContacts($client, 'test-list')->create('a@b.test');
})->throws(SesV2Exception::class);

it('confirms a contact', function (): void {
    $client = Mockery::mock(SesV2Client::class);
    $client->shouldReceive('updateContact')->once()->with(Mockery::on(
        fn (array $args): bool => $args['EmailAddress'] === 'a@b.test'
            && $args['AttributesData'] === '{"confirmed":true}'
    ));

    new SesNewsletterContacts($client, 'test-list')->confirm('a@b.test');
});
```

- [ ] **Step 2: `composer require aws/aws-sdk-php` then run tests** → FAIL (classes missing).

- [ ] **Step 3: Implement**

```php
// app/Site/Newsletter/NewsletterContacts.php
<?php

declare(strict_types=1);

namespace App\Site\Newsletter;

interface NewsletterContacts
{
    public function create(string $email): void;

    public function confirm(string $email): void;
}
```

```php
// app/Site/Newsletter/SesNewsletterContacts.php
<?php

declare(strict_types=1);

namespace App\Site\Newsletter;

use Aws\SesV2\Exception\SesV2Exception;
use Aws\SesV2\SesV2Client;

final readonly class SesNewsletterContacts implements NewsletterContacts
{
    public function __construct(
        private SesV2Client $client,
        private string $listName,
    ) {}

    public function create(string $email): void
    {
        try {
            $this->client->createContact([
                'ContactListName' => $this->listName,
                'EmailAddress' => $email,
                'AttributesData' => '{"confirmed":false}',
            ]);
        } catch (SesV2Exception $exception) {
            if ($exception->getAwsErrorCode() !== 'AlreadyExistsException') {
                throw $exception;
            }
        }
    }

    public function confirm(string $email): void
    {
        $this->client->updateContact([
            'ContactListName' => $this->listName,
            'EmailAddress' => $email,
            'AttributesData' => '{"confirmed":true}',
        ]);
    }
}
```

```php
// tests/Support/FakeNewsletterContacts.php
<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Site\Newsletter\NewsletterContacts;

final class FakeNewsletterContacts implements NewsletterContacts
{
    /** @var list<string> */
    public array $created = [];

    /** @var list<string> */
    public array $confirmed = [];

    public function create(string $email): void
    {
        $this->created[] = $email;
    }

    public function confirm(string $email): void
    {
        $this->confirmed[] = $email;
    }
}
```

`AppServiceProvider::register()` addition:

```php
use App\Site\Newsletter\NewsletterContacts;
use App\Site\Newsletter\SesNewsletterContacts;
use Aws\SesV2\SesV2Client;

$this->app->singleton(NewsletterContacts::class, fn (): NewsletterContacts => new SesNewsletterContacts(
    new SesV2Client(['version' => 'latest', 'region' => config()->string('services.ses_contacts.region')]),
    config()->string('services.ses_contacts.list'),
));
```

`config/services.php` addition:

```php
'ses_contacts' => [
    'list' => env('OAST_SES_CONTACT_LIST', 'oast-launch'),
    'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
],
```

(Coverage note: the `AppServiceProvider` closure needs a test that resolves `NewsletterContacts` from the container with fake AWS env creds set — assert it returns a `SesNewsletterContacts`. AWS SDK constructs clients lazily; no network happens.)

- [ ] **Step 4: Run to verify pass** — `vendor/bin/pest tests/Unit/Site/ && composer test:unit` → PASS at 100%.
- [ ] **Step 5: Commit** — `git add -A app config tests composer.* && git commit -m "feat: Add SES-backed newsletter contacts"`

---

### Task 4: Subscribe HTTP flow

**Files:**
- Create: `app/Http/Controllers/Site/SubscribeController.php`, `app/Http/Controllers/Site/ConfirmSubscriptionController.php`, `app/Http/Requests/SubscribeRequest.php`, `app/Mail/ConfirmSubscription.php`, `resources/views/mail/confirm-subscription.blade.php`, `resources/views/site/confirmed.blade.php` (minimal placeholder; Task 6 styles it)
- Modify: `routes/web.php`, `app/Providers/AppServiceProvider.php` (rate limiter)
- Test: `tests/Feature/SubscribeFlowTest.php`

**Interfaces:**
- Consumes: `NewsletterContacts` (Task 3).
- Produces routes: `POST /subscribe` (name `subscribe`, middleware `throttle:subscribe`), `GET /subscribe/confirm/{email}` (name `subscribe.confirm`, middleware `signed`).
- Honeypot: hidden field named `website` — if non-empty, respond exactly like success but do nothing.
- Mail: `ConfirmSubscription` mailable with `URL::signedRoute('subscribe.confirm', ['email' => $email])`; sent synchronously (no queue in prod).

- [ ] **Step 1: Write failing tests**

```php
// tests/Feature/SubscribeFlowTest.php
<?php

declare(strict_types=1);

use App\Mail\ConfirmSubscription;
use App\Site\Newsletter\NewsletterContacts;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Tests\Support\FakeNewsletterContacts;

beforeEach(function (): void {
    Mail::fake();
    RateLimiter::clear('subscribe:127.0.0.1');
    $this->fake = new FakeNewsletterContacts();
    app()->instance(NewsletterContacts::class, $this->fake);
});

it('subscribes, creates an unconfirmed contact, and mails a signed confirm link', function (): void {
    $this->post('/subscribe', ['email' => 'a@b.test', 'website' => ''])
        ->assertRedirect('/')
        ->assertSessionHas('status');

    expect($this->fake->created)->toBe(['a@b.test']);
    Mail::assertSent(ConfirmSubscription::class, fn (ConfirmSubscription $mail): bool => $mail->hasTo('a@b.test'));
});

it('silently ignores honeypot submissions', function (): void {
    $this->post('/subscribe', ['email' => 'bot@spam.test', 'website' => 'http://spam'])
        ->assertRedirect('/');

    expect($this->fake->created)->toBe([]);
    Mail::assertNothingSent();
});

it('rejects invalid emails', function (): void {
    $this->from('/')->post('/subscribe', ['email' => 'not-an-email', 'website' => ''])
        ->assertRedirect('/')
        ->assertSessionHasErrors('email');
});

it('rate limits after 5 attempts per minute', function (): void {
    foreach (range(1, 5) as $i) {
        $this->post('/subscribe', ['email' => "a{$i}@b.test", 'website' => '']);
    }

    $this->post('/subscribe', ['email' => 'a6@b.test', 'website' => ''])->assertStatus(429);
});

it('confirms via a signed link', function (): void {
    $url = URL::signedRoute('subscribe.confirm', ['email' => 'a@b.test']);

    $this->get($url)->assertOk()->assertSee('confirmed', escape: false);
    expect($this->fake->confirmed)->toBe(['a@b.test']);
});

it('rejects a tampered confirm link', function (): void {
    $this->get(route('subscribe.confirm', ['email' => 'a@b.test']))->assertForbidden();
    expect($this->fake->confirmed)->toBe([]);
});
```

- [ ] **Step 2: Run to verify failure** — routes missing → FAIL.

- [ ] **Step 3: Implement**

```php
// app/Http/Requests/SubscribeRequest.php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SubscribeRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:254'],
            'website' => ['nullable', 'string'], // honeypot — validated as present-but-ignored
        ];
    }

    public function isSpam(): bool
    {
        return filled($this->input('website'));
    }
}
```

```php
// app/Http/Controllers/Site/SubscribeController.php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Http\Requests\SubscribeRequest;
use App\Mail\ConfirmSubscription;
use App\Site\Newsletter\NewsletterContacts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Mail;

final class SubscribeController
{
    public function __invoke(SubscribeRequest $request, NewsletterContacts $contacts): RedirectResponse
    {
        if (! $request->isSpam()) {
            $email = $request->string('email')->value();
            $contacts->create($email);
            Mail::to($email)->send(new ConfirmSubscription($email));
        }

        return redirect('/')->with('status', 'Check your inbox to confirm.');
    }
}
```

```php
// app/Http/Controllers/Site/ConfirmSubscriptionController.php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Site\Newsletter\NewsletterContacts;
use Illuminate\Contracts\View\View;

final class ConfirmSubscriptionController
{
    public function __invoke(string $email, NewsletterContacts $contacts): View
    {
        $contacts->confirm($email);

        return view('site.confirmed', ['email' => $email]);
    }
}
```

Wait — route model-less parameter order: Laravel injects route params after dependencies; declare as `__invoke(NewsletterContacts $contacts, string $email)`. Use that order.

```php
// app/Mail/ConfirmSubscription.php
<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\URL;

final class ConfirmSubscription extends Mailable
{
    public function __construct(public readonly string $email) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirm your oast.sh subscription');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.confirm-subscription', with: [
            'confirmUrl' => URL::signedRoute('subscribe.confirm', ['email' => $this->email]),
        ]);
    }
}
```

`resources/views/mail/confirm-subscription.blade.php`:

```blade
<p>Confirm your subscription to oast.sh launch updates:</p>
<p><a href="{{ $confirmUrl }}">Confirm subscription</a></p>
<p>If you didn't request this, ignore this email.</p>
```

`resources/views/site/confirmed.blade.php` (placeholder; Task 6 wraps it in the layout):

```blade
<p>Subscription confirmed for {{ $email }}. See you at launch.</p>
```

`routes/web.php` additions:

```php
use App\Http\Controllers\Site\ConfirmSubscriptionController;
use App\Http\Controllers\Site\SubscribeController;

Route::post('/subscribe', SubscribeController::class)
    ->middleware('throttle:subscribe')->name('subscribe');
Route::get('/subscribe/confirm/{email}', ConfirmSubscriptionController::class)
    ->middleware('signed')->name('subscribe.confirm');
```

`AppServiceProvider::boot()` addition:

```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

RateLimiter::for('subscribe', fn (Request $request): Limit => Limit::perMinute(5)->by('subscribe:' . $request->ip()));
```

- [ ] **Step 4: Run to verify pass** — `vendor/bin/pest tests/Feature/SubscribeFlowTest.php` → 6 passing.
- [ ] **Step 5: Commit** — `git add -A app resources routes tests && git commit -m "feat: Add newsletter subscribe flow with signed double-opt-in"`

---

### Task 5: API gating

**Files:**
- Create: `app/Http/Middleware/EnsureApiEnabled.php`
- Modify: `config/oast.php`, `routes/api.php` (attach middleware to the api-domain group)
- Test: `tests/Feature/ApiGateTest.php`

**Interfaces:**
- Produces: `config('oast.api_enabled')` from `OAST_API_ENABLED` default `true`; middleware `EnsureApiEnabled` → `abort(404)` when disabled (renders problem+json on the api host via the existing handler). Middleware (not conditional route registration) so `route:cache` stays safe.

- [ ] **Step 1: Write failing tests**

```php
// tests/Feature/ApiGateTest.php
<?php

declare(strict_types=1);

it('404s api routes when the api is disabled', function (): void {
    config()->set('oast.api_enabled', false);

    $this->getJson('https://' . config()->string('oast.api_domain') . '/reviews/1')
        ->assertNotFound();
});

it('serves api routes when enabled', function (): void {
    config()->set('oast.api_enabled', true);

    // unknown id still 404s, but by model binding — assert the problem body shape exists either way
    $this->getJson('https://' . config()->string('oast.api_domain') . '/reviews/999999')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json');
});

it('does not gate site routes', function (): void {
    config()->set('oast.api_enabled', false);

    $this->get('/')->assertOk();
});
```

- [ ] **Step 2: Run to verify failure.**

- [ ] **Step 3: Implement**

```php
// app/Http/Middleware/EnsureApiEnabled.php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureApiEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config()->boolean('oast.api_enabled'), 404);

        return $next($request);
    }
}
```

`config/oast.php` addition: `'api_enabled' => (bool) env('OAST_API_ENABLED', true),` — and attach `EnsureApiEnabled::class` to the api-domain route group in `routes/api.php`.

- [ ] **Step 4: Run to verify pass** (first test needs Task 6's `/` route to exist for the third case — if running before Task 6, assert against `/subscribe/confirm` signed instead; final ordering has Task 6 after, so re-run this file after Task 6 too).
- [ ] **Step 5: Commit** — `git add app config routes tests && git commit -m "feat: Gate the api surface behind OAST_API_ENABLED"`

---

### Task 6: Site pages (structure-first)

**Files:**
- Create: `resources/views/site/layout.blade.php`, `resources/views/site/home.blade.php`, `resources/views/site/reviews-index.blade.php`, `resources/views/site/review-show.blade.php`; rewrite `resources/views/site/confirmed.blade.php` to extend the layout
- Create: `app/Http/Controllers/Site/HomeController.php`, `app/Http/Controllers/Site/ReviewIndexController.php`, `app/Http/Controllers/Site/ReviewShowController.php`
- Modify: `routes/web.php` (replace the default `/` closure), delete `resources/views/welcome.blade.php`
- Test: `tests/Feature/SitePagesTest.php`

**Interfaces:**
- Consumes: `PublicationRepository::all()/find()` (Task 1).
- Produces routes: `GET /` (name `home`), `GET /reviews` (name `reviews.index`), `GET /reviews/{slug}` (name `reviews.show`, 404 on unknown slug).
- Views are semantic + light Tailwind utility classes only (`max-w-*`, spacing, `font-mono` on product output). Section structure per the spec: hero / problem / how-it-works / split-explainer / featured reviews (first 3 publications) / roadmap / signup form (posts to `route('subscribe')`, includes hidden `website` honeypot input, shows `session('status')` flash). Findings table columns: severity, confidence, title, location (location + model names in `font-mono`). Split findings render `disagreement` in a two-voice `<blockquote>` pair when `confidence === 'split'`. Meta strip on review pages: spec name/link/license, dimension, panelists, judge, total cost (formatted `$0.62`), reviewed date. Copy comes verbatim from the spec's Appendix A draft copy.

- [ ] **Step 1: Write failing tests**

```php
// tests/Feature/SitePagesTest.php
<?php

declare(strict_types=1);

use App\Site\PublicationRepository;

beforeEach(function (): void {
    app()->bind(PublicationRepository::class, fn (): PublicationRepository => new PublicationRepository(base_path('tests/fixtures/publications')));
});

it('renders the homepage with concept copy, featured reviews, and the signup form', function (): void {
    $this->get('/')->assertOk()
        ->assertSee('Notify me')
        ->assertSee('name="website"', escape: false)   // honeypot present
        ->assertSee('The Council vs. a well-designed spec')  // featured publication headline
        ->assertSee('consensus');
});

it('renders the reviews index', function (): void {
    $this->get('/reviews')->assertOk()->assertSee('Train Travel API');
});

it('renders a review page with findings, meta, and cost', function (): void {
    $this->get('/reviews/train-travel-domain-modeling')->assertOk()
        ->assertSee('Booking lifecycle never modeled as data')
        ->assertSee('blocker')
        ->assertSee('$0.62')
        ->assertSee('anthropic/claude-opus-4.8');
});

it('404s unknown review slugs', function (): void {
    $this->get('/reviews/nope')->assertNotFound();
});
```

- [ ] **Step 2: Run to verify failure.**

- [ ] **Step 3: Implement** — controllers:

```php
// app/Http/Controllers/Site/HomeController.php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Site\PublicationRepository;
use Illuminate\Contracts\View\View;

final class HomeController
{
    public function __invoke(PublicationRepository $publications): View
    {
        return view('site.home', ['featured' => array_slice($publications->all(), 0, 3)]);
    }
}
```

```php
// app/Http/Controllers/Site/ReviewIndexController.php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Site\PublicationRepository;
use Illuminate\Contracts\View\View;

final class ReviewIndexController
{
    public function __invoke(PublicationRepository $publications): View
    {
        return view('site.reviews-index', ['publications' => $publications->all()]);
    }
}
```

```php
// app/Http/Controllers/Site/ReviewShowController.php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Site\PublicationRepository;
use Illuminate\Contracts\View\View;

final class ReviewShowController
{
    public function __invoke(PublicationRepository $publications, string $slug): View
    {
        $publication = $publications->find($slug);

        abort_if($publication === null, 404);

        return view('site.review-show', ['publication' => $publication]);
    }
}
```

Routes (`routes/web.php`, replacing the default `/`):

```php
use App\Http\Controllers\Site\HomeController;
use App\Http\Controllers\Site\ReviewIndexController;
use App\Http\Controllers\Site\ReviewShowController;

Route::get('/', HomeController::class)->name('home');
Route::get('/reviews', ReviewIndexController::class)->name('reviews.index');
Route::get('/reviews/{slug}', ReviewShowController::class)->name('reviews.show');
```

Views: build `layout.blade.php` with `@vite` assets, a header (wordmark "oast", nav: Reviews), `{{ $slot ?? '' }}`-free classic `@yield('content')` sections, footer ("oast — raw spec in, refined spec out."). `home.blade.php` sections in spec order using Appendix A copy verbatim; signup form:

```blade
<form method="POST" action="{{ route('subscribe') }}">
    @csrf
    <input type="email" name="email" required placeholder="you@company.com">
    <input type="text" name="website" tabindex="-1" autocomplete="off" class="hidden" aria-hidden="true">
    <button type="submit">Notify me</button>
    @if (session('status')) <p role="status">{{ session('status') }}</p> @endif
    @error('email') <p role="alert">{{ $message }}</p> @enderror
</form>
```

`review-show.blade.php` core loop (structure the designers will re-skin):

```blade
@foreach ($publication->findings as $finding)
    <article>
        <header>
            <span class="font-mono">{{ $finding['severity'] ?? '' }}</span>
            <span class="font-mono">{{ $finding['confidence'] ?? '' }}</span>
            <h3>{{ $finding['title'] ?? '' }}</h3>
            <code>{{ $finding['location'] ?? '' }}</code>
        </header>
        <p>{{ $finding['finding'] ?? '' }}</p>
        <p><em>{{ $finding['why_it_matters'] ?? '' }}</em></p>
        @if (($finding['confidence'] ?? null) === 'split' && filled($finding['disagreement'] ?? null))
            <blockquote data-split>{{ $finding['disagreement'] }}</blockquote>
        @endif
        <p>{{ $finding['suggested_change'] ?? '' }}</p>
    </article>
@endforeach
```

- [ ] **Step 4: Run to verify pass** — `vendor/bin/pest tests/Feature/SitePagesTest.php tests/Feature/ApiGateTest.php` → PASS; then `composer test` → full gate green (100/100).
- [ ] **Step 5: Commit** — `git add -A app resources routes tests && git commit -m "feat: Add pre-launch site pages"`

---

### Task 7: Generate + publish launch content (LIVE, ~$2)

**Files:**
- Create: `database/publications/slack-web-api-domain-modeling.json`, `database/publications/train-travel-domain-modeling.json`, `database/publications/train-travel-resource-relationships.json`, `database/publications/train-travel-workflows.json`, commentary drafts under `database/publications/commentary/*.md`

**Steps:** (needs `OPENROUTER_API_KEY` + queue workers, run from repo root)

- [ ] Run the four reviews (existing train-travel D1 review #3 in the dev DB is reusable if still present; otherwise regenerate):
  `php artisan oast:review fixtures/specs/train-travel.yaml --dimension=resource-relationships` (+ workers), same for `--dimension=workflows`, and `php artisan oast:review fixtures/specs/slack.yaml`.
- [ ] Write 2-4 sentence commentary per publication in `database/publications/commentary/{slug}.md` (human voice — flag for Hunter's edit).
- [ ] `php artisan site:publish {id} {slug} --headline="…" --commentary=database/publications/commentary/{slug}.md --spec-name="…" --spec-url="…" --spec-license="…"` for each.
- [ ] Verify: `vendor/bin/pest tests/Feature/SitePagesTest.php` still green, then boot `composer dev` and eyeball `/, /reviews, /reviews/{slug}` for all four.
- [ ] Commit — `git add database/publications && git commit -m "content: Publish launch reviews (Slack D1, Train Travel D1/D2/D7)"`

## Self-review notes (applied)

- Confirm-controller param order corrected inline (dependencies before route params).
- ApiGateTest's site-route case depends on Task 6's `/`; ordering note included in Task 5.
- The `welcome.blade.php` deletion is in Task 6 where `/` is replaced.
