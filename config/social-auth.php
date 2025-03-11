<?php
return [
    'iam' => [
        'base_url' => env('IAM_SERVICE_BASE_URL', 'http://localhost:8447/iam/v1'),
        'client_uid' => env('IAM_CLIENT_UID', 'myst'),
        'client_key' => env('IAM_CLIENT_KEY', '59aaebb7-15d7-4acb-a393-904f021e52f0'),
        'client_secret' => env('IAM_CLIENT_SECRET', '1qazZAQ!'),
        'lang' => env('IAM_LANG', 'bn'),
        'host' => env('IAM_HOST', 'iam-dev.banglalink.net'),
    ],
    'cache' => [
        'prefix' => env('SOCIAL_AUTH_STATE_PREFIX', 'social_auth_state:'),
        'ttl' => 600, // in seconds
    ],
    'providers' => [
        'google' => [
            'enabled' => !empty(env('GOOGLE_CLIENT_ID')),
            'client_id' => env('GOOGLE_CLIENT_ID', 'google_client_id'),
            'client_secret' => env('GOOGLE_CLIENT_SECRET', 'google_client_secret'),
            'redirect_uri' => env('GOOGLE_REDIRECT_URI', 'http://localhost:8000/auth/google/callback'),
            'oauth_base_url' => env('GOOGLE_OAUTH_BASE_URL', 'https://accounts.google.com/o/oauth2/v2/auth?'),
            'token_base_url' => env('GOOGLE_OAUTH_TOKEN_URL', 'https://oauth2.googleapis.com/token'),
            'user_info_base_url' => env('GOOGLE_USER_INFO_BASE_URL', 'https://www.googleapis.com/oauth2/v3/userinfo'),
        ],
        'facebook' => [
            'enabled' => !empty(env('FACEBOOK_CLIENT_ID')),
            'client_id' => env('FACEBOOK_CLIENT_ID', 'facebook_client_id'),
            'client_secret' => env('FACEBOOK_CLIENT_SECRET', 'facebook_client_secret'),
            'redirect_uri' => env('FACEBOOK_REDIRECT_URI', 'http://localhost:8000/auth/facebook/callback'),
            'oauth_base_url' => env('FACEBOOK_OAUTH_BASE_URL', 'https://www.facebook.com/v16.0/dialog/oauth?'),
            'token_base_url' => env('FACEBOOK_OAUTH_TOKEN_URL', 'https://graph.facebook.com/v16.0/oauth/access_token'),
            'user_info_base_url' => env('FACEBOOK_USER_INFO_BASE_URL', 'https://graph.facebook.com/v16.0/me'),
        ],
        // Other providers...
    ],
];
