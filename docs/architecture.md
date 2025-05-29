# Package Architecture and Design (Stateless Refactor)

This document outlines the refactored architecture for the Laravel Social Connect package, designed for stateless operation where the consuming application manages all data persistence.

## Core Principles

1.  **Stateless Services**: The package services will not maintain any internal state related to specific users or accounts between requests. All necessary information (like API tokens) must be provided for each operation.
2.  **No Internal Persistence**: The package will **not** include any database migrations or Eloquent models. It will not store tokens, user data, posts, metrics, messages, or comments.
3.  **User-Managed Data**: The consuming Laravel application is responsible for:
    *   Storing and retrieving API credentials (access tokens, refresh tokens, etc.) for each connected social account.
    *   Storing any data returned by the package services (e.g., published post IDs, metrics results, message content, comment details) in their own database schema or data structures.
4.  **Data Transfer Objects (DTOs) / Arrays**: Services will return data primarily as structured PHP arrays or potentially simple Data Transfer Objects (DTOs) for clarity, rather than Eloquent models.

## Directory Structure (Conceptual)

```
laravel-social-connect/
├── config/
│   └── social-connect.php      # Configuration (API keys, redirect URIs, etc.)
├── src/
│   ├── Contracts/              # Interfaces for services
│   │   ├── SocialPlatformInterface.php
│   │   ├── PublishableInterface.php
│   │   ├── MetricsInterface.php
│   │   ├── MessagingInterface.php
│   │   └── CommentManagementInterface.php
│   ├── DTOs/                   # (Optional) Data Transfer Objects for returned data
│   ├── Exceptions/             # Custom exceptions
│   ├── Facades/                # Laravel Facade
│   │   └── SocialConnect.php
│   ├── Services/               # Platform-specific service implementations
│   │   ├── Facebook/
│   │   ├── Instagram/
│   │   ├── Twitter/
│   │   ├── LinkedIn/
│   │   └── YouTube/
│   ├── SocialConnectManager.php  # Main manager class
│   └── SocialConnectServiceProvider.php # Service provider
├── routes/
│   └── web.php                 # Routes for OAuth callbacks (optional, user can define)
├── resources/
│   └── views/                  # Optional views for OAuth flow examples
├── tests/                      # Unit and Feature tests
├── README.md
├── LICENSE.md
└── composer.json
```

**Key Changes:**

*   `database/migrations` directory is removed.
*   `src/Models` directory is removed.
*   Services in `src/Services/` will be refactored.

## Service Interaction Flow

1.  **Configuration**: The user configures platform API keys and redirect URIs in `config/social-connect.php` (published from the package).
2.  **Authentication**: 
    *   The package provides methods to generate the OAuth redirect URL (`SocialConnect::platform("facebook")->getRedirectUrl()`).
    *   The user redirects their application user to this URL.
    *   Upon callback, the package provides a method to exchange the authorization code for tokens (`SocialConnect::platform("facebook")->exchangeCodeForToken($code)`). This method returns the access token, refresh token (if applicable), expiry time, and basic user/profile info (ID, name, email).
    *   **The user's application is responsible for securely storing these tokens** (e.g., in their `users` table or a dedicated `social_tokens` table).
3.  **API Calls (Publishing, Metrics, etc.)**: 
    *   When the user wants to perform an action (e.g., publish a post), they retrieve the stored access token for the relevant platform and user.
    *   They instantiate the appropriate service via the `SocialConnectManager`, passing the **access token** and any other required identifiers (like the platform user ID or page ID if needed).
    *   Example: `SocialConnect::publisher("facebook", $accessToken, $pageId)->publishText("Hello")`
    *   The service method performs the API call using the provided token.
    *   The service returns the result (e.g., the platform's post ID, metrics data as an array, list of messages as an array) directly to the user's application.
    *   **The user's application decides how and where to store this returned data.**

## Data Structures

*   **Tokens**: Methods handling token exchange will return an array or DTO containing `access_token`, `refresh_token`, `expires_in`, `platform_user_id`, `name`, `email`, etc.
*   **Returned Data**: Methods for publishing, metrics, messaging, comments will return structured arrays or DTOs representing the result (e.g., `["platform_post_id" => "12345"]`, `["followers" => 1000, "engagement" => 0.05]`, array of message objects/arrays).

## Token Management

*   The package **will not** handle token storage or automatic refresh.
*   The user's application must implement logic to:
    *   Store tokens securely.
    *   Check token expiry before making API calls.
    *   Use the refresh token (if available) to obtain a new access token when needed (the package might provide a helper method like `SocialConnect::platform("facebook")->refreshToken($refreshToken)`).
    *   Update the stored tokens after a refresh.

## Conclusion

This stateless architecture provides maximum flexibility for developers to integrate social media functionalities into their Laravel applications without imposing a specific database structure. It shifts the responsibility of data persistence entirely to the consuming application.
