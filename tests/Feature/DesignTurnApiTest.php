<?php

declare(strict_types=1);

use App\Ai\Agents\Designer;
use App\Http\Problems\ProblemType;

const DRAFT = "openapi: 3.1.0\ninfo:\n  title: Library\n  version: 0.1.0\n";

beforeEach(function (): void {
    config(['oast.api_domain' => 'api.oast.test']);
});

it('returns the drafted spec and reply', function (): void {
    Designer::fake([['spec' => DRAFT, 'reply' => 'Started the spec.']]);

    $this->postJson("https://{$this->apiHost()}/design/turns", [
        'spec' => '',
        'message' => 'Library lending API. Books, members, loans.',
        'history' => [],
    ])
        ->assertOk()
        ->assertExactJson(['spec' => DRAFT, 'reply' => 'Started the spec.']);
});

it('accepts a request without history', function (): void {
    Designer::fake([['spec' => DRAFT, 'reply' => 'ok']]);

    $this->postJson("https://{$this->apiHost()}/design/turns", ['spec' => DRAFT, 'message' => 'add books'])
        ->assertOk();
});

it('returns problem+json when the designer output stays invalid', function (): void {
    Designer::fake([
        ['spec' => 'info: [unclosed', 'reply' => 'a'],
        ['spec' => 'info: [unclosed', 'reply' => 'b'],
    ]);

    $this->postJson("https://{$this->apiHost()}/design/turns", ['spec' => DRAFT, 'message' => 'x'])
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', ProblemType::InvalidDesignerOutput->value)
        ->assertJsonPath('title', 'Designer produced an invalid spec')
        ->assertJsonCount(2, 'errors');
});

it('rejects a missing message', function (): void {
    $this->postJson("https://{$this->apiHost()}/design/turns", ['spec' => DRAFT])
        ->assertStatus(422)
        ->assertJsonPath('type', ProblemType::Validation->value)
        ->assertJsonPath('errors.message.0', fn($msg) => filled($msg));
});

it('rejects a missing spec key', function (): void {
    $this->postJson("https://{$this->apiHost()}/design/turns", ['message' => 'x'])
        ->assertStatus(422)
        ->assertJsonPath('type', ProblemType::Validation->value)
        ->assertJsonPath('errors.spec.0', fn($msg) => filled($msg));
});

it('rejects a spec over 1 MB', function (): void {
    $this->postJson("https://{$this->apiHost()}/design/turns", [
        'spec' => str_repeat('a', 1048577),
        'message' => 'x',
    ])
        ->assertStatus(422)
        ->assertJsonPath('errors.spec.0', fn($msg) => filled($msg));
});

it('rejects a history entry with an unknown role', function (): void {
    $this->postJson("https://{$this->apiHost()}/design/turns", [
        'spec' => DRAFT,
        'message' => 'x',
        'history' => [['role' => 'system', 'content' => 'nope']],
    ])
        ->assertStatus(422)
        // Laravel keys this "history.0.role" as one flat, literally-dotted
        // key (not a nested "history" -> 0 -> "role" structure), and
        // Arr::get (which assertJsonPath uses under the hood) has no dot
        // escaping, so it cannot address that key via a single dot path.
        // Assert on the exact key directly instead.
        ->assertJsonPath('errors', fn($errors) => filled($errors['history.0.role'][0] ?? null));
});

it('rejects a history entry whose content is over 16 KB', function (): void {
    $this->postJson("https://{$this->apiHost()}/design/turns", [
        'spec' => DRAFT,
        'message' => 'x',
        'history' => [['role' => 'user', 'content' => str_repeat('a', 16385)]],
    ])
        ->assertStatus(422)
        ->assertJsonPath('type', ProblemType::Validation->value)
        ->assertJsonPath('errors', fn(array $errors): bool => filled($errors['history.0.content'][0] ?? null));
});

it('retries once over HTTP and returns the second draft', function (): void {
    Designer::fake([
        ['spec' => 'info: [unclosed', 'reply' => 'first'],
        ['spec' => DRAFT, 'reply' => 'second'],
    ]);

    $this->postJson("https://{$this->apiHost()}/design/turns", ['spec' => DRAFT, 'message' => 'add books'])
        ->assertOk()
        ->assertJsonPath('reply', 'second');

    Designer::assertPrompted(fn(object $prompt): bool => str_contains($prompt->prompt, 'Your previous spec did not parse'));
});
