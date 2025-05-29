<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Social Connect Configuration
    |--------------------------------------------------------------------------
    |
    | This file is for configuring the Social Connect package.
    |
    */

    // Default storage disk for media uploads
    'storage_disk' => env('SOCIAL_CONNECT_STORAGE_DISK', 'public'),

    // Path within the disk where media will be stored
    'storage_path' => env('SOCIAL_CONNECT_STORAGE_PATH', 'social-media'),

    // Default cache duration in minutes
    'cache_duration' => env('SOCIAL_CONNECT_CACHE_DURATION', 60),

    // OAuth configuration for each platform
    'platforms' => [
        'facebook' => [
            'client_id' => env('FACEBOOK_CLIENT_ID'),
            'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
            'redirect' => env('FACEBOOK_REDIRECT_URI'),
            'scopes' => [
                'email',
                'public_profile',
                'pages_show_list',
                'pages_read_engagement',
                'pages_manage_posts',
                'pages_manage_metadata',
                'pages_manage_engagement',
                'pages_messaging',
                'instagram_basic',
                'instagram_content_publish',
                'instagram_manage_comments',
                'instagram_manage_insights',
            ],
            'graph_version' => 'v18.0',
        ],
        
        'instagram' => [
            'client_id' => env('INSTAGRAM_CLIENT_ID'),
            'client_secret' => env('INSTAGRAM_CLIENT_SECRET'),
            'redirect' => env('INSTAGRAM_REDIRECT_URI'),
            'scopes' => [
                'user_profile',
                'user_media',
                'instagram_graph_user_profile',
                'instagram_graph_user_media',
                'instagram_content_publish',
                'instagram_manage_comments',
                'instagram_manage_insights',
            ],
        ],
        
        'twitter' => [
            'client_id' => env('TWITTER_CLIENT_ID'),
            'client_secret' => env('TWITTER_CLIENT_SECRET'),
            'redirect' => env('TWITTER_REDIRECT_URI'),
            'scopes' => [
                'tweet.read',
                'tweet.write',
                'users.read',
                'offline.access',
                'dm.read',
                'dm.write',
                'like.read',
                'like.write',
            ],
        ],
        
        'linkedin' => [
            'client_id' => env('LINKEDIN_CLIENT_ID'),
            'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
            'redirect' => env('LINKEDIN_REDIRECT_URI'),
            'scopes' => [
                'r_liteprofile',
                'r_emailaddress',
                'w_member_social',
                'r_organization_social',
                'w_organization_social',
                'rw_organization_admin',
            ],
        ],
        
        'youtube' => [
            'client_id' => env('YOUTUBE_CLIENT_ID'),
            'client_secret' => env('YOUTUBE_CLIENT_SECRET'),
            'redirect' => env('YOUTUBE_REDIRECT_URI'),
            'scopes' => [
                'https://www.googleapis.com/auth/youtube',
                'https://www.googleapis.com/auth/youtube.force-ssl',
                'https://www.googleapis.com/auth/youtube.readonly',
                'https://www.googleapis.com/auth/youtube.upload',
                'https://www.googleapis.com/auth/youtube.channel-memberships.creator',
                'https://www.googleapis.com/auth/youtube.partner',
            ],
        ],
    ],

    // Queue configuration
    'queue' => [
        'enabled' => env('SOCIAL_CONNECT_QUEUE_ENABLED', true),
        'connection' => env('SOCIAL_CONNECT_QUEUE_CONNECTION', 'default'),
        'queue' => env('SOCIAL_CONNECT_QUEUE_NAME', 'social-connect'),
    ],

    // Rate limiting configuration
    'rate_limiting' => [
        'enabled' => env('SOCIAL_CONNECT_RATE_LIMITING_ENABLED', true),
        'max_attempts' => env('SOCIAL_CONNECT_RATE_LIMITING_MAX_ATTEMPTS', 5),
        'decay_minutes' => env('SOCIAL_CONNECT_RATE_LIMITING_DECAY_MINUTES', 1),
    ],

    // Webhook configuration
    'webhooks' => [
        'enabled' => env('SOCIAL_CONNECT_WEBHOOKS_ENABLED', false),
        'secret' => env('SOCIAL_CONNECT_WEBHOOKS_SECRET'),
        'route' => env('SOCIAL_CONNECT_WEBHOOKS_ROUTE', 'social-connect/webhook'),
    ],

    // Logging configuration
    'logging' => [
        'enabled' => env('SOCIAL_CONNECT_LOGGING_ENABLED', true),
        'channel' => env('SOCIAL_CONNECT_LOGGING_CHANNEL', 'stack'),
    ],
];
