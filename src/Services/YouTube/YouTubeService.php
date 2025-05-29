<?php

namespace VendorName\SocialConnect\Services\YouTube;

use Google_Client;
use Google_Service_YouTube;
use Google_Service_Oauth2;
use Illuminate\Support\Facades\Config;
use VendorName\SocialConnect\Contracts\SocialPlatformInterface;
use VendorName\SocialConnect\Exceptions\AuthenticationException;

class YouTubeService implements SocialPlatformInterface
{
    /**
     * Google API Client.
     *
     * @var \Google_Client
     */
    protected $googleClient;

    /**
     * YouTube Client ID.
     *
     * @var string
     */
    protected $clientId;

    /**
     * YouTube Client Secret.
     *
     * @var string
     */
    protected $clientSecret;

    /**
     * YouTube Redirect URI.
     *
     * @var string
     */
    protected $redirectUri;

    /**
     * Create a new YouTubeService instance.
     */
    public function __construct()
    {
        $config = Config::get("social-connect.platforms.youtube");

        if (!isset($config["client_id"], $config["client_secret"], $config["redirect_uri"])) {
            throw new AuthenticationException("YouTube client ID, client secret, or redirect URI is not configured.");
        }

        $this->clientId = $config["client_id"];
        $this->clientSecret = $config["client_secret"];
        $this->redirectUri = $config["redirect_uri"];

        $this->googleClient = new Google_Client();
        $this->googleClient->setClientId($this->clientId);
        $this->googleClient->setClientSecret($this->clientSecret);
        $this->googleClient->setRedirectUri($this->redirectUri);
        $this->googleClient->setAccessType("offline"); // Request refresh token
        $this->googleClient->setPrompt("consent"); // Force consent screen for refresh token
    }

    /**
     * Get the authentication redirect URL.
     *
     * @param array $params Optional parameters (e.g., state, scope).
     * @return string
     */
    public function getRedirectUrl(array $params = []): string
    {
        // Define default scopes required by the package
        $defaultScopes = [
            Google_Service_YouTube::YOUTUBE_FORCE_SSL, // Manage YouTube account
            Google_Service_YouTube::YOUTUBE_UPLOAD, // Upload videos
            Google_Service_YouTube::YOUTUBE_READONLY, // View account info
            Google_Service_Oauth2::USERINFO_EMAIL, // Get user email
            Google_Service_Oauth2::USERINFO_PROFILE, // Get user profile info
            // Add scopes for analytics if needed (e.g., youtube.readonly, youtubereporting.readonly)
            "https://www.googleapis.com/auth/youtube.readonly",
            "https://www.googleapis.com/auth/youtubepartner",
            "https://www.googleapis.com/auth/yt-analytics.readonly",
            "https://www.googleapis.com/auth/yt-analytics-monetary.readonly",
        ];

        $scopes = $params["scope"] ?? Config::get("social-connect.platforms.youtube.scopes", $defaultScopes);
        $state = $params["state"] ?? bin2hex(random_bytes(16));

        $this->googleClient->setScopes($scopes);
        $this->googleClient->setState($state);

        return $this->googleClient->createAuthUrl();
    }

    /**
     * Exchange authorization code for an access token.
     *
     * @param string $code Authorization code from callback.
     * @param string|null $state State parameter (optional, for verification).
     * @return array Returns an array containing token information and basic user profile.
     * @throws AuthenticationException
     */
    public function exchangeCodeForToken(string $code, ?string $state = null): array
    {
        try {
            // Exchange code for access token
            $tokenData = $this->googleClient->fetchAccessTokenWithAuthCode($code);

            if (isset($tokenData["error"])) {
                throw new AuthenticationException("Failed to retrieve access token from Google: " . $tokenData["error_description"]);
            }

            if (!isset($tokenData["access_token"])) {
                throw new AuthenticationException("Access token not found in Google response.");
            }

            // Set the access token for subsequent API calls
            $this->googleClient->setAccessToken($tokenData);

            // Get user profile info from Google OAuth2 service
            $oauth2Service = new Google_Service_Oauth2($this->googleClient);
            $userInfo = $oauth2Service->userinfo->get();

            // Get YouTube channel info (requires YouTube scope)
            $youtubeService = new Google_Service_YouTube($this->googleClient);
            $channelsResponse = $youtubeService->channels->listChannels("snippet,contentDetails,statistics", ["mine" => true]);
            $channel = $channelsResponse->getItems()[0] ?? null;

            if (!$channel) {
                throw new AuthenticationException("Could not retrieve YouTube channel information.");
            }

            $channelId = $channel->getId();
            $channelSnippet = $channel->getSnippet();

            return [
                "platform" => "youtube",
                "access_token" => $tokenData["access_token"],
                "refresh_token" => $tokenData["refresh_token"] ?? null,
                "token_secret" => null, // OAuth 2.0 doesn't use token secrets
                "expires_in" => $tokenData["expires_in"] ?? null,
                "platform_user_id" => $channelId, // Use YouTube Channel ID as the primary ID
                "name" => $channelSnippet->getTitle() ?? $userInfo->getName(),
                "email" => $userInfo->getEmail(),
                "avatar" => $channelSnippet->getThumbnails()->getDefault()->getUrl() ?? $userInfo->getPicture(),
                "username" => $channelSnippet->getCustomUrl() ?? null, // YouTube custom URL if set
                "raw_token_data" => $tokenData,
                "raw_profile_data" => $userInfo->toSimpleObject(), // Google profile
                "raw_channel_data" => $channel->toSimpleObject(), // YouTube channel data
            ];
        } catch (\Exception $e) {
            throw new AuthenticationException("YouTube token exchange failed: " . $e->getMessage());
        }
    }

    /**
     * Refresh an access token using a refresh token.
     *
     * @param string $refreshToken
     * @return array Returns new token data.
     * @throws AuthenticationException
     */
    public function refreshToken(string $refreshToken): array
    {
        try {
            $this->googleClient->fetchAccessTokenWithRefreshToken($refreshToken);
            $tokenData = $this->googleClient->getAccessToken();

            if (isset($tokenData["error"])) {
                throw new AuthenticationException("Failed to refresh access token from Google: " . $tokenData["error_description"]);
            }

            if (!isset($tokenData["access_token"])) {
                throw new AuthenticationException("Refreshed access token not found in Google response.");
            }

            return [
                "platform" => "youtube",
                "access_token" => $tokenData["access_token"],
                "refresh_token" => $tokenData["refresh_token"] ?? $refreshToken, // Return new or old refresh token
                "token_secret" => null,
                "expires_in" => $tokenData["expires_in"] ?? null,
                "raw_token_data" => $tokenData,
            ];
        } catch (\Exception $e) {
            throw new AuthenticationException("YouTube token refresh failed: " . $e->getMessage());
        }
    }
}
