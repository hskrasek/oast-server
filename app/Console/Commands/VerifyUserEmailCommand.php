<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Identity\CanonicalEmail;
use App\Models\User;
use Illuminate\Console\Command;
use Override;

final class VerifyUserEmailCommand extends Command
{
    #[Override]
    protected $signature = 'oast:user:verify {email}';

    #[Override]
    protected $description = 'Mark a user email as verified.';

    public function handle(): int
    {
        $user = User::query()->where('email', CanonicalEmail::from((string) $this->argument('email')))->first();

        if (! $user instanceof User) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        $this->info('Email verified.');

        return self::SUCCESS;
    }
}
