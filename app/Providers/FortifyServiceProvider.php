<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Identity\CreateNewUser;
use App\Actions\Identity\ResetUserPassword;
use App\Actions\Identity\UpdateUserPassword;
use App\Actions\Identity\UpdateUserProfileInformation;
use App\Http\Responses\GenericPasswordResetLinkResponse;
use App\Identity\CanonicalEmail;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse as FailedPasswordResetLinkRequestResponseContract;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse as SuccessfulPasswordResetLinkRequestResponseContract;
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
        Fortify::registerView(fn(Request $request): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View => view('auth.register', [
            'token' => $request->session()->get('oast.invitation.token'),
        ]));
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        /** @var view-string $login */
        $login = 'auth.login';
        Fortify::loginView(fn(): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View => view($login));
        /** @var view-string $forgotPassword */
        $forgotPassword = 'auth.forgot-password';
        Fortify::requestPasswordResetLinkView(fn(): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View => view($forgotPassword));
        /** @var view-string $resetPassword */
        $resetPassword = 'auth.reset-password';
        Fortify::resetPasswordView(fn(Request $request): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View => view($resetPassword, ['request' => $request]));
        /** @var view-string $verifyEmail */
        $verifyEmail = 'auth.verify-email';
        Fortify::verifyEmailView(fn(): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View => view($verifyEmail));
        /** @var view-string $confirmPassword */
        $confirmPassword = 'auth.confirm-password';
        Fortify::confirmPasswordView(fn(): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View => view($confirmPassword));

        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::query()->where('email', CanonicalEmail::from($request->string('email')->value()))->first();
            $password = $request->string('password')->value();
            $hash = $user instanceof User ? $user->password : self::DUMMY_PASSWORD_HASH;
            // Returning null (never throwing) lets Fortify's AttemptToAuthenticate
            // increment the login limiter and fire the Failed event.
            if (! Hash::check($password, $hash) || ! $user instanceof User) {
                return null;
            }

            if (Hash::needsRehash($user->password)) {
                $user->forceFill(['password' => Hash::make($password)])->save();
            }

            return $user;
        });

        RateLimiter::for('login', fn(Request $request): Limit => Limit::perMinute(5)->by(
            CanonicalEmail::from($request->string('email')->value()) . '|' . $request->ip(),
        ));

        RateLimiter::for('forgot-password', fn(Request $request): Limit => Limit::perMinute(5)->by(
            CanonicalEmail::from($request->string('email')->value()) . '|' . $request->ip(),
        ));

        // By IP alone: registration is anonymous, so keying on submitted email
        // would let a probing client rotate addresses past the limiter.
        RateLimiter::for('register', fn(Request $request): Limit => Limit::perMinute(5)->by((string) $request->ip()));

        // Fortify's own route registration only reads config('fortify.limiters.*')
        // for login/two-factor/passkeys/verification — password.email (POST
        // /forgot-password) is registered with no limiter hook at all, so the
        // 'forgot-password' limiter above can't be wired through config the way
        // the others are. Attach it directly to the already-registered named
        // route instead, once every provider (including Fortify's) has booted.
        //
        // Deliberately NOT RouteFacade::getRoutes()->getByName(): Fortify calls
        // ->name() on the route object *after* Router::post() already added it
        // to the RouteCollection, so RouteCollection's name-indexed lookup
        // ($nameList) is built while the route is still unnamed and never gets
        // refreshed until Illuminate's own RouteServiceProvider does so later
        // in this same booted-callback queue. Scanning getName() directly reads
        // the route object's current name instead of that stale index.
        $this->app->booted(function (): void {
            $limited = ['password.email' => 'throttle:forgot-password', 'register.store' => 'throttle:register'];
            foreach (RouteFacade::getRoutes()->getRoutes() as $candidate) {
                $throttle = $limited[$candidate->getName() ?? ''] ?? null;
                if ($throttle !== null) {
                    $candidate->middleware($throttle);
                }
            }
        });

        // Both the "link sent" and "no such user" responses must be
        // byte-identical, or the response itself becomes an enumeration
        // oracle regardless of timing/throttling.
        $this->app->bind(FailedPasswordResetLinkRequestResponseContract::class, GenericPasswordResetLinkResponse::class);
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponseContract::class, GenericPasswordResetLinkResponse::class);
    }
}
