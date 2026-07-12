<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Site\Publication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', fn() => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/** @return array{User, Organization, OrganizationMembership} */
function memberFixture(string $role = 'member'): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $membership = OrganizationMembership::factory()->for($organization)->for($user)->create(['role' => $role]);

    return [$user, $organization, $membership];
}

/**
 * Issue a valid organization-scoped bearer token for the given (or a fresh)
 * member fixture so API feature tests can authenticate the bearer-only surface.
 *
 * @return array{User, Organization, string}
 */
function apiTokenFixture(): array
{
    [$user, $organization] = memberFixture();
    $created = app(App\Tokens\PersonalAccessTokenService::class)->create($user, $organization, 'test', null);

    return [$user, $organization, $created->plainTextToken];
}

function fixtureSpecPath(): string
{
    $path = sys_get_temp_dir() . '/oast-spec-' . uniqid() . '.yaml';
    file_put_contents($path, 'openapi: 3.1.0');

    return $path;
}

function orchestrator(array $configOverrides = []): App\Council\CouncilOrchestrator
{
    $config = array_merge([
        'timeout' => 30,
        'api_domain' => 'api.oast.test',
        'panelists' => ['a/one', 'b/two', 'c/three'],
        'judge' => 'judge/strong',
        'baseline' => null,
        'quorum' => 2,
    ], $configOverrides);

    return new App\Council\CouncilOrchestrator(new App\Council\FindingValidator, $config);
}

function validFinding(array $overrides = []): array
{
    return array_merge([
        'dimension' => 'domain-modeling',
        'title' => 'Order exposes DB join table',
        'severity' => 'blocker',
        'confidence' => 'consensus',
        'location' => '#/paths/~1order_line_items',
        'finding' => 'A join table is exposed as a resource.',
        'why_it_matters' => 'Chains the public contract to the DB schema.',
        'suggested_change' => 'Model orders and line items as domain resources.',
    ], $overrides);
}

function fakeCouncil(): void
{
    App\Ai\Agents\Panelist::fake(['critique a', 'critique b', 'critique c']);
    App\Ai\Agents\Judge::fake([['findings' => [validFinding()]]]);
}

function ogPublicationFixture(array $overrides = []): Publication
{
    return Publication::fromArray(array_merge([
        'slug' => 'train-travel-domain-modeling',
        'headline' => 'The Council vs. a well-designed spec',
        'commentary_md' => '',
        'spec_name' => 'Train Travel API',
        'spec_source_url' => 'https://example.test/spec',
        'spec_license' => 'CC-BY',
        'dimension' => 'domain-modeling',
        'panelists' => ['openai/gpt-5.5'],
        'judge' => 'anthropic/claude-opus-4.8',
        'findings' => [
            ['severity' => 'blocker'],
            ['severity' => 'should-fix'],
        ],
        'metrics' => [['total_cost_usd' => 0.62]],
        'reviewed_at' => '2026-07-05T00:00:00Z',
        'published_at' => '2026-07-05T00:00:00Z',
    ], $overrides));
}
