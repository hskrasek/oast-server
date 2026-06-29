<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Review
 */
final class ReviewResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'mode' => $this->mode,
            'dimension' => $this->dimension,
            'panel_size' => $this->panel_size,
            'findings' => $this->findings,
            'metrics' => $this->metrics,
            'status' => $this->status,
        ];
    }
}
