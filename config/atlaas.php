<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rich assistant content (images)
    |--------------------------------------------------------------------------
    |
    | Default source is Wikimedia (no API key). Optional: unsplash, pexels
    | with keys in config/services.php.
    |
    */

    'image_source' => env('IMAGE_SOURCE', 'wikimedia'),

    /*
    |--------------------------------------------------------------------------
    | Demo user seeding (TestDataSeeder)
    |--------------------------------------------------------------------------
    |
    | When false, php artisan db:seed still runs roles, permissions, and
    | built-in tools — but does not create admin@demo.test / teacher@demo.test /
    | student@demo.test with the well-known password. Set SEED_DEMO_ACCOUNTS=true
    | only on trusted local or staging machines where you want those accounts.
    |
    */

    'seed_demo_accounts' => filter_var(env('SEED_DEMO_ACCOUNTS', false), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Phase 3e — student safety modes
    |--------------------------------------------------------------------------
    */

    'crisis_detection_enabled' => filter_var(env('ATLAAS_CRISIS_DETECTION_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    'topic_scope_llm_enabled' => filter_var(env('ATLAAS_TOPIC_SCOPE_LLM_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    'lms_sync_enabled' => filter_var(env('ATLAAS_LMS_SYNC_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

];
