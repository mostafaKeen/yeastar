<?php

namespace App\Services\Telephony;

use App\Enums\ConnectionStatus;
use App\Models\BitrixConnection;
use App\Models\YeastarConnection;
use App\Services\Bitrix24\BitrixClient;
use App\Services\Yeastar\YeastarClientFactory;

/**
 * Incrementally synchronizes Bitrix24 CRM Contacts into a Yeastar P-Series Phonebook
 * (CRM_Synchronization) so caller names resolve natively on Linkus UC clients and LDAP IP phones.
 */
class PhonebookSyncService
{
    public function __construct(
        private readonly BitrixClient $bitrixClient,
        private readonly YeastarClientFactory $yeastarFactory,
    ) {}

    /**
     * @return array{synced: int, last_id: int, has_more: bool}
     */
    public function syncBatch(
        YeastarConnection $pbx,
        int $lastId = 0,
        int $maxPages = 5,
        string $phonebookName = 'CRM_Synchronization',
    ): array {
        $bitrix = BitrixConnection::query()->where('status', ConnectionStatus::Connected->value)->first();
        if ($bitrix === null) {
            return ['synced' => 0, 'last_id' => $lastId, 'has_more' => false];
        }

        $transport = $this->yeastarFactory->for($pbx);
        $synced = 0;
        $cursorId = $lastId;
        $hasMore = false;

        for ($page = 0; $page < $maxPages; $page++) {
            $contacts = $this->bitrixClient->listCrmContacts($bitrix, $cursorId);
            if ($contacts === []) {
                $hasMore = false;
                break;
            }

            foreach ($contacts as $contact) {
                $id = (int) ($contact['ID'] ?? 0);
                if ($id > $cursorId) {
                    $cursorId = $id;
                }

                $phones = [];
                foreach ((array) ($contact['PHONE'] ?? []) as $phoneEntry) {
                    if (is_array($phoneEntry) && ! empty($phoneEntry['VALUE'])) {
                        $phones[] = trim((string) $phoneEntry['VALUE']);
                    }
                }

                if ($phones === []) {
                    continue;
                }

                $email = null;
                foreach ((array) ($contact['EMAIL'] ?? []) as $emailEntry) {
                    if (is_array($emailEntry) && ! empty($emailEntry['VALUE'])) {
                        $email = trim((string) $emailEntry['VALUE']);
                        break;
                    }
                }

                $firstName = trim((string) ($contact['NAME'] ?? ''));
                $lastName = trim((string) ($contact['LAST_NAME'] ?? ''));
                if ($firstName === '' && $lastName === '') {
                    $firstName = 'CRM Contact #'.$id;
                }

                $transport->upsertPhonebookContact($pbx, [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'numbers' => array_values(array_unique($phones)),
                    'phonebook' => $phonebookName,
                ]);

                $synced++;
            }

            if (count($contacts) < 50) {
                $hasMore = false;
                break;
            }

            $hasMore = true;
        }

        $bitrix->update(['last_synced_at' => now()]);

        return [
            'synced' => $synced,
            'last_id' => $cursorId,
            'has_more' => $hasMore,
        ];
    }
}
