# Laravel Social Connect Package

A comprehensive Laravel package that allows users to connect and manage multiple social media platforms (Facebook, Instagram, Twitter/X, LinkedIn, YouTube) from a single interface.

## Features

- **Account Connection**: Connect Facebook pages, Instagram accounts, Twitter/X accounts, LinkedIn company pages, and YouTube channels
- **Post Publishing**: Publish text, image, video, and link posts to all connected platforms
- **Metrics Retrieval**: Get detailed analytics and insights for accounts and posts
- **Direct Messaging**: Access and reply to direct messages across all platforms
- **Comment Management**: View, reply to, and moderate comments on all platforms

## Requirements

- PHP 8.0 or higher
- Laravel 8.0 or higher
- Composer
- Valid API credentials for each platform you want to use

## Installation

You can install the package via composer:

```bash
composer require vendor-name/laravel-social-connect
```

After installing the package, publish the configuration file and migrations:

```bash
php artisan vendor:publish --provider="VendorName\SocialConnect\SocialConnectServiceProvider" --tag="config"
php artisan vendor:publish --provider="VendorName\SocialConnect\SocialConnectServiceProvider" --tag="migrations"
```

Run the migrations:

```bash
php artisan migrate
```

## Configuration

Set up your environment variables in your `.env` file:

```
# Facebook
FACEBOOK_CLIENT_ID=your-facebook-client-id
FACEBOOK_CLIENT_SECRET=your-facebook-client-secret
FACEBOOK_REDIRECT_URI=https://your-app.com/callback/facebook

# Instagram
INSTAGRAM_CLIENT_ID=your-instagram-client-id
INSTAGRAM_CLIENT_SECRET=your-instagram-client-secret
INSTAGRAM_REDIRECT_URI=https://your-app.com/callback/instagram

# Twitter/X
TWITTER_CLIENT_ID=your-twitter-client-id
TWITTER_CLIENT_SECRET=your-twitter-client-secret
TWITTER_REDIRECT_URI=https://your-app.com/callback/twitter

# LinkedIn
LINKEDIN_CLIENT_ID=your-linkedin-client-id
LINKEDIN_CLIENT_SECRET=your-linkedin-client-secret
LINKEDIN_REDIRECT_URI=https://your-app.com/callback/linkedin

# YouTube
YOUTUBE_CLIENT_ID=your-youtube-client-id
YOUTUBE_CLIENT_SECRET=your-youtube-client-secret
YOUTUBE_REDIRECT_URI=https://your-app.com/callback/youtube

# Package Configuration
SOCIAL_CONNECT_STORAGE_DISK=public
SOCIAL_CONNECT_STORAGE_PATH=social-media
SOCIAL_CONNECT_CACHE_DURATION=60
SOCIAL_CONNECT_QUEUE_ENABLED=true
SOCIAL_CONNECT_QUEUE_CONNECTION=default
SOCIAL_CONNECT_QUEUE_NAME=social-connect
SOCIAL_CONNECT_RATE_LIMITING_ENABLED=true
SOCIAL_CONNECT_RATE_LIMITING_MAX_ATTEMPTS=5
SOCIAL_CONNECT_RATE_LIMITING_DECAY_MINUTES=1
SOCIAL_CONNECT_WEBHOOKS_ENABLED=false
SOCIAL_CONNECT_WEBHOOKS_SECRET=your-webhook-secret
SOCIAL_CONNECT_WEBHOOKS_ROUTE=social-connect/webhook
SOCIAL_CONNECT_LOGGING_ENABLED=true
SOCIAL_CONNECT_LOGGING_CHANNEL=stack
```

## Usage

### Connecting Social Media Accounts

```php
use VendorName\SocialConnect\Facades\SocialConnect;

// Redirect to OAuth provider
public function redirectToProvider($platform)
{
    return SocialConnect::platform($platform)->redirect();
}

// Handle callback from OAuth provider
public function handleProviderCallback($platform)
{
    $account = SocialConnect::platform($platform)->callback();
    
    // $account is now a SocialAccount model instance
    return redirect()->route('dashboard')->with('success', "Connected to {$platform}!");
}
```

### Publishing Posts

```php
use VendorName\SocialConnect\Facades\SocialConnect;

// Get a social account
$account = \VendorName\SocialConnect\Models\SocialAccount::find($accountId);

// Publish a text post
$post = SocialConnect::publisher($account)->publishText('Hello, world!');

// Publish an image post
$post = SocialConnect::publisher($account)->publishImage(
    'Check out this image!',
    '/path/to/image.jpg'
);

// Publish a video post
$post = SocialConnect::publisher($account)->publishVideo(
    'Check out this video!',
    '/path/to/video.mp4',
    '/path/to/thumbnail.jpg'
);

// Publish a link post
$post = SocialConnect::publisher($account)->publishLink(
    'Check out this link!',
    'https://example.com',
    'Example Title',
    'Example Description',
    '/path/to/image.jpg'
);

// Schedule a post
$post = SocialConnect::publisher($account)->publishText(
    'This is a scheduled post!',
    [
        'scheduled_at' => now()->addDays(2),
    ]
);

// Delete a post
SocialConnect::publisher($account)->deletePost($postId);
```

### Retrieving Metrics

```php
use VendorName\SocialConnect\Facades\SocialConnect;

// Get a social account
$account = \VendorName\SocialConnect\Models\SocialAccount::find($accountId);

// Get account metrics
$metrics = SocialConnect::metrics($account)->getAccountMetrics();

// Get post metrics
$postMetrics = SocialConnect::metrics($account)->getPostMetrics($postId);

// Get audience demographics
$demographics = SocialConnect::metrics($account)->getAudienceDemographics();

// Get historical data
$historicalData = SocialConnect::metrics($account)->getHistoricalData(
    'followers',
    now()->subMonths(3),
    now()
);
```

### Managing Direct Messages

```php
use VendorName\SocialConnect\Facades\SocialConnect;

// Get a social account
$account = \VendorName\SocialConnect\Models\SocialAccount::find($accountId);

// Get conversations
$conversations = SocialConnect::messaging($account)->getConversations();

// Get messages for a conversation
$messages = SocialConnect::messaging($account)->getMessages($conversationId);

// Send a new message
$message = SocialConnect::messaging($account)->sendMessage(
    $recipientId,
    'Hello, this is a test message!'
);

// Reply to a conversation
$reply = SocialConnect::messaging($account)->replyToConversation(
    $conversationId,
    'This is a reply to your message.'
);

// Mark a conversation as read
SocialConnect::messaging($account)->markConversationAsRead($conversationId);
```

### Managing Comments

```php
use VendorName\SocialConnect\Facades\SocialConnect;

// Get a social account
$account = \VendorName\SocialConnect\Models\SocialAccount::find($accountId);

// Get comments for a post
$comments = SocialConnect::comments($account)->getComments($postId);

// Get replies to a comment
$replies = SocialConnect::comments($account)->getCommentReplies($commentId);

// Post a new comment
$comment = SocialConnect::comments($account)->postComment(
    $postId,
    'This is a test comment!'
);

// Reply to a comment
$reply = SocialConnect::comments($account)->replyToComment(
    $commentId,
    'This is a reply to your comment.'
);

// Like/react to a comment
SocialConnect::comments($account)->reactToComment($commentId, 'like');

// Remove a reaction from a comment
SocialConnect::comments($account)->removeCommentReaction($commentId, 'like');

// Delete a comment
SocialConnect::comments($account)->deleteComment($commentId);

// Hide a comment
SocialConnect::comments($account)->hideComment($commentId);

// Unhide a comment
SocialConnect::comments($account)->unhideComment($commentId);
```

## Error Handling

The package throws specific exceptions for different types of errors:

- `VendorName\SocialConnect\Exceptions\AuthenticationException`: For authentication and authorization errors
- `VendorName\SocialConnect\Exceptions\PublishingException`: For errors related to publishing content
- `VendorName\SocialConnect\Exceptions\MetricsException`: For errors related to retrieving metrics
- `VendorName\SocialConnect\Exceptions\MessagingException`: For errors related to direct messaging
- `VendorName\SocialConnect\Exceptions\CommentException`: For errors related to comment management

Example of error handling:

```php
use VendorName\SocialConnect\Exceptions\PublishingException;
use VendorName\SocialConnect\Facades\SocialConnect;

try {
    $post = SocialConnect::publisher($account)->publishText('Hello, world!');
} catch (PublishingException $e) {
    // Handle publishing error
    return back()->with('error', $e->getMessage());
} catch (\Exception $e) {
    // Handle other errors
    return back()->with('error', 'An unexpected error occurred.');
}
```

## Events

The package dispatches events for various actions:

- `VendorName\SocialConnect\Events\AccountConnected`: When a social account is connected
- `VendorName\SocialConnect\Events\PostPublished`: When a post is published
- `VendorName\SocialConnect\Events\MessageSent`: When a message is sent
- `VendorName\SocialConnect\Events\CommentPosted`: When a comment is posted

You can listen for these events in your `EventServiceProvider`:

```php
protected $listen = [
    'VendorName\SocialConnect\Events\AccountConnected' => [
        'App\Listeners\HandleAccountConnected',
    ],
    'VendorName\SocialConnect\Events\PostPublished' => [
        'App\Listeners\HandlePostPublished',
    ],
];
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
