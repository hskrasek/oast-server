<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Override;

/**
 * @extends Factory<OrganizationInvitation>
 */
final class OrganizationInvitationFactory extends Factory
{
    #[Override]
    protected $model = OrganizationInvitation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'invited_by_user_id' => User::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => OrganizationRole::Member,
            'token_hash' => hash('sha256', Str::random(40)),
            'expires_at' => now()->addDay(),
            'accepted_at' => null,
            'revoked_at' => null,
        ];
    }
}
