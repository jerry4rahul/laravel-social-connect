<?php

namespace VendorName\SocialConnect\Services\Facebook;

use GuzzleHttp\Client;
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
     * The client ID.
     *
     * @var string
     */
    protected $clientId;

    /**
     * The client secret.
     *
     * @var string
     */
    protected $clientSecret;

    /**
     * The redirect URL.
     *
     * @var string
     */
    protected $redirectUrl;

    /**
     * Create a new FacebookService instance.
     *
     * @param string $clientId
     * @param string $clientSecret
     * @param string|null $redirectUrl
     */
    public function __construct(string $clientId, string $clientSecret, string $redirectUrl = null)
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->redirectUrl = $redirectUrl;
        $this->client = new Client([
            'base_uri' => 'https://graph.facebook.com/v18.0/',
            'timeout' => 30,
        ]);
    }

    /**
     * Get the authorization URL for Facebook.
     *
     * @param array $scopes
     * @param string $redirectUrl
     * @return string
     */
    public function getAuthorizationUrl(array $scopes = [], string $redirectUrl = null): string
    {
        $scopes = count($scopes) > 0 ? $scopes : $this->getDefaultScopes();
        $redirectUrl = $redirectUrl ?: $this->redirectUrl;

        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUrl,
            'scope' => implode(',', $scopes),
            'response_type' => 'code',
            'state' => $this->generateState(),
        ];

        return 'https://www.facebook.com/v18.0/dialog/oauth?' . http_build_query($params);
    }

    /**
     * Handle the callback from Facebook and retrieve the access token.
     *
     * @param string $code
     * @param string $redirectUrl
     * @return array
     * @throws \VendorName\SocialConnect\Exceptions\AuthenticationException
     */
    public function handleCallback(string $code, string $redirectUrl = null): array
    {
        $redirectUrl = $redirectUrl ?: $this->redirectUrl;

        try {
            $response = $this->client->post('oauth/access_token', [
                'form_params' => [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'code' => $code,
                    'redirect_uri' => $redirectUrl,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['access_token'])) {
                throw new AuthenticationException('Failed to retrieve access token from Facebook.');
            }

            // Get long-lived token
            $longLivedToken = $this->getLongLivedToken($data['access_token']);

            // Get user profile
            $profile = $this->getUserProfile($longLivedToken['access_token']);

            return [
                'access_token' => $longLivedToken['access_token'],
                'refresh_token' => null, // Facebook doesn't use refresh tokens for user tokens
                'expires_in' => $longLivedToken['expires_in'],
                'token_type' => 'bearer',
                'profile' => $profile,
            ];
        } catch (\Exception $e) {
            throw new AuthenticationException('Failed to authenticate with Facebook: ' . $e->getMessage());
        }
    }

    /**
     * Exchange a short-lived token for a long-lived token.
     *
     * @param string $accessToken
     * @return array
     * @throws \VendorName\SocialConnect\Exceptions\AuthenticationException
     */
    protected function getLongLivedToken(string $accessToken): array
    {
        try {
            $response = $this->client->get('oauth/access_token', [
                'query' => [
                    'grant_type' => 'fb_exchange_token',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'fb_exchange_token' => $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['access_token'])) {
                throw new AuthenticationException('Failed to retrieve long-lived token from Facebook.');
            }

            return [
                'access_token' => $data['access_token'],
                'expires_in' => $data['expires_in'] ?? 5184000, // Default to 60 days
            ];
        } catch (\Exception $e) {
            throw new AuthenticationException('Failed to exchange token with Facebook: ' . $e->getMessage());
        }
    }

    /**
     * Refresh the access token using the refresh token.
     * Note: Facebook doesn't use refresh tokens for user tokens, but we implement this for interface compatibility.
     *
     * @param string $refreshToken
     * @return array
     * @throws \VendorName\SocialConnect\Exceptions\AuthenticationException
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        throw new AuthenticationException('Facebook does not support refreshing user access tokens. Use page tokens instead.');
    }

    /**
     * Get the user profile from Facebook.
     *
     * @param string $accessToken
     * @return array
     * @throws \VendorName\SocialConnect\Exceptions\AuthenticationException
     */
    public function getUserProfile(string $accessToken): array
    {
        try {
            $response = $this->client->get('me', [
                'query' => [
                    'fields' => 'id,name,email,picture.type(large)',
                    'access_token' => $accessToken,
                ],
            ]);

            $profile = json_decode($response->getBody()->getContents(), true);

            // Get user pages if available
            $pages = $this->getUserPages($accessToken);

            return [
                'id' => $profile['id'],
                'name' => $profile['name'],
                'email' => $profile['email'] ?? null,
                'avatar' => $profile['picture']['data']['url'] ?? null,
                'pages' => $pages,
            ];
        } catch (\Exception $e) {
            throw new AuthenticationException('Failed to get user profile from Facebook: ' . $e->getMessage());
        }
    }

    /**
     * Get the user's Facebook pages.
     *
     * @param string $accessToken
     * @return array
     */
    public function getUserPages(string $accessToken): array
    {
        try {
            $response = $this->client->get('me/accounts', [
                'query' => [
                    'access_token' => $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return $data['data'] ?? [];
        } catch (\Exception $e) {
            // If we can't get pages, return empty array
            return [];
        }
    }

    /**
     * Get the platform name.
     *
     * @return string
     */
    public function getPlatformName(): string
    {
        return 'facebook';
    }

    /**
     * Get the default scopes for Facebook.
     *
     * @return array
     */
    public function getDefaultScopes(): array
    {
        return [
            'email',
            'public_profile',
            'pages_show_list',
            'pages_read_engagement',
            'pages_manage_posts',
            'pages_manage_metadata',
            'pages_read_user_content',
            'pages_manage_engagement',
        ];
    }

    /**
     * Validate the access token.
     *
     * @param string $accessToken
     * @return bool
     */
    public function validateAccessToken(string $accessToken): bool
    {
        try {
            $response = $this->client->get('debug_token', [
                'query' => [
                    'input_token' => $accessToken,
                    'access_token' => $this->clientId . '|' . $this->clientSecret,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return isset($data['data']) && $data['data']['is_valid'] === true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Generate a random state parameter for OAuth.
     *
     * @return string
     */
    protected function generateState(): string
    {
        return bin2hex(random_bytes(16));
    }
}
