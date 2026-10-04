<?php

return [
    'android_sdk' => env(
        'TABLEPLAY_ANDROID_SDK',
        env('ANDROID_SDK_ROOT', env('ANDROID_HOME', env('LOCALAPPDATA').'\Android\Sdk')),
    ),

    'java' => env('TABLEPLAY_JAVA'),

    'package_identifiers' => [
        'staff-android' => 'com.tableplay.tableplay_staff',
        'customer-android' => 'com.tableplay.tableplay_tablet',
    ],

    'installer' => [
        'disk' => env('TABLEPLAY_INSTALLER_DISK', 'updates'),
        'path' => env('TABLEPLAY_INSTALLER_PATH', 'server-windows/stable/TablePlay-Setup.exe'),
        'version' => env('TABLEPLAY_INSTALLER_VERSION'),
        'sha256' => strtolower((string) env('TABLEPLAY_INSTALLER_SHA256', '')),
    ],
];
