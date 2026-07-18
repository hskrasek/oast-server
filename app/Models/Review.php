<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

final class Review extends Model
{
    /**
     * @use HasFactory<\Database\Factories\ReviewFactory>
     */
    use HasFactory;

    #[Override]
    protected $guarded = ['organization_id', 'created_by_user_id'];

    #[Override]
    protected $casts = [
        'panelists' => 'array',
        'findings' => 'array',
        'metrics' => 'array',
        'panel_size' => 'integer',
    ];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

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
