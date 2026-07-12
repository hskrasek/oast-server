<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Identity\CanonicalEmail;
use App\Identity\PasswordRules;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Override;

final class ResetUserPasswordCommand extends Command
{
    #[Override]
    protected $signature = 'oast:user:password {email}';

    #[Override]
    protected $description = 'Reset a user password.';

    public function handle(): int
    {
        $user = User::query()->where('email', CanonicalEmail::from((string) $this->argument('email')))->first();

        if (! $user instanceof User) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        $rawPassword = $this->secret('New password');
        $rawConfirmation = $this->secret('Confirm password');
        $password = is_string($rawPassword) ? $rawPassword : '';
        $confirmation = is_string($rawConfirmation) ? $rawConfirmation : '';

        if (! hash_equals($password, $confirmation)) {
            $this->error('Passwords do not match.');

            return self::FAILURE;
        }

        $validator = Validator::make(['password' => $password], ['password' => PasswordRules::base()]);

        if ($validator->fails()) {
            $this->error($validator->errors()->first('password'));

            return self::FAILURE;
        }

        $user->forceFill(['password' => Hash::make($password)])->save();
        $this->info('Password updated.');

        return self::SUCCESS;
    }
}
