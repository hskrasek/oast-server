<?php

declare(strict_types=1);

use App\Models\Review;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('creates the identity schema and singleton installation row', function (): void {
    expect(Schema::hasColumns('organizations', ['id', 'name']))->toBeTrue()
        ->and(Schema::hasColumns('organization_memberships', ['organization_id', 'user_id', 'role']))->toBeTrue()
        ->and(Schema::hasColumns('organization_invitations', ['token_hash', 'accepted_at', 'revoked_at']))->toBeTrue()
        ->and(Schema::hasColumns('personal_access_tokens', ['organization_id', 'revoked_at']))->toBeTrue()
        ->and(Schema::hasColumns('reviews', ['organization_id', 'created_by_user_id']))->toBeTrue()
        ->and(DB::table('installation')->where('id', 1)->whereNull('bootstrapped_at')->exists())->toBeTrue();
});

it('cascades a non-final-owner membership and nulls that users audit references', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $organizationId = DB::table('organizations')->insertGetId([
        'name' => 'Acme', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('organization_memberships')->insert([
        ['organization_id' => $organizationId, 'user_id' => $owner->id, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()],
        ['organization_id' => $organizationId, 'user_id' => $member->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('organization_invitations')->insert([
        'organization_id' => $organizationId, 'invited_by_user_id' => $member->id,
        'email' => 'next@example.test', 'role' => 'member',
        'token_hash' => hash('sha256', 'token'), 'expires_at' => now()->addDay(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $review = Review::factory()->create([
        'organization_id' => $organizationId, 'created_by_user_id' => $member->id,
    ]);

    $member->delete();

    expect(DB::table('organization_memberships')->where('user_id', $member->id)->doesntExist())->toBeTrue()
        ->and(DB::table('organization_memberships')->where('user_id', $owner->id)->where('role', 'owner')->exists())->toBeTrue()
        ->and(DB::table('organization_invitations')->value('invited_by_user_id'))->toBeNull()
        ->and($review->refresh()->created_by_user_id)->toBeNull();
});

it('restricts organization deletion while reviews exist', function (): void {
    $organizationId = DB::table('organizations')->insertGetId([
        'name' => 'Acme', 'created_at' => now(), 'updated_at' => now(),
    ]);
    Review::factory()->create(['organization_id' => $organizationId]);

    expect(fn() => DB::table('organizations')->where('id', $organizationId)->delete())
        ->toThrow(QueryException::class);
});
