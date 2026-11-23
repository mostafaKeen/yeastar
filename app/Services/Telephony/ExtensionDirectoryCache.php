<?php

namespace App\Services\Telephony;

use App\Models\ExtensionMapping;
use App\Models\ExternalLine;
use Illuminate\Support\Facades\Cache;

class ExtensionDirectoryCache
{
    private const TTL_SECONDS = 300;

    /**
     * Load tenant extension mappings from Redis/Cache (zero DB queries on hot call path).
     *
     * @return array{
     *   by_extension: array<string, array{bitrix_user_id: string, yeastar_connection_id: string, click_to_call_enabled: bool, popup_enabled: bool}>,
     *   by_bitrix_user: array<string, array{yeastar_extension: string, yeastar_connection_id: string, click_to_call_enabled: bool, popup_enabled: bool}>
     * }
     */
    public function getMap(string $tenantId): array
    {
        return Cache::remember("t:{$tenantId}:ext_map", now()->addSeconds(self::TTL_SECONDS), static function (): array {
            $rows = ExtensionMapping::query()
                ->where('is_active', true)
                ->get([
                    'yeastar_connection_id',
                    'bitrix_user_id',
                    'yeastar_extension',
                    'click_to_call_enabled',
                    'popup_enabled',
                ]);

            $byExtension = [];
            $byBitrixUser = [];

            foreach ($rows as $row) {
                $byExtension[$row->yeastar_extension] = [
                    'bitrix_user_id' => $row->bitrix_user_id,
                    'yeastar_connection_id' => $row->yeastar_connection_id,
                    'click_to_call_enabled' => (bool) $row->click_to_call_enabled,
                    'popup_enabled' => (bool) $row->popup_enabled,
                ];

                if (! isset($byBitrixUser[$row->bitrix_user_id])) {
                    $byBitrixUser[$row->bitrix_user_id] = [
                        'yeastar_extension' => $row->yeastar_extension,
                        'yeastar_connection_id' => $row->yeastar_connection_id,
                        'click_to_call_enabled' => (bool) $row->click_to_call_enabled,
                        'popup_enabled' => (bool) $row->popup_enabled,
                    ];
                }
            }

            return [
                'by_extension' => $byExtension,
                'by_bitrix_user' => $byBitrixUser,
            ];
        });
    }

    /**
     * @return array{bitrix_user_id: string, yeastar_connection_id: string, click_to_call_enabled: bool, popup_enabled: bool}|null
     */
    public function findByExtension(string $tenantId, string $extension): ?array
    {
        return $this->getMap($tenantId)['by_extension'][$extension] ?? null;
    }

    /**
     * @return array{yeastar_extension: string, yeastar_connection_id: string, click_to_call_enabled: bool, popup_enabled: bool}|null
     */
    public function findByBitrixUser(string $tenantId, string $bitrixUserId): ?array
    {
        return $this->getMap($tenantId)['by_bitrix_user'][$bitrixUserId] ?? null;
    }

    public function resolveDefaultLineNumber(string $tenantId, ?string $yeastarConnectionId = null): string
    {
        return (string) Cache::remember(
            "t:{$tenantId}:default_line:".($yeastarConnectionId ?? 'any'),
            now()->addSeconds(self::TTL_SECONDS),
            static function () use ($yeastarConnectionId): string {
                $query = ExternalLine::query()->where('is_active', true);

                if ($yeastarConnectionId !== null) {
                    $query->where('yeastar_connection_id', $yeastarConnectionId);
                }

                $line = $query->orderByDesc('is_default')->orderBy('created_at')->first();

                return $line?->bitrix_line_number ?? 'yeastar-default';
            },
        );
    }

    public function invalidate(string $tenantId): void
    {
        Cache::forget("t:{$tenantId}:ext_map");
    }
}
