<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PersonalAccessTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;
use LogicException;
use Stringable;
use Override;

final class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /** @use HasFactory<PersonalAccessTokenFactory> */
    use HasFactory;

    #[Override]
    protected $fillable = ['organization_id', 'name', 'token', 'abilities', 'expires_at', 'revoked_at'];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    protected static function booted(): void
    {
        self::updating(function (self $token): void {
            if ($token->isDirty('organization_id')) {
                throw new LogicException('Token organization is immutable.');
            }
        });
    }

    /** @return array<string, string|Stringable> */
    protected function casts(): array
    {
        return [...parent::casts(), 'revoked_at' => 'datetime'];
    }
}
