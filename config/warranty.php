<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Automatic warranty / support coverage lookup
    |--------------------------------------------------------------------------
    |
    | Credentials for vendor coverage APIs used by `snipeit:lookup-warranties`.
    | A provider without credentials is skipped.
    */

    'enabled' => env('WARRANTY_LOOKUP_ENABLED', false),

    // Days before an asset is looked up again.
    'recheck_days' => env('WARRANTY_LOOKUP_RECHECK_DAYS', 30),

    // When true, the latest vendor end date is written to the asset's
    // warranty_months (and purchase_date, if the asset has none).
    'apply_to_asset' => env('WARRANTY_LOOKUP_APPLY_TO_ASSET', true),

    'dell' => [
        'base_url' => env('WARRANTY_DELL_BASE_URL', 'https://apigtwb2c.us.dell.com'),
        'client_id' => env('WARRANTY_DELL_CLIENT_ID'),
        'client_secret' => env('WARRANTY_DELL_CLIENT_SECRET'),
    ],

    'apple' => [
        'client_id' => env('WARRANTY_APPLE_CLIENT_ID'),
        'key_id' => env('WARRANTY_APPLE_KEY_ID'),
        'private_key' => env('WARRANTY_APPLE_PRIVATE_KEY'),
        'mode' => env('WARRANTY_APPLE_MODE', 'business'),
    ],

];
