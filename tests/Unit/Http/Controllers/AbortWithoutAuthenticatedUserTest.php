<?php

declare(strict_types=1);

use App\Actions\Identity\UpdateUserPassword;
use App\Actions\Identity\UpdateUserProfileInformation;
use App\Http\Controllers\AccountPasswordController;
use App\Http\Controllers\AccountSettingsController;
use App\Http\Controllers\MembershipController;
use App\Http\Controllers\OrganizationInvitationController;
use App\Http\Controllers\OwnershipTransferController;
use App\Http\Controllers\ReviewEventsController;
use App\Http\Controllers\TokenSettingsController;
use App\Http\Requests\CreateInvitationRequest;
use App\Http\Requests\CreatePersonalAccessTokenRequest;
use App\Http\Requests\TransferOwnershipRequest;
use App\Http\Requests\UpdateAccountPasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\OrganizationMembership;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Organizations\MembershipService;
use App\Organizations\OrganizationContext;
use App\Tokens\PersonalAccessTokenService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
|--------------------------------------------------------------------------
| Unreachable-by-HTTP `instanceof User` abort(403) branches
|--------------------------------------------------------------------------
|
| Every route these controllers sit behind requires the `auth` middleware,
| which guarantees $request->user() is a real User before the controller
| ever runs. The `if (! $user instanceof User) abort(403);` idiom exists as
| defense-in-depth (and to narrow the type for static analysis), so it can
| never be exercised through a real HTTP request. Each test below invokes
| the controller method directly with a crafted Request whose own user
| resolver returns null, while any Gate/OrganizationContext dependency that
| needs a real actor is fed a *different* Request/guard so those checks
| still pass before the abort is reached.
*/

function nullUserRequest(): Request
{
    $request = Request::create('/');
    $request->setUserResolver(fn() => null);

    return $request;
}

it('aborts TokenSettingsController::index without an authenticated user', function (): void {
    $request = nullUserRequest();
    $context = new OrganizationContext($request);
    expect(fn() => (new TokenSettingsController)->index($request, $context))->toThrow(HttpException::class);
});

it('aborts TokenSettingsController::store without an authenticated user', function (): void {
    $request = new CreatePersonalAccessTokenRequest;
    $request->setUserResolver(fn() => null);
    $context = new OrganizationContext($request);
    expect(fn() => (new TokenSettingsController)->store($request, $context, app(PersonalAccessTokenService::class)))
        ->toThrow(HttpException::class);
});

it('aborts TokenSettingsController::destroy without an authenticated user', function (): void {
    $token = PersonalAccessToken::factory()->create();
    $request = nullUserRequest();
    $context = new OrganizationContext($request);
    expect(fn() => (new TokenSettingsController)->destroy($request, $token, $context, app(PersonalAccessTokenService::class)))
        ->toThrow(HttpException::class);
});

it('aborts AccountSettingsController::show without an authenticated user', function (): void {
    expect(fn() => (new AccountSettingsController)->show(nullUserRequest()))->toThrow(HttpException::class);
});

it('aborts AccountSettingsController::update without an authenticated user', function (): void {
    $request = new UpdateProfileRequest;
    $request->setUserResolver(fn() => null);
    expect(fn() => (new AccountSettingsController)->update($request, app(UpdateUserProfileInformation::class)))
        ->toThrow(HttpException::class);
});

it('aborts AccountPasswordController without an authenticated user', function (): void {
    $request = new UpdateAccountPasswordRequest;
    $request->setUserResolver(fn() => null);
    expect(fn() => (new AccountPasswordController)($request, app(UpdateUserPassword::class)))
        ->toThrow(HttpException::class);
});

it('aborts MembershipController::destroy without an authenticated user once authorized', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $target = OrganizationMembership::factory()->for($organization)->for(User::factory())->create();
    $this->actingAs($owner);
    $contextRequest = Request::create('/');
    $contextRequest->setUserResolver(fn() => $owner);
    $context = new OrganizationContext($contextRequest);
    $request = nullUserRequest();
    expect(fn() => (new MembershipController)->destroy($request, (string) $target->id, $context, app(MembershipService::class)))
        ->toThrow(HttpException::class);
});

it('aborts OrganizationInvitationController::store without an authenticated user once authorized', function (): void {
    [$owner] = memberFixture(role: 'owner');
    $this->actingAs($owner);
    $contextRequest = Request::create('/');
    $contextRequest->setUserResolver(fn() => $owner);
    $context = new OrganizationContext($contextRequest);
    $request = new CreateInvitationRequest;
    $request->merge(['email' => 'invited@example.test']);
    $request->setUserResolver(fn() => null);
    expect(fn() => (new OrganizationInvitationController)->store($request, $context, app(App\Organizations\InvitationService::class)))
        ->toThrow(HttpException::class);
});

it('aborts OwnershipTransferController without an authenticated user', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $target = OrganizationMembership::factory()->for($organization)->for(User::factory())->create();
    $contextRequest = Request::create('/');
    $contextRequest->setUserResolver(fn() => $owner);
    $context = new OrganizationContext($contextRequest);
    $request = new TransferOwnershipRequest;
    $request->merge(['membership_id' => $target->id]);
    $request->setUserResolver(fn() => null);
    expect(fn() => (new OwnershipTransferController)($request, $context, app(MembershipService::class)))
        ->toThrow(HttpException::class);
});

it('aborts ReviewEventsController::principal without an authenticated user or token', function (): void {
    $request = nullUserRequest();
    $context = new OrganizationContext($request);
    $method = new ReflectionMethod(ReviewEventsController::class, 'principal');
    expect(fn() => $method->invoke(new ReviewEventsController, $request, $context))
        ->toThrow(HttpException::class);
});
