<?php

declare(strict_types=1);

use App\Actions\Identity\DeleteUserAction;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Validation\ValidationException;

it('rejects deleting a final owner through the only app-owned deletion path', function (): void {
    [$owner] = memberFixture(role: 'owner');
    expect(fn() => app(DeleteUserAction::class)($owner))->toThrow(ValidationException::class);
    expect($owner->fresh())->not->toBeNull();
});

it('deletes a non-final member through the guarded action', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $member = User::factory()->create();
    OrganizationMembership::factory()->for($organization)->for($member)->create();
    app(DeleteUserAction::class)($member);
    expect($member->fresh())->toBeNull();
});
