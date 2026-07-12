<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationRole;
use Database\Factories\OrganizationMembershipFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

final class OrganizationMembership extends Model
{
    /** @use HasFactory<OrganizationMembershipFactory> */
    use HasFactory;

    #[Override]
    protected $fillable = ['organization_id', 'user_id', 'role'];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['role' => OrganizationRole::class];
    }
}
