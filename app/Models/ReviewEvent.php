<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

final class ReviewEvent extends Model
{
    public const null UPDATED_AT = null;

    #[Override]
    protected $fillable = ['event', 'data'];

    /**
     * @return BelongsTo<Review, $this>
     */
    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    /**
     * @return array{data: 'array'}
     */
    protected function casts(): array
    {
        return ['data' => 'array'];
    }
}
