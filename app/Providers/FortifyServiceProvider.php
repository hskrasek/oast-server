<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Identity\CreateNewUser;
use App\Actions\Identity\ResetUserPassword;
use App\Actions\Identity\UpdateUserPassword;
use App\Actions\Identity\UpdateUserProfileInformation;
use App\Identity\CanonicalEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;

final class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::registerView(fn(Request $request): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View => view('auth.register', [
            'token' => $request->session()->get('oast.invitation.token'),
        ]));
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::loginView(fn(): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View => view('auth.login'));
        Fortify::requestPasswordResetLinkView(fn(): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn(Request $request): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View => view('auth.reset-password', ['request' => $request]));
        Fortify::verifyEmailView(fn(): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View => view('auth.verify-email'));
        Fortify::confirmPasswordView(fn(): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View => view('auth.confirm-password'));

        RateLimiter::for('login', fn(Request $request): Limit => Limit::perMinute(5)->by(
            CanonicalEmail::from($request->string('email')->value()) . '|' . $request->ip(),
        ));
    }
}
