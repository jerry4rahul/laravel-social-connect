# Package Architecture and Database Schema

This document outlines the architecture and database schema for the Laravel Social Connect package.

## Package Structure

```
laravel-social-connect/
├── config/
│   └── social-connect.php       # Configuration file
├── database/
│   └── migrations/              # Database migrations
├── resources/
│   ├── views/                   # Blade views for dashboard
│   └── assets/                  # CSS, JS assets
├── routes/
│   └── web.php                  # Package routes
├── src/
│   ├── Console/                 # Console commands
│   ├── Contracts/               # Interfaces
│   ├── Events/                  # Events
│   ├── Exceptions/              # Custom exceptions
│   ├── Facades/                 # Facade classes
│   ├── Http/                    # HTTP controllers, middleware
│   ├── Jobs/                    # Queue jobs
│   ├── Models/                  # Eloquent models
│   ├── Providers/               # Service providers
│   ├── Services/                # Service classes
│   │   ├── Facebook/            # Facebook-specific services
│   │   ├── Instagram/           # Instagram-specific services
│   │   ├── Twitter/             # Twitter-specific services
│   │   ├── LinkedIn/            # LinkedIn-specific services
│   │   ├── YouTube/             # YouTube-specific services
│   │   └── Common/              # Shared services
│   └── SocialConnectManager.php # Main package class
└── tests/                       # Test files
```

## Core Components

### 1. SocialConnectManager

The main entry point for the package, responsible for:
- Managing platform connections
- Providing a unified interface for all platforms
- Handling authentication flows
- Dispatching operations to platform-specific services

### 2. Platform Services

Each platform (Facebook, Instagram, Twitter, LinkedIn, YouTube) will have dedicated service classes for:
- Authentication and token management
- Post publishing
- Metrics retrieval
- DM/message management
- Comment and interaction management

### 3. Models

Eloquent models representing the database entities:
- SocialAccount
- SocialPost
- SocialMetric
- SocialMessage
- SocialComment
- SocialInteraction

### 4. Contracts (Interfaces)

Interfaces defining the required methods for each service type:
- SocialPlatformInterface
- PublishableInterface
- MetricsInterface
- MessagingInterface
- CommentableInterface

## Database Schema

### social_accounts

Stores connected social media accounts:

```
CREATE TABLE social_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    platform VARCHAR(20) NOT NULL, -- facebook, instagram, twitter, linkedin, youtube
    platform_id VARCHAR(255) NOT NULL, -- ID on the platform
    name VARCHAR(255) NOT NULL,
    username VARCHAR(255),
    email VARCHAR(255),
    avatar VARCHAR(255),
    access_token TEXT NOT NULL,
    refresh_token TEXT,
    token_expires_at TIMESTAMP NULL,
    scopes JSON,
    metadata JSON,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE(user_id, platform, platform_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

### social_posts

Stores posts published through the package:

```
CREATE TABLE social_posts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    social_account_id BIGINT UNSIGNED NOT NULL,
    platform VARCHAR(20) NOT NULL,
    platform_post_id VARCHAR(255) NOT NULL,
    content TEXT,
    media_urls JSON,
    post_type VARCHAR(50) NOT NULL, -- text, image, video, link, etc.
    post_url VARCHAR(255),
    scheduled_at TIMESTAMP NULL,
    published_at TIMESTAMP NULL,
    status VARCHAR(20) NOT NULL, -- draft, scheduled, published, failed
    metadata JSON,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (social_account_id) REFERENCES social_accounts(id) ON DELETE CASCADE
);
```

### social_metrics

Stores metrics and insights for accounts and posts:

```
CREATE TABLE social_metrics (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    social_account_id BIGINT UNSIGNED NULL,
    social_post_id BIGINT UNSIGNED NULL,
    metric_type VARCHAR(50) NOT NULL, -- impression, reach, engagement, etc.
    metric_value JSON NOT NULL, -- can store complex metrics data
    period_start TIMESTAMP NULL,
    period_end TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (social_account_id) REFERENCES social_accounts(id) ON DELETE SET NULL,
    FOREIGN KEY (social_post_id) REFERENCES social_posts(id) ON DELETE SET NULL
);
```

### social_messages

Stores direct messages:

```
CREATE TABLE social_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    social_account_id BIGINT UNSIGNED NOT NULL,
    conversation_id VARCHAR(255) NOT NULL,
    platform_message_id VARCHAR(255) NOT NULL,
    sender_id VARCHAR(255) NOT NULL,
    sender_name VARCHAR(255),
    recipient_id VARCHAR(255) NOT NULL,
    recipient_name VARCHAR(255),
    content TEXT NOT NULL,
    attachments JSON,
    is_read BOOLEAN DEFAULT FALSE,
    sent_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (social_account_id) REFERENCES social_accounts(id) ON DELETE CASCADE
);
```

### social_comments

Stores comments on posts:

```
CREATE TABLE social_comments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    social_account_id BIGINT UNSIGNED NOT NULL,
    social_post_id BIGINT UNSIGNED NULL,
    platform_comment_id VARCHAR(255) NOT NULL,
    platform_post_id VARCHAR(255) NOT NULL,
    parent_comment_id BIGINT UNSIGNED NULL,
    commenter_id VARCHAR(255),
    commenter_name VARCHAR(255),
    commenter_username VARCHAR(255),
    content TEXT NOT NULL,
    attachments JSON,
    is_hidden BOOLEAN DEFAULT FALSE,
    commented_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (social_account_id) REFERENCES social_accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (social_post_id) REFERENCES social_posts(id) ON DELETE SET NULL,
    FOREIGN KEY (parent_comment_id) REFERENCES social_comments(id) ON DELETE SET NULL
);
```

### social_interactions

Stores likes, shares, and other interactions:

```
CREATE TABLE social_interactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    social_account_id BIGINT UNSIGNED NOT NULL,
    social_post_id BIGINT UNSIGNED NULL,
    social_comment_id BIGINT UNSIGNED NULL,
    interaction_type VARCHAR(50) NOT NULL, -- like, share, retweet, etc.
    platform_interaction_id VARCHAR(255),
    interactor_id VARCHAR(255),
    interactor_name VARCHAR(255),
    interactor_username VARCHAR(255),
    metadata JSON,
    interacted_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (social_account_id) REFERENCES social_accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (social_post_id) REFERENCES social_posts(id) ON DELETE SET NULL,
    FOREIGN KEY (social_comment_id) REFERENCES social_comments(id) ON DELETE SET NULL
);
```

## Authentication Flow

1. User initiates connection to a social platform
2. Package redirects to platform's OAuth authorization page
3. User grants permissions to our application
4. Platform redirects back with authorization code
5. Package exchanges code for access token
6. Access token is stored in `social_accounts` table
7. Refresh token flow is implemented where applicable
8. Token validation and expiration handling

## Key Features Implementation

### 1. Account Connection

- OAuth-based authentication for all platforms
- Token storage and management
- Account metadata retrieval and storage

### 2. Post Publishing

- Text, image, video, and link post support
- Cross-platform publishing
- Scheduled posting
- Draft management

### 3. Metrics Retrieval

- Account-level metrics (followers, engagement, etc.)
- Post-level metrics (impressions, likes, shares, etc.)
- Historical data and trends
- Exportable reports

### 4. DM Management

- Conversation listing
- Message sending and receiving
- Attachment support
- Read status tracking

### 5. Comment Management

- Comment retrieval and display
- Comment posting and replying
- Comment moderation (hide, delete)
- Sentiment analysis (optional)

## Configuration Options

The `config/social-connect.php` file will include:

```php
return [
    // API credentials
    'credentials' => [
        'facebook' => [
            'client_id' => env('FACEBOOK_CLIENT_ID'),
            'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
            'redirect' => env('FACEBOOK_REDIRECT_URI'),
        ],
        'instagram' => [
            'client_id' => env('INSTAGRAM_CLIENT_ID'),
            'client_secret' => env('INSTAGRAM_CLIENT_SECRET'),
            'redirect' => env('INSTAGRAM_REDIRECT_URI'),
        ],
        // Similar for other platforms
    ],
    
    // Feature toggles
    'features' => [
        'publishing' => true,
        'metrics' => true,
        'messaging' => true,
        'comments' => true,
    ],
    
    // Cache settings
    'cache' => [
        'enabled' => true,
        'duration' => 60, // minutes
    ],
    
    // Rate limiting
    'rate_limits' => [
        'facebook' => [
            'calls_per_hour' => 200,
        ],
        // Similar for other platforms
    ],
    
    // Webhook settings
    'webhooks' => [
        'enabled' => false,
        'secret' => env('SOCIAL_CONNECT_WEBHOOK_SECRET'),
        'routes' => [
            'facebook' => 'social-connect/webhook/facebook',
            // Similar for other platforms
        ],
    ],
];
```

## Service Provider Registration

The package will register its service provider in the Laravel application:

```php
// In config/app.php
'providers' => [
    // Other providers
    VendorName\SocialConnect\SocialConnectServiceProvider::class,
],

'aliases' => [
    // Other aliases
    'SocialConnect' => VendorName\SocialConnect\Facades\SocialConnect::class,
],
```

## Facade Usage Example

```php
// Connect to a platform
SocialConnect::connect('facebook');

// Publish a post
SocialConnect::platform('facebook')
    ->account($accountId)
    ->publish([
        'content' => 'Hello world!',
        'media' => ['image.jpg'],
    ]);

// Get metrics
$metrics = SocialConnect::platform('instagram')
    ->account($accountId)
    ->metrics()
    ->period('last_30_days')
    ->get();

// Get messages
$messages = SocialConnect::platform('twitter')
    ->account($accountId)
    ->messages()
    ->conversation($conversationId)
    ->get();

// Reply to a comment
SocialConnect::platform('youtube')
    ->account($accountId)
    ->comments()
    ->reply($commentId, 'Thank you for your feedback!');
```
