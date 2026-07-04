<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

final class ReviewPanelResponse extends Model
{
    #[Override]
    protected $fillable = ['model', 'ok', 'content', 'error', 'ms', 'usage', 'cost_usd', 'late'];

    /**
     * @return BelongsTo<Review, $this>
     */
    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    /**
     * @return array{ok: 'boolean', late: 'boolean', usage: 'array', cost_usd: 'float'}
     */
    protected function casts(): array
    {
        return ['ok' => 'boolean', 'late' => 'boolean', 'usage' => 'array', 'cost_usd' => 'float'];
    }
}
