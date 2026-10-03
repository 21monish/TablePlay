<?php

return [
    'mode' => env('TABLEPLAY_MODE', 'restaurant'),
    'cloud_console_enabled' => filter_var(env('TABLEPLAY_CLOUD_CONSOLE', false), FILTER_VALIDATE_BOOL),
    'runtime_root' => env('TABLEPLAY_RUNTIME_ROOT'),
    'cloud_url' => rtrim((string) env('TABLEPLAY_CLOUD_URL', ''), '/'),
    'license_public_key' => env('TABLEPLAY_LICENSE_PUBLIC_KEY'),
    'license_private_key' => env('TABLEPLAY_LICENSE_PRIVATE_KEY'),
    'license_key_id' => env('TABLEPLAY_LICENSE_KEY_ID', 'tableplay-market-v1'),
    'trusted_license_public_keys' => json_decode((string) env('TABLEPLAY_TRUSTED_LICENSE_PUBLIC_KEYS', '{}'), true) ?: [],
    'sync_timeout_seconds' => (int) env('TABLEPLAY_LICENSE_SYNC_TIMEOUT', 12),
    'offline_request_ttl_days' => (int) env('TABLEPLAY_OFFLINE_REQUEST_TTL_DAYS', 30),
    'offline_license_lease_days' => (int) env('TABLEPLAY_OFFLINE_LICENSE_LEASE_DAYS', 30),
    'device_fingerprint_override' => env('TABLEPLAY_DEVICE_FINGERPRINT_OVERRIDE'),
    'allow_local_plan_changes' => (bool) env('TABLEPLAY_ALLOW_LOCAL_PLAN_CHANGES', false),
];
