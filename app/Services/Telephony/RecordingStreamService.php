<?php

namespace App\Services\Telephony;

use App\Enums\ConnectionStatus;
use App\Enums\YeastarTransportMode;
use App\Models\ApiAuditLog;
use App\Models\BitrixConnection;
use App\Models\CallLog;
use App\Models\YeastarConnection;
use App\Security\SsrfGuard;
use App\Services\Bitrix24\BitrixClient;
use App\Services\Yeastar\YeastarClientFactory;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Zero-Disk Call Recording Streamer.
 *
 * Streams audio directly from Yeastar P-Series Open API into Bitrix24's attachRecord uploadUrl
 * or directly to an authorized tenant supervisor's browser with HTTP Range support.
 * Never persists recording bytes to local disk or server object storage.
 */
class RecordingStreamService
{
    public function __construct(
        private readonly BitrixClient $bitrixClient,
        private readonly YeastarClientFactory $yeastarFactory,
        private readonly SsrfGuard $ssrfGuard,
    ) {}

    /**
     * Stream a call recording from Yeastar directly into Bitrix24 without writing to local disk.
     */
    public function transferToBitrix(CallLog $callLog): ?string
    {
        if (empty($callLog->bitrix_call_id) || empty($callLog->yeastar_recording_id)) {
            return null;
        }

        $bitrix = BitrixConnection::query()->where('status', ConnectionStatus::Connected->value)->first();
        $pbx = YeastarConnection::query()->find($callLog->yeastar_connection_id);

        if ($bitrix === null || $pbx === null) {
            throw new RuntimeException('Active Bitrix24 and Yeastar connections are required to stream call recordings.');
        }

        $filename = basename($callLog->yeastar_recording_id);
        if (! str_contains($filename, '.')) {
            $filename .= '.mp3';
        }

        // 1. Ask Yeastar for a short-lived download URL
        $sourceUrl = $this->resolveYeastarDownloadUrl($pbx, $callLog->yeastar_recording_id);

        // 2. Request Bitrix24 attachRecord multipart uploadUrl (avoids Base64 memory bloat)
        $attachInit = $this->bitrixClient->attachCallRecord($bitrix, [
            'CALL_ID' => $callLog->bitrix_call_id,
            'FILENAME' => $filename,
        ]);

        if (! empty($attachInit['FILE_ID'])) {
            $fileId = (string) $attachInit['FILE_ID'];
            $callLog->update([
                'bitrix_file_id' => $fileId,
                'recording_status' => 'attached',
            ]);

            return $fileId;
        }

        $uploadUrl = (string) ($attachInit['uploadUrl'] ?? '');
        $fieldName = (string) ($attachInit['fieldName'] ?? 'file');

        if ($uploadUrl === '') {
            throw new RuntimeException('Bitrix24 attachRecord did not return FILE_ID or uploadUrl.');
        }

        $safeUploadUrl = $this->ssrfGuard->assertSafeExternalHttpsUrl(
            $uploadUrl,
            resolveDns: ! app()->environment('testing'),
        );

        // 3. Open streaming download from Yeastar and pipe PSR-7 stream directly into Bitrix24 uploadUrl
        $sourceResponse = Http::timeout(60)
            ->withOptions(['stream' => true])
            ->get($sourceUrl);

        if (! $sourceResponse->successful()) {
            throw new RuntimeException("Failed streaming recording from Yeastar (HTTP {$sourceResponse->status()}).");
        }

        $psrStream = $sourceResponse->toPsrResponse()->getBody();

        $uploadResponse = Http::timeout(120)
            ->acceptJson()
            ->attach($fieldName, $psrStream, $filename)
            ->post($safeUploadUrl);

        $uploadData = $uploadResponse->json();
        $fileId = is_array($uploadData)
            ? (string) ($uploadData['result']['FILE_ID'] ?? $uploadData['FILE_ID'] ?? '')
            : '';

        if (! $uploadResponse->successful() || $fileId === '') {
            throw new RuntimeException("Failed uploading streamed recording to Bitrix24 (HTTP {$uploadResponse->status()}).");
        }

        $callLog->update([
            'bitrix_file_id' => $fileId,
            'recording_status' => 'attached',
        ]);

        return $fileId;
    }

    /**
     * Stream call recording audio on-the-fly directly to an authorized browser with HTTP 206 Range support.
     */
    public function streamPlaybackResponse(CallLog $callLog, ?string $rangeHeader = null): StreamedResponse
    {
        if (empty($callLog->yeastar_recording_id)) {
            abort(404, 'No recording is associated with this call.');
        }

        $pbx = YeastarConnection::query()->findOrFail($callLog->yeastar_connection_id);
        $sourceUrl = $this->resolveYeastarDownloadUrl($pbx, $callLog->yeastar_recording_id);

        $headers = [];
        if ($rangeHeader !== null && $rangeHeader !== '') {
            $headers['Range'] = $rangeHeader;
        }

        $upstream = Http::timeout(60)
            ->withHeaders($headers)
            ->withOptions(['stream' => true])
            ->get($sourceUrl);

        if (! $upstream->successful() && $upstream->status() !== 206) {
            abort(502, 'Unable to stream recording from Yeastar PBX.');
        }

        ApiAuditLog::query()->create([
            'tenant_id' => $callLog->tenant_id,
            'provider' => 'yeastar',
            'method' => 'STREAM /recording/playback',
            'http_status' => $upstream->status(),
            'duration_ms' => 0,
            'request_summary' => ['call_log_id' => $callLog->id, 'range' => $rangeHeader],
            'response_summary' => ['mode' => 'zero_disk_proxy'],
            'created_at' => now(),
        ]);

        $psrResponse = $upstream->toPsrResponse();
        $bodyStream = $psrResponse->getBody();

        $responseHeaders = [
            'Content-Type' => $upstream->header('Content-Type') ?: 'audio/mpeg',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ];

        foreach (['Content-Length', 'Content-Range'] as $headerName) {
            $val = $upstream->header($headerName);
            if ($val !== '') {
                $responseHeaders[$headerName] = $val;
            }
        }

        return response()->stream(static function () use ($bodyStream): void {
            while (! $bodyStream->eof()) {
                echo $bodyStream->read(65536);
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }
        }, $upstream->status(), $responseHeaders);
    }

    private function resolveYeastarDownloadUrl(YeastarConnection $pbx, string $recordingFile): string
    {
        $info = $this->yeastarFactory->for($pbx)->requestRecordingDownload($pbx, $recordingFile);
        $downloadPath = (string) ($info['download_resource_url'] ?? $info['data']['download_resource_url'] ?? $info['url'] ?? '');

        if ($downloadPath === '') {
            throw new RuntimeException('Yeastar recording/download did not return a download resource URL.');
        }

        if (str_starts_with($downloadPath, 'https://')) {
            return $this->ssrfGuard->assertSafeExternalHttpsUrl(
                $downloadPath,
                resolveDns: ! app()->environment('testing'),
            );
        }

        if ($pbx->transport_mode === YeastarTransportMode::DirectHttps && ! empty($pbx->pbx_base_url)) {
            $base = $this->ssrfGuard->assertSafeExternalHttpsUrl(
                $pbx->pbx_base_url,
                resolveDns: ! app()->environment('testing'),
            );

            return rtrim($base, '/').'/'.ltrim($downloadPath, '/');
        }

        throw new RuntimeException('Unable to construct safe HTTPS download URL for recording.');
    }
}
