# oast.sh M3A — Identity and Organizations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to execute this plan one task at a time. Keep every checkbox, focused test, full gate, and commit boundary.

**Goal:** Add the shared self-host identity and organization foundation, invitation-gated registration, management screens, organization-scoped bearer tokens, and organization-safe review/API/SSE authorization without implementing the M3B review workspace or Docker image.

**Architecture:** Fortify owns browser login, reset, verification, confirmation, and invited registration endpoints. Application actions own canonical identity data, one-use bootstrap, invitations, memberships, and final-owner invariants. Sanctum parses bearer tokens, a custom token model permanently binds every token to one organization, and `OrganizationContext` is the only HTTP tenant source. Review creation commits ownership before dispatching the existing batch; lookup and streaming always re-resolve inside that organization. SSE capacity uses independently expiring lease IDs under a principal lock and rechecks review ownership, membership, and PAT validity every poll.

**Tech Stack:** PHP 8.5, Laravel 13, Laravel Fortify `^1.37`, Laravel Sanctum `^4.3`, Blade, Tailwind CSS 4, Vite Plus, Pest 4, SQLite/PostgreSQL.

**Scope guardrails:** Preserve the public publication routes and `PublicationRepository`. Do not add M3B review index/create/report UI, JSON Pointer mapping, browser `EventSource`, Docker, organization deletion, self-service account deletion, 2FA, OAuth, billing, multiple active memberships, or organization switching. Use `Organization`, never `Workspace` or `Team`.

---

### Task 1: Install dependencies and add the additive schema

**Files:**

- Modify: `composer.json`, `composer.lock`, `phpunit.xml`
- Create: `config/fortify.php`, `config/sanctum.php`
- Create: `database/migrations/2026_07_11_000001_create_identity_and_organization_tables.php`
- Create: `database/migrations/2026_07_11_000002_add_organization_ownership_to_reviews.php`
- Create: `tests/Feature/IdentitySchemaTest.php`

- [ ] **Step 1: Install the locked dependency ranges and publish their configs**

Run:

```bash
composer require laravel/fortify:^1.37 laravel/sanctum:^4.3
php artisan vendor:publish --provider="Laravel\Fortify\FortifyServiceProvider" --tag=fortify-config
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider" --tag=sanctum-config
```

Verify `composer.json` contains:

```json
"laravel/fortify": "^1.37",
"laravel/sanctum": "^4.3"
```

In the published `config/fortify.php`, replace the corresponding keys with:

```php
'guard' => 'web',
'middleware' => ['web'],
'home' => '/',
'username' => 'email',
'email' => 'email',
'lowercase_usernames' => true,
'views' => true,
'limiters' => ['login' => 'login', 'passkeys' => null],
'features' => [
    Laravel\Fortify\Features::resetPasswords(),
    Laravel\Fortify\Features::emailVerification(),
],
```

In the published `config/sanctum.php`, replace the corresponding keys with:

```php
'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),
'stateful' => array_values(array_filter(
    array_map('trim', explode(',', (string) env(
        'SANCTUM_STATEFUL_DOMAINS',
        parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: '',
    ))),
    static fn (string $domain): bool => $domain !== ''
        && $domain !== (string) env('OAST_API_DOMAIN', 'api.oast.test'),
)),
'guard' => [],
'expiration' => null,
'last_used_at' => false,
```

- [ ] **Step 2: Write the schema test**

Create `tests/Feature/IdentitySchemaTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Review;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('creates the identity schema and singleton installation row', function (): void {
    expect(Schema::hasColumns('organizations', ['id', 'name']))->toBeTrue()
        ->and(Schema::hasColumns('organization_memberships', ['organization_id', 'user_id', 'role']))->toBeTrue()
        ->and(Schema::hasColumns('organization_invitations', ['token_hash', 'accepted_at', 'revoked_at']))->toBeTrue()
        ->and(Schema::hasColumns('personal_access_tokens', ['organization_id', 'revoked_at']))->toBeTrue()
        ->and(Schema::hasColumns('reviews', ['organization_id', 'created_by_user_id']))->toBeTrue()
        ->and(DB::table('installation')->where('id', 1)->whereNull('bootstrapped_at')->exists())->toBeTrue();
});

it('cascades a non-final-owner membership and nulls that users audit references', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $organizationId = DB::table('organizations')->insertGetId([
        'name' => 'Acme', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('organization_memberships')->insert([
        ['organization_id' => $organizationId, 'user_id' => $owner->id, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()],
        ['organization_id' => $organizationId, 'user_id' => $member->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('organization_invitations')->insert([
        'organization_id' => $organizationId, 'invited_by_user_id' => $member->id,
        'email' => 'next@example.test', 'role' => 'member',
        'token_hash' => hash('sha256', 'token'), 'expires_at' => now()->addDay(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $review = Review::factory()->create([
        'organization_id' => $organizationId, 'created_by_user_id' => $member->id,
    ]);

    $member->delete();

    expect(DB::table('organization_memberships')->where('user_id', $member->id)->doesntExist())->toBeTrue()
        ->and(DB::table('organization_memberships')->where('user_id', $owner->id)->where('role', 'owner')->exists())->toBeTrue()
        ->and(DB::table('organization_invitations')->value('invited_by_user_id'))->toBeNull()
        ->and($review->refresh()->created_by_user_id)->toBeNull();
});

it('restricts organization deletion while reviews exist', function (): void {
    $organizationId = DB::table('organizations')->insertGetId([
        'name' => 'Acme', 'created_at' => now(), 'updated_at' => now(),
    ]);
    Review::factory()->create(['organization_id' => $organizationId]);

    expect(fn () => DB::table('organizations')->where('id', $organizationId)->delete())
        ->toThrow(QueryException::class);
});
```

- [ ] **Step 3: Run the test and confirm the intended failure**

Run: `vendor/bin/pest tests/Feature/IdentitySchemaTest.php`

Expected: FAIL because the organization tables and review ownership columns do not exist.

- [ ] **Step 4: Create the migrations**

Create `database/migrations/2026_07_11_000001_create_identity_and_organization_tables.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('organization_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
            $table->index(['organization_id', 'role']);
        });

        Schema::create('organization_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email');
            $table->string('role');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'email']);
        });

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('installation', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->timestamp('bootstrapped_at')->nullable();
            $table->foreignId('default_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
        });

        DB::table('installation')->insert([
            'id' => 1, 'bootstrapped_at' => null, 'default_organization_id' => null,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('installation');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('organization_invitations');
        Schema::dropIfExists('organization_memberships');
        Schema::dropIfExists('organizations');
    }
};
```

Create `database/migrations/2026_07_11_000002_add_organization_ownership_to_reviews.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->after('organization_id')->constrained('users')->nullOnDelete();
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropConstrainedForeignId('organization_id');
        });
    }
};
```

- [ ] **Step 5: Remove the obsolete `User` coverage exclusion and run the gate**

Delete this exact block from `phpunit.xml`:

```xml
<exclude>
    <!-- Stock Laravel auth scaffolding, unused by M0. -->
    <file>app/Models/User.php</file>
</exclude>
```

Run: `vendor/bin/pest tests/Feature/IdentitySchemaTest.php`

Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock phpunit.xml config/fortify.php config/sanctum.php database/migrations tests/Feature/IdentitySchemaTest.php
git commit -m "feat: add identity organization schema"
```

---

### Task 2: Add organization models, factories, and the shared fixture

**Files:**

- Create: `app/Enums/OrganizationRole.php`
- Create: `app/Models/Organization.php`, `OrganizationMembership.php`, `OrganizationInvitation.php`, `Installation.php`, `PersonalAccessToken.php`
- Modify: `app/Models/User.php`, `app/Models/Review.php`
- Create: `database/factories/OrganizationFactory.php`, `OrganizationMembershipFactory.php`, `OrganizationInvitationFactory.php`, `PersonalAccessTokenFactory.php`
- Modify: `database/factories/ReviewFactory.php`
- Modify: `tests/Pest.php`
- Create: `tests/Unit/Models/OrganizationModelsTest.php`

- [ ] **Step 1: Write the model test**

Create `tests/Unit/Models/OrganizationModelsTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\OrganizationMembership;
use App\Models\PersonalAccessToken;
use LogicException;

it('exposes the shared owner fixture and typed relationships', function (): void {
    [$user, $organization, $membership] = memberFixture(role: 'owner');

    expect($membership->role)->toBe(OrganizationRole::Owner)
        ->and($user->memberships()->sole()->is($membership))->toBeTrue()
        ->and($user->organizations()->sole()->is($organization))->toBeTrue()
        ->and($organization->members()->sole()->is($user))->toBeTrue();
});

it('keeps personal access token organization immutable', function (): void {
    $token = PersonalAccessToken::factory()->create();
    $token->organization_id++;

    expect(fn () => $token->save())->toThrow(LogicException::class, 'Token organization is immutable.');
});

it('enforces one membership per user', function (): void {
    [$user] = memberFixture();

    expect(fn () => OrganizationMembership::factory()->for($user)->create())
        ->toThrow(Illuminate\Database\QueryException::class);
});
```

- [ ] **Step 2: Run the test and confirm failure**

Run: `vendor/bin/pest tests/Unit/Models/OrganizationModelsTest.php`

Expected: FAIL because the enum, models, factories, and fixture do not exist.

- [ ] **Step 3: Create the enum and models**

Create `app/Enums/OrganizationRole.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum OrganizationRole: string
{
    case Owner = 'owner';
    case Member = 'member';
}
```

Create `app/Models/Organization.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    protected $fillable = ['name'];

    /** @return HasMany<OrganizationMembership, $this> */
    public function memberships(): HasMany { return $this->hasMany(OrganizationMembership::class); }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_memberships')->withPivot(['id', 'role'])->withTimestamps();
    }

    /** @return HasMany<OrganizationInvitation, $this> */
    public function invitations(): HasMany { return $this->hasMany(OrganizationInvitation::class); }

    /** @return HasMany<Review, $this> */
    public function reviews(): HasMany { return $this->hasMany(Review::class); }
}
```

Create `app/Models/OrganizationMembership.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationRole;
use Database\Factories\OrganizationMembershipFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class OrganizationMembership extends Model
{
    /** @use HasFactory<OrganizationMembershipFactory> */
    use HasFactory;

    protected $fillable = ['organization_id', 'user_id', 'role'];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    /** @return array<string, string> */
    protected function casts(): array { return ['role' => OrganizationRole::class]; }
}
```

Create `app/Models/OrganizationInvitation.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationRole;
use Database\Factories\OrganizationInvitationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class OrganizationInvitation extends Model
{
    /** @use HasFactory<OrganizationInvitationFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id', 'invited_by_user_id', 'email', 'role', 'token_hash',
        'expires_at', 'accepted_at', 'revoked_at',
    ];

    public function available(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo { return $this->belongsTo(User::class, 'invited_by_user_id'); }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => OrganizationRole::class, 'expires_at' => 'datetime',
            'accepted_at' => 'datetime', 'revoked_at' => 'datetime',
        ];
    }
}
```

Create `app/Models/Installation.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Installation extends Model
{
    protected $table = 'installation';
    public $timestamps = false;
    protected $fillable = ['bootstrapped_at', 'default_organization_id'];

    /** @return BelongsTo<Organization, $this> */
    public function defaultOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'default_organization_id');
    }

    /** @return array<string, string> */
    protected function casts(): array { return ['bootstrapped_at' => 'datetime']; }
}
```

Create `app/Models/PersonalAccessToken.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PersonalAccessTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;
use LogicException;

final class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /** @use HasFactory<PersonalAccessTokenFactory> */
    use HasFactory;

    protected $fillable = ['organization_id', 'name', 'token', 'abilities', 'expires_at', 'revoked_at'];

    protected static function booted(): void
    {
        self::updating(function (self $token): void {
            if ($token->isDirty('organization_id')) {
                throw new LogicException('Token organization is immutable.');
            }
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [...parent::casts(), 'revoked_at' => 'datetime'];
    }
}
```

- [ ] **Step 4: Replace the relevant `User` and `Review` contracts**

Add these imports and class/trait declarations to `app/Models/User.php`:

```php
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

final class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens;
    /** @use HasFactory<UserFactory> */
    use HasFactory;
    use Notifiable;

    /** @return HasMany<OrganizationMembership, $this> */
    public function memberships(): HasMany { return $this->hasMany(OrganizationMembership::class); }

    /** @return BelongsToMany<Organization, $this> */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_memberships')->withPivot(['id', 'role'])->withTimestamps();
    }
```

In `app/Models/Review.php`, add `BelongsTo`, replace the guarded property, and add both relations:

```php
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Override]
protected $guarded = ['organization_id', 'created_by_user_id'];

/** @return BelongsTo<Organization, $this> */
public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }

/** @return BelongsTo<User, $this> */
public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by_user_id'); }
```

- [ ] **Step 5: Create the factories and shared fixture**

Create the four factory `definition()` bodies with these complete returned arrays (retain the standard namespace/import/class shell used by `ReviewFactory`):

```php
// database/factories/OrganizationFactory.php
return ['name' => fake()->company()];

// database/factories/OrganizationMembershipFactory.php
return [
    'organization_id' => App\Models\Organization::factory(),
    'user_id' => App\Models\User::factory(),
    'role' => App\Enums\OrganizationRole::Member,
];

// database/factories/OrganizationInvitationFactory.php
return [
    'organization_id' => App\Models\Organization::factory(),
    'invited_by_user_id' => App\Models\User::factory(),
    'email' => fake()->unique()->safeEmail(),
    'role' => App\Enums\OrganizationRole::Member,
    'token_hash' => hash('sha256', Illuminate\Support\Str::random(40)),
    'expires_at' => now()->addDay(),
    'accepted_at' => null,
    'revoked_at' => null,
];

// database/factories/PersonalAccessTokenFactory.php
return [
    'tokenable_type' => App\Models\User::class,
    'tokenable_id' => App\Models\User::factory(),
    'organization_id' => App\Models\Organization::factory(),
    'name' => 'test token',
    'token' => hash('sha256', Illuminate\Support\Str::random(40)),
    'abilities' => ['review:create', 'review:read', 'review:follow'],
    'last_used_at' => null,
    'expires_at' => null,
    'revoked_at' => null,
];
```

Add to `database/factories/ReviewFactory.php`:

```php
use App\Models\Organization;

// first entries in definition():
'organization_id' => Organization::factory(),
'created_by_user_id' => null,
```

Add these imports and the one global fixture to `tests/Pest.php`:

```php
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;

/** @return array{User, Organization, OrganizationMembership} */
function memberFixture(string $role = 'member'): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $membership = OrganizationMembership::factory()->for($organization)->for($user)->create(['role' => $role]);

    return [$user, $organization, $membership];
}
```

Do not define `ownerFixture()` or `ownerFixtureWithMembership()` anywhere; all later tests call `memberFixture(role: 'owner')`.

- [ ] **Step 6: Run and commit**

Run: `vendor/bin/pest tests/Unit/Models/OrganizationModelsTest.php tests/Feature/IdentitySchemaTest.php`

Expected: PASS.

```bash
git add app/Enums app/Models database/factories tests/Pest.php tests/Unit/Models
git commit -m "feat: add organization domain models"
```

---

### Task 3: Add identity primitives and Fortify login, reset, verification, and confirmation

**Files:**

- Create: `app/Identity/CanonicalEmail.php`, `PasswordRules.php`, `RegistrationData.php`
- Create: `app/Actions/Identity/ResetUserPassword.php`
- Create: `app/Http/Middleware/CanonicalizeEmailInput.php`
- Create: `app/Providers/FortifyServiceProvider.php`
- Modify: `bootstrap/app.php`, `bootstrap/providers.php`
- Create: `resources/views/components/auth-layout.blade.php`, `form-errors.blade.php`
- Create: `resources/views/auth/login.blade.php`, `forgot-password.blade.php`, `reset-password.blade.php`, `verify-email.blade.php`, `confirm-password.blade.php`
- Modify: `tests/TestCase.php`
- Create: `tests/Unit/Identity/IdentityPrimitivesTest.php`, `tests/Feature/FortifyAuthenticationTest.php`

- [ ] **Step 1: Write focused identity and Fortify tests**

Create `tests/Unit/Identity/IdentityPrimitivesTest.php`:

```php
<?php

declare(strict_types=1);

use App\Identity\CanonicalEmail;
use App\Identity\PasswordRules;
use Illuminate\Validation\Rules\Password;

it('canonicalizes email', function (): void {
    expect(CanonicalEmail::from('  OWNER@Example.TEST '))->toBe('owner@example.test');
});

it('separates base and confirmed password rules', function (): void {
    expect(PasswordRules::base())->toHaveCount(3)
        ->and(PasswordRules::base()[0])->toBe('required')
        ->and(PasswordRules::base()[1])->toBe('string')
        ->and(PasswordRules::base()[2])->toBeInstanceOf(Password::class)
        ->and(PasswordRules::confirmed())->toHaveCount(4)
        ->and(PasswordRules::confirmed()[3])->toBe('confirmed');
});
```

Create `tests/Feature/FortifyAuthenticationTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

it('logs in by canonical email and logs out only by post', function (): void {
    $user = User::factory()->create(['email' => 'owner@example.test', 'password' => 'correct horse battery staple']);

    $this->post('/login', ['email' => ' OWNER@EXAMPLE.TEST ', 'password' => 'correct horse battery staple'])
        ->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
    $this->get('/logout')->assertMethodNotAllowed();
    $this->post('/logout')->assertRedirect('/');
    $this->assertGuest();
});

it('uses the same login failure for unknown email and wrong password', function (): void {
    User::factory()->create(['email' => 'owner@example.test', 'password' => 'correct horse battery staple']);
    $unknown = $this->from('/login')->post('/login', ['email' => 'missing@example.test', 'password' => 'wrong']);
    $wrong = $this->from('/login')->post('/login', ['email' => 'owner@example.test', 'password' => 'wrong']);

    expect(session('errors')->get('email'))->toBe(__('auth.failed') === session('errors')->get('email')[0]
        ? session('errors')->get('email') : session('errors')->get('email'));
    $unknown->assertSessionHasErrors(['email' => __('auth.failed')]);
    $wrong->assertSessionHasErrors(['email' => __('auth.failed')]);
});

it('canonicalizes password reset lookup and resets with confirmed rules', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'owner@example.test']);
    $this->post('/forgot-password', ['email' => ' OWNER@EXAMPLE.TEST '])->assertSessionHas('status');
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $mail) use ($user): bool {
        $response = $this->post('/reset-password', [
            'token' => $mail->token, 'email' => ' OWNER@EXAMPLE.TEST ',
            'password' => 'new correct horse battery staple',
            'password_confirmation' => 'new correct horse battery staple',
        ]);
        $response->assertSessionHas('status');
        return true;
    });
    expect(Hash::check('new correct horse battery staple', $user->refresh()->password))->toBeTrue();
});

it('serves verification and password confirmation views', function (): void {
    $user = User::factory()->unverified()->create();
    $this->actingAs($user)->get('/email/verify')->assertOk()->assertSee('Verify email');
    $this->actingAs($user)->get('/user/confirm-password')->assertOk()->assertSee('Confirm password');
});
```

- [ ] **Step 2: Run the tests and confirm failure**

Run: `vendor/bin/pest tests/Unit/Identity/IdentityPrimitivesTest.php tests/Feature/FortifyAuthenticationTest.php`

Expected: FAIL because identity services, provider, and views are absent.

- [ ] **Step 3: Create identity primitives and reset action**

Create `app/Identity/CanonicalEmail.php`:

```php
<?php

declare(strict_types=1);

namespace App\Identity;

final class CanonicalEmail
{
    public static function from(string $email): string { return mb_strtolower(trim($email)); }
}
```

Create `app/Identity/PasswordRules.php`:

```php
<?php

declare(strict_types=1);

namespace App\Identity;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rules\Password;

final class PasswordRules
{
    /** @return list<ValidationRule|string> */
    public static function base(): array
    {
        return ['required', 'string', Password::min(12)->uncompromised()];
    }

    /** @return list<ValidationRule|string> */
    public static function confirmed(): array
    {
        return [...self::base(), 'confirmed'];
    }
}
```

Make breach-list tests deterministic without weakening production. Modify `tests/TestCase.php`:

```php
protected function setUp(): void
{
    parent::setUp();
    $this->app->bind(
        Illuminate\Contracts\Validation\UncompromisedVerifier::class,
        fn (): Illuminate\Contracts\Validation\UncompromisedVerifier => new class implements Illuminate\Contracts\Validation\UncompromisedVerifier {
            public function verify($data): bool { return true; }
        },
    );
}
```

Production still uses Laravel's real `NotPwnedVerifier`; only the test application avoids network calls and treats fixture passwords as uncompromised.

Create `app/Identity/RegistrationData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Identity;

final readonly class RegistrationData
{
    public function __construct(public string $name, public string $email, public string $password) {}
}
```

Create `app/Actions/Identity/ResetUserPassword.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Identity\PasswordRules;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

final class ResetUserPassword implements ResetsUserPasswords
{
    /** @param array<string, string> $input */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, ['password' => PasswordRules::confirmed()])->validate();
        $user->forceFill(['password' => Hash::make($input['password'])])->save();
    }
}
```

Create `app/Http/Middleware/CanonicalizeEmailInput.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Identity\CanonicalEmail;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CanonicalizeEmailInput
{
    public function handle(Request $request, Closure $next): Response
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => CanonicalEmail::from($request->input('email'))]);
        }
        return $next($request);
    }
}
```

- [ ] **Step 4: Register Fortify without invitation registration**

Create `app/Providers/FortifyServiceProvider.php`:

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Identity\ResetUserPassword;
use App\Identity\CanonicalEmail;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

final class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::loginView(fn () => view('auth.login'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.reset-password', ['request' => $request]));
        Fortify::verifyEmailView(fn () => view('auth.verify-email'));
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));

        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::query()->where('email', CanonicalEmail::from($request->string('email')->value()))->first();
            if (! $user instanceof User || ! Hash::check($request->string('password')->value(), $user->password)) {
                throw ValidationException::withMessages(['email' => __('auth.failed')]);
            }
            return $user;
        });

        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute(5)->by(
            CanonicalEmail::from($request->string('email')->value()).'|'.$request->ip(),
        ));
    }
}
```

Add to `bootstrap/providers.php`:

```php
App\Providers\FortifyServiceProvider::class,
```

Add inside `withMiddleware()` in `bootstrap/app.php`:

```php
$middleware->append(App\Http\Middleware\CanonicalizeEmailInput::class);
```

- [ ] **Step 5: Create the complete auth views**

Create `resources/views/components/auth-layout.blade.php`:

```blade
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title ?? 'oast.sh' }}</title>@vite(['resources/css/app.css', 'resources/js/app.js'])</head>
<body><nav class="o-nav"><a class="o-wordmark" href="{{ route('home') }}">oast<em>.sh</em></a></nav>
<main class="mx-auto max-w-lg px-6 py-16">{{ $slot }}</main></body></html>
```

Create `resources/views/components/form-errors.blade.php`:

```blade
@if ($errors->any())<div class="o-confirm-box" role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
```

Create the five files with these exact forms:

```blade
{{-- resources/views/auth/login.blade.php --}}
<x-auth-layout title="Sign in"><h1 class="o-headline">Sign in</h1><x-form-errors />
<form method="POST" action="{{ route('login') }}" class="o-form">@csrf
<label for="email">Email</label><input class="o-input" id="email" name="email" type="email" required autofocus>
<label for="password">Password</label><input class="o-input" id="password" name="password" type="password" required>
<button class="o-btn" type="submit">Sign in</button><a href="{{ route('password.request') }}">Forgot password?</a></form></x-auth-layout>

{{-- resources/views/auth/forgot-password.blade.php --}}
<x-auth-layout title="Reset password"><h1 class="o-headline">Reset password</h1><x-form-errors />
<form method="POST" action="{{ route('password.email') }}" class="o-form">@csrf
<label for="email">Email</label><input class="o-input" id="email" name="email" type="email" required>
<button class="o-btn" type="submit">Send reset link</button></form></x-auth-layout>

{{-- resources/views/auth/reset-password.blade.php --}}
<x-auth-layout title="Choose password"><h1 class="o-headline">Choose password</h1><x-form-errors />
<form method="POST" action="{{ route('password.update') }}" class="o-form">@csrf
<input type="hidden" name="token" value="{{ $request->route('token') }}">
<label for="email">Email</label><input class="o-input" id="email" name="email" type="email" value="{{ $request->email }}" required>
<label for="password">Password</label><input class="o-input" id="password" name="password" type="password" required>
<label for="password_confirmation">Confirm password</label><input class="o-input" id="password_confirmation" name="password_confirmation" type="password" required>
<button class="o-btn" type="submit">Reset password</button></form></x-auth-layout>

{{-- resources/views/auth/verify-email.blade.php --}}
<x-auth-layout title="Verify email"><h1 class="o-headline">Verify email</h1><p>Check your inbox, or request another link.</p>
<form method="POST" action="{{ route('verification.send') }}">@csrf<button class="o-btn" type="submit">Resend verification</button></form>
<form method="POST" action="{{ route('logout') }}">@csrf<button class="o-btn o-btn-outline" type="submit">Sign out</button></form></x-auth-layout>

{{-- resources/views/auth/confirm-password.blade.php --}}
<x-auth-layout title="Confirm password"><h1 class="o-headline">Confirm password</h1><x-form-errors />
<form method="POST" action="{{ route('password.confirm') }}" class="o-form">@csrf
<label for="password">Password</label><input class="o-input" id="password" name="password" type="password" required>
<button class="o-btn" type="submit">Confirm</button></form></x-auth-layout>
```

There is intentionally no registration view, registration route, `RegistrationPolicy`, or invitation action in this task.

- [ ] **Step 6: Run and commit**

Run: `vendor/bin/pest tests/Unit/Identity/IdentityPrimitivesTest.php tests/Feature/FortifyAuthenticationTest.php`

Expected: PASS.

```bash
git add app/Identity app/Actions/Identity app/Http/Middleware/CanonicalizeEmailInput.php app/Providers/FortifyServiceProvider.php bootstrap resources/views/auth resources/views/components tests/TestCase.php tests/Unit/Identity tests/Feature/FortifyAuthenticationTest.php
git commit -m "feat: add canonical Fortify authentication"
```

---

### Task 4: Add protected one-use bootstrap with a real landing target

**Files:**

- Create: `app/Actions/Installation/BootstrapInstallation.php`
- Create: `app/Http/Middleware/EnsureInstallationBootstrapped.php`
- Create: `app/Http/Requests/AuthorizeSetupRequest.php`, `BootstrapInstallationRequest.php`
- Create: `app/Http/Controllers/SetupAuthorizationController.php`, `SetupController.php`
- Modify: `config/oast.php`, `.env.example`, `bootstrap/app.php`, `routes/web.php`
- Create: `resources/views/setup/authorize.blade.php`, `setup/create.blade.php`, `app/home.blade.php`
- Create: `tests/Support/FileDatabaseProcess.php`, `tests/Fixtures/m3a-race.php`
- Create: `tests/Feature/SetupTest.php`

- [ ] **Step 1: Write setup tests, including the file-backed process race**

Create `tests/Support/FileDatabaseProcess.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use Symfony\Component\Process\Process;

final class FileDatabaseProcess
{
    /** @param list<string> $arguments */
    public static function start(string $database, array $arguments): Process
    {
        $process = new Process([PHP_BINARY, base_path('tests/Fixtures/m3a-race.php'), $database, ...$arguments], base_path());
        $process->setTimeout(30);
        $process->start();
        return $process;
    }
}
```

Create `tests/Fixtures/m3a-race.php`:

```php
<?php

declare(strict_types=1);

use App\Actions\Installation\BootstrapInstallation;
use App\Identity\RegistrationData;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $argv[1]]);
Illuminate\Support\Facades\DB::purge('sqlite');

try {
    $operation = $argv[2] ?? '';
    if ($operation === 'setup') {
        app(BootstrapInstallation::class)(new RegistrationData($argv[3], $argv[4], $argv[5]), $argv[6]);
    } elseif ($operation === 'accept') {
        $invitation = App\Models\OrganizationInvitation::query()->findOrFail((int) $argv[3]);
        $user = App\Models\User::query()->findOrFail((int) $argv[4]);
        app(App\Organizations\InvitationAcceptanceService::class)->accept($invitation, $user);
    } elseif ($operation === 'revoke') {
        $invitation = App\Models\OrganizationInvitation::query()->findOrFail((int) $argv[3]);
        app(App\Organizations\InvitationService::class)->revoke($invitation);
    } elseif ($operation === 'demote') {
        $actor = App\Models\User::query()->findOrFail((int) $argv[3]);
        $target = App\Models\OrganizationMembership::query()->findOrFail((int) $argv[4]);
        app(App\Organizations\MembershipService::class)->changeRole($actor, $target, App\Enums\OrganizationRole::Member);
    } elseif ($operation === 'remove') {
        $actor = App\Models\User::query()->findOrFail((int) $argv[3]);
        $target = App\Models\OrganizationMembership::query()->findOrFail((int) $argv[4]);
        app(App\Organizations\MembershipService::class)->remove($actor, $target);
    } else {
        exit(64);
    }
    echo "won\n";
    exit(0);
} catch (NotFoundHttpException) {
    echo "already-bootstrapped\n";
    exit(44);
} catch (Illuminate\Validation\ValidationException|Illuminate\Database\Eloquent\ModelNotFoundException|Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $exception) {
    fwrite(STDERR, $exception::class.": invariant loser\n");
    exit(42);
} catch (Symfony\Component\HttpKernel\Exception\HttpException $exception) {
    if ($exception->getStatusCode() === 403) {
        fwrite(STDERR, $exception::class.": invariant loser\n");
        exit(42);
    }
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
} catch (Illuminate\Database\QueryException $exception) {
    $message = strtolower($exception->getMessage());
    if (str_contains($message, 'locked') || str_contains($message, 'busy')) {
        fwrite(STDERR, $exception::class.": sqlite lock failure\n");
        exit(70);
    }
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable::class.': '.$throwable->getMessage()."\n");
    exit(1);
}
```

Create `tests/Feature/SetupTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Installation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FileDatabaseProcess;

beforeEach(fn () => config(['oast.bootstrap_secret' => 'test-bootstrap-secret']));

it('requires a posted secret and bootstraps once into an existing route', function (): void {
    Review::factory()->create(['organization_id' => null, 'created_by_user_id' => null]);
    $this->get('/setup?bootstrap_secret=test-bootstrap-secret')->assertOk()->assertSee('Bootstrap secret');
    expect(session('oast.setup.authorized'))->toBeNull();
    $this->post('/setup/authorize', ['bootstrap_secret' => 'wrong'])->assertSessionHasErrors('bootstrap_secret');
    $this->post('/setup/authorize', ['bootstrap_secret' => 'test-bootstrap-secret'])->assertRedirect(route('setup.show'));
    $this->post('/setup', [
        'name' => 'Operator', 'email' => ' OWNER@EXAMPLE.TEST ', 'organization_name' => 'Acme',
        'password' => 'correct horse battery staple', 'password_confirmation' => 'correct horse battery staple',
    ])->assertRedirect(route('app.home'));

    $user = User::query()->sole();
    expect($user->email)->toBe('owner@example.test')->and($user->hasVerifiedEmail())->toBeTrue()
        ->and(OrganizationMembership::query()->sole()->role->value)->toBe('owner')
        ->and(Review::query()->sole()->organization_id)->toBe(Organization::query()->sole()->id)
        ->and(Review::query()->sole()->created_by_user_id)->toBeNull()
        ->and(Installation::query()->findOrFail(1)->bootstrapped_at)->not->toBeNull();
    $this->get('/app')->assertOk()->assertSee('Installation ready');
    $this->get('/setup')->assertNotFound();
});

it('serializes concurrent bootstrap against a file sqlite database', function (): void {
    $database = tempnam(sys_get_temp_dir(), 'oast-setup-');
    expect($database)->toBeString();
    $env = ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database];
    $migrate = new Symfony\Component\Process\Process([PHP_BINARY, 'artisan', 'migrate:fresh', '--force'], base_path(), $env);
    expect($migrate->run())->toBe(0, $migrate->getErrorOutput());

    $a = FileDatabaseProcess::start($database, ['setup', 'One', 'one@example.test', 'correct horse battery staple', 'One Org']);
    $b = FileDatabaseProcess::start($database, ['setup', 'Two', 'two@example.test', 'correct horse battery staple', 'Two Org']);
    $a->wait(); $b->wait();
    expect([$a->getExitCode(), $b->getExitCode()])->toContain(0)->toContain(44);

    config(['database.connections.sqlite.database' => $database]); DB::purge('sqlite');
    expect(User::query()->count())->toBe(1)->and(Organization::query()->count())->toBe(1)
        ->and(OrganizationMembership::query()->count())->toBe(1);
    unlink($database);
});
```

- [ ] **Step 2: Run and confirm failure**

Run: `vendor/bin/pest tests/Feature/SetupTest.php`

Expected: FAIL because setup code and routes do not exist.

- [ ] **Step 3: Add config and the bootstrap action**

Append to `config/oast.php`:

```php
'bootstrap_secret' => env('OAST_BOOTSTRAP_SECRET'),
'enforce_email_verification' => (bool) env('OAST_ENFORCE_EMAIL_VERIFICATION', false),
'invitation_ttl_hours' => (int) env('OAST_INVITATION_TTL_HOURS', 72),
'max_active_reviews' => (int) env('OAST_MAX_ACTIVE_REVIEWS', 10),
'max_concurrent_streams' => (int) env('OAST_MAX_CONCURRENT_STREAMS', 5),
```

Append to `.env.example`:

```dotenv
OAST_BOOTSTRAP_SECRET=
OAST_ENFORCE_EMAIL_VERIFICATION=false
OAST_INVITATION_TTL_HOURS=72
OAST_MAX_ACTIVE_REVIEWS=10
OAST_MAX_CONCURRENT_STREAMS=5
```

Create `app/Actions/Installation/BootstrapInstallation.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Installation;

use App\Enums\OrganizationRole;
use App\Identity\RegistrationData;
use App\Models\Installation;
use App\Models\Organization;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class BootstrapInstallation
{
    public function __invoke(RegistrationData $data, string $organizationName): User
    {
        return DB::transaction(function () use ($data, $organizationName): User {
            $installation = Installation::query()->lockForUpdate()->findOrFail(1);
            if ($installation->bootstrapped_at !== null) { throw new NotFoundHttpException; }
            $user = User::query()->create([
                'name' => $data->name, 'email' => $data->email,
                'password' => Hash::make($data->password), 'email_verified_at' => now(),
            ]);
            $organization = Organization::query()->create(['name' => $organizationName]);
            $organization->memberships()->create(['user_id' => $user->id, 'role' => OrganizationRole::Owner]);
            Review::query()->whereNull('organization_id')->update([
                'organization_id' => $organization->id, 'created_by_user_id' => null,
            ]);
            $installation->update(['bootstrapped_at' => now(), 'default_organization_id' => $organization->id]);
            return $user;
        }, 3);
    }
}
```

- [ ] **Step 4: Add requests, middleware, controllers, routes, and real target**

Create `app/Http/Requests/AuthorizeSetupRequest.php` and `BootstrapInstallationRequest.php`:

```php
<?php
// AuthorizeSetupRequest.php
declare(strict_types=1);
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
final class AuthorizeSetupRequest extends FormRequest {
    public function authorize(): bool { return true; }
    /** @return array<string, list<string>> */
    public function rules(): array { return ['bootstrap_secret' => ['required', 'string']]; }
}

<?php
// BootstrapInstallationRequest.php
declare(strict_types=1);
namespace App\Http\Requests;
use App\Identity\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;
final class BootstrapInstallationRequest extends FormRequest {
    public function authorize(): bool { return $this->session()->get('oast.setup.authorized') === true; }
    /** @return array<string, mixed> */
    public function rules(): array { return [
        'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        'organization_name' => ['required', 'string', 'max:255'], 'password' => PasswordRules::confirmed(),
    ]; }
}
```

Create `app/Http/Middleware/EnsureInstallationBootstrapped.php`:

```php
<?php

declare(strict_types=1);
namespace App\Http\Middleware;
use App\Models\Installation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
final class EnsureInstallationBootstrapped {
    public function handle(Request $request, Closure $next): Response {
        if (Installation::query()->findOrFail(1)->bootstrapped_at === null) { return redirect()->route('setup.show'); }
        return $next($request);
    }
}
```

Create `app/Http/Controllers/SetupAuthorizationController.php` and `SetupController.php`:

```php
<?php
// SetupAuthorizationController.php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\Http\Requests\AuthorizeSetupRequest;
use App\Models\Installation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
final class SetupAuthorizationController {
    public function __invoke(AuthorizeSetupRequest $request): RedirectResponse {
        if (Installation::query()->findOrFail(1)->bootstrapped_at !== null) { throw new NotFoundHttpException; }
        $configured = config('oast.bootstrap_secret');
        if (! is_string($configured) || $configured === '' || ! hash_equals($configured, $request->string('bootstrap_secret')->value())) {
            throw ValidationException::withMessages(['bootstrap_secret' => 'The bootstrap secret is invalid.']);
        }
        $request->session()->regenerateToken();
        $request->session()->put('oast.setup.authorized', true);
        return redirect()->route('setup.show');
    }
}

<?php
// SetupController.php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\Actions\Installation\BootstrapInstallation;
use App\Http\Requests\BootstrapInstallationRequest;
use App\Identity\RegistrationData;
use App\Models\Installation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
final class SetupController {
    public function show(Request $request): View {
        if (Installation::query()->findOrFail(1)->bootstrapped_at !== null) { throw new NotFoundHttpException; }
        return view($request->session()->get('oast.setup.authorized') === true ? 'setup.create' : 'setup.authorize');
    }
    public function store(BootstrapInstallationRequest $request, BootstrapInstallation $bootstrap): RedirectResponse {
        $user = $bootstrap(new RegistrationData(
            $request->string('name')->value(), $request->string('email')->value(), $request->string('password')->value(),
        ), $request->string('organization_name')->value());
        $request->session()->forget('oast.setup.authorized');
        Auth::login($user); $request->session()->regenerate();
        return redirect()->route('app.home');
    }
}
```

Add alias in `bootstrap/app.php`:

```php
$middleware->alias(['installation' => App\Http\Middleware\EnsureInstallationBootstrapped::class]);
```

Add imports and these exact routes to `routes/web.php`:

```php
use App\Http\Controllers\SetupAuthorizationController;
use App\Http\Controllers\SetupController;

Route::get('/setup', [SetupController::class, 'show'])->name('setup.show');
Route::post('/setup/authorize', SetupAuthorizationController::class)->middleware('throttle:5,1')->name('setup.authorize');
Route::post('/setup', [SetupController::class, 'store'])->middleware('throttle:5,1')->name('setup.store');
Route::prefix('app')->name('app.')->middleware(['installation', 'auth'])->group(function (): void {
    Route::view('/', 'app.home')->name('home');
});
```

Create the views:

```blade
{{-- resources/views/setup/authorize.blade.php --}}
<x-auth-layout title="Authorize setup"><h1 class="o-headline">Authorize setup</h1><x-form-errors />
<form method="POST" action="{{ route('setup.authorize') }}" class="o-form">@csrf
<label for="bootstrap_secret">Bootstrap secret</label><input class="o-input" id="bootstrap_secret" name="bootstrap_secret" type="password" required>
<button class="o-btn" type="submit">Continue</button></form></x-auth-layout>

{{-- resources/views/setup/create.blade.php --}}
<x-auth-layout title="Create installation"><h1 class="o-headline">Create installation</h1><x-form-errors />
<form method="POST" action="{{ route('setup.store') }}" class="o-form">@csrf
<label for="name">Name</label><input class="o-input" id="name" name="name" required>
<label for="email">Email</label><input class="o-input" id="email" name="email" type="email" required>
<label for="organization_name">Organization</label><input class="o-input" id="organization_name" name="organization_name" required>
<label for="password">Password</label><input class="o-input" id="password" name="password" type="password" required>
<label for="password_confirmation">Confirm password</label><input class="o-input" id="password_confirmation" name="password_confirmation" type="password" required>
<button class="o-btn" type="submit">Create installation</button></form></x-auth-layout>

{{-- resources/views/app/home.blade.php --}}
<x-auth-layout title="Installation ready"><h1 class="o-headline">Installation ready</h1>
<p>Your account and organization are ready.</p><form method="POST" action="{{ route('logout') }}">@csrf<button class="o-btn" type="submit">Sign out</button></form></x-auth-layout>
```

- [ ] **Step 5: Add exact pre-bootstrap Fortify/public-route coverage**

Create `tests/Feature/PreBootstrapAccessTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Installation;

it('redirects every enabled Fortify entry point to setup before bootstrap', function (string $method, string $uri): void {
    expect(Installation::query()->findOrFail(1)->bootstrapped_at)->toBeNull();
    $response = $method === 'get' ? $this->get($uri) : $this->post($uri);
    $response->assertRedirect(route('setup.show'));
})->with([
    ['get', '/login'],
    ['post', '/login'],
    ['get', '/forgot-password'],
    ['post', '/forgot-password'],
    ['get', '/reset-password/test-token'],
    ['get', '/email/verify'],
    ['get', '/user/confirm-password'],
]);

it('keeps setup health assets and publication pages reachable before bootstrap', function (): void {
    $this->get('/setup')->assertOk();
    $this->get('/up')->assertOk();
    $this->get('/')->assertOk();
    $this->get('/why')->assertOk();
    $this->get('/reviews')->assertOk();
    $this->get('/build/assets/app.css')->assertNotFound(); // no redirect to setup
});
```

In Task 4, change `config/fortify.php` to:

```php
'middleware' => ['web', App\Http\Middleware\EnsureInstallationBootstrapped::class],
```

This applies `EnsureInstallationBootstrapped` to Fortify routes only. Invitation routes added in Task 6 also use the `installation` alias. Do not append this middleware globally.

- [ ] **Step 6: Run and commit**

Run: `vendor/bin/pest tests/Feature/SetupTest.php tests/Feature/PreBootstrapAccessTest.php`

Expected: PASS, including one process exiting `0`, one exiting `44`, one row in each bootstrap table, Fortify redirects before bootstrap, and public/setup/health routes do not redirect.

```bash
git add app/Actions/Installation app/Http/Middleware/EnsureInstallationBootstrapped.php app/Http/Requests app/Http/Controllers/Setup* config/oast.php config/fortify.php .env.example bootstrap/app.php routes/web.php resources/views/setup resources/views/app/home.blade.php tests/Support tests/Fixtures tests/Feature/SetupTest.php tests/Feature/PreBootstrapAccessTest.php
git commit -m "feat: add protected installation bootstrap"
```

---

### Task 5: Add organization context and configured verification middleware

**Files:**

- Create: `app/Organizations/OrganizationContext.php`, `MissingOrganizationMembership.php`
- Create: `app/Http/Middleware/EnsureEmailIsVerifiedWhenConfigured.php`, `EnsureOrganizationMembership.php`
- Modify: `app/Providers/AppServiceProvider.php`, `bootstrap/app.php`, `routes/web.php`
- Create: `resources/views/app/no-organization.blade.php`
- Create: `tests/Feature/OrganizationContextTest.php`, `VerificationPolicyTest.php`

- [ ] **Step 1: Write the context and middleware tests**

Create `tests/Feature/OrganizationContextTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Organizations\OrganizationContext;

it('resolves the sole browser membership', function (): void {
    [$user, $organization] = memberFixture();
    $this->actingAs($user)->get('/app')->assertOk();
    expect(app(OrganizationContext::class)->organization()->is($organization))->toBeTrue();
});

it('resolves a token organization only through a matching membership', function (): void {
    [$user, $organization] = memberFixture();
    $token = PersonalAccessToken::factory()->for($user, 'tokenable')->for($organization)->create();
    $user->withAccessToken($token);
    request()->setUserResolver(fn () => $user);
    app()->forgetScopedInstances();
    expect(app(OrganizationContext::class)->organization()->is($organization))->toBeTrue();
});

it('renders a holding page for a zero-membership user', function (): void {
    $this->actingAs(User::factory()->create())->get('/app')->assertOk()
        ->assertSee('You are not a member of any organization');
});
```

Create `tests/Feature/VerificationPolicyTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Installation;

it('allows unverified members when enforcement is disabled', function (): void {
    Installation::query()->findOrFail(1)->update(['bootstrapped_at' => now()]);
    [$user] = memberFixture(); $user->forceFill(['email_verified_at' => null])->save();
    config(['oast.enforce_email_verification' => false]);
    $this->actingAs($user)->get('/app')->assertOk();
});

it('redirects unverified members when enforcement is enabled without affecting public pages', function (): void {
    Installation::query()->findOrFail(1)->update(['bootstrapped_at' => now()]);
    [$user] = memberFixture(); $user->forceFill(['email_verified_at' => null])->save();
    config(['oast.enforce_email_verification' => true]);
    $this->actingAs($user)->get('/app')->assertRedirect(route('verification.notice'));
    $this->get('/')->assertOk();
});
```

- [ ] **Step 2: Run and confirm failure**

Run: `vendor/bin/pest tests/Feature/OrganizationContextTest.php tests/Feature/VerificationPolicyTest.php`

Expected: FAIL because the context and middleware do not exist.

- [ ] **Step 3: Create the context and middleware**

Create `app/Organizations/MissingOrganizationMembership.php`:

```php
<?php

declare(strict_types=1);
namespace App\Organizations;
use RuntimeException;
final class MissingOrganizationMembership extends RuntimeException {}
```

Create `app/Organizations/OrganizationContext.php`:

```php
<?php

declare(strict_types=1);
namespace App\Organizations;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PersonalAccessToken;
use App\Models\Review;
use App\Models\User;
use Illuminate\Http\Request;
final class OrganizationContext {
    private ?OrganizationMembership $membership = null;
    public function __construct(private readonly Request $request) {}
    public function organization(): Organization { return $this->membership()->organization; }
    public function membership(): OrganizationMembership {
        if ($this->membership instanceof OrganizationMembership) { return $this->membership; }
        $user = $this->request->user();
        if (! $user instanceof User) { throw new MissingOrganizationMembership; }
        $query = OrganizationMembership::query()->with('organization')->where('user_id', $user->id);
        $token = $this->token();
        if ($token instanceof PersonalAccessToken) { $query->where('organization_id', $token->organization_id); }
        $membership = $query->first();
        if (! $membership instanceof OrganizationMembership) { throw new MissingOrganizationMembership; }
        return $this->membership = $membership;
    }
    public function token(): ?PersonalAccessToken {
        $token = $this->request->user()?->currentAccessToken();
        return $token instanceof PersonalAccessToken ? $token : null;
    }
    public function stillAuthorized(Review $review): bool {
        $user = $this->request->user();
        if (! $user instanceof User) { return false; }
        $organizationId = $this->membership?->organization_id ?? $this->membership()->organization_id;
        $member = OrganizationMembership::query()->where('user_id', $user->id)->where('organization_id', $organizationId)->exists();
        $owned = Review::query()->whereKey($review->id)->where('organization_id', $organizationId)->exists();
        if (! $member || ! $owned) { return false; }
        $token = $this->token();
        return ! $token instanceof PersonalAccessToken || PersonalAccessToken::query()->whereKey($token->id)
            ->whereNull('revoked_at')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists();
    }
}
```

Create `app/Http/Middleware/EnsureEmailIsVerifiedWhenConfigured.php` and `EnsureOrganizationMembership.php`:

```php
<?php
// EnsureEmailIsVerifiedWhenConfigured.php
declare(strict_types=1);
namespace App\Http\Middleware;
use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
final class EnsureEmailIsVerifiedWhenConfigured {
    public function handle(Request $request, Closure $next): Response {
        $user = $request->user();
        if (config()->boolean('oast.enforce_email_verification') && $user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }
        return $next($request);
    }
}

<?php
// EnsureOrganizationMembership.php
declare(strict_types=1);
namespace App\Http\Middleware;
use App\Organizations\MissingOrganizationMembership;
use App\Organizations\OrganizationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
final class EnsureOrganizationMembership {
    public function handle(Request $request, Closure $next): Response {
        try { app(OrganizationContext::class)->membership(); }
        catch (MissingOrganizationMembership) { return response()->view('app.no-organization'); }
        return $next($request);
    }
}
```

Add to `AppServiceProvider::register()`:

```php
$this->app->scoped(App\Organizations\OrganizationContext::class);
```

Add aliases in `bootstrap/app.php`:

```php
'verified.configured' => App\Http\Middleware\EnsureEmailIsVerifiedWhenConfigured::class,
'organization' => App\Http\Middleware\EnsureOrganizationMembership::class,
'ability' => Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
'abilities' => Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
```

The Sanctum aliases are required because Laravel 13 and Sanctum 4.3 do not register them automatically.

Replace the Task 4 `/app` route group with this exact group; it references only the existing `app.home` view:

```php
Route::prefix('app')->name('app.')->middleware(['installation', 'auth', 'verified.configured', 'organization'])->group(function (): void {
    Route::view('/', 'app.home')->name('home');
});
```

Create `resources/views/app/no-organization.blade.php`:

```blade
<x-auth-layout title="No organization"><h1 class="o-headline">You are not a member of any organization</h1>
<p>Ask an owner to invite this email address.</p><form method="POST" action="{{ route('logout') }}">@csrf
<button class="o-btn" type="submit">Sign out</button></form></x-auth-layout>
```

This task does not register account, organization-settings, token-settings, or review routes.

- [ ] **Step 4: Run and commit**

Run: `vendor/bin/pest tests/Feature/OrganizationContextTest.php tests/Feature/VerificationPolicyTest.php`

Expected: PASS.

```bash
git add app/Organizations app/Http/Middleware app/Providers/AppServiceProvider.php bootstrap/app.php routes/web.php resources/views/app/no-organization.blade.php tests/Feature/OrganizationContextTest.php tests/Feature/VerificationPolicyTest.php
git commit -m "feat: add organization request context"
```

---

### Task 6: Add invitations, the registration policy seam, and invited Fortify registration

**Files:**

- Create: `app/Identity/RegistrationPolicy.php`, `SelfHostedRegistrationPolicy.php`
- Create: `app/Actions/Identity/CreateNewUser.php`
- Create: `app/Organizations/InvitationService.php`, `InvitationAcceptanceService.php`
- Create: `app/Http/Controllers/InvitationController.php`, `InvitationAcceptanceController.php`
- Create: `app/Mail/OrganizationInvitationMail.php`
- Modify: `app/Providers/AppServiceProvider.php`, `app/Providers/FortifyServiceProvider.php`, `config/fortify.php`, `routes/web.php`
- Create: `resources/views/auth/register.blade.php`, `invitations/show.blade.php`, `invitations/unavailable.blade.php`, `mail/organization-invitation.blade.php`
- Replace: `tests/Fixtures/m3a-race.php`
- Create: `tests/Feature/InvitationFlowTest.php`

- [ ] **Step 1: Write invitation tests**

Create `tests/Feature/InvitationFlowTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Installation;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Organizations\InvitationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Process\Process;
use Tests\Support\FileDatabaseProcess;

beforeEach(fn () => Installation::query()->findOrFail(1)->update(['bootstrapped_at' => now()]));

it('redirects invitation continuations to setup before bootstrap', function (): void {
    Installation::query()->findOrFail(1)->update(['bootstrapped_at' => null]);
    $token = str_repeat('a', 64);
    $this->get(route('invitations.show', $token))->assertRedirect(route('setup.show'));
    $this->post(route('invitations.start-registration', $token))->assertRedirect(route('setup.show'));
    $this->post(route('invitations.start-login', $token))->assertRedirect(route('setup.show'));
    $this->post(route('invitations.accept', $token))->assertRedirect(route('setup.show'));
});

it('creates a canonical hashed invitation and keeps a copyable URL when mail fails', function (): void {
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('mail down'));
    [$owner, $organization] = memberFixture(role: 'owner');
    $result = app(InvitationService::class)->createOrReplace($organization, $owner, ' NEW@EXAMPLE.TEST ');
    $plain = basename($result['url']);
    expect($plain)->toMatch('/^[a-f0-9]{64}$/')
        ->and($result['invitation']->email)->toBe('new@example.test')
        ->and($result['invitation']->token_hash)->toBe(hash('sha256', $plain))
        ->and($result['invitation']->getRawOriginal('token_hash'))->not->toContain($plain);
});

it('preserves an invitation through matching-email login and acceptance', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $user = User::factory()->create(['email' => 'new@example.test']);
    $plain = basename(app(InvitationService::class)->createOrReplace($organization, $owner, $user->email)['url']);
    $this->post(route('invitations.start-login', $plain))->assertRedirect(route('login'));
    expect(session('oast.invitation.token'))->toBe($plain)
        ->and(session('url.intended'))->toBe(route('invitations.show', $plain));
    $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('invitations.show', $plain));
    $this->post(route('invitations.accept', $plain))->assertRedirect(route('app.home'));
    expect($user->memberships()->where('organization_id', $organization->id)->exists())->toBeTrue();
});

it('returns to the invitation after login but rejects a mismatched email', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $other = User::factory()->create(['email' => 'other@example.test']);
    $plain = basename(app(InvitationService::class)->createOrReplace($organization, $owner, 'new@example.test')['url']);
    $this->post(route('invitations.start-login', $plain));
    $this->post(route('login'), ['email' => $other->email, 'password' => 'password'])
        ->assertRedirect(route('invitations.show', $plain));
    $this->post(route('invitations.accept', $plain))->assertSessionHasErrors('invitation');
    expect($other->memberships()->count())->toBe(0);
});

it('posts an invitation token into encrypted session before register', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $result = app(InvitationService::class)->createOrReplace($organization, $owner, 'new@example.test');
    $plain = basename($result['url']);
    $this->post(route('invitations.start-registration', $plain))->assertRedirect(route('register'));
    expect(session('oast.invitation.token'))->toBe($plain);
    expect(session()->get('_previous.url'))->not->toContain($plain.'?');
});

it('registers and consumes an invitation atomically', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $plain = basename(app(InvitationService::class)->createOrReplace($organization, $owner, 'new@example.test')['url']);
    $this->post(route('invitations.start-registration', $plain));
    $this->post('/register', [
        'name' => 'New Member', 'email' => ' NEW@EXAMPLE.TEST ',
        'password' => 'correct horse battery staple', 'password_confirmation' => 'correct horse battery staple',
        'invitation_token' => $plain,
    ])->assertRedirect('/');
    $user = User::query()->where('email', 'new@example.test')->sole();
    expect(OrganizationMembership::query()->where('user_id', $user->id)->value('organization_id'))->toBe($organization->id)
        ->and(OrganizationInvitation::query()->where('email', 'new@example.test')->value('accepted_at'))->not->toBeNull();
});

it('uses one unavailable response for unknown expired revoked and consumed tokens', function (string $state): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $plain = basename(app(InvitationService::class)->createOrReplace($organization, $owner, 'new@example.test')['url']);
    $invitation = OrganizationInvitation::query()->where('email', 'new@example.test')->sole();
    if ($state === 'expired') { $invitation->update(['expires_at' => now()->subMinute()]); }
    if ($state === 'revoked') { $invitation->update(['revoked_at' => now()]); }
    if ($state === 'consumed') { $invitation->update(['accepted_at' => now()]); }
    if ($state === 'unknown') { $plain = str_repeat('a', 64); }
    $this->get(route('invitations.show', $plain))->assertOk()->assertSee('This invitation is not available')
        ->assertHeader('Referrer-Policy', 'no-referrer');
})->with(['unknown', 'expired', 'revoked', 'consumed']);

it('rejects mismatched existing users and members without partial writes', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $plain = basename(app(InvitationService::class)->createOrReplace($organization, $owner, 'new@example.test')['url']);
    $other = User::factory()->create(['email' => 'other@example.test']);
    $this->actingAs($other)->post(route('invitations.accept', $plain))->assertSessionHasErrors('invitation');
    expect($other->memberships()->count())->toBe(0)->and(OrganizationInvitation::query()->sole()->accepted_at)->toBeNull();
});
```

- [ ] **Step 2: Run and confirm failure**

Run: `vendor/bin/pest tests/Feature/InvitationFlowTest.php`

Expected: FAIL because invitation services and registration are absent.

- [ ] **Step 3: Add the registration policy and invitation services**

Create `app/Identity/RegistrationPolicy.php` and `SelfHostedRegistrationPolicy.php`:

```php
<?php
// RegistrationPolicy.php
declare(strict_types=1);
namespace App\Identity;
use App\Models\OrganizationInvitation;
use App\Models\User;
interface RegistrationPolicy { public function register(RegistrationData $data, OrganizationInvitation $invitation): User; }

<?php
// SelfHostedRegistrationPolicy.php
declare(strict_types=1);
namespace App\Identity;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Organizations\InvitationAcceptanceService;
final readonly class SelfHostedRegistrationPolicy implements RegistrationPolicy {
    public function __construct(private InvitationAcceptanceService $acceptance) {}
    public function register(RegistrationData $data, OrganizationInvitation $invitation): User {
        return $this->acceptance->registerAndAccept($invitation, $data);
    }
}
```

Create `app/Organizations/InvitationService.php`:

```php
<?php

declare(strict_types=1);
namespace App\Organizations;
use App\Enums\OrganizationRole;
use App\Identity\CanonicalEmail;
use App\Mail\OrganizationInvitationMail;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;
final class InvitationService {
    /** @return array{invitation: OrganizationInvitation, url: string} */
    public function createOrReplace(Organization $organization, User $inviter, string $email): array {
        $email = CanonicalEmail::from($email);
        [$invitation, $plain] = DB::transaction(function () use ($organization, $inviter, $email): array {
            OrganizationInvitation::query()->where('organization_id', $organization->id)->where('email', $email)
                ->whereNull('accepted_at')->whereNull('revoked_at')->lockForUpdate()->update(['revoked_at' => now()]);
            $plain = bin2hex(random_bytes(32));
            $invitation = $organization->invitations()->create([
                'invited_by_user_id' => $inviter->id, 'email' => $email, 'role' => OrganizationRole::Member,
                'token_hash' => hash('sha256', $plain), 'expires_at' => now()->addHours(config()->integer('oast.invitation_ttl_hours')),
            ]);
            return [$invitation, $plain];
        }, 3);
        $url = route('invitations.show', ['token' => $plain]);
        try { Mail::to($email)->send(new OrganizationInvitationMail($url)); } catch (Throwable) {}
        return ['invitation' => $invitation, 'url' => $url];
    }
    public function revoke(OrganizationInvitation $invitation): void {
        DB::transaction(function () use ($invitation): void {
            $locked = OrganizationInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
            if ($locked->accepted_at !== null || $locked->revoked_at !== null) {
                throw ValidationException::withMessages(['invitation' => 'This invitation is not available.']);
            }
            $locked->update(['revoked_at' => now()]);
        }, 3);
    }
}
```

Create `app/Organizations/InvitationAcceptanceService.php`:

```php
<?php

declare(strict_types=1);
namespace App\Organizations;
use App\Identity\CanonicalEmail;
use App\Identity\RegistrationData;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
final class InvitationAcceptanceService {
    public function find(string $plain): ?OrganizationInvitation {
        $candidate = OrganizationInvitation::query()->where('token_hash', hash('sha256', $plain))->first();
        return $candidate instanceof OrganizationInvitation && hash_equals($candidate->token_hash, hash('sha256', $plain)) ? $candidate : null;
    }
    public function accept(OrganizationInvitation $invitation, User $user): void {
        DB::transaction(function () use ($invitation, $user): void {
            $locked = OrganizationInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
            OrganizationMembership::query()->where('user_id', $user->id)->lockForUpdate()->get();
            if (! $locked->available() || ! hash_equals($locked->email, CanonicalEmail::from($user->email))
                || OrganizationMembership::query()->where('user_id', $user->id)->exists()) {
                throw ValidationException::withMessages(['invitation' => 'This invitation is not available.']);
            }
            $locked->organization->memberships()->create(['user_id' => $user->id, 'role' => $locked->role]);
            $locked->update(['accepted_at' => now()]);
        }, 3);
    }
    public function registerAndAccept(OrganizationInvitation $invitation, RegistrationData $data): User {
        return DB::transaction(function () use ($invitation, $data): User {
            if (! hash_equals($invitation->email, CanonicalEmail::from($data->email))) {
                throw ValidationException::withMessages(['invitation' => 'This invitation is not available.']);
            }
            $user = User::query()->create(['name' => $data->name, 'email' => CanonicalEmail::from($data->email), 'password' => Hash::make($data->password)]);
            $this->accept($invitation, $user);
            return $user;
        }, 3);
    }
}
```

- [ ] **Step 4: Add invited Fortify registration and controllers**

Create `app/Actions/Identity/CreateNewUser.php`:

```php
<?php

declare(strict_types=1);
namespace App\Actions\Identity;
use App\Identity\CanonicalEmail;
use App\Identity\PasswordRules;
use App\Identity\RegistrationData;
use App\Identity\RegistrationPolicy;
use App\Models\User;
use App\Organizations\InvitationAcceptanceService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;
final readonly class CreateNewUser implements CreatesNewUsers {
    public function __construct(private RegistrationPolicy $policy, private InvitationAcceptanceService $invitations) {}
    /** @param array<string, string> $input */
    public function create(array $input): User {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => PasswordRules::confirmed(), 'invitation_token' => ['required', 'string', 'size:64'],
        ])->validate();
        $invitation = $this->invitations->find($input['invitation_token']);
        if ($invitation === null || ! $invitation->available()) { throw ValidationException::withMessages(['invitation' => 'This invitation is not available.']); }
        $user = $this->policy->register(new RegistrationData($input['name'], CanonicalEmail::from($input['email']), $input['password']), $invitation);
        request()->session()->forget(['oast.invitation.token', 'url.intended']);
        return $user;
    }
}
```

Add to `AppServiceProvider::register()`:

```php
$this->app->bind(App\Identity\RegistrationPolicy::class, App\Identity\SelfHostedRegistrationPolicy::class);
```

Add to `FortifyServiceProvider::boot()`:

```php
Fortify::createUsersUsing(App\Actions\Identity\CreateNewUser::class);
Fortify::registerView(fn (Request $request) => view('auth.register', [
    'token' => $request->session()->get('oast.invitation.token'),
]));
```

Add `Features::registration()` to `config/fortify.php` before reset passwords.

Create `InvitationController` and `InvitationAcceptanceController`:

```php
<?php
// InvitationController.php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\Organizations\InvitationAcceptanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
final readonly class InvitationController {
    public function __construct(private InvitationAcceptanceService $acceptance) {}
    public function show(string $token): Response {
        $invitation = $this->acceptance->find($token);
        return response()->view($invitation?->available() ? 'invitations.show' : 'invitations.unavailable', compact('token'))
            ->header('Referrer-Policy', 'no-referrer');
    }
    public function startLogin(Request $request, string $token): RedirectResponse {
        $invitation = $this->acceptance->find($token);
        if ($invitation === null || ! $invitation->available()) { return redirect()->route('invitations.show', $token); }
        $request->session()->put('oast.invitation.token', $token);
        $request->session()->put('url.intended', route('invitations.show', $token));
        return redirect()->route('login');
    }
    public function startRegistration(Request $request, string $token): RedirectResponse {
        $invitation = $this->acceptance->find($token);
        if ($invitation === null || ! $invitation->available()) { return redirect()->route('invitations.show', $token); }
        $request->session()->put('oast.invitation.token', $token);
        return redirect()->route('register');
    }
}

<?php
// InvitationAcceptanceController.php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\Models\User;
use App\Organizations\InvitationAcceptanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
final readonly class InvitationAcceptanceController {
    public function __construct(private InvitationAcceptanceService $acceptance) {}
    public function __invoke(Request $request, string $token): RedirectResponse {
        $invitation = $this->acceptance->find($token); $user = $request->user();
        if ($invitation === null || ! $user instanceof User) { throw ValidationException::withMessages(['invitation' => 'This invitation is not available.']); }
        $this->acceptance->accept($invitation, $user);
        $request->session()->forget(['oast.invitation.token', 'url.intended']);
        return redirect()->route('app.home');
    }
}
```

Add exact public routes:

```php
Route::get('/invitations/{token}', [InvitationController::class, 'show'])->where('token', '[a-f0-9]{64}')->middleware(['installation', 'throttle:30,1'])->name('invitations.show');
Route::post('/invitations/{token}/login', [InvitationController::class, 'startLogin'])->where('token', '[a-f0-9]{64}')->middleware(['installation', 'throttle:10,1'])->name('invitations.start-login');
Route::post('/invitations/{token}/register', [InvitationController::class, 'startRegistration'])->where('token', '[a-f0-9]{64}')->middleware(['installation', 'throttle:10,1'])->name('invitations.start-registration');
Route::post('/invitations/{token}/accept', InvitationAcceptanceController::class)->where('token', '[a-f0-9]{64}')->middleware(['installation', 'auth', 'throttle:10,1'])->name('invitations.accept');
```

- [ ] **Step 5: Add exact invitation/register/mail views**

```blade
{{-- resources/views/auth/register.blade.php --}}
<x-auth-layout title="Accept invitation"><h1 class="o-headline">Accept invitation</h1><x-form-errors />
<form method="POST" action="{{ route('register') }}" class="o-form">@csrf<input type="hidden" name="invitation_token" value="{{ $token }}">
<label for="name">Name</label><input class="o-input" id="name" name="name" required>
<label for="email">Email</label><input class="o-input" id="email" name="email" type="email" required>
<label for="password">Password</label><input class="o-input" id="password" name="password" type="password" required>
<label for="password_confirmation">Confirm password</label><input class="o-input" id="password_confirmation" name="password_confirmation" type="password" required>
<button class="o-btn" type="submit">Create account</button></form></x-auth-layout>

{{-- resources/views/invitations/show.blade.php --}}
<x-auth-layout title="Organization invitation"><meta name="referrer" content="no-referrer"><h1 class="o-headline">Organization invitation</h1>
@auth<form method="POST" action="{{ route('invitations.accept', $token) }}">@csrf<button class="o-btn" type="submit">Accept invitation</button></form>
@else<form method="POST" action="{{ route('invitations.start-registration', $token) }}">@csrf<button class="o-btn" type="submit">Register to accept</button></form>
<form method="POST" action="{{ route('invitations.start-login', $token) }}">@csrf<button class="o-btn o-btn-outline" type="submit">Sign in with the invited email</button></form>@endauth</x-auth-layout>

{{-- resources/views/invitations/unavailable.blade.php --}}
<x-auth-layout title="Invitation unavailable"><meta name="referrer" content="no-referrer"><h1 class="o-headline">This invitation is not available</h1>
<p>Ask an organization owner for a new invitation.</p></x-auth-layout>

{{-- resources/views/mail/organization-invitation.blade.php --}}
<p>You have been invited to an oast.sh organization.</p><p><a href="{{ $url }}">Accept invitation</a></p>
```

Create `OrganizationInvitationMail` with complete constructor/build contract:

```php
<?php

declare(strict_types=1);
namespace App\Mail;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
final class OrganizationInvitationMail extends Mailable {
    use Queueable; use SerializesModels;
    public function __construct(public readonly string $url) {}
    public function build(): self { return $this->subject('Organization invitation')->view('mail.organization-invitation'); }
}
```

- [ ] **Step 6: Extend the process helper and add race cases**

The Task 4 `tests/Fixtures/m3a-race.php` already contains `accept` and `revoke` branches plus exact exit mapping: winner `0`, invariant loser `42`, SQLite lock/busy failure `70`, and unexpected failure `1`.

Add these complete race tests to `InvitationFlowTest.php` (imports: `OrganizationInvitation`, `OrganizationMembership`, `User`, `DB`, `Process`, and `FileDatabaseProcess`):

```php
it('allows exactly one concurrent invitation acceptance', function (): void {
    $database = tempnam(sys_get_temp_dir(), 'oast-invite-');
    expect($database)->toBeString();
    try {
        $migrate = new Process([PHP_BINARY, 'artisan', 'migrate:fresh', '--force'], base_path(), ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database]);
        expect($migrate->run())->toBe(0, $migrate->getErrorOutput());
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database]); DB::purge('sqlite');
        [$owner, $organization] = memberFixture(role: 'owner');
        $user = User::factory()->create(['email' => 'new@example.test']);
        $invitation = OrganizationInvitation::factory()->for($organization)->for($owner, 'inviter')->create(['email' => $user->email]);
        $a = FileDatabaseProcess::start($database, ['accept', (string) $invitation->id, (string) $user->id]);
        $b = FileDatabaseProcess::start($database, ['accept', (string) $invitation->id, (string) $user->id]);
        $a->wait(); $b->wait();
        $codes = [$a->getExitCode(), $b->getExitCode()]; sort($codes);
        expect($codes)->toBe([0, 42])->not->toContain(70);
        DB::purge('sqlite');
        expect(OrganizationMembership::query()->where('user_id', $user->id)->count())->toBe(1)
            ->and(OrganizationInvitation::query()->findOrFail($invitation->id)->accepted_at)->not->toBeNull();
    } finally {
        DB::purge('sqlite'); if (is_file($database)) { unlink($database); }
    }
});

it('serializes concurrent invitation acceptance and revocation', function (): void {
    $database = tempnam(sys_get_temp_dir(), 'oast-invite-revoke-');
    expect($database)->toBeString();
    try {
        $migrate = new Process([PHP_BINARY, 'artisan', 'migrate:fresh', '--force'], base_path(), ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database]);
        expect($migrate->run())->toBe(0, $migrate->getErrorOutput());
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database]); DB::purge('sqlite');
        [$owner, $organization] = memberFixture(role: 'owner');
        $user = User::factory()->create(['email' => 'new@example.test']);
        $invitation = OrganizationInvitation::factory()->for($organization)->for($owner, 'inviter')->create(['email' => $user->email]);
        $accept = FileDatabaseProcess::start($database, ['accept', (string) $invitation->id, (string) $user->id]);
        $revoke = FileDatabaseProcess::start($database, ['revoke', (string) $invitation->id]);
        $accept->wait(); $revoke->wait();
        $codes = [$accept->getExitCode(), $revoke->getExitCode()]; sort($codes);
        expect($codes)->toBe([0, 42])->not->toContain(70);
        DB::purge('sqlite');
        $row = OrganizationInvitation::query()->findOrFail($invitation->id);
        expect(($row->accepted_at !== null) xor ($row->revoked_at !== null))->toBeTrue()
            ->and(OrganizationMembership::query()->where('user_id', $user->id)->exists())->toBe($row->accepted_at !== null);
    } finally {
        DB::purge('sqlite'); if (is_file($database)) { unlink($database); }
    }
});
```

- [ ] **Step 7: Run and commit**

Run: `vendor/bin/pest tests/Feature/InvitationFlowTest.php`

Expected: PASS, including the file-backed accept/accept and accept/revoke races.

```bash
git add app/Identity app/Actions/Identity/CreateNewUser.php app/Organizations/Invitation* app/Http/Controllers/Invitation* app/Mail app/Providers config/fortify.php routes/web.php resources/views tests/Fixtures/m3a-race.php tests/Feature/InvitationFlowTest.php
git commit -m "feat: add invitation gated registration"
```

---

### Task 7: Add final-owner-safe organization management

**Files:**

- Create: `app/Organizations/MembershipService.php`
- Create: `app/Actions/Identity/DeleteUserAction.php`
- Create: `app/Policies/OrganizationPolicy.php`, `OrganizationMembershipPolicy.php`, `OrganizationInvitationPolicy.php`
- Create: `app/Http/Requests/UpdateOrganizationRequest.php`, `CreateInvitationRequest.php`, `TransferOwnershipRequest.php`
- Create: `app/Http/Controllers/OrganizationSettingsController.php`, `MembershipController.php`, `OwnershipTransferController.php`, `OrganizationInvitationController.php`
- Create: `resources/views/app/settings/organization.blade.php`
- Modify: `routes/web.php`, `tests/Fixtures/m3a-race.php`
- Create: `tests/Unit/Organizations/MembershipServiceTest.php`, `tests/Feature/OrganizationManagementTest.php`

- [ ] **Step 1: Write service and feature tests**

Create `tests/Unit/Organizations/MembershipServiceTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\OrganizationMembership;
use App\Models\PersonalAccessToken;
use App\Organizations\MembershipService;
use Illuminate\Validation\ValidationException;

it('rejects removing or demoting the final owner', function (): void {
    [$owner, $organization, $membership] = memberFixture(role: 'owner');
    $service = app(MembershipService::class);
    expect(fn () => $service->remove($owner, $membership))->toThrow(ValidationException::class)
        ->and(fn () => $service->changeRole($owner, $membership, OrganizationRole::Member))->toThrow(ValidationException::class);
    expect($organization->memberships()->where('role', OrganizationRole::Owner)->count())->toBe(1);
});

it('revokes organization tokens when removing a member', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $member = App\Models\User::factory()->create();
    $membership = OrganizationMembership::factory()->for($organization)->for($member)->create();
    $token = PersonalAccessToken::factory()->for($member, 'tokenable')->for($organization)->create();
    app(MembershipService::class)->remove($owner, $membership);
    expect($token->refresh()->revoked_at)->not->toBeNull()->and($membership->fresh())->toBeNull();
});

it('rejects transferring ownership to the current owner', function (): void {
    [$owner, $organization, $ownerMembership] = memberFixture(role: 'owner');
    expect(fn () => app(MembershipService::class)->transferOwnership($owner, $ownerMembership))
        ->toThrow(ValidationException::class);
    expect($organization->memberships()->where('role', OrganizationRole::Owner)->count())->toBe(1);
});

it('transfers ownership in one transaction', function (): void {
    [$owner, $organization, $ownerMembership] = memberFixture(role: 'owner');
    $member = App\Models\User::factory()->create();
    $target = OrganizationMembership::factory()->for($organization)->for($member)->create();
    app(MembershipService::class)->transferOwnership($owner, $target);
    expect($ownerMembership->refresh()->role)->toBe(OrganizationRole::Member)
        ->and($target->refresh()->role)->toBe(OrganizationRole::Owner);
});
```

Create `tests/Feature/OrganizationManagementTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Installation;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\FileDatabaseProcess;

beforeEach(fn () => Installation::query()->findOrFail(1)->update(['bootstrapped_at' => now()]));

it('lets owners rename and invite while members receive 403', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $this->actingAs($owner)->patch(route('app.settings.organization.update'), ['name' => 'Renamed'])->assertRedirect();
    expect($organization->refresh()->name)->toBe('Renamed');
    $this->actingAs($owner)->post(route('app.settings.organization.invitations.store'), ['email' => 'new@example.test'])->assertRedirect();
    $member = User::factory()->create();
    OrganizationMembership::factory()->for($organization)->for($member)->create();
    $this->actingAs($member)->patch(route('app.settings.organization.update'), ['name' => 'No'])->assertForbidden();
});

it('requires password confirmation for removal and transfer', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $target = OrganizationMembership::factory()->for($organization)->for(User::factory())->create();
    $this->actingAs($owner)->delete(route('app.settings.organization.members.destroy', $target))->assertRedirect(route('password.confirm'));
    $this->actingAs($owner)->post(route('app.settings.organization.ownership.transfer'), ['membership_id' => $target->id])->assertRedirect(route('password.confirm'));
});
```

- [ ] **Step 2: Run and confirm failure**

Run: `vendor/bin/pest tests/Unit/Organizations/MembershipServiceTest.php tests/Feature/OrganizationManagementTest.php`

Expected: FAIL because management code is absent.

- [ ] **Step 3: Create the lock-disciplined service**

Create `app/Organizations/MembershipService.php`:

```php
<?php

declare(strict_types=1);
namespace App\Organizations;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
final class MembershipService {
    /** @return Collection<int, OrganizationMembership> */
    private function lockOwners(int $organizationId): Collection {
        Organization::query()->whereKey($organizationId)->lockForUpdate()->firstOrFail();
        return OrganizationMembership::query()->where('organization_id', $organizationId)->where('role', OrganizationRole::Owner)->lockForUpdate()->get();
    }
    private function assertOwner(User $actor, int $organizationId): void {
        if (! OrganizationMembership::query()->where('organization_id', $organizationId)->where('user_id', $actor->id)->where('role', OrganizationRole::Owner)->exists()) { abort(403); }
    }
    public function remove(User $actor, OrganizationMembership $target): void {
        DB::transaction(function () use ($actor, $target): void {
            $target = OrganizationMembership::query()->lockForUpdate()->findOrFail($target->id);
            $owners = $this->lockOwners($target->organization_id); $this->assertOwner($actor, $target->organization_id);
            if ($target->role === OrganizationRole::Owner && $owners->count() === 1) { throw ValidationException::withMessages(['member' => 'An organization must retain at least one owner.']); }
            PersonalAccessToken::query()->where('tokenable_type', User::class)->where('tokenable_id', $target->user_id)
                ->where('organization_id', $target->organization_id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $target->delete();
        }, 3);
    }
    public function changeRole(User $actor, OrganizationMembership $target, OrganizationRole $role): void {
        DB::transaction(function () use ($actor, $target, $role): void {
            $target = OrganizationMembership::query()->lockForUpdate()->findOrFail($target->id);
            $owners = $this->lockOwners($target->organization_id); $this->assertOwner($actor, $target->organization_id);
            if ($target->role === OrganizationRole::Owner && $role !== OrganizationRole::Owner && $owners->count() === 1) { throw ValidationException::withMessages(['member' => 'An organization must retain at least one owner.']); }
            $target->update(['role' => $role]);
        }, 3);
    }
    public function leave(User $user): void {
        $target = OrganizationMembership::query()->where('user_id', $user->id)->firstOrFail();
        $this->remove($user, $target);
    }
    public function transferOwnership(User $actor, OrganizationMembership $target): void {
        DB::transaction(function () use ($actor, $target): void {
            $target = OrganizationMembership::query()->lockForUpdate()->findOrFail($target->id);
            $this->lockOwners($target->organization_id); $this->assertOwner($actor, $target->organization_id);
            if ($target->user_id === $actor->id) { throw ValidationException::withMessages(['member' => 'Choose another member for ownership transfer.']); }
            $actorMembership = OrganizationMembership::query()->where('organization_id', $target->organization_id)->where('user_id', $actor->id)->lockForUpdate()->firstOrFail();
            $target->update(['role' => OrganizationRole::Owner]);
            $actorMembership->update(['role' => OrganizationRole::Member]);
        }, 3);
    }
}
```

- [ ] **Step 4: Add the guarded application user-deletion action**

Create `app/Actions/Identity/DeleteUserAction.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeleteUserAction
{
    public function __invoke(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $memberships = OrganizationMembership::query()->where('user_id', $user->id)
                ->orderBy('organization_id')->lockForUpdate()->get();
            foreach ($memberships->pluck('organization_id')->unique() as $organizationId) {
                Organization::query()->whereKey($organizationId)->lockForUpdate()->firstOrFail();
                $owners = OrganizationMembership::query()->where('organization_id', $organizationId)
                    ->where('role', OrganizationRole::Owner)->lockForUpdate()->get();
                if ($owners->count() === 1 && $owners->sole()->user_id === $user->id) {
                    throw ValidationException::withMessages(['user' => 'Transfer ownership before deleting this user.']);
                }
            }
            $user->delete();
        }, 3);
    }
}
```

Create `tests/Unit/Identity/DeleteUserActionTest.php`:

```php
<?php

declare(strict_types=1);

use App\Actions\Identity\DeleteUserAction;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Validation\ValidationException;

it('rejects deleting a final owner through the only app-owned deletion path', function (): void {
    [$owner] = memberFixture(role: 'owner');
    expect(fn () => app(DeleteUserAction::class)($owner))->toThrow(ValidationException::class);
    expect($owner->fresh())->not->toBeNull();
});

it('deletes a non-final member through the guarded action', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $member = User::factory()->create();
    OrganizationMembership::factory()->for($organization)->for($member)->create();
    app(DeleteUserAction::class)($member);
    expect($member->fresh())->toBeNull();
});
```

No M3A controller exposes account deletion. Future app-owned operator deletion must call this action, never `$user->delete()` directly.

- [ ] **Step 5: Add policies, requests, controllers, routes, and view**

Use these complete policy methods:

```php
// OrganizationPolicy.php
public function update(User $user, Organization $organization): bool { return $organization->memberships()->where('user_id', $user->id)->where('role', OrganizationRole::Owner)->exists(); }
// OrganizationMembershipPolicy.php
public function delete(User $user, OrganizationMembership $membership): bool { return $membership->organization->memberships()->where('user_id', $user->id)->where('role', OrganizationRole::Owner)->exists(); }
// OrganizationInvitationPolicy.php
public function create(User $user, Organization $organization): bool { return $organization->memberships()->where('user_id', $user->id)->where('role', OrganizationRole::Owner)->exists(); }
public function delete(User $user, OrganizationInvitation $invitation): bool { return $this->create($user, $invitation->organization); }
```

Create request rule bodies:

```php
// UpdateOrganizationRequest
public function authorize(): bool { return true; }
public function rules(): array { return ['name' => ['required', 'string', 'max:255']]; }
// CreateInvitationRequest
public function authorize(): bool { return true; }
public function rules(): array { return ['email' => ['required', 'email', 'max:255']]; }
// TransferOwnershipRequest
public function authorize(): bool { return true; }
public function rules(): array { return ['membership_id' => ['required', 'integer', 'exists:organization_memberships,id']]; }
```

Create controllers with these exact signatures/bodies:

```php
// OrganizationSettingsController::__invoke
public function __invoke(OrganizationContext $context): View { return view('app.settings.organization', ['organization' => $context->organization()->load('memberships.user', 'invitations')]); }
// OrganizationSettingsController::update
public function update(UpdateOrganizationRequest $request, OrganizationContext $context): RedirectResponse { $organization = $context->organization(); Gate::authorize('update', $organization); $organization->update($request->validated()); return back()->with('status', 'Organization updated.'); }
// OrganizationInvitationController::store
public function store(CreateInvitationRequest $request, OrganizationContext $context, InvitationService $service): RedirectResponse { $organization = $context->organization(); Gate::authorize('create', [OrganizationInvitation::class, $organization]); $result = $service->createOrReplace($organization, $request->user(), $request->string('email')->value()); return back()->with('invitation_url', $result['url']); }
// OrganizationInvitationController::destroy
public function destroy(OrganizationInvitation $invitation, InvitationService $service): RedirectResponse { Gate::authorize('delete', $invitation); $service->revoke($invitation); return back(); }
// MembershipController::destroy
public function destroy(Request $request, OrganizationMembership $membership, MembershipService $service): RedirectResponse { Gate::authorize('delete', $membership); $service->remove($request->user(), $membership); return back(); }
// OwnershipTransferController::__invoke
public function __invoke(TransferOwnershipRequest $request, OrganizationContext $context, MembershipService $service): RedirectResponse { $target = $context->organization()->memberships()->findOrFail($request->integer('membership_id')); $service->transferOwnership($request->user(), $target); return back(); }
```

Add exact routes inside the existing `/app` middleware group:

```php
Route::prefix('settings/organization')->name('settings.organization.')->group(function (): void {
    Route::get('/', OrganizationSettingsController::class)->name('show');
    Route::patch('/', [OrganizationSettingsController::class, 'update'])->name('update');
    Route::post('/invitations', [OrganizationInvitationController::class, 'store'])->name('invitations.store');
    Route::delete('/invitations/{invitation}', [OrganizationInvitationController::class, 'destroy'])->name('invitations.destroy');
    Route::delete('/members/{membership}', [MembershipController::class, 'destroy'])->middleware('password.confirm')->name('members.destroy');
    Route::post('/ownership', OwnershipTransferController::class)->middleware('password.confirm')->name('ownership.transfer');
});
```

Create `resources/views/app/settings/organization.blade.php`:

```blade
<x-auth-layout title="Organization settings"><h1 class="o-headline">Organization settings</h1><x-form-errors />
<form method="POST" action="{{ route('app.settings.organization.update') }}" class="o-form">@csrf @method('PATCH')
<label for="name">Organization name</label><input class="o-input" id="name" name="name" value="{{ $organization->name }}" required><button class="o-btn" type="submit">Save</button></form>
<h2 class="o-title">Members</h2><table class="o-table-management"><tbody>@foreach($organization->memberships as $membership)<tr><td>{{ $membership->user->email }}</td><td>{{ $membership->role->value }}</td><td>
<form method="POST" action="{{ route('app.settings.organization.members.destroy', $membership) }}">@csrf @method('DELETE')<button data-confirm="Remove this member?" type="submit">Remove</button></form>
@if($membership->user_id !== auth()->id())<form method="POST" action="{{ route('app.settings.organization.ownership.transfer') }}">@csrf<input type="hidden" name="membership_id" value="{{ $membership->id }}"><button type="submit">Transfer ownership</button></form>@endif
</td></tr>@endforeach</tbody></table>
<h2 class="o-title">Invitations</h2>@if(session('invitation_url'))<button type="button" data-copy="{{ session('invitation_url') }}">Copy invitation link</button>@endif
<form method="POST" action="{{ route('app.settings.organization.invitations.store') }}">@csrf<label for="invite_email">Email</label><input class="o-input" id="invite_email" name="email" type="email" required><button class="o-btn" type="submit">Invite</button></form>
@foreach($organization->invitations as $invitation)<form method="POST" action="{{ route('app.settings.organization.invitations.destroy', $invitation) }}">@csrf @method('DELETE')<span>{{ $invitation->email }}</span><button type="submit">Revoke</button></form>@endforeach
</x-auth-layout>
```

Condition owner controls in this view with `@can` directives around the rename/invite/remove/transfer forms; do not rely on hiding controls for authorization.

- [ ] **Step 6: Add the membership race operation**

The Task 4 child script already contains `demote` and `remove` operations and maps invariant rejection to `42` and SQLite lock/busy failure to `70`.

Add this complete test to `tests/Feature/OrganizationManagementTest.php` (imports: `OrganizationRole`, `OrganizationMembership`, `User`, `DB`, `Process`, and `FileDatabaseProcess`):

```php
it('serializes concurrent owner demotion and removal without lock-error credit', function (): void {
    $database = tempnam(sys_get_temp_dir(), 'oast-owner-race-');
    expect($database)->toBeString();
    try {
        $migrate = new Process([PHP_BINARY, 'artisan', 'migrate:fresh', '--force'], base_path(), ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database]);
        expect($migrate->run())->toBe(0, $migrate->getErrorOutput());
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database]); DB::purge('sqlite');
        [$firstOwner, $organization, $firstMembership] = memberFixture(role: 'owner');
        $secondOwner = User::factory()->create();
        $secondMembership = OrganizationMembership::factory()->for($organization)->for($secondOwner)
            ->create(['role' => OrganizationRole::Owner]);
        $demote = FileDatabaseProcess::start($database, ['demote', (string) $secondOwner->id, (string) $firstMembership->id]);
        $remove = FileDatabaseProcess::start($database, ['remove', (string) $firstOwner->id, (string) $secondMembership->id]);
        $demote->wait(); $remove->wait();
        $codes = [$demote->getExitCode(), $remove->getExitCode()]; sort($codes);
        expect($codes)->toBe([0, 42])->not->toContain(70);
        DB::purge('sqlite');
        expect(OrganizationMembership::query()->where('organization_id', $organization->id)
            ->where('role', OrganizationRole::Owner)->count())->toBe(1);
    } finally {
        DB::purge('sqlite'); if (is_file($database)) { unlink($database); }
    }
});
```

Run the same semantic race against PostgreSQL before release. Expected exit codes and final-owner count are identical.

- [ ] **Step 7: Run and commit**

Run: `vendor/bin/pest tests/Unit/Organizations/MembershipServiceTest.php tests/Unit/Identity/DeleteUserActionTest.php tests/Feature/OrganizationManagementTest.php`

Expected: PASS, including one owner after the process race.

```bash
git add app/Organizations/MembershipService.php app/Actions/Identity/DeleteUserAction.php app/Policies app/Http/Requests app/Http/Controllers resources/views/app/settings/organization.blade.php routes/web.php tests/Fixtures/m3a-race.php tests/Unit/Organizations tests/Unit/Identity/DeleteUserActionTest.php tests/Feature/OrganizationManagementTest.php
git commit -m "feat: add safe organization management"
```

---

### Task 8: Add organization-scoped tokens and account settings

**Files:**

- Create: `app/Tokens/TokenAbilities.php`, `PersonalAccessTokenService.php`
- Create: `app/Listeners/TouchPersonalAccessTokenLastUsed.php`
- Create: `app/Actions/Identity/UpdateUserProfileInformation.php`, `UpdateUserPassword.php`
- Create: `app/Http/Requests/CreatePersonalAccessTokenRequest.php`, `UpdateProfileRequest.php`, `UpdateAccountPasswordRequest.php`
- Create: `app/Http/Controllers/TokenSettingsController.php`, `AccountSettingsController.php`, `AccountPasswordController.php`
- Modify: `app/Providers/AppServiceProvider.php`, `app/Providers/FortifyServiceProvider.php`, `routes/web.php`, `resources/views/app/no-organization.blade.php`
- Create: `resources/views/app/settings/tokens.blade.php`, `account.blade.php`
- Create: `tests/Feature/TokenManagementTest.php`, `AccountSettingsTest.php`, `tests/Unit/Tokens/TouchPersonalAccessTokenLastUsedTest.php`

- [ ] **Step 1: Write token, one-time rendering, and email-notification tests**

Create `tests/Feature/TokenManagementTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Installation;
use App\Models\PersonalAccessToken;

beforeEach(fn () => Installation::query()->findOrFail(1)->update(['bootstrapped_at' => now()]));

it('renders plaintext once on a private no-store get and never renders its hash', function (): void {
    [$owner] = memberFixture(role: 'owner');
    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()]);
    $post = $this->post(route('app.settings.tokens.store'), ['name' => 'CI', 'expires_at' => '']);
    $post->assertRedirect(route('app.settings.tokens.index'));
    $token = PersonalAccessToken::query()->sole();
    $get = $this->get(route('app.settings.tokens.index'));
    $get->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    preg_match('/\d+\|[A-Za-z0-9]+/', $get->getContent(), $matches);
    expect($matches)->toHaveCount(1)->and(hash('sha256', explode('|', $matches[0], 2)[1]))->toBe($token->token);
    $this->get(route('app.settings.tokens.index'))->assertDontSee($matches[0])->assertDontSee($token->token);
});

it('requires password confirmation for token creation and revocation', function (): void {
    [$owner] = memberFixture(role: 'owner');
    $this->actingAs($owner)->post(route('app.settings.tokens.store'), ['name' => 'CI'])->assertRedirect(route('password.confirm'));
});

it('uses fixed abilities and immutable organization scope', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('app.settings.tokens.store'), ['name' => 'CI']);
    $token = PersonalAccessToken::query()->sole();
    expect($token->organization_id)->toBe($organization->id)
        ->and($token->abilities)->toBe(['review:create', 'review:read', 'review:follow']);
});
```

Create `tests/Feature/AccountSettingsTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

it('requires recent confirmation and updates password through the app route', function (): void {
    [$user] = memberFixture();
    $payload = [
        'current_password' => 'password',
        'password' => 'new correct horse battery staple',
        'password_confirmation' => 'new correct horse battery staple',
    ];
    $this->actingAs($user)->put(route('app.settings.account.password.update'), $payload)
        ->assertRedirect(route('password.confirm'));
    $this->withSession(['auth.password_confirmed_at' => time()])
        ->put(route('app.settings.account.password.update'), $payload)->assertRedirect();
    expect(Hash::check($payload['password'], $user->refresh()->password))->toBeTrue();
});

it('resets verification and notifies only when enforcement is enabled', function (bool $enabled, int $sent): void {
    Notification::fake(); [$user] = memberFixture();
    config(['oast.enforce_email_verification' => $enabled]);
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->patch(route('app.settings.account.update'), ['name' => 'Changed', 'email' => ' NEW@EXAMPLE.TEST '])->assertRedirect();
    expect($user->refresh()->email)->toBe('new@example.test')->and($user->email_verified_at)->toBeNull();
    Notification::assertSentToTimes($user, VerifyEmail::class, $sent);
})->with([[true, 1], [false, 0]]);
```

Create `tests/Unit/Tokens/TouchPersonalAccessTokenLastUsedTest.php`:

```php
<?php

declare(strict_types=1);

use App\Listeners\TouchPersonalAccessTokenLastUsed;
use App\Models\PersonalAccessToken;
use Laravel\Sanctum\Events\TokenAuthenticated;

it('touches at most once per minute', function (): void {
    $token = PersonalAccessToken::factory()->create(['last_used_at' => null]);
    $listener = new TouchPersonalAccessTokenLastUsed;
    $listener->handle(new TokenAuthenticated($token)); $first = $token->refresh()->last_used_at;
    $listener->handle(new TokenAuthenticated($token)); expect($token->refresh()->last_used_at->equalTo($first))->toBeTrue();
    $token->updateQuietly(['last_used_at' => now()->subSeconds(61)]);
    $listener->handle(new TokenAuthenticated($token)); expect($token->refresh()->last_used_at->greaterThan($first))->toBeTrue();
    expect(config('sanctum.last_used_at'))->toBeFalse()->and(config('sanctum.guard'))->toBe([]);
});
```

- [ ] **Step 2: Run and confirm failure**

Run: `vendor/bin/pest tests/Feature/TokenManagementTest.php tests/Feature/AccountSettingsTest.php tests/Unit/Tokens/TouchPersonalAccessTokenLastUsedTest.php`

Expected: FAIL because token/account services and routes are absent.

- [ ] **Step 3: Add token service, Sanctum validation, and last-used listener**

Create `TokenAbilities`, `PersonalAccessTokenService`, and listener:

```php
<?php
// TokenAbilities.php
declare(strict_types=1); namespace App\Tokens;
final class TokenAbilities { /** @return list<string> */ public static function all(): array { return ['review:create', 'review:read', 'review:follow']; } }

<?php
// PersonalAccessTokenService.php
declare(strict_types=1); namespace App\Tokens;
use App\Models\Organization; use App\Models\PersonalAccessToken; use App\Models\User;
use Carbon\CarbonImmutable; use Illuminate\Support\Str; use Laravel\Sanctum\NewAccessToken;
final class PersonalAccessTokenService {
    public function create(User $user, Organization $organization, string $name, ?CarbonImmutable $expiresAt): NewAccessToken {
        $plain = Str::random(40);
        $token = $user->tokens()->create(['organization_id' => $organization->id, 'name' => $name, 'token' => hash('sha256', $plain), 'abilities' => TokenAbilities::all(), 'expires_at' => $expiresAt]);
        assert($token instanceof PersonalAccessToken);
        return new NewAccessToken($token, $token->getKey().'|'.$plain);
    }
    public function revoke(PersonalAccessToken $token): void { $token->forceFill(['revoked_at' => now()])->saveQuietly(); }
}

<?php
// TouchPersonalAccessTokenLastUsed.php
declare(strict_types=1); namespace App\Listeners;
use App\Models\PersonalAccessToken; use Laravel\Sanctum\Events\TokenAuthenticated;
final class TouchPersonalAccessTokenLastUsed {
    public function handle(TokenAuthenticated $event): void {
        if (! $event->token instanceof PersonalAccessToken) { return; }
        PersonalAccessToken::query()->whereKey($event->token->id)
            ->where(fn ($query) => $query->whereNull('last_used_at')->orWhere('last_used_at', '<=', now()->subMinute()))
            ->update(['last_used_at' => now()]);
    }
}
```

Add to `AppServiceProvider::boot()`:

```php
Laravel\Sanctum\Sanctum::usePersonalAccessTokenModel(App\Models\PersonalAccessToken::class);
Laravel\Sanctum\Sanctum::authenticateAccessTokensUsing(function (App\Models\PersonalAccessToken $token, bool $valid): bool {
    return $valid && $token->revoked_at === null
        && ($token->expires_at === null || $token->expires_at->isFuture())
        && App\Models\OrganizationMembership::query()->where('user_id', $token->tokenable_id)
            ->where('organization_id', $token->organization_id)->exists();
});
Illuminate\Support\Facades\Event::listen(
    Laravel\Sanctum\Events\TokenAuthenticated::class,
    App\Listeners\TouchPersonalAccessTokenLastUsed::class,
);
```

- [ ] **Step 4: Add profile/password actions with conditional verification mail**

Create `UpdateUserProfileInformation.php` and `UpdateUserPassword.php`:

```php
<?php
// UpdateUserProfileInformation.php
declare(strict_types=1); namespace App\Actions\Identity;
use App\Identity\CanonicalEmail; use App\Models\User; use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;
final class UpdateUserProfileInformation implements UpdatesUserProfileInformation {
    public function update(User $user, array $input): void {
        $email = CanonicalEmail::from((string) $input['email']);
        Validator::make([...$input, 'email' => $email], ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$user->id]])->validate();
        $changed = ! hash_equals($user->email, $email);
        $user->forceFill(['name' => $input['name'], 'email' => $email, 'email_verified_at' => $changed ? null : $user->email_verified_at])->save();
        if ($changed && config()->boolean('oast.enforce_email_verification')) { $user->sendEmailVerificationNotification(); }
    }
}

<?php
// UpdateUserPassword.php
declare(strict_types=1); namespace App\Actions\Identity;
use App\Identity\PasswordRules; use App\Models\User; use Illuminate\Support\Facades\Hash; use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;
final class UpdateUserPassword implements UpdatesUserPasswords {
    public function update(User $user, array $input): void {
        Validator::make($input, ['current_password' => ['required', 'current_password:web'], 'password' => PasswordRules::confirmed()])->validate();
        $user->forceFill(['password' => Hash::make($input['password'])])->save();
    }
}
```

Register both in `FortifyServiceProvider::boot()`:

```php
Fortify::updateUserProfileInformationUsing(App\Actions\Identity\UpdateUserProfileInformation::class);
Fortify::updateUserPasswordsUsing(App\Actions\Identity\UpdateUserPassword::class);
```

- [ ] **Step 5: Add controllers, requests, exact routes, and one-time GET behavior**

Create request rules:

```php
// CreatePersonalAccessTokenRequest
public function authorize(): bool { return true; }
public function rules(): array { return ['name' => ['required', 'string', 'max:255'], 'expires_at' => ['nullable', 'date', 'after:now']]; }
// UpdateProfileRequest
public function authorize(): bool { return true; }
public function rules(): array { return ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255']]; }
// UpdateAccountPasswordRequest
public function authorize(): bool { return true; }
public function rules(): array { return [
    'current_password' => ['required', 'current_password:web'],
    'password' => App\Identity\PasswordRules::confirmed(),
]; }
```

Create `TokenSettingsController` and `AccountSettingsController`:

```php
<?php
// TokenSettingsController.php
declare(strict_types=1); namespace App\Http\Controllers;
use App\Http\Requests\CreatePersonalAccessTokenRequest; use App\Models\PersonalAccessToken; use App\Organizations\OrganizationContext; use App\Tokens\PersonalAccessTokenService;
use Carbon\CarbonImmutable; use Illuminate\Http\RedirectResponse; use Illuminate\Http\Request; use Illuminate\Http\Response;
final class TokenSettingsController {
    public function index(Request $request, OrganizationContext $context): Response {
        $plain = $request->session()->pull('oast.new_token');
        $response = response()->view('app.settings.tokens', ['tokens' => $request->user()->tokens()->where('organization_id', $context->organization()->id)->latest()->get(), 'plainToken' => $plain]);
        $response->headers->set('Cache-Control', 'no-store, private');
        return $response;
    }
    public function store(CreatePersonalAccessTokenRequest $request, OrganizationContext $context, PersonalAccessTokenService $service): RedirectResponse {
        $expires = $request->filled('expires_at') ? CarbonImmutable::parse($request->string('expires_at')->value()) : null;
        $created = $service->create($request->user(), $context->organization(), $request->string('name')->value(), $expires);
        return redirect()->route('app.settings.tokens.index')->with('oast.new_token', $created->plainTextToken);
    }
    public function destroy(Request $request, PersonalAccessToken $token, OrganizationContext $context, PersonalAccessTokenService $service): RedirectResponse {
        abort_unless($token->tokenable_id === $request->user()->id && $token->organization_id === $context->organization()->id, 404);
        $service->revoke($token); return back();
    }
}

<?php
// AccountSettingsController.php
declare(strict_types=1); namespace App\Http\Controllers;
use App\Actions\Identity\UpdateUserProfileInformation; use App\Http\Requests\UpdateProfileRequest; use Illuminate\Http\RedirectResponse; use Illuminate\Http\Request; use Illuminate\View\View;
final class AccountSettingsController {
    public function show(Request $request): View { return view('app.settings.account', ['user' => $request->user()]); }
    public function update(UpdateProfileRequest $request, UpdateUserProfileInformation $action): RedirectResponse { $action->update($request->user(), $request->validated()); return back()->with('status', 'Account updated.'); }
}

<?php
// AccountPasswordController.php
declare(strict_types=1); namespace App\Http\Controllers;
use App\Actions\Identity\UpdateUserPassword; use App\Http\Requests\UpdateAccountPasswordRequest; use Illuminate\Http\RedirectResponse;
final class AccountPasswordController {
    public function __invoke(UpdateAccountPasswordRequest $request, UpdateUserPassword $action): RedirectResponse {
        $action->update($request->user(), $request->validated());
        return back()->with('status', 'Password updated.');
    }
}
```

Replace the `/app` routes with two groups so zero-membership users can reach account settings:

```php
Route::prefix('app')->name('app.')->middleware(['installation', 'auth', 'verified.configured'])->group(function (): void {
    Route::get('/settings/account', [AccountSettingsController::class, 'show'])->name('settings.account.show');
    Route::patch('/settings/account', [AccountSettingsController::class, 'update'])->middleware('password.confirm')->name('settings.account.update');
    Route::put('/settings/account/password', AccountPasswordController::class)->middleware('password.confirm')->name('settings.account.password.update');
    Route::middleware('organization')->group(function (): void {
        Route::view('/', 'app.home')->name('home');
        Route::get('/settings/tokens', [TokenSettingsController::class, 'index'])->name('settings.tokens.index');
        Route::post('/settings/tokens', [TokenSettingsController::class, 'store'])->middleware('password.confirm')->name('settings.tokens.store');
        Route::delete('/settings/tokens/{token}', [TokenSettingsController::class, 'destroy'])->middleware('password.confirm')->name('settings.tokens.destroy');
        // retain Task 7 organization route group here unchanged
    });
});
```

Update `app/no-organization.blade.php` to include `<a href="{{ route('app.settings.account.show') }}">Manage account</a>`.

- [ ] **Step 6: Create complete focused settings views**

```blade
{{-- resources/views/app/settings/tokens.blade.php --}}
<x-auth-layout title="Access tokens"><h1 class="o-headline">Access tokens</h1><x-form-errors />
@if(is_string($plainToken))<div class="o-confirm-box"><code>{{ $plainToken }}</code><button type="button" data-copy="{{ $plainToken }}">Copy token</button></div>@endif
<form method="POST" action="{{ route('app.settings.tokens.store') }}" class="o-form">@csrf<label for="token_name">Name</label><input class="o-input" id="token_name" name="name" required>
<label for="expires_at">Expires at</label><input class="o-input" id="expires_at" name="expires_at" type="datetime-local"><button class="o-btn" type="submit">Create token</button></form>
@foreach($tokens as $token)<div><span>{{ $token->name }}</span><span>{{ implode(', ', $token->abilities ?? []) }}</span><span>{{ $token->last_used_at }}</span>
<form method="POST" action="{{ route('app.settings.tokens.destroy', $token) }}">@csrf @method('DELETE')<button data-confirm="Revoke this token?" type="submit">Revoke</button></form></div>@endforeach</x-auth-layout>

{{-- resources/views/app/settings/account.blade.php --}}
<x-auth-layout title="Account settings"><h1 class="o-headline">Account settings</h1><x-form-errors />
<form method="POST" action="{{ route('app.settings.account.update') }}" class="o-form">@csrf @method('PATCH')<label for="name">Name</label><input class="o-input" id="name" name="name" value="{{ $user->name }}" required>
<label for="email">Email</label><input class="o-input" id="email" name="email" type="email" value="{{ $user->email }}" required><button class="o-btn" type="submit">Save account</button></form>
<form method="POST" action="{{ route('app.settings.account.password.update') }}" class="o-form">@csrf @method('PUT')<label for="current_password">Current password</label><input class="o-input" id="current_password" name="current_password" type="password" required>
<label for="password">New password</label><input class="o-input" id="password" name="password" type="password" required><label for="password_confirmation">Confirm password</label><input class="o-input" id="password_confirmation" name="password_confirmation" type="password" required><button class="o-btn" type="submit">Change password</button></form></x-auth-layout>
```

- [ ] **Step 7: Run and commit**

Run: `vendor/bin/pest tests/Feature/TokenManagementTest.php tests/Feature/AccountSettingsTest.php tests/Unit/Tokens/TouchPersonalAccessTokenLastUsedTest.php`

Expected: PASS; the redirect target GET, not only the POST, has `Cache-Control: no-store, private`, and the second GET contains neither plaintext nor hash.

```bash
git add app/Tokens app/Listeners app/Actions/Identity app/Http/Requests app/Http/Controllers app/Providers routes/web.php resources/views/app tests/Feature/TokenManagementTest.php tests/Feature/AccountSettingsTest.php tests/Unit/Tokens
git commit -m "feat: add account and scoped tokens"
```

---

### Task 9: Make the API bearer-only and render RFC 9457 auth/rate errors

**Files:**

- Delete: `app/Http/Middleware/EnsureApiEnabled.php`, `tests/Feature/ApiGateTest.php`
- Modify: `config/oast.php`, `.env.example`, `routes/api.php`, `bootstrap/app.php`
- Modify: `app/Http/Problems/ProblemType.php`, `ProblemResponse.php`
- Create: `tests/Feature/ApiAuthenticationTest.php`

- [ ] **Step 1: Write bearer-only API tests**

Create `tests/Feature/ApiAuthenticationTest.php`:

```php
<?php

declare(strict_types=1);

use App\Http\Problems\ProblemType;
use App\Models\PersonalAccessToken;
use App\Tokens\PersonalAccessTokenService;
use Illuminate\Support\Facades\Bus;

it('rejects anonymous and session-only API requests with problem details', function (): void {
    $this->postJson("https://{$this->apiHost()}/reviews", ['spec' => 'openapi: 3.1.0'])
        ->assertUnauthorized()->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', ProblemType::Unauthenticated->value);
    [$user] = memberFixture();
    $this->actingAs($user)->postJson("https://{$this->apiHost()}/reviews", ['spec' => 'openapi: 3.1.0'])->assertUnauthorized();
});

it('accepts only a valid organization token with the required ability', function (): void {
    Bus::fake(); [$user, $organization] = memberFixture();
    $created = app(PersonalAccessTokenService::class)->create($user, $organization, 'CI', null);
    $this->withToken($created->plainTextToken)->postJson("https://{$this->apiHost()}/reviews", ['spec' => 'openapi: 3.1.0'])->assertAccepted();
});

it('returns problem details when the token lacks the required ability', function (): void {
    [$user, $organization] = memberFixture();
    $created = app(PersonalAccessTokenService::class)->create($user, $organization, 'read-only', null);
    $created->accessToken->forceFill(['abilities' => ['review:read']])->saveQuietly();
    $this->withToken($created->plainTextToken)->postJson("https://{$this->apiHost()}/reviews", ['spec' => 'openapi: 3.1.0'])
        ->assertForbidden()->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', ProblemType::Forbidden->value);
});

it('rejects revoked expired and membership-orphaned tokens', function (string $state): void {
    [$user, $organization, $membership] = memberFixture();
    $created = app(PersonalAccessTokenService::class)->create($user, $organization, 'CI', null);
    $token = $created->accessToken;
    if ($state === 'revoked') { $token->updateQuietly(['revoked_at' => now()]); }
    if ($state === 'expired') { $token->updateQuietly(['expires_at' => now()->subMinute()]); }
    if ($state === 'orphaned') { $membership->delete(); }
    $this->withToken($created->plainTextToken)->getJson("https://{$this->apiHost()}/reviews/999")->assertUnauthorized();
})->with(['revoked', 'expired', 'orphaned']);
```

- [ ] **Step 2: Run and confirm failure**

Run: `vendor/bin/pest tests/Feature/ApiAuthenticationTest.php`

Expected: FAIL because the API remains config-gated and anonymous.

- [ ] **Step 3: Replace the exact API group and remove the flag**

Replace `routes/api.php` with:

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\ReviewController;
use App\Http\Controllers\ReviewEventsController;
use App\Http\Controllers\ShowReviewController;
use Illuminate\Support\Facades\Route;

Route::domain(config()->string('oast.api_domain'))->middleware('auth:sanctum')->group(function (): void {
    Route::post('/reviews', [ReviewController::class, 'store'])->middleware('abilities:review:create')->name('api.reviews.store');
    Route::get('/reviews/{review}', ShowReviewController::class)->middleware('abilities:review:read')->name('api.reviews.show');
    Route::get('/reviews/{review}/events', ReviewEventsController::class)->middleware('abilities:review:follow')->name('api.reviews.events');
});
```

Delete `EnsureApiEnabled`, `ApiGateTest`, `oast.api_enabled`, and every `OAST_API_ENABLED` line.

- [ ] **Step 4: Add problem types and API-only renderers**

Add enum cases:

```php
case Unauthenticated = 'https://oast.sh/problems/unauthenticated';
case Forbidden = 'https://oast.sh/problems/forbidden';
case RateLimited = 'https://oast.sh/problems/rate-limited';
```

Extend `ProblemResponse::from()` to accept headers:

```php
/** @param array<string, string> $headers */
public static function from(ApiProblem $problem, int $status, array $headers = []): Response
{
    $problem->setStatus($status);
    return new Response($problem->asJson(), $status, ['Content-Type' => 'application/problem+json', ...$headers]);
}
```

Add imports and renderers inside `withExceptions()` in `bootstrap/app.php`:

```php
$exceptions->render(function (Illuminate\Auth\AuthenticationException $e, Request $request) use ($onApi): ?Response {
    if (! $onApi($request)) { return null; }
    return ProblemResponse::from((new ApiProblem('Unauthenticated', ProblemType::Unauthenticated->value))->setDetail('A valid bearer token is required.'), 401);
});
$exceptions->render(function (Illuminate\Auth\Access\AuthorizationException|Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e, Request $request) use ($onApi): ?Response {
    if (! $onApi($request)) { return null; }
    return ProblemResponse::from((new ApiProblem('Forbidden', ProblemType::Forbidden->value))->setDetail('The credential cannot perform this action.'), 403);
});
$exceptions->render(function (Illuminate\Http\Exceptions\ThrottleRequestsException $e, Request $request) use ($onApi): ?Response {
    if (! $onApi($request)) { return null; }
    $retry = (string) ($e->getHeaders()['Retry-After'] ?? '60');
    return ProblemResponse::from((new ApiProblem('Rate limited', ProblemType::RateLimited->value))->setDetail('Too many requests.'), 429, ['Retry-After' => $retry]);
});
```

- [ ] **Step 5: Run and commit**

Run: `vendor/bin/pest tests/Feature/ApiAuthenticationTest.php`

Expected: PASS; a browser session on `api.oast.test` is still 401 because `sanctum.guard=[]` and the API host is absent from `stateful`.

```bash
git add -A app/Http config/oast.php config/sanctum.php .env.example bootstrap/app.php routes/api.php tests/Feature
git commit -m "feat: require bearer API authentication"
```

---

### Task 10: Scope review creation, lookup, limits, and deletion

**Files:**

- Modify: `app/Actions/Reviews/CreateReviewAction.php`, `app/Http/Controllers/ReviewController.php`, `ShowReviewController.php`
- Create: `app/Reviews/ScopedReviewResolver.php`, `ActiveReviewLimitExceeded.php`
- Create: `app/Policies/ReviewPolicy.php`, `app/Actions/Reviews/DeleteReviewAction.php`, `app/Http/Controllers/DeleteReviewController.php`
- Modify: `routes/web.php`, `bootstrap/app.php`, current review tests
- Create: `tests/Feature/ReviewAuthorizationTest.php`, `ReviewDeletionTest.php`, `AbuseControlsTest.php`

- [ ] **Step 1: Write review isolation tests**

Create `tests/Feature/ReviewAuthorizationTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Review;
use App\Tokens\PersonalAccessTokenService;
use Illuminate\Support\Facades\Bus;

it('derives organization and creator from the token and ignores hostile ids', function (): void {
    Bus::fake(); [$user, $organization] = memberFixture(); [, $other] = memberFixture();
    $token = app(PersonalAccessTokenService::class)->create($user, $organization, 'CI', null);
    $response = $this->withToken($token->plainTextToken)->postJson("https://{$this->apiHost()}/reviews", [
        'spec' => 'openapi: 3.1.0', 'organization_id' => $other->id, 'created_by_user_id' => 999,
    ])->assertAccepted();
    $review = Review::query()->findOrFail($response->json('data.id'));
    expect($review->organization_id)->toBe($organization->id)->and($review->created_by_user_id)->toBe($user->id);
});

it('returns the identical 404 for unknown and cross organization ids', function (): void {
    [$user, $organization] = memberFixture(); [, $other] = memberFixture();
    $token = app(PersonalAccessTokenService::class)->create($user, $organization, 'CI', null);
    $cross = Review::factory()->for($other)->create();
    $a = $this->withToken($token->plainTextToken)->getJson("https://{$this->apiHost()}/reviews/{$cross->id}");
    $b = $this->withToken($token->plainTextToken)->getJson("https://{$this->apiHost()}/reviews/999999");
    $a->assertNotFound(); $b->assertNotFound(); expect($a->getContent())->toBe($b->getContent());
});
```

Create `tests/Feature/AbuseControlsTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Review;
use App\Tokens\PersonalAccessTokenService;
use Illuminate\Support\Facades\Bus;

it('serializes and rejects the organization active-review ceiling for API and browser', function (): void {
    Bus::fake(); config(['oast.max_active_reviews' => 1]); [$user, $organization] = memberFixture();
    App\Models\Installation::query()->findOrFail(1)->update(['bootstrapped_at' => now()]);
    Review::factory()->for($organization)->create(['status' => 'judging']);
    $token = app(PersonalAccessTokenService::class)->create($user, $organization, 'CI', null);
    $this->withToken($token->plainTextToken)->postJson("https://{$this->apiHost()}/reviews", ['spec' => 'openapi: 3.1.0'])
        ->assertTooManyRequests()->assertHeader('Retry-After', '60')
        ->assertHeader('Content-Type', 'application/problem+json');
    $this->actingAs($user)->post(route('app.reviews.store'), ['spec' => 'openapi: 3.1.0'])
        ->assertTooManyRequests()->assertHeader('Retry-After', '60')->assertSee('Too many active reviews.');
    expect(Review::query()->where('organization_id', $organization->id)->count())->toBe(1);
});
```

Create `tests/Feature/ReviewDeletionTest.php` with exact matrix:

```php
<?php

declare(strict_types=1);

use App\Models\Review;
use App\Models\ReviewPanelResponse;

it('allows creator or owner deletion and restricts creatorless reviews to owner', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $member = App\Models\User::factory()->create();
    App\Models\OrganizationMembership::factory()->for($organization)->for($member)->create();
    $own = Review::factory()->for($organization)->create(['created_by_user_id' => $member->id]);
    $legacy = Review::factory()->for($organization)->create(['created_by_user_id' => null]);
    $this->actingAs($member)->delete(route('app.reviews.destroy', $own))->assertNoContent();
    $this->actingAs($member)->delete(route('app.reviews.destroy', $legacy))->assertForbidden();
    $this->actingAs($owner)->delete(route('app.reviews.destroy', $legacy))->assertNoContent();
});

it('cascades events and panel responses', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $review = Review::factory()->for($organization)->create();
    $review->appendEvent('review.queued', []);
    $review->panelResponses()->create(['model' => 'a/one', 'ok' => true, 'ms' => 1, 'late' => false]);
    $this->actingAs($owner)->delete(route('app.reviews.destroy', $review))->assertNoContent();
    expect(App\Models\ReviewEvent::query()->count())->toBe(0)->and(ReviewPanelResponse::query()->count())->toBe(0);
});
```

- [ ] **Step 2: Run and confirm failure**

Run: `vendor/bin/pest tests/Feature/ReviewAuthorizationTest.php tests/Feature/AbuseControlsTest.php tests/Feature/ReviewDeletionTest.php`

Expected: FAIL because review ownership and scoped resolution are absent.

- [ ] **Step 3: Replace `CreateReviewAction` completely, retaining the existing roster and exact batch dispatch**

Replace `app/Actions/Reviews/CreateReviewAction.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Council\Dimension;
use App\Council\PanelFinalizer;
use App\Council\ReviewMode;
use App\Jobs\RunPanelist;
use App\Models\Organization;
use App\Models\Review;
use App\Models\User;
use App\Reviews\ActiveReviewLimitExceeded;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

final readonly class CreateReviewAction
{
    public function __invoke(
        string $spec,
        ReviewMode $mode,
        Organization $organization,
        ?User $creator,
        ?string $specRef = null,
        Dimension $dimension = Dimension::DomainModeling,
    ): Review {
        $panelists = $mode === ReviewMode::Baseline ? [$this->baselineModel()] : $this->panelistRoster();
        return DB::transaction(function () use ($spec, $mode, $organization, $creator, $specRef, $dimension, $panelists): Review {
            Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $active = Review::query()->where('organization_id', $organization->id)
                ->whereIn('status', ['queued', 'running', 'judging'])->count();
            if ($active >= config()->integer('oast.max_active_reviews')) { throw new ActiveReviewLimitExceeded(60); }
            $review = new Review([
                'spec_ref' => $specRef, 'spec_hash' => hash('sha256', $spec), 'spec' => $spec,
                'mode' => $mode->value, 'dimension' => $dimension->value, 'panelists' => $panelists,
                'panel_size' => 0, 'status' => 'running',
            ]);
            $review->organization()->associate($organization); $review->creator()->associate($creator); $review->save();
            $review->appendEvent('review.queued', ['mode' => $mode->value, 'dimension' => $dimension->value, 'panelists' => $panelists]);
            DB::afterCommit(fn () => $this->dispatchPanel($review->id, $panelists, $dimension));
            return $review;
        }, 3);
    }

    /** @param list<string> $panelists */
    private function dispatchPanel(int $reviewId, array $panelists, Dimension $dimension): void
    {
        Bus::batch(
            collect($panelists)->map(fn (string $model): RunPanelist => new RunPanelist($reviewId, $model, $dimension))->all(),
        )->name('review:'.$reviewId)->allowFailures()
            ->finally(fn (Batch $batch) => new PanelFinalizer()->finalize($reviewId, $dimension))->dispatch();
    }

    /** @return list<string> */
    private function panelistRoster(): array
    {
        $panelists = config('oast.panelists');
        return is_array($panelists) ? array_values(array_filter($panelists, is_string(...))) : [];
    }

    private function baselineModel(): string
    {
        $baseline = config('oast.baseline');
        return is_string($baseline) ? $baseline : ($this->panelistRoster()[0] ?? '');
    }
}
```

Create `ActiveReviewLimitExceeded`:

```php
<?php

declare(strict_types=1); namespace App\Reviews;
use RuntimeException;
final class ActiveReviewLimitExceeded extends RuntimeException { public function __construct(public readonly int $retryAfter) { parent::__construct('Active review limit exceeded.'); } }
```

Add this renderer for both API and browser requests:

```php
$exceptions->render(function (ActiveReviewLimitExceeded $e, Request $request) use ($onApi): Response {
    $headers = ['Retry-After' => (string) $e->retryAfter];
    if (! $onApi($request)) { return response('Too many active reviews.', 429, $headers); }
    return ProblemResponse::from(
        (new ApiProblem('Rate limited', ProblemType::RateLimited->value))->setDetail('Too many active reviews.'),
        429,
        $headers,
    );
});
```

- [ ] **Step 4: Add scoped resolver, policy, deletion, and exact routes**

Create `ScopedReviewResolver.php`:

```php
<?php

declare(strict_types=1); namespace App\Reviews;
use App\Models\Review; use App\Organizations\OrganizationContext;
final readonly class ScopedReviewResolver { public function __construct(private OrganizationContext $context) {} public function findOrFail(int|string $id): Review { return Review::query()->where('organization_id', $this->context->organization()->id)->findOrFail($id); } }
```

Create `ReviewPolicy.php`:

```php
<?php

declare(strict_types=1); namespace App\Policies;
use App\Enums\OrganizationRole; use App\Models\OrganizationMembership; use App\Models\Review; use App\Models\User;
final class ReviewPolicy {
    public function view(User $user, Review $review): bool { return OrganizationMembership::query()->where('user_id', $user->id)->where('organization_id', $review->organization_id)->exists(); }
    public function follow(User $user, Review $review): bool { return $this->view($user, $review); }
    public function delete(User $user, Review $review): bool { return $review->created_by_user_id === $user->id || OrganizationMembership::query()->where('user_id', $user->id)->where('organization_id', $review->organization_id)->where('role', OrganizationRole::Owner)->exists(); }
}
```

Create deletion action/controller:

```php
<?php
// DeleteReviewAction.php
declare(strict_types=1); namespace App\Actions\Reviews;
use App\Models\Review; final class DeleteReviewAction { public function __invoke(Review $review): void { $review->delete(); } }

<?php
// DeleteReviewController.php
declare(strict_types=1); namespace App\Http\Controllers;
use App\Actions\Reviews\DeleteReviewAction; use App\Reviews\ScopedReviewResolver; use Illuminate\Http\Response; use Illuminate\Support\Facades\Gate;
final class DeleteReviewController { public function __invoke(string $review, ScopedReviewResolver $resolver, DeleteReviewAction $delete): Response { $model = $resolver->findOrFail($review); Gate::authorize('delete', $model); $delete($model); return response()->noContent(); } }
```

Replace `ReviewController::store()` body with:

```php
$review = $action(
    $request->string('spec')->value(),
    $request->enum('mode', ReviewMode::class, ReviewMode::Council),
    app(App\Organizations\OrganizationContext::class)->organization(),
    $request->user(),
    dimension: $request->enum('dimension', Dimension::class, Dimension::DomainModeling),
);
return new ReviewResource($review)->response()->setStatusCode(202)->header('Location', route($request->getHost() === config('oast.api_domain') ? 'api.reviews.show' : 'app.reviews.show', $review));
```

Replace `ShowReviewController::__invoke()` with:

```php
public function __invoke(string $review, App\Reviews\ScopedReviewResolver $resolver): ReviewResource
{
    $model = $resolver->findOrFail($review); Illuminate\Support\Facades\Gate::authorize('view', $model); return new ReviewResource($model);
}
```

Add exact browser routes inside the existing `organization` group, static routes before parameters:

```php
Route::prefix('reviews')->name('reviews.')->group(function (): void {
    Route::post('/', [ReviewController::class, 'store'])->name('store');
    Route::get('/{review}', ShowReviewController::class)->name('show');
    Route::get('/{review}/events', ReviewEventsController::class)->name('events');
    Route::delete('/{review}', DeleteReviewController::class)->name('destroy');
});
```

- [ ] **Step 5: Update existing tests and prove dispatch occurs after commit**

In every current call to `CreateReviewAction`, add `memberFixture()`'s organization and user before `specRef`. Add this exact test to `tests/Unit/Actions/Reviews/CreateReviewActionTest.php`:

```php
it('dispatches the unchanged batch only after the ownership transaction commits', function (): void {
    Bus::fake(); [$user, $organization] = memberFixture();
    $review = app(CreateReviewAction::class)('openapi: 3.1.0', ReviewMode::Council, $organization, $user, 'spec.yaml');
    expect($review->organization_id)->toBe($organization->id)->and($review->created_by_user_id)->toBe($user->id);
    Bus::assertBatched(fn ($batch): bool => $batch->jobs->count() === count(config('oast.panelists'))
        && $batch->jobs->every(fn ($job): bool => $job instanceof RunPanelist));
});
```

Authenticate all existing API review tests with a token created through `PersonalAccessTokenService`. Do not add user/model coverage exclusions.

- [ ] **Step 6: Run and commit**

Run: `vendor/bin/pest tests/Feature/ReviewAuthorizationTest.php tests/Feature/ReviewDeletionTest.php tests/Feature/AbuseControlsTest.php tests/Unit/Actions/Reviews/CreateReviewActionTest.php tests/Feature/ReviewApiTest.php`

Expected: PASS; the batch assertion uses existing `RunPanelist`, `PanelFinalizer`, `panelistRoster()`, and `baselineModel()` behavior.

```bash
git add app/Actions/Reviews app/Reviews app/Policies/ReviewPolicy.php app/Http/Controllers bootstrap/app.php routes tests
git commit -m "feat: scope review lifecycle to organizations"
```

---

### Task 11: Add independently expiring SSE leases and per-poll authorization

**Files:**

- Create: `app/Streaming/StreamLease.php`, `StreamLeaseManager.php`, `StreamLimitExceeded.php`
- Modify: `app/Http/Controllers/ReviewEventsController.php`, `bootstrap/app.php`
- Create: `tests/Unit/Streaming/StreamLeaseManagerTest.php`, `tests/Feature/SseAuthorizationTest.php`
- Modify: `tests/Feature/ReviewEventsStreamTest.php`

- [ ] **Step 1: Write lease tests that prove abandoned IDs expire independently**

Create `tests/Unit/Streaming/StreamLeaseManagerTest.php`:

```php
<?php

declare(strict_types=1);

use App\Streaming\StreamLeaseManager;
use App\Streaming\StreamLimitExceeded;
use Illuminate\Support\Carbon;

it('counts live unique lease ids and releases idempotently', function (): void {
    config(['oast.max_concurrent_streams' => 2]); $manager = app(StreamLeaseManager::class);
    $one = $manager->acquire('token:1'); $two = $manager->acquire('token:1');
    expect($one->id())->not->toBe($two->id());
    expect(fn () => $manager->acquire('token:1'))->toThrow(StreamLimitExceeded::class);
    $one->release(); $one->release(); expect($manager->acquire('token:1'))->toBeInstanceOf(App\Streaming\StreamLease::class);
});

it('purges each abandoned lease by its own expiry even when another lease refreshes', function (): void {
    Carbon::setTestNow('2026-07-11 00:00:00'); config(['oast.max_concurrent_streams' => 2]);
    $manager = app(StreamLeaseManager::class); $abandoned = $manager->acquire('user:1');
    Carbon::setTestNow(now()->addMinutes(10)); $active = $manager->acquire('user:1'); $active->refresh();
    Carbon::setTestNow(now()->addMinutes(6)); $active->refresh();
    expect($manager->acquire('user:1')->id())->not->toBe($abandoned->id());
    Carbon::setTestNow();
});

it('keeps token and browser principals separate', function (): void {
    config(['oast.max_concurrent_streams' => 1]); $manager = app(StreamLeaseManager::class);
    $manager->acquire('token:7'); expect($manager->acquire('user:7'))->toBeInstanceOf(App\Streaming\StreamLease::class);
});
```

Create `tests/Feature/SseAuthorizationTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Review;
use App\Organizations\OrganizationContext;
use App\Tokens\PersonalAccessTokenService;

it('returns a browser 429 before streaming when the user ceiling is full', function (): void {
    config(['oast.max_concurrent_streams' => 1]);
    [$user, $organization] = memberFixture();
    App\Models\Installation::query()->findOrFail(1)->update(['bootstrapped_at' => now()]);
    $review = Review::factory()->for($organization)->create(['status' => 'running']);
    $lease = app(App\Streaming\StreamLeaseManager::class)->acquire('user:'.$user->id);
    try {
        $this->actingAs($user)->get(route('app.reviews.events', $review->id))
            ->assertTooManyRequests()->assertHeader('Retry-After', '60')->assertContent('');
    } finally {
        $lease->release();
    }
});

it('returns 404 before streaming a cross organization review', function (): void {
    [$user, $organization] = memberFixture(); [, $other] = memberFixture();
    $token = app(PersonalAccessTokenService::class)->create($user, $organization, 'CI', null);
    $review = Review::factory()->for($other)->create();
    $this->withToken($token->plainTextToken)->get("https://{$this->apiHost()}/reviews/{$review->id}/events")->assertNotFound();
});

it('terminates the API stream when the token is revoked before its next poll', function (): void {
    [$user, $organization] = memberFixture();
    $token = app(PersonalAccessTokenService::class)->create($user, $organization, 'CI', null);
    $review = Review::factory()->for($organization)->create(['status' => 'running']);
    $review->appendEvent('review.queued', []);
    $token->accessToken->forceFill(['revoked_at' => now()])->saveQuietly();
    $body = $this->withToken($token->plainTextToken)
        ->get("https://{$this->apiHost()}/reviews/{$review->id}/events")->streamedContent();
    expect($body)->toBe('');
});

it('makes real context authorization false after membership removal review reassignment or token expiry', function (string $failure): void {
    [$user, $organization, $membership] = memberFixture();
    $token = app(PersonalAccessTokenService::class)->create($user, $organization, 'CI', null);
    $review = Review::factory()->for($organization)->create(['status' => 'running']);
    $this->withToken($token->plainTextToken);
    $request = Illuminate\Http\Request::create('/');
    $request->setUserResolver(fn () => $user->withAccessToken($token->accessToken));
    $context = new OrganizationContext($request);
    if ($failure === 'membership removed') { $membership->delete(); }
    if ($failure === 'review reassigned') { [, $other] = memberFixture(); $review->update(['organization_id' => $other->id]); }
    if ($failure === 'pat expired') { $token->accessToken->forceFill(['expires_at' => now()->subMinute()])->saveQuietly(); }
    expect($context->stillAuthorized($review))->toBeFalse();
})->with(['membership removed', 'review reassigned', 'pat expired']);
```

The feature test names deliberately match the production checks; unit/model tests in Tasks 5 and 8 cover the actual membership, review, revocation, and expiration queries. Logout from another request is not asserted: the approved spec requires per-poll membership/review/PAT checks, not persistent browser-session revocation.

- [ ] **Step 2: Run and confirm failure**

Run: `vendor/bin/pest tests/Unit/Streaming/StreamLeaseManagerTest.php tests/Feature/SseAuthorizationTest.php`

Expected: FAIL because stream leases do not exist.

- [ ] **Step 3: Create the complete per-ID lease implementation**

Create `app/Streaming/StreamLimitExceeded.php`:

```php
<?php

declare(strict_types=1); namespace App\Streaming;
use RuntimeException;
final class StreamLimitExceeded extends RuntimeException { public function __construct(public readonly int $retryAfter) { parent::__construct('Concurrent stream limit exceeded.'); } }
```

Create `app/Streaming/StreamLease.php`:

```php
<?php

declare(strict_types=1); namespace App\Streaming;
final class StreamLease {
    private bool $released = false;
    public function __construct(private readonly StreamLeaseManager $manager, private readonly string $principal, private readonly string $leaseId) {}
    public function id(): string { return $this->leaseId; }
    public function refresh(): void { if (! $this->released) { $this->manager->refresh($this->principal, $this->leaseId); } }
    public function release(): void { if ($this->released) { return; } $this->released = true; $this->manager->release($this->principal, $this->leaseId); }
}
```

Create `app/Streaming/StreamLeaseManager.php`:

```php
<?php

declare(strict_types=1); namespace App\Streaming;
use Illuminate\Support\Facades\Cache; use Illuminate\Support\Str;
final class StreamLeaseManager {
    private const int TTL_SECONDS = 900;
    public function acquire(string $principal): StreamLease {
        $id = (string) Str::uuid();
        $this->locked($principal, function (array $leases) use ($principal, $id): array {
            $leases = $this->live($leases);
            if (count($leases) >= config()->integer('oast.max_concurrent_streams')) { throw new StreamLimitExceeded(60); }
            $leases[$id] = now()->addSeconds(self::TTL_SECONDS)->getTimestamp(); return $leases;
        });
        return new StreamLease($this, $principal, $id);
    }
    public function refresh(string $principal, string $id): void {
        $this->locked($principal, function (array $leases) use ($id): array {
            $leases = $this->live($leases); if (array_key_exists($id, $leases)) { $leases[$id] = now()->addSeconds(self::TTL_SECONDS)->getTimestamp(); }
            return $leases;
        });
    }
    public function release(string $principal, string $id): void {
        $this->locked($principal, function (array $leases) use ($id): array { $leases = $this->live($leases); unset($leases[$id]); return $leases; });
    }
    /** @param callable(array<string,int>): array<string,int> $change */
    private function locked(string $principal, callable $change): void {
        $key = 'oast:sse:'.hash('sha256', $principal);
        Cache::lock($key.':lock', 5)->block(2, function () use ($key, $change): void {
            $stored = Cache::get($key, []); $leases = is_array($stored) ? array_filter($stored, is_int(...)) : [];
            $leases = $change($leases);
            if ($leases === []) { Cache::forget($key); } else { Cache::put($key, $leases, now()->addSeconds(self::TTL_SECONDS * 2)); }
        });
    }
    /** @param array<string,int> $leases @return array<string,int> */
    private function live(array $leases): array { return array_filter($leases, fn (int $expiry): bool => $expiry > now()->getTimestamp()); }
}
```

The cache key's aggregate TTL is not capacity state. Capacity is the count of non-expired per-ID timestamps after purge; refreshing one ID cannot extend another ID's timestamp.

- [ ] **Step 4: Replace the stream controller with scoped resolution, leases, and per-poll checks**

Replace `ReviewEventsController::__invoke()` and add `principal()`; retain the existing `emit()` unchanged:

```php
public function __invoke(
    Request $request,
    string $review,
    App\Reviews\ScopedReviewResolver $resolver,
    App\Organizations\OrganizationContext $context,
    App\Streaming\StreamLeaseManager $leases,
): StreamedResponse {
    $model = $resolver->findOrFail($review);
    Illuminate\Support\Facades\Gate::authorize('follow', $model);
    $lease = $leases->acquire($this->principal($request, $context));
    $lastId = (int) ($request->headers->get('Last-Event-ID') ?? $request->query('lastEventId', '0'));
    return response()->stream(function () use ($model, $lastId, $context, $lease): void {
        set_time_limit(0); $cursor = $lastId;
        try {
            while (true) {
                if (! $context->stillAuthorized($model)) { return; }
                $lease->refresh();
                foreach ($model->events()->where('id', '>', $cursor)->orderBy('id')->get() as $event) {
                    $this->emit($event); $cursor = $event->id;
                    if (in_array($event->event, self::TERMINAL, true)) { return; }
                }
                if (connection_aborted() === 1) { return; }
                Sleep::for(500)->milliseconds();
            }
        } finally { $lease->release(); }
    }, headers: ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'X-Accel-Buffering' => 'no']);
}

private function principal(Request $request, App\Organizations\OrganizationContext $context): string
{
    $token = $context->token();
    return $token instanceof App\Models\PersonalAccessToken ? 'token:'.$token->id : 'user:'.$request->user()->id;
}
```

Add this renderer for both API and browser requests:

```php
$exceptions->render(function (StreamLimitExceeded $e, Request $request) use ($onApi): Response {
    $headers = ['Retry-After' => (string) $e->retryAfter];
    if (! $onApi($request)) { return response('', 429, $headers); }
    return ProblemResponse::from(
        (new ApiProblem('Rate limited', ProblemType::RateLimited->value))->setDetail('Too many concurrent event streams.'),
        429,
        $headers,
    );
});
```

- [ ] **Step 5: Update existing SSE tests and run**

Authenticate API SSE tests with `review:follow` tokens and owned reviews. Keep the existing namespace-scoped `connection_aborted()` stub, Last-Event-ID replay assertions, 500 ms sleep assertion, and terminal replay assertion.

Run: `vendor/bin/pest tests/Unit/Streaming/StreamLeaseManagerTest.php tests/Feature/SseAuthorizationTest.php tests/Feature/ReviewEventsStreamTest.php`

Expected: PASS; the sixth live lease at default five is 429, an abandoned lease expires after its own 15 minutes, and another lease's refresh does not preserve it.

- [ ] **Step 6: Commit**

```bash
git add app/Streaming app/Http/Controllers/ReviewEventsController.php bootstrap/app.php tests/Unit/Streaming tests/Feature/SseAuthorizationTest.php tests/Feature/ReviewEventsStreamTest.php
git commit -m "feat: reauthorize and limit review streams"
```

---

### Task 12: Add organization-aware review and operator recovery commands

**Files:**

- Modify: `app/Console/Commands/ReviewCommand.php`
- Create: `app/Console/Commands/ResetUserPasswordCommand.php`, `VerifyUserEmailCommand.php`
- Modify: `tests/Feature/ReviewCommandTest.php`
- Create: `tests/Feature/OperatorCommandsTest.php`

- [ ] **Step 1: Write command tests**

Create `tests/Feature/OperatorCommandsTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('resets a canonical user password with explicit matching confirmation', function (): void {
    $user = User::factory()->create(['email' => 'owner@example.test']);
    $this->artisan('oast:user:password', ['email' => ' OWNER@EXAMPLE.TEST '])
        ->expectsQuestion('New password', 'correct horse battery staple')
        ->expectsQuestion('Confirm password', 'correct horse battery staple')
        ->assertSuccessful();
    expect(Hash::check('correct horse battery staple', $user->refresh()->password))->toBeTrue();
});

it('rejects mismatched command passwords before base validation', function (): void {
    User::factory()->create(['email' => 'owner@example.test']);
    $this->artisan('oast:user:password', ['email' => 'owner@example.test'])
        ->expectsQuestion('New password', 'correct horse battery staple')
        ->expectsQuestion('Confirm password', 'different password')
        ->expectsOutputToContain('Passwords do not match.')->assertFailed();
});

it('verifies canonical email idempotently and reports unknown users generically', function (): void {
    $user = User::factory()->unverified()->create(['email' => 'owner@example.test']);
    $this->artisan('oast:user:verify', ['email' => ' OWNER@EXAMPLE.TEST '])->assertSuccessful();
    expect($user->refresh()->hasVerifiedEmail())->toBeTrue();
    $this->artisan('oast:user:verify', ['email' => 'missing@example.test'])->expectsOutput('User not found.')->assertFailed();
});
```

Add to `ReviewCommandTest.php`:

```php
it('requires an explicit organization', function (): void {
    $this->artisan('oast:review', ['spec' => fixtureSpecPath()])->expectsOutputToContain('The --organization option is required.')->assertFailed();
});
```

- [ ] **Step 2: Run and confirm failure**

Run: `vendor/bin/pest tests/Feature/ReviewCommandTest.php tests/Feature/OperatorCommandsTest.php`

Expected: FAIL because command ownership and recovery commands are absent.

- [ ] **Step 3: Update the review command at its exact call site**

Append to `$signature`:

```php
{--organization= : Required organization ID that owns the review}
```

Before reading the spec, add:

```php
$organizationId = $this->option('organization');
if (! is_string($organizationId) || trim($organizationId) === '') { $this->error('The --organization option is required.'); return self::FAILURE; }
$organization = App\Models\Organization::query()->find($organizationId);
if (! $organization instanceof App\Models\Organization) { $this->error('Organization not found.'); return self::FAILURE; }
```

Replace the current action call with:

```php
$created = $review(File::get($path), $mode, $organization, null, $path, $dimension);
```

- [ ] **Step 4: Create both commands with complete password behavior**

Create `ResetUserPasswordCommand.php`:

```php
<?php

declare(strict_types=1); namespace App\Console\Commands;
use App\Identity\CanonicalEmail; use App\Identity\PasswordRules; use App\Models\User; use Illuminate\Console\Command; use Illuminate\Support\Facades\Hash; use Illuminate\Support\Facades\Validator; use Override;
final class ResetUserPasswordCommand extends Command {
    #[Override] protected $signature = 'oast:user:password {email}';
    #[Override] protected $description = 'Reset a user password.';
    public function handle(): int {
        $user = User::query()->where('email', CanonicalEmail::from((string) $this->argument('email')))->first();
        if (! $user instanceof User) { $this->error('User not found.'); return self::FAILURE; }
        $password = (string) $this->secret('New password'); $confirmation = (string) $this->secret('Confirm password');
        if (! hash_equals($password, $confirmation)) { $this->error('Passwords do not match.'); return self::FAILURE; }
        $validator = Validator::make(['password' => $password], ['password' => PasswordRules::base()]);
        if ($validator->fails()) { $this->error($validator->errors()->first('password')); return self::FAILURE; }
        $user->forceFill(['password' => Hash::make($password)])->save(); $this->info('Password updated.'); return self::SUCCESS;
    }
}
```

Create `VerifyUserEmailCommand.php`:

```php
<?php

declare(strict_types=1); namespace App\Console\Commands;
use App\Identity\CanonicalEmail; use App\Models\User; use Illuminate\Console\Command; use Override;
final class VerifyUserEmailCommand extends Command {
    #[Override] protected $signature = 'oast:user:verify {email}';
    #[Override] protected $description = 'Mark a user email as verified.';
    public function handle(): int {
        $user = User::query()->where('email', CanonicalEmail::from((string) $this->argument('email')))->first();
        if (! $user instanceof User) { $this->error('User not found.'); return self::FAILURE; }
        if (! $user->hasVerifiedEmail()) { $user->markEmailAsVerified(); }
        $this->info('Email verified.'); return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Run and commit**

Run: `vendor/bin/pest tests/Feature/ReviewCommandTest.php tests/Feature/OperatorCommandsTest.php`

Expected: PASS; console reviews have `organization_id` and null `created_by_user_id`, and password validation uses `base()` after an explicit match.

```bash
git add app/Console/Commands tests/Feature/ReviewCommandTest.php tests/Feature/OperatorCommandsTest.php
git commit -m "feat: add organization operator commands"
```

---

### Task 13: Complete management UI/security coverage and run all gates

**Files:**

- Create: `resources/views/components/app-layout.blade.php`
- Modify: every M3A auth/setup/invitation/settings Blade view, `resources/js/app.js`, `resources/css/app.css`
- Create: `tests/Feature/ManagementScreensTest.php`
- Modify: `tests/Feature/SitePagesTest.php`, `AGENTS.md`

- [ ] **Step 1: Write the management-screen security test**

Create `tests/Feature/ManagementScreensTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Installation;
use App\Models\OrganizationInvitation;
use App\Models\PersonalAccessToken;

beforeEach(fn () => Installation::query()->findOrFail(1)->update(['bootstrapped_at' => now()]));

it('renders exact settings forms without tenant or secret inputs', function (): void {
    [$owner] = memberFixture(role: 'owner');
    $this->actingAs($owner)->get(route('app.settings.account.show'))->assertOk()->assertSee('name="email"', false)->assertDontSee('organization_id');
    $this->get(route('app.settings.organization.show'))->assertOk()->assertSee('name="email"', false)->assertDontSee('token_hash');
    $this->get(route('app.settings.tokens.index'))->assertOk()->assertSee('name="name"', false)->assertDontSee('organization_id');
});

it('never leaks another organizations members invitations or tokens', function (): void {
    [$owner] = memberFixture(role: 'owner');
    [$other, $otherOrganization] = memberFixture(role: 'owner');
    OrganizationInvitation::factory()->for($otherOrganization)->for($other, 'inviter')->create(['email' => 'secret-other@example.test']);
    PersonalAccessToken::factory()->for($other, 'tokenable')->for($otherOrganization)->create(['name' => 'other-secret-token']);
    $this->actingAs($owner)->get(route('app.settings.organization.show'))->assertDontSee('secret-other@example.test');
    $this->get(route('app.settings.tokens.index'))->assertDontSee('other-secret-token');
});

it('keeps public publication pages public before and after bootstrap', function (): void {
    $this->get('/')->assertOk(); $this->get('/why')->assertOk(); $this->get('/reviews')->assertOk();
});

it('has no unrestricted registration link and uses post logout', function (): void {
    $this->get('/login')->assertOk()->assertDontSee('Create account')->assertDontSee('href="/logout"', false);
});
```

- [ ] **Step 2: Run and confirm failure**

Run: `vendor/bin/pest tests/Feature/ManagementScreensTest.php tests/Feature/SitePagesTest.php`

Expected: FAIL until shared layout/navigation and conditional controls are complete.

- [ ] **Step 3: Add the shared application layout and exact JS behavior**

Create `resources/views/components/app-layout.blade.php`:

```blade
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title ?? 'oast.sh' }}</title>@vite(['resources/css/app.css', 'resources/js/app.js'])</head><body>
<nav class="o-nav"><a class="o-wordmark" href="{{ route('app.home') }}">oast<em>.sh</em></a><div class="o-settings-nav">
<a href="{{ route('app.settings.account.show') }}">Account</a>@if(auth()->user()?->memberships()->exists())<a href="{{ route('app.settings.organization.show') }}">Organization</a><a href="{{ route('app.settings.tokens.index') }}">Tokens</a>@endif
<form method="POST" action="{{ route('logout') }}">@csrf<button type="submit">Sign out</button></form></div></nav>
<main class="mx-auto max-w-[var(--container-page)] px-6 py-12">@if(session('status'))<div class="o-confirm-box">{{ session('status') }}</div>@endif<x-form-errors />{{ $slot }}</main></body></html>
```

Change authenticated `app/*` settings/home views from `<x-auth-layout>` to `<x-app-layout>` without changing their exact form actions or field names.

Replace `resources/js/app.js` with:

```js
document.addEventListener("click", async (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;
    const copy = target.closest("[data-copy]");
    if (copy instanceof HTMLElement) {
        await navigator.clipboard.writeText(copy.dataset.copy ?? "");
        copy.textContent = "Copied";
    }
    const confirm = target.closest("[data-confirm]");
    if (
        confirm instanceof HTMLElement &&
        !window.confirm(confirm.dataset.confirm ?? "Are you sure?")
    ) {
        event.preventDefault();
    }
});
```

Append exact component rules to `resources/css/app.css`:

```css
@layer components {
    .o-form {
        @apply my-6 grid gap-3;
    }
    .o-form label {
        @apply font-mono text-label uppercase tracking-label text-faint;
    }
    .o-error {
        @apply font-mono text-mono-small text-danger;
    }
    .o-settings-nav {
        @apply flex items-center gap-4 font-mono text-mono-ui;
    }
    .o-settings-nav a {
        @apply text-muted no-underline hover:text-ink;
    }
    .o-table-management {
        @apply w-full border-collapse font-mono text-mono-ui;
    }
    .o-table-management td {
        @apply border-b border-hairline px-3 py-3;
    }
}
```

- [ ] **Step 4: Run the complete focused security suite**

Run:

```bash
vendor/bin/pest \
  tests/Feature/IdentitySchemaTest.php \
  tests/Feature/SetupTest.php \
  tests/Feature/FortifyAuthenticationTest.php \
  tests/Feature/VerificationPolicyTest.php \
  tests/Feature/InvitationFlowTest.php \
  tests/Feature/OrganizationManagementTest.php \
  tests/Feature/TokenManagementTest.php \
  tests/Feature/AccountSettingsTest.php \
  tests/Feature/OrganizationContextTest.php \
  tests/Feature/ApiAuthenticationTest.php \
  tests/Feature/ReviewAuthorizationTest.php \
  tests/Feature/ReviewDeletionTest.php \
  tests/Feature/AbuseControlsTest.php \
  tests/Feature/SseAuthorizationTest.php \
  tests/Feature/OperatorCommandsTest.php \
  tests/Feature/ManagementScreensTest.php
```

Expected: PASS with no live LLM or mail network calls.

- [ ] **Step 5: Run route, migration, secret, frontend, and full quality gates**

Run:

```bash
php artisan migrate:fresh --env=testing
php artisan route:list --path=setup
php artisan route:list --path=app
php artisan route:list --path=invitations
php artisan route:list --domain=api.oast.test
rg "OAST_API_ENABLED|EnsureApiEnabled|bootstrap_secret.*query|token_hash.*view|ownerFixture|ownerFixtureWithMembership" app config routes resources tests .env.example
bun run build
bun run test:lint
composer test
```

Expected:

- migrations create singleton installation and preserve `reviews.organization_id` as nullable with `restrictOnDelete`;
- setup routes are `setup.show`, `setup.authorize`, and `setup.store`;
- browser routes have exactly the `app.*` names shown in Tasks 4, 7, 8, 10;
- API routes all have `auth:sanctum` and their exact `abilities:*` middleware;
- `rg` has no unsafe production matches and no undefined fixture helpers;
- build/lint pass;
- type coverage is 100%, line coverage is exactly 100%, Pint/Rector/Vite formatting is clean, and PHPStan max passes.

- [ ] **Step 6: Document only operator-visible M3A changes and commit**

Append to `AGENTS.md`:

```markdown
### M3A identity operations

- Set a high-entropy `OAST_BOOTSTRAP_SECRET`; `/setup` is one-use and returns 404 after bootstrap.
- `OAST_ENFORCE_EMAIL_VERIFICATION=false` keeps no-SMTP self-host installs usable; set it to `true` when mail works.
- The API is bearer-only. Create organization-scoped PATs in `/app/settings/tokens`.
- `oast:review` requires `--organization=<id>`.
- Recovery commands: `oast:user:password <email>` and `oast:user:verify <email>`.
```

```bash
git add -A
git commit -m "chore: finish M3A identity foundation"
```

---

## Dependency Map

- Task 1 is the schema/package prerequisite for every later task.
- Task 2 provides models, factories, and the only shared fixture: `memberFixture(role: 'owner')`.
- Task 3 provides canonical identity and Fortify login/reset/verification/confirmation without registration.
- Task 4 depends on Tasks 1–3 and supplies its own real `app.home` redirect target; it does not depend on invitations, organization settings, tokens, or account controllers.
- Task 5 depends on Tasks 2 and 4 and adds middleware only around the already-existing `app.home` route.
- Task 6 depends on Tasks 3 and 5 and introduces `RegistrationPolicy`, `SelfHostedRegistrationPolicy`, Fortify registration, and invitation acceptance atomically in one task.
- Task 7 depends on Task 6 for invitation management and adds organization routes only with controllers created in that task.
- Task 8 depends on Tasks 5 and 7 and creates account/token controllers before registering their routes.
- Task 9 depends on Task 8's Sanctum model/auth callback and makes the API bearer-only.
- Task 10 depends on Tasks 5 and 9 and changes all review creation/resolution paths together.
- Task 11 depends on Tasks 9–10 for authenticated scoped SSE.
- Task 12 depends on Task 10's final `CreateReviewAction` signature and Task 3's `PasswordRules::base()`.
- Task 13 depends on all functional tasks and is the only full-suite/UI polish task.

## Risks and Required Validation

- **SQLite concurrency:** SQLite does not provide PostgreSQL-style row locks. The unique membership index plus file-backed, separate-PHP-process tests are mandatory. Run the same setup, invitation, and final-owner races against PostgreSQL before release.
- **Fortify/Sanctum package drift:** keep Fortify `^1.37` and Sanctum `^4.3`; verify `TokenAuthenticated` still fires while `sanctum.last_used_at=false` suppresses Sanctum's direct write.
- **One-time token caching:** test the redirected GET that renders plaintext, not only the creation POST. It must be `Cache-Control: no-store, private`; the next GET must contain neither plaintext nor hash.
- **SSE capacity:** never replace the per-ID expiry map with an aggregate count/TTL. An active stream may refresh only its own lease ID.
- **SSE revocation:** every poll rechecks membership, current review organization, and PAT revocation/expiry. Do not invent cross-request browser logout termination without adding a persistent session-revocation design.
- **Email changes:** reset verification on every changed canonical email; call `sendEmailVerificationNotification()` only when `oast.enforce_email_verification=true`.
- **Batch timing:** keep `DB::afterCommit()` and the exact existing `Bus::batch(...)->name(...)->allowFailures()->finally(...)->dispatch()` chain. Never dispatch while the ownership transaction can still roll back.
- **API/session separation:** preserve `sanctum.guard=[]` and remove the API domain from Sanctum stateful domains; verify a valid browser session alone is 401 on `api.*`.
- **Tenant resolution:** no controller accepts `organization_id` as authority, and unknown/cross-organization review IDs remain byte-identical 404 problem responses.
- **M3A boundary:** no browser review workspace, review index/create/report page, EventSource UI, source mapper, Docker, external CLI-repository change, or organization deletion belongs in this plan.

## Writing-Plans Self-Review

- Every code-producing task contains exact file content or focused snippets with imports/signatures, an initial failing test command, a passing focused command, and a commit.
- Task order is independently executable: schema → models/fixture → non-registration Fortify identity → bootstrap with existing target → context → invitations and invited registration → organization management → tokens/account → API → reviews → SSE → commands → UI/full gates.
- `PasswordRules::base()` and `confirmed()` are explicit; the CLI compares both secret prompts then validates `base()`.
- Every owner fixture is `memberFixture(role: 'owner')`; no undefined helper is used.
- `CreateReviewAction` retains complete `panelistRoster()`, `baselineModel()`, and current batch/finalizer code and dispatches through `DB::afterCommit()`.
- The token-rendering GET has explicit private/no-store behavior and first/later GET tests.
- SSE uses unique lease IDs with individual expiries and lock-protected purge/count/add/refresh/remove operations.
- Per-poll SSE checks match the approved spec exactly and do not claim that logout elsewhere terminates an existing browser stream.
- Email-change notification behavior has enabled/disabled notification tests.
- Fortify/Sanctum versions, Sanctum config, encrypted-session invitation registration, FK actions, token revocation on membership removal, and no `User` coverage exclusion are preserved.
- Route groups and route names are exact, browser foundation groups reference only existing controllers/views, and the API is bearer-only.
- Concurrency coverage names `tests/Support/FileDatabaseProcess.php`, `tests/Fixtures/m3a-race.php`, and exact child-process arguments.
- The final gates include migration, route, secret, frontend, line/type coverage, lint, Rector, and PHPStan validation.
