<?php

return [
    'bitrix24' => [
        'client_id' => env('BITRIX24_CLIENT_ID', ''),
        'client_secret' => env('BITRIX24_CLIENT_SECRET', ''),
        'oauth_server' => env('BITRIX24_OAUTH_SERVER', 'https://oauth.bitrix.info/oauth/token/'),
        'required_scopes' => ['telephony', 'crm', 'user_basic'],
        'event_max_drift_seconds' => (int) env('BITRIX24_EVENT_MAX_DRIFT', 300),
        'frame_ancestors' => [
            'https://*.bitrix24.com',
            'https://*.bitrix24.eu',
            'https://*.bitrix24.de',
            'https://*.bitrix24.ru',
            'https://*.bitrix24.cn',
            'https://*.bitrix24.in',
            'https://*.bitrix24.com.br',
        ],
    ],

    'yeastar' => [
        'min_firmware_version' => '37.7.0.16',
        'recommended_firmware_version' => '37.9.0.18',
        'token_refresh_buffer_seconds' => 300,
        'http_timeout_seconds' => 15,
        'tunnel_broker_wss_url' => env('YEASTAR_TUNNEL_WSS_URL', 'wss://tunnel.example.com/agent'),
        'static_egress_ips' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('PLATFORM_EGRESS_IPS', '')),
        ))),
    ],

    'retention' => [
        // Bitrix24 Marketplace requires at least 3 days of REST API logs
        'api_audit_min_days' => 3,
        'api_audit_default_days' => (int) env('API_AUDIT_RETENTION_DAYS', 14),
        'webhook_deliveries_days' => (int) env('WEBHOOK_RETENTION_DAYS', 14),
    ],
];
