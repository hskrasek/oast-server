<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

final class Installation extends Model
{
    #[Override]
    public $timestamps = false;

    #[Override]
    protected $table = 'installation';

    #[Override]
    protected $fillable = ['bootstrapped_at', 'default_organization_id'];

    /** @return BelongsTo<Organization, $this> */
    public function defaultOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'default_organization_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['bootstrapped_at' => 'datetime'];
    }
}
