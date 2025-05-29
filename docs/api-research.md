# Social Media API Research

This document outlines the APIs, authentication methods, and permissions required for each social media platform that will be integrated into the Laravel Social Connect package.

## Facebook

### Authentication
- OAuth 2.0 authentication flow
- Requires App ID and App Secret from Facebook Developer Portal
- Uses access tokens (short-lived and long-lived)
- Page access tokens required for page management

### Required Permissions/Scopes
- `pages_show_list` - To access the list of pages managed by the user
- `pages_read_engagement` - To read page insights and metrics
- `pages_manage_posts` - To publish content on pages
- `pages_manage_metadata` - To manage page settings
- `pages_read_user_content` - To read user-generated content on pages
- `pages_manage_engagement` - To manage comments and messages

### Key Endpoints
- `/me/accounts` - Get list of pages managed by user
- `/page-id/feed` - Post content to page
- `/page-id/insights` - Get page metrics
- `/page-id/conversations` - Access messages
- `/page-id/comments` - Manage comments

## Instagram

### Authentication
- Uses Facebook's OAuth system (Instagram Professional accounts are linked to Facebook)
- Requires Facebook Page connection
- Uses same access tokens as Facebook Pages

### Required Permissions/Scopes
- `instagram_basic` - Basic access to Instagram account data
- `instagram_content_publish` - To publish content
- `instagram_manage_comments` - To manage comments
- `instagram_manage_insights` - To access metrics

### Key Endpoints
- `/me/accounts?fields=instagram_business_account` - Get Instagram account ID
- `/instagram-account-id/media` - Create media container
- `/instagram-account-id/media_publish` - Publish prepared media
- `/instagram-account-id/insights` - Get account metrics
- `/media-id/comments` - Access and manage comments

## Twitter/X

### Authentication
- OAuth 1.0a for user authentication
- OAuth 2.0 for app-only authentication
- Requires API Key, API Secret, Access Token, and Access Token Secret

### Required Permissions/Scopes
- `tweet.read` - Read tweets and timelines
- `tweet.write` - Post tweets
- `users.read` - Read user profile information
- `dm.read` - Read direct messages
- `dm.write` - Send direct messages
- `like.read` - View likes
- `like.write` - Like tweets

### Key Endpoints
- `/2/tweets` - Post new tweets
- `/2/users/:id/tweets` - Get user tweets
- `/2/tweets/:id/metrics` - Get tweet metrics
- `/2/dm_conversations` - Access direct messages
- `/2/tweets/search/recent` - Search for tweets

## LinkedIn

### Authentication
- OAuth 2.0 authentication flow
- Requires Client ID and Client Secret from LinkedIn Developer Portal
- Uses access tokens with defined expiration

### Required Permissions/Scopes
- `r_liteprofile` - Read basic profile data
- `r_organization_social` - Read company page data
- `rw_organization_admin` - Manage company pages
- `w_member_social` - Post content on behalf of members
- `w_organization_social` - Post content on behalf of organizations

### Key Endpoints
- `/v2/organizations` - Get company pages
- `/v2/ugcPosts` - Create and publish posts
- `/v2/organizationalEntityShareStatistics` - Get post metrics
- `/v2/socialActions` - Manage likes and comments

## YouTube

### Authentication
- OAuth 2.0 authentication flow
- Requires API Key and Client Secret from Google Developer Console
- Uses access tokens with refresh token capability

### Required Permissions/Scopes
- `https://www.googleapis.com/auth/youtube` - Full access to YouTube account
- `https://www.googleapis.com/auth/youtube.readonly` - Read-only access
- `https://www.googleapis.com/auth/youtube.upload` - Upload videos
- `https://www.googleapis.com/auth/youtube.force-ssl` - Required for comments and private data

### Key Endpoints
- `/youtube/v3/channels` - Get channel information
- `/youtube/v3/videos` - Upload and manage videos
- `/youtube/v3/commentThreads` - Manage comments
- `/youtube/v3/activities` - Get channel activities
- `/youtube/v3/analytics` - Access analytics data

## Common Authentication Flow

For all platforms, our package will implement the following authentication flow:

1. User initiates connection to a social platform
2. Package redirects to platform's OAuth authorization page
3. User grants permissions to our application
4. Platform redirects back with authorization code
5. Package exchanges code for access token
6. Access token is stored securely in database
7. Refresh token flow is implemented where applicable
8. Token validation and expiration handling

## Rate Limiting Considerations

All platforms implement rate limiting that must be handled gracefully:

- Facebook/Instagram: Varies by endpoint (typically 200 calls/hour)
- Twitter/X: 500 requests per 15-minute window for most endpoints
- LinkedIn: 100 calls per day per user for most endpoints
- YouTube: 10,000 units per day (different endpoints cost different units)

Our package will implement:
- Token bucket rate limiting
- Exponential backoff for retries
- Caching strategies to minimize API calls
