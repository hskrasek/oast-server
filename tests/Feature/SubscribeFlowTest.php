<?php

declare(strict_types=1);

use App\Mail\ConfirmSubscription;
use App\Site\Newsletter\NewsletterContacts;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Tests\Support\FakeNewsletterContacts;
use Tests\Support\ThrowingOnConfirmNewsletterContacts;

beforeEach(function (): void {
    Mail::fake();
    RateLimiter::clear('subscribe:127.0.0.1');
    $this->fake = new FakeNewsletterContacts();
    app()->instance(NewsletterContacts::class, $this->fake);
});

it('subscribes, creates an unconfirmed contact, and mails a signed confirm link', function (): void {
    $this->post('/subscribe', ['email' => 'a@b.test', 'website' => ''])
        ->assertRedirect('/')
        ->assertSessionHas('status');

    expect($this->fake->created)->toBe(['a@b.test']);
    Mail::assertSent(ConfirmSubscription::class, fn(ConfirmSubscription $mail): bool => $mail->hasTo('a@b.test'));
});

it('silently ignores honeypot submissions', function (): void {
    $this->post('/subscribe', ['email' => 'bot@spam.test', 'website' => 'http://spam'])
        ->assertRedirect('/');

    expect($this->fake->created)->toBe([]);
    Mail::assertNothingSent();
});

it('rejects invalid emails', function (): void {
    $this->from('/')->post('/subscribe', ['email' => 'not-an-email', 'website' => ''])
        ->assertRedirect('/')
        ->assertSessionHasErrors('email');
});

it('rate limits after 5 attempts per minute', function (): void {
    foreach (range(1, 5) as $i) {
        $this->post('/subscribe', ['email' => "a{$i}@b.test", 'website' => '']);
    }

    $this->post('/subscribe', ['email' => 'a6@b.test', 'website' => ''])->assertStatus(429);
});

it('rate limits per real client ip resolved via the Cloudflare-set CF-Connecting-IP header', function (): void {
    RateLimiter::clear('subscribe:10.0.0.1');
    RateLimiter::clear('subscribe:10.0.0.2');

    foreach (range(1, 5) as $i) {
        $this->withHeaders(['CF-Connecting-IP' => '10.0.0.1'])
            ->post('/subscribe', ['email' => "ip1-{$i}@b.test", 'website' => ''])
            ->assertRedirect('/');
    }

    // 6th request from the same CF-reported IP is throttled.
    $this->withHeaders(['CF-Connecting-IP' => '10.0.0.1'])
        ->post('/subscribe', ['email' => 'ip1-6@b.test', 'website' => ''])
        ->assertStatus(429);

    // A different CF-reported IP has its own bucket and still passes.
    $this->withHeaders(['CF-Connecting-IP' => '10.0.0.2'])
        ->post('/subscribe', ['email' => 'ip2-1@b.test', 'website' => ''])
        ->assertRedirect('/');
});

it('does not let a client-supplied X-Forwarded-Host leak into the signed confirm link', function (): void {
    $this->withHeaders(['X-Forwarded-Host' => 'evil.com'])
        ->post('/subscribe', ['email' => 'a@b.test', 'website' => ''])
        ->assertRedirect('/');

    Mail::assertSent(ConfirmSubscription::class, function (ConfirmSubscription $mail): bool {
        $confirmUrl = $mail->content()->with['confirmUrl'];

        expect($confirmUrl)->not->toContain('evil.com');

        return true;
    });
});

it('confirms via a signed link', function (): void {
    $url = URL::signedRoute('subscribe.confirm', ['email' => 'a@b.test']);

    $this->get($url)->assertOk()->assertSee('confirmed', escape: false);
    expect($this->fake->confirmed)->toBe(['a@b.test']);
});

it('rejects a tampered confirm link', function (): void {
    $this->get(route('subscribe.confirm', ['email' => 'a@b.test']))->assertForbidden();
    expect($this->fake->confirmed)->toBe([]);
});

it('builds confirmation mail with signed link', function (): void {
    $mailable = new ConfirmSubscription('a@b.test');

    expect($mailable->envelope()->subject)->toBe('Confirm your oast.sh subscription')
        ->and($mailable->content()->view)->toBe('mail.confirm-subscription')
        ->and($mailable->content()->with['confirmUrl'])->toContain('/subscribe/confirm/a@b.test');
});

it('logs mail failure but still succeeds with idempotent create', function (): void {
    Mail::shouldReceive('to')->with('a@b.test')->andThrow(new Exception('Mail send failed'));

    $this->post('/subscribe', ['email' => 'a@b.test', 'website' => ''])
        ->assertRedirect('/')
        ->assertSessionHas('status', 'Check your inbox to confirm.');

    expect($this->fake->created)->toBe(['a@b.test']);
});

it('confirms idempotently when SES reports the contact was never found', function (): void {
    app()->instance(NewsletterContacts::class, new ThrowingOnConfirmNewsletterContacts('NotFoundException'));

    $url = URL::signedRoute('subscribe.confirm', ['email' => 'a@b.test']);

    $this->get($url)->assertOk()->assertSee('confirmed', escape: false);
});

it('surfaces other SES failures as a server error', function (): void {
    app()->instance(NewsletterContacts::class, new ThrowingOnConfirmNewsletterContacts('BadRequestException'));

    $url = URL::signedRoute('subscribe.confirm', ['email' => 'a@b.test']);

    $this->get($url)->assertStatus(500);
});
