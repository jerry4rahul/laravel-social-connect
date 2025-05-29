<?php

namespace VendorName\SocialConnect\Services\Twitter;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Subscriber\Oauth\Oauth1;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use VendorName\SocialConnect\Contracts\SocialPlatformInterface;
use VendorName\SocialConnect\Exceptions\AuthenticationException;

class TwitterService implements SocialPlatformInterface
{
    /**
     * The HTTP client instance.
     *
     * @var \GuzzleHttp\Client
     */
    protected $clientV1; // For OAuth 1.0a
    protected $clientV2; // For API v2 calls

    /**
     * Twitter Consumer Key (API Key).
     *
     * @var string
     */
    protected $consumerKey;

    /**
     * Twitter Consumer Secret (API Secret).
     *
     * @var string
     */
    protected $consumerSecret;

    /**
     * Twitter Callback URL.
     *
     * @var string
     */
    protected $callbackUrl;

    /**
     * Create a new TwitterService instance.
     */
    public function __construct()
    {
        $config = Config::get("social-connect.platforms.twitter");

        if (!isset($config["consumer_key"], $config["consumer_secret"], $config["callback_url"])) {
            throw new AuthenticationException("Twitter consumer key, consumer secret, or callback URL is not configured.");
        }

        $this->consumerKey = $config["consumer_key"];
        $this->consumerSecret = $config["consumer_secret"];
        $this->callbackUrl = $config["callback_url"];

        // Client for OAuth 1.0a handshake
        $stackV1 = HandlerStack::create();
        $middlewareV1 = new Oauth1([
            "consumer_key" => $this->consumerKey,
            "consumer_secret" => $this->consumerSecret,
            "callback" => $this->callbackUrl,
            "token" => "", // Provided later
            "token_secret" => "", // Provided later
        ]);
        $stackV1->push($middlewareV1);
        $this->clientV1 = new Client([
            "base_uri" => "https://api.twitter.com/oauth/",
            "handler" => $stackV1,
            "auth" => "oauth",
            "timeout" => 30,
        ]);

        // Client for API v2 calls (Bearer token or User Context OAuth 2.0)
        $this->clientV2 = new Client([
            "base_uri" => "https://api.twitter.com/2/",
            "timeout" => 30,
        ]);
    }

    /**
     * Get the authentication redirect URL (OAuth 1.0a Step 1).
     *
     * @param array $params Optional parameters.
     * @return string
     * @throws AuthenticationException
     */
    public function getRedirectUrl(array $params = []): string
    {
        try {
            // Step 1: Obtain a request token
            $response = $this->clientV1->post("request_token");

            if ($response->getStatusCode() !== 200) {
                throw new AuthenticationException("Failed to get request token from Twitter.");
            }

            parse_str((string) $response->getBody(), $token);

            if (!isset($token["oauth_token"], $token["oauth_token_secret"])) {
                 throw new AuthenticationException("Invalid request token response from Twitter.");
            }

            // Store request token secret for later use in callback
            Cache::put("twitter_oauth_token_secret_" . $token["oauth_token"], $token["oauth_token_secret"], now()->addMinutes(15));

            // Step 2: Redirect user to Twitter for authorization
            return "https://api.twitter.com/oauth/authenticate?oauth_token=" . $token["oauth_token"];
        } catch (GuzzleException $e) {
            throw new AuthenticationException("Twitter OAuth 1.0a Step 1 failed: " . $e->getMessage());
        }
    }

    /**
     * Handle the OAuth callback and exchange request token for access token (OAuth 1.0a Step 3).
     *
     * @param string $oauthToken The oauth_token from callback.
     * @param string|null $oauthVerifier The oauth_verifier from callback.
     * @return array Returns an array containing token information and basic user profile.
     * @throws AuthenticationException
     */
    public function exchangeCodeForToken(string $oauthToken, ?string $oauthVerifier = null): array
    {
        if (!$oauthVerifier) {
            throw new AuthenticationException("OAuth verifier is missing from Twitter callback.");
        }

        // Retrieve the request token secret stored earlier
        $oauthTokenSecret = Cache::pull("twitter_oauth_token_secret_" . $oauthToken);
        if (!$oauthTokenSecret) {
            throw new AuthenticationException("OAuth token secret not found or expired.");
        }

        try {
            // Reconfigure middleware with the request token and secret
            $middlewareV1 = new Oauth1([
                "consumer_key" => $this->consumerKey,
                "consumer_secret" => $this->consumerSecret,
                "token" => $oauthToken,
                "token_secret" => $oauthTokenSecret,
                "verifier" => $oauthVerifier,
            ]);
            $stackV1 = HandlerStack::create();
            $stackV1->push($middlewareV1);
            $client = new Client([
                "base_uri" => "https://api.twitter.com/oauth/",
                "handler" => $stackV1,
                "auth" => "oauth",
            ]);

            // Step 3: Exchange request token for an access token
            $response = $client->post("access_token");

            if ($response->getStatusCode() !== 200) {
                throw new AuthenticationException("Failed to get access token from Twitter.");
            }

            parse_str((string) $response->getBody(), $accessTokenData);

            if (!isset($accessTokenData["oauth_token"], $accessTokenData["oauth_token_secret"], $accessTokenData["user_id"], $accessTokenData["screen_name"])) {
                throw new AuthenticationException("Invalid access token response from Twitter.");
            }

            // Get user profile using the obtained access token (API v2)
            $profileData = $this->getUserProfile($accessTokenData["oauth_token"], $accessTokenData["oauth_token_secret"]);

            return [
                "platform" => "twitter",
                "access_token" => $accessTokenData["oauth_token"],
                "refresh_token" => null, // OAuth 1.0a doesn't use refresh tokens
                "token_secret" => $accessTokenData["oauth_token_secret"], // Specific to OAuth 1.0a
                "expires_in" => null, // OAuth 1.0a tokens don't typically expire
                "platform_user_id" => $accessTokenData["user_id"],
                "name" => $profileData["name"] ?? $accessTokenData["screen_name"],
                "email" => null, // Twitter API v2 doesn't provide email by default
                "avatar" => $profileData["profile_image_url"] ?? null,
                "username" => $accessTokenData["screen_name"],
                "raw_token_data" => $accessTokenData,
                "raw_profile_data" => $profileData,
            ];
        } catch (GuzzleException | \Exception $e) {
            throw new AuthenticationException("Twitter OAuth 1.0a Step 3 failed: " . $e->getMessage());
        }
    }

    /**
     * Get user profile information using an access token (API v2).
     *
     * @param string $accessToken User's OAuth 1.0a Access Token.
     * @param string $tokenSecret User's OAuth 1.0a Access Token Secret.
     * @return array
     * @throws AuthenticationException
     */
    public function getUserProfile(string $accessToken, string $tokenSecret): array
    {
        try {
            // Use OAuth 1.0a User Context for API v2 call
            $middlewareV2 = new Oauth1([
                "consumer_key" => $this->consumerKey,
                "consumer_secret" => $this->consumerSecret,
                "token" => $accessToken,
                "token_secret" => $tokenSecret,
            ]);
            $stackV2 = HandlerStack::create();
            $stackV2->push($middlewareV2);
            $client = new Client([
                "base_uri" => "https://api.twitter.com/2/",
                "handler" => $stackV2,
                "auth" => "oauth",
            ]);

            $response = $client->get("users/me", [
                "query" => [
                    "user.fields" => "id,name,username,created_at,description,entities,location,pinned_tweet_id,profile_image_url,protected,public_metrics,url,verified,withheld",
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data["data"]["id"])) {
                throw new AuthenticationException("Failed to retrieve user profile from Twitter API v2.");
            }

            return $data["data"];
        } catch (GuzzleException $e) {
            throw new AuthenticationException("Failed to get Twitter user profile: " . $e->getMessage());
        }
    }

    /**
     * Refresh an access token (Not applicable for Twitter OAuth 1.0a).
     *
     * @param string $refreshToken
     * @return array
     * @throws AuthenticationException
     */
    public function refreshToken(string $refreshToken): array
    {
        throw new AuthenticationException("Twitter OAuth 1.0a does not support token refresh.");
    }

    // Note: This implementation uses OAuth 1.0a which is required for many v1.1 endpoints (like DMs)
    // and can also be used for v2 endpoints with User Context.
    // If only v2 features requiring OAuth 2.0 PKCE are needed, a different flow would be implemented.
}
