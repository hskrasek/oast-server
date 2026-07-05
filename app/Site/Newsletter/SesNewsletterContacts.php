<?php

declare(strict_types=1);

namespace App\Site\Newsletter;

use Aws\SesV2\Exception\SesV2Exception;
use Aws\SesV2\SesV2Client;

final readonly class SesNewsletterContacts implements NewsletterContacts
{
    public function __construct(
        private SesV2Client $client,
        private string $listName,
    ) {}

    public function create(string $email): void
    {
        try {
            $this->client->createContact([
                'ContactListName' => $this->listName,
                'EmailAddress' => $email,
                'AttributesData' => '{"confirmed":false}',
            ]);
        } catch (SesV2Exception $sesV2Exception) {
            if ($sesV2Exception->getAwsErrorCode() !== 'AlreadyExistsException') {
                throw $sesV2Exception;
            }
        }
    }

    public function confirm(string $email): void
    {
        $this->client->updateContact([
            'ContactListName' => $this->listName,
            'EmailAddress' => $email,
            'AttributesData' => '{"confirmed":true}',
        ]);
    }
}
