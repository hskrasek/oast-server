<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Identity\CreateNewUser;
use App\Actions\Identity\ResetUserPassword;
use App\Actions\Identity\UpdateUserPassword;
use App\Actions\Identity\UpdateUserProfileInformation;
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
    // ponytail: pre-computed bcrypt hash for timing attack mitigation, constant literal is safe
    private const string DUMMY_PASSWORD_HASH = '$2y$12$S9QoSzsS5SEKYzIVZvuBe.Z4xyHLjRMsJPjB1q8UkE3L5SBWyQsVG';

    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::registerView(fn(Request $request) => view('auth.register', [
            'token' => $request->session()->get('oast.invitation.token'),
        ]));
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
            $password = $request->string('password')->value();
            $hash = $user instanceof User ? $user->password : self::DUMMY_PASSWORD_HASH;
            if (! Hash::check($password, $hash) || ! $user instanceof User) {
                throw ValidationException::withMessages(['email' => __('auth.failed')]);
            }
            return $user;
        });

        RateLimiter::for('login', fn(Request $request): Limit => Limit::perMinute(5)->by(
            CanonicalEmail::from($request->string('email')->value()) . '|' . $request->ip(),
        ));
    }
}
