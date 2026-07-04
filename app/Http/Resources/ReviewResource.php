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
            'id' => $this->id,
            'status' => $this->status,
            'mode' => $this->mode,
            'dimension' => $this->dimension,
            'panelists' => $this->panelists,
            'panel_size' => $this->panel_size,
            'findings' => $this->when($this->findings !== null, $this->findings),
            'metrics' => $this->when($this->metrics !== null, $this->metrics),
            'created_at' => $this->created_at,
        ];
    }
}
