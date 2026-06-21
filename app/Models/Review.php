<?php

declare(strict_types=1);

namespace App\Models;

use App\Council\ReviewResult;
use Illuminate\Database\Eloquent\Model;
use Override;

final class Review extends Model
{
    #[Override]
    protected $guarded = [];

    #[Override]
    protected $casts = [
        'panelists' => 'array',
        'raw_panelist_responses' => 'array',
        'findings' => 'array',
        'metrics' => 'array',
        'panel_size' => 'integer',
    ];

    public static function fromResult(ReviewResult $result, ?string $specRef, string $specHash): self
    {
        return self::create(array_merge($result->toArray(), [
            'spec_ref' => $specRef,
            'spec_hash' => $specHash,
        ]));
    }
}
