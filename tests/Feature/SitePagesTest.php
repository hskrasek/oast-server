<?php

declare(strict_types=1);

use App\Site\PublicationRepository;

beforeEach(function (): void {
    app()->bind(PublicationRepository::class, fn(): PublicationRepository => new PublicationRepository(base_path('tests/fixtures/publications')));
});

it('renders the homepage with concept copy, featured reviews, and the signup form', function (): void {
    $this->get('/')->assertOk()
        ->assertSee('Notify me')
        ->assertSee('name="website"', escape: false)   // honeypot present
        ->assertSee('The Council vs. a well-designed spec')  // featured publication headline
        ->assertSee('consensus')
        ->assertDontSee('Payment flow retry safety');  // 4th fixture is older, not in top 3
});

it('renders the reviews index', function (): void {
    $this->get('/reviews')->assertOk()->assertSee('Train Travel API');
});

it('renders a review page with findings, meta, and cost', function (): void {
    $this->get('/reviews/train-travel-domain-modeling')->assertOk()
        ->assertSee('Booking lifecycle never modeled as data')
        ->assertSee('blocker')
        ->assertSee('$0.62')
        ->assertSee('anthropic/claude-opus-4.8');
});

it('404s unknown review slugs', function (): void {
    $this->get('/reviews/nope')->assertNotFound();
});

it('renders commentary as markdown and strips unsafe HTML', function (): void {
    $this->get('/reviews/slack-domain-modeling')->assertOk()
        ->assertSee('<em>famously</em>', escape: false)  // markdown emphasis rendered
        ->assertDontSee('<script>');  // script tags stripped for safety
});

it('renders disagreement text for a non-split finding, not just split confidence', function (): void {
    app()->bind(PublicationRepository::class, fn(): PublicationRepository => new PublicationRepository(base_path('tests/fixtures/publications-disagreement')));

    $this->get('/reviews/majority-disagreement')->assertOk()
        ->assertSee('majority')
        ->assertSee('One panelist dissented: this is intentional denormalization, not a modeling gap.');
});

it('honors X-Forwarded-Proto from the trusted tunnel proxy when generating asset urls', function (): void {
    $html = $this->withHeaders(['X-Forwarded-Proto' => 'https'])
        ->get('/')
        ->assertOk()
        ->getContent();

    expect($html)->toContain('src="https://')
        ->and($html)->not->toContain('src="http://');
});
