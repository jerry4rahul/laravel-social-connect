<?php

namespace VendorName\SocialConnect\Services\Instagram;

use GuzzleHttp\Client;
use VendorName\SocialConnect\Contracts\SocialPlatformInterface;
use VendorName\SocialConnect\Exceptions\AuthenticationException;

class InstagramService implements SocialPlatformInterface
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
     * Create a new InstagramService instance.
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
            'base_uri' => 'https://graph.instagram.com/',
            'timeout' => 30,
        ]);
    }

    /**
     * Get the authorization URL for Instagram.
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

        return 'https://api.instagram.com/oauth/authorize?' . http_build_query($params);
    }

    /**
     * Handle the callback from Instagram and retrieve the access token.
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
            $client = new Client([
                'base_uri' => 'https://api.instagram.com/',
                'timeout' => 30,
            ]);

            $response = $client->post('oauth/access_token', [
                'form_params' => [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'code' => $code,
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $redirectUrl,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['access_token'])) {
                throw new AuthenticationException('Failed to retrieve access token from Instagram.');
            }

            // Get long-lived token
            $longLivedToken = $this->getLongLivedToken($data['access_token']);

            // Get user profile
            $profile = $this->getUserProfile($longLivedToken['access_token']);

            return [
                'access_token' => $longLivedToken['access_token'],
                'refresh_token' => null, // Instagram doesn't use refresh tokens
                'expires_in' => $longLivedToken['expires_in'],
                'token_type' => 'bearer',
                'profile' => $profile,
            ];
        } catch (\Exception $e) {
            throw new AuthenticationException('Failed to authenticate with Instagram: ' . $e->getMessage());
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
            $client = new Client([
                'base_uri' => 'https://graph.instagram.com/',
                'timeout' => 30,
            ]);

            $response = $client->get('access_token', [
                'query' => [
                    'grant_type' => 'ig_exchange_token',
                    'client_secret' => $this->clientSecret,
                    'access_token' => $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['access_token'])) {
                throw new AuthenticationException('Failed to retrieve long-lived token from Instagram.');
            }

            return [
                'access_token' => $data['access_token'],
                'expires_in' => $data['expires_in'] ?? 5184000, // Default to 60 days
            ];
        } catch (\Exception $e) {
            throw new AuthenticationException('Failed to exchange token with Instagram: ' . $e->getMessage());
        }
    }

    /**
     * Refresh the access token using the refresh token.
     * Note: Instagram doesn't use refresh tokens, but we can refresh long-lived tokens.
     *
     * @param string $refreshToken
     * @return array
     * @throws \VendorName\SocialConnect\Exceptions\AuthenticationException
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        // For Instagram, we don't use a refresh token, but we can refresh the long-lived token
        try {
            $response = $this->client->get('refresh_access_token', [
                'query' => [
                    'grant_type' => 'ig_refresh_token',
                    'access_token' => $refreshToken, // For Instagram, we use the access token itself
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['access_token'])) {
                throw new AuthenticationException('Failed to refresh access token from Instagram.');
            }

            return [
                'access_token' => $data['access_token'],
                'refresh_token' => null,
                'expires_in' => $data['expires_in'] ?? 5184000, // Default to 60 days
                'token_type' => 'bearer',
            ];
        } catch (\Exception $e) {
            throw new AuthenticationException('Failed to refresh token with Instagram: ' . $e->getMessage());
        }
    }

    /**
     * Get the user profile from Instagram.
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
                    'fields' => 'id,username,account_type,media_count',
                    'access_token' => $accessToken,
                ],
            ]);

            $profile = json_decode($response->getBody()->getContents(), true);

            return [
                'id' => $profile['id'],
                'name' => $profile['username'],
                'username' => $profile['username'],
                'account_type' => $profile['account_type'] ?? null,
                'media_count' => $profile['media_count'] ?? null,
            ];
        } catch (\Exception $e) {
            throw new AuthenticationException('Failed to get user profile from Instagram: ' . $e->getMessage());
        }
    }

    /**
     * Get the platform name.
     *
     * @return string
     */
    public function getPlatformName(): string
    {
        return 'instagram';
    }

    /**
     * Get the default scopes for Instagram.
     *
     * @return array
     */
    public function getDefaultScopes(): array
    {
        return [
            'user_profile',
            'user_media',
            'instagram_basic',
            'instagram_content_publish',
            'instagram_manage_comments',
            'instagram_manage_insights',
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
            $response = $this->client->get('me', [
                'query' => [
                    'fields' => 'id',
                    'access_token' => $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return isset($data['id']);
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
