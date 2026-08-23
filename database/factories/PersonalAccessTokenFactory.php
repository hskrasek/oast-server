<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Override;

/**
 * @extends Factory<PersonalAccessToken>
 */
final class PersonalAccessTokenFactory extends Factory
{
    #[Override]
    protected $model = PersonalAccessToken::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tokenable_type' => User::class,
            'tokenable_id' => User::factory(),
            'organization_id' => Organization::factory(),
            'name' => 'test token',
            'token' => hash('sha256', Str::random(40)),
            'abilities' => ['review:create', 'review:read', 'review:follow'],
            'last_used_at' => null,
            'expires_at' => null,
            'revoked_at' => null,
        ];
    }
}
