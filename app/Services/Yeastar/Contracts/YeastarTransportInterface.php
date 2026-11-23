<?php

namespace App\Services\Yeastar\Contracts;

use App\Models\YeastarConnection;

interface YeastarTransportInterface
{
    /**
     * Execute a Yeastar P-Series Open API request.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function request(YeastarConnection $connection, string $method, string $path, array $payload = []): array;

    /**
     * Test connectivity and verify firmware meets minimum Open API version (37.7.0.16+).
     *
     * @return array{ok: bool, firmware_version: string|null, meets_min_firmware: bool, latency_ms: int, message: string}
     */
    public function healthCheck(YeastarConnection $connection): array;

    /**
     * Retrieve PBX SIP extensions for Bitrix24 user mapping.
     *
     * @return array<int, array{number: string, caller_id_name: string, email: string|null, presence_status: string|null}>
     */
    public function listExtensions(YeastarConnection $connection): array;

    /**
     * Retrieve configured PBX trunks for Bitrix24 external line registration.
     *
     * @return array<int, array{id: string, name: string, type: string|null, status: string|null}>
     */
    public function listTrunks(YeastarConnection $connection): array;

    /**
     * Initiate an outbound call via Yeastar P-Series Call Control API.
     *
     * @return array<string, mixed>
     */
    public function dial(YeastarConnection $connection, string $callerExtension, string $calleeNumber): array;

    /**
     * Request a temporary Yeastar recording download path/URL (never stored on our server).
     *
     * @return array<string, mixed>
     */
    public function requestRecordingDownload(YeastarConnection $connection, string $recordingFile): array;

    /**
     * Create or update a CRM contact inside a Yeastar P-Series Phonebook (supports Linkus & LDAP lookup).
     *
     * @param  array{first_name: string, last_name?: string, email?: string|null, numbers: list<string>, phonebook?: string}  $contact
     * @return array<string, mixed>
     */
    public function upsertPhonebookContact(YeastarConnection $connection, array $contact): array;
}
