<?php

declare(strict_types=1);

use App\Site\Newsletter\SesNewsletterContacts;
use Aws\Command;
use Aws\SesV2\Exception\SesV2Exception;
use Aws\SesV2\SesV2Client;

it('creates an unconfirmed contact', function (): void {
    $client = Mockery::mock(SesV2Client::class);
    $client->shouldReceive('createContact')->once()->with(Mockery::on(
        fn(array $args): bool => $args['ContactListName'] === 'test-list'
            && $args['EmailAddress'] === 'a@b.test'
            && $args['AttributesData'] === '{"confirmed":false}',
    ));

    new SesNewsletterContacts($client, 'test-list')->create('a@b.test');
});

it('treats an already-existing contact as success', function (): void {
    $client = Mockery::mock(SesV2Client::class);
    $command = new Command('CreateContact');
    $client->shouldReceive('createContact')->once()->andThrow(
        new SesV2Exception('exists', $command, ['code' => 'AlreadyExistsException']),
    );

    new SesNewsletterContacts($client, 'test-list')->create('a@b.test');
    expect(true)->toBeTrue();
});

it('rethrows other SES failures', function (): void {
    $client = Mockery::mock(SesV2Client::class);
    $command = new Command('CreateContact');
    $client->shouldReceive('createContact')->once()->andThrow(
        new SesV2Exception('nope', $command, ['code' => 'BadRequestException']),
    );

    new SesNewsletterContacts($client, 'test-list')->create('a@b.test');
})->throws(SesV2Exception::class);

it('confirms a contact', function (): void {
    $client = Mockery::mock(SesV2Client::class);
    $client->shouldReceive('updateContact')->once()->with(Mockery::on(
        fn(array $args): bool => $args['ContactListName'] === 'test-list'
            && $args['EmailAddress'] === 'a@b.test'
            && $args['AttributesData'] === '{"confirmed":true}',
    ));

    new SesNewsletterContacts($client, 'test-list')->confirm('a@b.test');
});

it('container resolves NewsletterContacts to SesNewsletterContacts', function (): void {
    // Verify the AppServiceProvider binding works by checking that we can resolve
    // the interface. The binding is configured in AppServiceProvider::register()
    $resolved = app(App\Site\Newsletter\NewsletterContacts::class);

    expect($resolved)->toBeInstanceOf(SesNewsletterContacts::class);
});
