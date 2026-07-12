<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationRole;
use Database\Factories\OrganizationInvitationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

final class OrganizationInvitation extends Model
{
    /** @use HasFactory<OrganizationInvitationFactory> */
    use HasFactory;

    #[Override]
    protected $fillable = [
        'organization_id', 'invited_by_user_id', 'email', 'role', 'token_hash',
        'expires_at', 'accepted_at', 'revoked_at',
    ];

    public function available(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => OrganizationRole::class, 'expires_at' => 'datetime',
            'accepted_at' => 'datetime', 'revoked_at' => 'datetime',
        ];
    }
}
