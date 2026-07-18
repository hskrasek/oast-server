<?php

declare(strict_types=1);

use App\Models\PersonalAccessToken;
use App\Models\Review;
use App\Models\User;
use App\Organizations\MissingOrganizationMembership;
use App\Organizations\OrganizationContext;
use Illuminate\Http\Request;

it('refuses to resolve a membership for a guest request', function (): void {
    $context = new OrganizationContext(new Request);

    expect(fn() => $context->membership())->toThrow(MissingOrganizationMembership::class);
});

it('reports a guest as not still authorized', function (): void {
    $review = Review::factory()->create();
    $request = new Request;
    $request->setUserResolver(fn(): ?User => null);

    expect(new OrganizationContext($request)->stillAuthorized($review))->toBeFalse();
});

it('reports not authorized once the membership backing the request is gone', function (): void {
    [$user, $organization, $membership] = memberFixture();
    $review = Review::factory()->for($organization)->create();
    $request = new Request;
    $request->setUserResolver(fn(): User => $user);
    $context = new OrganizationContext($request);
    $context->membership();
    $membership->delete();

    expect($context->stillAuthorized($review))->toBeFalse();
});

it('reports not authorized when the review belongs to another organization', function (): void {
    [$user] = memberFixture();
    $review = Review::factory()->create();
    $request = new Request;
    $request->setUserResolver(fn(): User => $user);

    expect(new OrganizationContext($request)->stillAuthorized($review))->toBeFalse();
});

it('reports authorized for a member with no access token', function (): void {
    [$user, $organization] = memberFixture();
    $review = Review::factory()->for($organization)->create();
    $request = new Request;
    $request->setUserResolver(fn(): User => $user);

    expect(new OrganizationContext($request)->stillAuthorized($review))->toBeTrue();
});

it('reports authorized for a live token scoped to the organization', function (): void {
    [$user, $organization] = memberFixture();
    $review = Review::factory()->for($organization)->create();
    $token = PersonalAccessToken::factory()->for($user, 'tokenable')->for($organization)->create(['expires_at' => now()->addDay()]);
    $user->withAccessToken($token);
    $request = new Request;
    $request->setUserResolver(fn(): User => $user);

    expect(new OrganizationContext($request)->stillAuthorized($review))->toBeTrue();
});

it('reports unauthorized for a revoked token', function (): void {
    [$user, $organization] = memberFixture();
    $review = Review::factory()->for($organization)->create();
    $token = PersonalAccessToken::factory()->for($user, 'tokenable')->for($organization)->create(['revoked_at' => now()]);
    $user->withAccessToken($token);
    $request = new Request;
    $request->setUserResolver(fn(): User => $user);

    expect(new OrganizationContext($request)->stillAuthorized($review))->toBeFalse();
});

it('reports unauthorized for an expired token', function (): void {
    [$user, $organization] = memberFixture();
    $review = Review::factory()->for($organization)->create();
    $token = PersonalAccessToken::factory()->for($user, 'tokenable')->for($organization)->create(['expires_at' => now()->subMinute()]);
    $user->withAccessToken($token);
    $request = new Request;
    $request->setUserResolver(fn(): User => $user);

    expect(new OrganizationContext($request)->stillAuthorized($review))->toBeFalse();
});
