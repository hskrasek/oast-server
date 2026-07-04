<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

final class Review extends Model
{
    /**
     * @use HasFactory<\Database\Factories\ReviewFactory>
     */
    use HasFactory;

    #[Override]
    protected $guarded = [];

    #[Override]
    protected $casts = [
        'panelists' => 'array',
        'findings' => 'array',
        'metrics' => 'array',
        'panel_size' => 'integer',
    ];

    /**
     * @return HasMany<ReviewEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ReviewEvent::class);
    }

    /**
     * @return HasMany<ReviewPanelResponse, $this>
     */
    public function panelResponses(): HasMany
    {
        return $this->hasMany(ReviewPanelResponse::class);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function appendEvent(string $event, array $data): ReviewEvent
    {
        return $this->events()->create(['event' => $event, 'data' => $data]);
    }
}
