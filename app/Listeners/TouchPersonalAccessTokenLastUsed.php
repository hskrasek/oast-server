<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\PersonalAccessToken;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Sanctum\Events\TokenAuthenticated;

final class TouchPersonalAccessTokenLastUsed
{
    public function handle(TokenAuthenticated $event): void
    {
        if (! $event->token instanceof PersonalAccessToken) {
            return;
        }

        PersonalAccessToken::query()->whereKey($event->token->id)
            ->where(fn(Builder $query): Builder => $query->whereNull('last_used_at')->orWhere('last_used_at', '<=', now()->subMinute()))
            ->update(['last_used_at' => now()]);
    }
}
