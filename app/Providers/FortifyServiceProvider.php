<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Identity\ResetUserPassword;
use App\Identity\CanonicalEmail;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

final class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        /** @var view-string $login */
        $login = 'auth.login';
        Fortify::loginView(fn() => view($login));
        /** @var view-string $forgotPassword */
        $forgotPassword = 'auth.forgot-password';
        Fortify::requestPasswordResetLinkView(fn() => view($forgotPassword));
        /** @var view-string $resetPassword */
        $resetPassword = 'auth.reset-password';
        Fortify::resetPasswordView(fn(Request $request) => view($resetPassword, ['request' => $request]));
        /** @var view-string $verifyEmail */
        $verifyEmail = 'auth.verify-email';
        Fortify::verifyEmailView(fn() => view($verifyEmail));
        /** @var view-string $confirmPassword */
        $confirmPassword = 'auth.confirm-password';
        Fortify::confirmPasswordView(fn() => view($confirmPassword));

        Fortify::authenticateUsing(function (Request $request): User {
            $user = User::query()->where('email', CanonicalEmail::from($request->string('email')->value()))->first();
            if (! $user instanceof User || ! Hash::check($request->string('password')->value(), $user->password)) {
                throw ValidationException::withMessages(['email' => __('auth.failed')]);
            }
            return $user;
        });

        RateLimiter::for('login', fn(Request $request): Limit => Limit::perMinute(5)->by(
            CanonicalEmail::from($request->string('email')->value()) . '|' . $request->ip(),
        ));
    }
}
