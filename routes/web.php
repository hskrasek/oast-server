<?php

declare(strict_types=1);

use App\Http\Controllers\AccountPasswordController;
use App\Http\Controllers\AccountSettingsController;
use App\Http\Controllers\App\ReviewIndexController as AppReviewIndexController;
use App\Http\Controllers\DeleteReviewController;
use App\Http\Controllers\InvitationAcceptanceController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MembershipController;
use App\Http\Controllers\OrganizationInvitationController;
use App\Http\Controllers\OrganizationSettingsController;
use App\Http\Controllers\OwnershipTransferController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\ReviewEventsController;
use App\Http\Controllers\SetupAuthorizationController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\ShowReviewController;
use App\Http\Controllers\Site\ConfirmSubscriptionController;
use App\Http\Controllers\Site\HomeController;
use App\Http\Controllers\Site\OgImageController;
use App\Http\Controllers\Site\ReviewIndexController;
use App\Http\Controllers\Site\ReviewShowController;
use App\Http\Controllers\Site\SubscribeController;
use App\Http\Controllers\TokenSettingsController;
use App\Site\Og\OgTemplate;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::view('/why', 'site.why')->name('why');
Route::get('/reviews', ReviewIndexController::class)->name('reviews.index');
Route::get('/reviews/{slug}', ReviewShowController::class)->name('reviews.show');

// Public OG image endpoint — crawlers hit this; it calls Cloudflare Browser
// Rendering. Outside session middleware so no Set-Cookie defeats edge caching.
Route::get('/og/{file}.png', OgImageController::class)
    ->where('file', '[A-Za-z0-9-]+')
    ->middleware('throttle:60,1')
    ->withoutMiddleware([
        // Strip session/cookie middleware so no Set-Cookie is emitted (which would
        // make Cloudflare refuse to cache the PNG). ShareErrorsFromSession and
        // PreventRequestForgery must go too — both call $request->session() (the
        // latter to stamp an XSRF-TOKEN cookie even on GET) and throw once
        // StartSession is gone.
        Illuminate\Session\Middleware\StartSession::class,
        Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        Illuminate\View\Middleware\ShareErrorsFromSession::class,
        Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
    ])
    ->name('og.image');

// Local-only HTML previews for iterating on the card design in a browser.
if (app()->environment('local')) {
    Route::get('/og/preview', fn(OgTemplate $template) => $template->home())->name('og.preview.home');
    Route::get('/og/preview/{slug}', function (string $slug, OgTemplate $template, App\Site\PublicationRepository $publications) {
        return $template->review($publications->find($slug) ?? abort(404));
    })->name('og.preview.review');
}

Route::post('/subscribe', SubscribeController::class)
    ->middleware('throttle:subscribe')->name('subscribe');
Route::get('/subscribe/confirm/{email}', ConfirmSubscriptionController::class)
    ->middleware('signed')->name('subscribe.confirm');

Route::get('/invitations/{token}', [InvitationController::class, 'show'])->where('token', '[a-f0-9]{64}')->middleware(['installation', 'throttle:30,1'])->name('invitations.show');
Route::post('/invitations/{token}/login', [InvitationController::class, 'startLogin'])->where('token', '[a-f0-9]{64}')->middleware(['installation', 'throttle:10,1'])->name('invitations.start-login');
Route::post('/invitations/{token}/register', [InvitationController::class, 'startRegistration'])->where('token', '[a-f0-9]{64}')->middleware(['installation', 'throttle:10,1'])->name('invitations.start-registration');
Route::post('/invitations/{token}/accept', InvitationAcceptanceController::class)->where('token', '[a-f0-9]{64}')->middleware(['installation', 'auth', 'throttle:10,1'])->name('invitations.accept');

Route::get('/setup', [SetupController::class, 'show'])->name('setup.show');
Route::post('/setup/authorize', SetupAuthorizationController::class)->middleware('throttle:5,1')->name('setup.authorize');
Route::post('/setup', [SetupController::class, 'store'])->middleware('throttle:5,1')->name('setup.store');
Route::prefix('app')->name('app.')->middleware(['installation', 'auth', 'verified.configured'])->group(function (): void {
    Route::get('/settings/account', [AccountSettingsController::class, 'show'])->name('settings.account.show');
    Route::patch('/settings/account', [AccountSettingsController::class, 'update'])->middleware('password.confirm')->name('settings.account.update');
    Route::put('/settings/account/password', AccountPasswordController::class)->middleware('password.confirm')->name('settings.account.password.update');
    Route::middleware('organization')->group(function (): void {
        Route::view('/', 'app.home')->name('home');
        Route::get('/settings/tokens', [TokenSettingsController::class, 'index'])->name('settings.tokens.index');
        Route::post('/settings/tokens', [TokenSettingsController::class, 'store'])->middleware('password.confirm')->name('settings.tokens.store');
        Route::delete('/settings/tokens/{token}', [TokenSettingsController::class, 'destroy'])->middleware('password.confirm')->name('settings.tokens.destroy');
        Route::prefix('settings/organization')->name('settings.organization.')->group(function (): void {
            Route::get('/', OrganizationSettingsController::class)->name('show');
            Route::patch('/', [OrganizationSettingsController::class, 'update'])->name('update');
            Route::post('/invitations', [OrganizationInvitationController::class, 'store'])->name('invitations.store');
            Route::delete('/invitations/{invitation}', [OrganizationInvitationController::class, 'destroy'])->name('invitations.destroy');
            Route::delete('/members/{membership}', [MembershipController::class, 'destroy'])->middleware('password.confirm')->name('members.destroy');
            Route::post('/ownership', OwnershipTransferController::class)->middleware('password.confirm')->name('ownership.transfer');
        });
    });
});

Route::prefix('app/reviews')
    ->name('app.reviews.')
    ->middleware(['installation', 'auth', 'verified.configured', 'organization'])
    ->group(function (): void {
        Route::get('/', AppReviewIndexController::class)->name('index');
        // ponytail: CreateReviewController lands with M3B T4. A closure keeps the
        // named route resolvable now (this test suite generates its URL) without a
        // forward reference to a class that doesn't exist yet — that would fatal
        // route-file load (Router::createRoute() -> RouteAction::parse() resolves
        // invokable-controller strings eagerly inside a route group) and fail
        // PHPStan's class.notFound check. T4 replaces this with the real controller.
        Route::get('/create', fn() => abort(501))->name('create');
        // M3A store/show stay on the existing controllers until T4/T5 replace them —
        // repointing now would 500 AbuseControlsTest, which exercises the real
        // abuse-ceiling behavior through the M3A ReviewController@store action.
        Route::post('/', [ReviewController::class, 'store'])->name('store');
        Route::get('/{review}', ShowReviewController::class)->name('show');
        Route::get('/{review}/events', ReviewEventsController::class)->name('events');
        Route::delete('/{review}', DeleteReviewController::class)->name('destroy');
    });
