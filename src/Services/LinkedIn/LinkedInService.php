<?php

namespace VendorName\SocialConnect\Services\LinkedIn;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;
use VendorName\SocialConnect\Contracts\SocialPlatformInterface;
use VendorName\SocialConnect\Exceptions\AuthenticationException;

class LinkedInService implements SocialPlatformInterface
{
    /**
     * The HTTP client instance.
     *
     * @var \GuzzleHttp\Client
     */
    protected $client;

    /**
     * LinkedIn Client ID.
     *
     * @var string
     */
    protected $clientId;

    /**
     * LinkedIn Client Secret.
     *
     * @var string
     */
    protected $clientSecret;

    /**
     * LinkedIn Redirect URI.
     *
     * @var string
     */
    protected $redirectUri;

    /**
     * Create a new LinkedInService instance.
     */
    public function __construct()
    {
        $config = Config::get("social-connect.platforms.linkedin");

        if (!isset($config["client_id"], $config["client_secret"], $config["redirect_uri"])) {
            throw new AuthenticationException("LinkedIn client ID, client secret, or redirect URI is not configured.");
        }

        $this->clientId = $config["client_id"];
        $this->clientSecret = $config["client_secret"];
        $this->redirectUri = $config["redirect_uri"];

        $this->client = new Client([
            "base_uri" => "https://www.linkedin.com/",
            "timeout" => 30,
        ]);
    }

    /**
     * Get the authentication redirect URL.
     *
     * @param array $params Optional parameters (e.g., state, scope).
     * @return string
     */
    public function getRedirectUrl(array $params = []): string
    {
        $defaultScopes = [
            "profile", "email", "openid", // Basic profile
            "w_member_social", // Post updates, make comments, like posts
            "r_liteprofile", "r_emailaddress", // Basic profile read
            "r_organization_social", "w_organization_social", // Company page posts/comments
            "rw_organization_admin", // Manage company pages & retrieve reporting data
            "r_ads", "rw_ads", // Ads related permissions (if needed)
            "r_basicprofile", // Deprecated but sometimes needed
            "r_1st_connections_size", // Get number of connections
            "r_compliance", "w_compliance", // Compliance endpoints (if needed)
            "r_marketing_partner_leads", "rw_marketing_partner_leads" // Lead Gen Forms (if needed)
        ];

        $scopes = $params["scope"] ?? Config::get("social-connect.platforms.linkedin.scopes", $defaultScopes);
        $state = $params["state"] ?? bin2hex(random_bytes(16));

        $query = http_build_query([
            "response_type" => "code",
            "client_id" => $this->clientId,
            "redirect_uri" => $this->redirectUri,
            "state" => $state,
            "scope" => implode(" ", $scopes),
        ]);

        return "https://www.linkedin.com/oauth/v2/authorization?" . $query;
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
            $response = $this->client->post("oauth/v2/accessToken", [
                "form_params" => [
                    "grant_type" => "authorization_code",
                    "code" => $code,
                    "redirect_uri" => $this->redirectUri,
                    "client_id" => $this->clientId,
                    "client_secret" => $this->clientSecret,
                ],
            ]);

            $tokenData = json_decode($response->getBody()->getContents(), true);

            if (!isset($tokenData["access_token"])) {
                throw new AuthenticationException("Failed to retrieve access token from LinkedIn.");
            }

            // Get user profile using the access token
            $profileData = $this->getUserProfile($tokenData["access_token"]);
            $emailData = $this->getUserEmail($tokenData["access_token"]);

            return [
                "platform" => "linkedin",
                "access_token" => $tokenData["access_token"],
                "refresh_token" => $tokenData["refresh_token"] ?? null,
                "token_secret" => null, // OAuth 2.0 doesn't use token secrets
                "expires_in" => $tokenData["expires_in"] ?? null,
                "platform_user_id" => $profileData["id"],
                "name" => ($profileData["localizedFirstName"] ?? "") . " " . ($profileData["localizedLastName"] ?? ""),
                "email" => $emailData["email"] ?? null,
                "avatar" => $profileData["profilePicture"]["displayImage~"]["elements"][0]["identifiers"][0]["identifier"] ?? null,
                "username" => null, // LinkedIn doesn't have a primary username like Twitter
                "raw_token_data" => $tokenData,
                "raw_profile_data" => $profileData,
                "raw_email_data" => $emailData,
            ];
        } catch (GuzzleException $e) {
            throw new AuthenticationException("LinkedIn token exchange failed: " . $e->getMessage());
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
            $client = new Client(["base_uri" => "https://api.linkedin.com/v2/", "timeout" => 30]);
            $response = $client->get("me", [
                "headers" => [
                    "Authorization" => "Bearer " . $accessToken,
                    "Connection" => "Keep-Alive",
                    "X-Restli-Protocol-Version" => "2.0.0", // Recommended header
                ],
                "query" => [
                    // Request specific fields needed, including profile picture
                    "projection" => "(id,localizedFirstName,localizedLastName,profilePicture(displayImage~:playableStreams))"
                ]
            ]);

            $profileData = json_decode($response->getBody()->getContents(), true);

            if (!isset($profileData["id"])) {
                throw new AuthenticationException("Failed to retrieve user profile from LinkedIn.");
            }

            return $profileData;
        } catch (GuzzleException $e) {
            throw new AuthenticationException("Failed to get LinkedIn user profile: " . $e->getMessage());
        }
    }

     /**
     * Get user email address using an access token.
     *
     * @param string $accessToken
     * @return array
     * @throws AuthenticationException
     */
    public function getUserEmail(string $accessToken): array
    {
        try {
            $client = new Client(["base_uri" => "https://api.linkedin.com/v2/", "timeout" => 30]);
            $response = $client->get("emailAddress", [
                "headers" => [
                    "Authorization" => "Bearer " . $accessToken,
                    "Connection" => "Keep-Alive",
                    "X-Restli-Protocol-Version" => "2.0.0",
                ],
                "query" => [
                    "q" => "members",
                    "projection" => "(elements*(handle~))"
                ]
            ]);

            $emailData = json_decode($response->getBody()->getContents(), true);

            // Extract the primary email
            $primaryEmail = null;
            if (isset($emailData["elements"][0]["handle~"]["emailAddress"])) {
                $primaryEmail = $emailData["elements"][0]["handle~"]["emailAddress"];
            }

            if (!$primaryEmail) {
                // Email might not be available or requires different scope/permissions
                // Log::warning("Could not retrieve primary email from LinkedIn.", ["response" => $emailData]);
                return ["email" => null, "raw_response" => $emailData];
            }

            return ["email" => $primaryEmail, "raw_response" => $emailData];
        } catch (GuzzleException $e) {
            // Don't fail the whole auth process if email fails, just return null
            // Log::error("Failed to get LinkedIn user email: " . $e->getMessage());
            return ["email" => null, "raw_response" => null];
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
            $response = $this->client->post("oauth/v2/accessToken", [
                "form_params" => [
                    "grant_type" => "refresh_token",
                    "refresh_token" => $refreshToken,
                    "client_id" => $this->clientId,
                    "client_secret" => $this->clientSecret,
                ],
            ]);

            $tokenData = json_decode($response->getBody()->getContents(), true);

            if (!isset($tokenData["access_token"])) {
                throw new AuthenticationException("Failed to refresh access token from LinkedIn.");
            }

            return [
                "platform" => "linkedin",
                "access_token" => $tokenData["access_token"],
                "refresh_token" => $tokenData["refresh_token"] ?? $refreshToken, // Return new or old refresh token
                "token_secret" => null,
                "expires_in" => $tokenData["expires_in"] ?? null,
                "raw_token_data" => $tokenData,
            ];
        } catch (GuzzleException $e) {
            throw new AuthenticationException("LinkedIn token refresh failed: " . $e->getMessage());
        }
    }
}
