<?php

namespace VendorName\SocialConnect\Services\Instagram;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;
use VendorName\SocialConnect\Contracts\SocialPlatformInterface;
use VendorName\SocialConnect\Exceptions\AuthenticationException;

class InstagramService implements SocialPlatformInterface
{
    /**
     * The HTTP client instance for Basic Display API.
     *
     * @var \GuzzleHttp\Client
     */
    protected $basicClient;

    /**
     * The HTTP client instance for Graph API.
     *
     * @var \GuzzleHttp\Client
     */
    protected $graphClient;

    /**
     * Instagram Client ID.
     *
     * @var string
     */
    protected $clientId;

    /**
     * Instagram Client Secret.
     *
     * @var string
     */
    protected $clientSecret;

    /**
     * Instagram Redirect URI.
     *
     * @var string
     */
    protected $redirectUri;

    /**
     * Required scopes.
     *
     * @var array
     */
    protected $scopes;

    /**
     * Create a new InstagramService instance.
     */
    public function __construct()
    {
        $config = Config::get("social-connect.platforms.instagram");

        if (!isset($config["client_id"], $config["client_secret"], $config["redirect"])) {
            throw new AuthenticationException("Instagram client ID, client secret, or redirect URI is not configured.");
        }

        $this->clientId = $config["client_id"];
        $this->clientSecret = $config["client_secret"];
        $this->redirectUri = $config["redirect"];
        $this->scopes = $config["scopes"] ?? ["user_profile", "user_media"]; // Default to Basic Display scopes

        $this->basicClient = new Client([
            "base_uri" => "https://api.instagram.com/",
            "timeout" => 30,
        ]);

        $this->graphClient = new Client([
            "base_uri" => "https://graph.instagram.com/",
            "timeout" => 30,
        ]);
    }

    /**
     * Get the authentication redirect URL.
     *
     * @param array $params Optional parameters.
     * @return string
     */
    public function getRedirectUrl(array $params = []): string
    {
        // Instagram Basic Display API OAuth flow
        $state = $params["state"] ?? bin2hex(random_bytes(16));
        session(["social_connect_state" => $state]);

        $query = http_build_query([
            "client_id" => $this->clientId,
            "redirect_uri" => $this->redirectUri,
            "scope" => implode(",", $this->scopes),
            "response_type" => "code",
            "state" => $state,
        ]);

        return "https://api.instagram.com/oauth/authorize?" . $query;
    }

    /**
     * Handle the OAuth callback and exchange code for token.
     *
     * @param string $code The authorization code from callback.
     * @param string|null $state The state parameter from callback for validation.
     * @return array Returns an array containing token information and basic user profile.
     * @throws AuthenticationException
     */
    public function exchangeCodeForToken(string $code, ?string $state = null): array
    {
        // Validate state if provided
        if ($state !== null) {
            $sessionState = session()->pull("social_connect_state");
            if (!$sessionState || $sessionState !== $state) {
                throw new AuthenticationException("Invalid OAuth state.");
            }
        }

        try {
            // Exchange code for short-lived access token (Basic Display API)
            $response = $this->basicClient->post("oauth/access_token", [
                "form_params" => [
                    "client_id" => $this->clientId,
                    "client_secret" => $this->clientSecret,
                    "grant_type" => "authorization_code",
                    "redirect_uri" => $this->redirectUri,
                    "code" => $code,
                ],
            ]);

            $tokenData = json_decode($response->getBody()->getContents(), true);

            if (!isset($tokenData["access_token"], $tokenData["user_id"])) {
                throw new AuthenticationException("Failed to retrieve access token or user ID from Instagram.");
            }

            $shortLivedToken = $tokenData["access_token"];
            $userId = $tokenData["user_id"];

            // Exchange short-lived token for a long-lived token (Basic Display API)
            $longLivedTokenData = $this->exchangeForLongLivedToken($shortLivedToken);
            $accessToken = $longLivedTokenData["access_token"] ?? $shortLivedToken;
            $expiresIn = $longLivedTokenData["expires_in"] ?? 3600; // Default 1 hour for short-lived

            // Get user profile information (Basic Display API)
            $profileData = $this->getUserProfile($accessToken);

            return [
                "platform" => "instagram",
                "access_token" => $accessToken,
                "refresh_token" => null, // Basic Display API uses long-lived tokens, refresh via API call
                "expires_in" => $expiresIn,
                "platform_user_id" => $userId,
                "name" => $profileData["username"] ?? null,
                "email" => null, // Basic Display API doesn't provide email
                "avatar" => null, // Basic Display API doesn't provide profile picture
                "raw_token_data" => $longLivedTokenData ?: $tokenData,
                "raw_profile_data" => $profileData,
            ];
        } catch (GuzzleException | \Exception $e) {
            throw new AuthenticationException("Instagram authentication failed: " . $e->getMessage());
        }
    }

    /**
     * Get user profile information using an access token (Basic Display API).
     *
     * @param string $accessToken
     * @return array
     * @throws AuthenticationException
     */
    public function getUserProfile(string $accessToken): array
    {
        try {
            // Basic Display API endpoint for user profile
            $response = $this->graphClient->get("me", [
                "query" => [
                    "fields" => "id,username,account_type,media_count",
                    "access_token" => $accessToken,
                ],
            ]);

            $profileData = json_decode($response->getBody()->getContents(), true);

            if (!isset($profileData["id"])) {
                throw new AuthenticationException("Failed to retrieve user profile from Instagram.");
            }

            return $profileData;
        } catch (GuzzleException $e) {
            throw new AuthenticationException("Failed to get Instagram user profile: " . $e->getMessage());
        }
    }

    /**
     * Exchange a short-lived access token for a long-lived one (Basic Display API).
     *
     * @param string $accessToken Short-lived access token.
     * @return array Long-lived token data.
     * @throws AuthenticationException
     */
    protected function exchangeForLongLivedToken(string $accessToken): array
    {
        try {
            $response = $this->graphClient->get("access_token", [
                "query" => [
                    "grant_type" => "ig_exchange_token",
                    "client_secret" => $this->clientSecret,
                    "access_token" => $accessToken,
                ],
            ]);

            $tokenData = json_decode($response->getBody()->getContents(), true);

            if (!isset($tokenData["access_token"])) {
                throw new AuthenticationException("Failed to exchange for long-lived Instagram token.");
            }

            return $tokenData; // Contains access_token, token_type, expires_in
        } catch (GuzzleException $e) {
            throw new AuthenticationException("Failed to exchange for long-lived Instagram token: " . $e->getMessage());
        }
    }

    /**
     * Refresh a long-lived access token (Basic Display API).
     *
     * @param string $longLivedAccessToken The existing long-lived token.
     * @return array Returns new token info.
     * @throws AuthenticationException
     */
    public function refreshToken(string $longLivedAccessToken): array
    {
        try {
            $response = $this->graphClient->get("refresh_access_token", [
                "query" => [
                    "grant_type" => "ig_refresh_token",
                    "access_token" => $longLivedAccessToken,
                ],
            ]);

            $tokenData = json_decode($response->getBody()->getContents(), true);

            if (!isset($tokenData["access_token"])) {
                throw new AuthenticationException("Failed to refresh Instagram access token.");
            }

            // The refreshed token is also long-lived
            return [
                "platform" => "instagram",
                "access_token" => $tokenData["access_token"],
                "refresh_token" => null,
                "expires_in" => $tokenData["expires_in"],
                "raw_token_data" => $tokenData,
            ];
        } catch (GuzzleException $e) {
            throw new AuthenticationException("Failed to refresh Instagram access token: " . $e->getMessage());
        }
    }

    // Note: Instagram Graph API (for Business/Creator accounts) uses the Facebook Login flow.
    // This service currently implements the Basic Display API flow.
    // A separate mechanism or configuration might be needed to handle Graph API authentication if required for publishing/insights/comments.
}
