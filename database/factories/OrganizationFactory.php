<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Override;

/**
 * @extends Factory<Organization>
 */
final class OrganizationFactory extends Factory
{
    #[Override]
    protected $model = Organization::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
        ];
    }
}
