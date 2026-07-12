<?php

declare(strict_types=1);

use App\Models\Installation;
use App\Models\Review;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;

beforeEach(function (): void {
    Installation::query()->findOrFail(1)->update(['bootstrapped_at' => now()]);
});

it('renders paste and upload controls', function (): void {
    [$user] = memberFixture();

    $this->actingAs($user)->get('/app/reviews/create')->assertOk()
        ->assertSee('Paste YAML or JSON')
        ->assertSee('Upload a file')
        ->assertSee('name="spec"', false)
        ->assertSee('name="spec_file"', false);
});

it('stores pasted source under trusted organization and creator and returns 202 location', function (): void {
    Bus::fake();
    [$user, $organization] = memberFixture();

    $response = $this->actingAs($user)->post('/app/reviews', [
        'spec' => "openapi: 3.1.0\ninfo:\n  title: Pasted\n",
        'mode' => 'council',
        'dimension' => 'domain-modeling',
        'organization_id' => 999999,
        'created_by_user_id' => 999999,
    ]);

    $review = Review::query()->sole();
    $response->assertAccepted()->assertHeader('Location', route('app.reviews.show', $review->id));
    expect($review->organization_id)->toBe($organization->id)
        ->and($review->created_by_user_id)->toBe($user->id)
        ->and($review->spec_ref)->toBeNull();
});

it('retains uploaded bytes and client filename without reserializing', function (): void {
    Bus::fake();
    [$user] = memberFixture();
    $source = "openapi: 3.1.0\n# retained comment\npaths: {}\n";

    $this->actingAs($user)->post('/app/reviews', [
        'spec_file' => UploadedFile::fake()->createWithContent('petstore.yaml', $source),
        'mode' => 'baseline',
        'dimension' => 'workflows',
    ])->assertAccepted();

    expect(Review::query()->sole()->only(['spec', 'spec_ref']))
        ->toBe(['spec' => $source, 'spec_ref' => 'petstore.yaml']);
});

it('rejects absent simultaneous and oversized sources', function (): void {
    [$user] = memberFixture();

    $this->actingAs($user)->post('/app/reviews', [
        'mode' => 'council', 'dimension' => 'domain-modeling',
    ])->assertSessionHasErrors(['spec', 'spec_file']);
    $this->actingAs($user)->post('/app/reviews', [
        'spec' => 'openapi: 3.1.0',
        'spec_file' => UploadedFile::fake()->createWithContent('also.yaml', 'openapi: 3.1.0'),
        'mode' => 'council', 'dimension' => 'domain-modeling',
    ])->assertSessionHasErrors(['spec', 'spec_file']);
    $this->actingAs($user)->post('/app/reviews', [
        'spec_file' => UploadedFile::fake()->create('huge.yaml', 5121),
        'mode' => 'council', 'dimension' => 'domain-modeling',
    ])->assertSessionHasErrors('spec_file');
});
