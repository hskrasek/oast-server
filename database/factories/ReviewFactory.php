<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Review;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
final class ReviewFactory extends Factory
{
    protected $model = Review::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'spec_ref' => 'spec.yaml',
            'spec_hash' => hash('sha256', 'spec'),
            'spec' => 'openapi: 3.1.0',
            'mode' => 'council',
            'dimension' => 'domain-modeling',
            'panelists' => [],
            'panel_size' => 0,
            'status' => 'queued',
        ];
    }
}
