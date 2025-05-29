<?php

namespace VendorName\SocialConnect\Services\Facebook;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;
use VendorName\SocialConnect\Contracts\SocialPlatformInterface;
use VendorName\SocialConnect\Exceptions\AuthenticationException;

class FacebookService implements SocialPlatformInterface
{
    /**
     * The HTTP client instance.
     *
     * @var \GuzzleHttp\Client
     */
    protected $client;

    /**
     * Facebook Graph API version.
     *
     * @var string
     */
    protected $graphVersion;

    /**
     * Facebook Client ID.
     *
     * @var string
     */
    protected $clientId;

    /**
     * Facebook Client Secret.
     *
     * @var string
     */
    protected $clientSecret;

    /**
     * Facebook Redirect URI.
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
     * Create a new FacebookService instance.
     */
    public function __construct()
    {
        $config = Config::get("social-connect.platforms.facebook");

        if (!isset($config["client_id"], $config["client_secret"], $config["redirect"])) {
            throw new AuthenticationException("Facebook client ID, client secret, or redirect URI is not configured.");
        }

        $this->clientId = $config["client_id"];
        $this->clientSecret = $config["client_secret"];
        $this->redirectUri = $config["redirect"];
        $this->scopes = $config["scopes"] ?? [];
        $this->graphVersion = $config["graph_version"] ?? "v18.0";

        $this->client = new Client([
            "base_uri" => "https://graph.facebook.com/{$this->graphVersion}/",
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
        $state = $params["state"] ?? bin2hex(random_bytes(16));
        session(["social_connect_state" => $state]);

        $query = http_build_query([
            "client_id" => $this->clientId,
            "redirect_uri" => $this->redirectUri,
            "scope" => implode(",", $this->scopes),
            "response_type" => "code",
            "state" => $state,
        ]);

        return "https://www.facebook.com/{$this->graphVersion}/dialog/oauth?" . $query;
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
            // Exchange code for access token
            $response = $this->client->get("oauth/access_token", [
                "query" => [
                    "client_id" => $this->clientId,
                    "client_secret" => $this->clientSecret,
                    "redirect_uri" => $this->redirectUri,
                    "code" => $code,
                ],
            ]);

            $tokenData = json_decode($response->getBody()->getContents(), true);

            if (!isset($tokenData["access_token"])) {
                throw new AuthenticationException("Failed to retrieve access token from Facebook.");
            }

            $accessToken = $tokenData["access_token"];
            $expiresIn = $tokenData["expires_in"] ?? null;

            // Get user profile information
            $profileData = $this->getUserProfile($accessToken);

            // Optionally exchange for a long-lived token
            $longLivedTokenData = $this->exchangeForLongLivedToken($accessToken);
            $accessToken = $longLivedTokenData["access_token"] ?? $accessToken;
            $expiresIn = $longLivedTokenData["expires_in"] ?? $expiresIn;

            return [
                "platform" => "facebook",
                "access_token" => $accessToken,
                "refresh_token" => null, // Facebook uses long-lived tokens, no standard refresh token
                "expires_in" => $expiresIn,
                "platform_user_id" => $profileData["id"],
                "name" => $profileData["name"] ?? null,
                "email" => $profileData["email"] ?? null,
                "avatar" => $profileData["picture"]["data"]["url"] ?? null,
                "raw_token_data" => $tokenData, // Original short-lived token data
                "raw_profile_data" => $profileData, // Raw profile data
            ];
        } catch (GuzzleException | \Exception $e) {
            throw new AuthenticationException("Facebook authentication failed: " . $e->getMessage());
        }
    }

    /**
     * Get user profile information using an access token.
     *
     * @param string $accessToken
     * @return array
     * @throws AuthenticationException
     */
    public function getUserProfile(string $accessToken): array
    {
        try {
            $response = $this->client->get("me", [
                "query" => [
                    "fields" => "id,name,email,picture.type(large)",
                    "access_token" => $accessToken,
                ],
            ]);

            $profileData = json_decode($response->getBody()->getContents(), true);

            if (!isset($profileData["id"])) {
                throw new AuthenticationException("Failed to retrieve user profile from Facebook.");
            }

            return $profileData;
        } catch (GuzzleException $e) {
            throw new AuthenticationException("Failed to get Facebook user profile: " . $e->getMessage());
        }
    }

    /**
     * Exchange a short-lived access token for a long-lived one.
     *
     * @param string $accessToken Short-lived access token.
     * @return array Long-lived token data.
     * @throws AuthenticationException
     */
    protected function exchangeForLongLivedToken(string $accessToken): array
    {
        try {
            $response = $this->client->get("oauth/access_token", [
                "query" => [
                    "grant_type" => "fb_exchange_token",
                    "client_id" => $this->clientId,
                    "client_secret" => $this->clientSecret,
                    "fb_exchange_token" => $accessToken,
                ],
            ]);

            $tokenData = json_decode($response->getBody()->getContents(), true);

            if (!isset($tokenData["access_token"])) {
                // It might fail if the token is already long-lived or other issues
                // Return empty array, the original token will be used
                return [];
            }

            return $tokenData;
        } catch (GuzzleException $e) {
            // Log the error but don't fail the whole process, return empty array
            report(new AuthenticationException("Failed to exchange for long-lived Facebook token: " . $e->getMessage()));
            return [];
        }
    }

    /**
     * Refresh an access token (Not applicable for Facebook's standard flow).
     *
     * @param string $refreshToken
     * @return array
     * @throws AuthenticationException
     */
    public function refreshToken(string $refreshToken): array
    {
        // Facebook uses long-lived tokens and doesn't have a standard refresh token flow like others.
        // Token validity needs to be checked and user re-authenticated if expired.
        throw new AuthenticationException("Facebook does not support standard token refresh via refresh tokens.");
    }
}
