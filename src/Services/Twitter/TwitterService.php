<?php

namespace VendorName\SocialConnect\Services\Twitter;

use GuzzleHttp\Client;
use VendorName\SocialConnect\Contracts\SocialPlatformInterface;
use VendorName\SocialConnect\Exceptions\AuthenticationException;

class TwitterService implements SocialPlatformInterface
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
     * Create a new TwitterService instance.
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
            'base_uri' => 'https://api.twitter.com/',
            'timeout' => 30,
        ]);
    }

    /**
     * Get the authorization URL for Twitter.
     *
     * @param array $scopes
     * @param string $redirectUrl
     * @return string
     */
    public function getAuthorizationUrl(array $scopes = [], string $redirectUrl = null): string
    {
        $scopes = count($scopes) > 0 ? $scopes : $this->getDefaultScopes();
        $redirectUrl = $redirectUrl ?: $this->redirectUrl;
        $state = $this->generateState();
        
        // Store state in session for verification
        session(['twitter_oauth_state' => $state]);

        $params = [
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUrl,
            'scope' => implode(' ', $scopes),
            'state' => $state,
            'code_challenge' => $this->generateCodeChallenge(),
            'code_challenge_method' => 'S256',
        ];

        return 'https://twitter.com/i/oauth2/authorize?' . http_build_query($params);
    }

    /**
     * Handle the callback from Twitter and retrieve the access token.
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
            $response = $this->client->post('2/oauth2/token', [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'auth' => [$this->clientId, $this->clientSecret],
                'form_params' => [
                    'code' => $code,
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $redirectUrl,
                    'code_verifier' => session('twitter_oauth_code_verifier'),
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['access_token'])) {
                throw new AuthenticationException('Failed to retrieve access token from Twitter.');
            }

            // Get user profile
            $profile = $this->getUserProfile($data['access_token']);

            return [
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'] ?? null,
                'expires_in' => $data['expires_in'] ?? 7200, // Default to 2 hours
                'token_type' => $data['token_type'] ?? 'bearer',
                'profile' => $profile,
            ];
        } catch (\Exception $e) {
            throw new AuthenticationException('Failed to authenticate with Twitter: ' . $e->getMessage());
        }
    }

    /**
     * Refresh the access token using the refresh token.
     *
     * @param string $refreshToken
     * @return array
     * @throws \VendorName\SocialConnect\Exceptions\AuthenticationException
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        try {
            $response = $this->client->post('2/oauth2/token', [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'auth' => [$this->clientId, $this->clientSecret],
                'form_params' => [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['access_token'])) {
                throw new AuthenticationException('Failed to refresh access token from Twitter.');
            }

            return [
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'] ?? $refreshToken,
                'expires_in' => $data['expires_in'] ?? 7200,
                'token_type' => $data['token_type'] ?? 'bearer',
            ];
        } catch (\Exception $e) {
            throw new AuthenticationException('Failed to refresh token with Twitter: ' . $e->getMessage());
        }
    }

    /**
     * Get the user profile from Twitter.
     *
     * @param string $accessToken
     * @return array
     * @throws \VendorName\SocialConnect\Exceptions\AuthenticationException
     */
    public function getUserProfile(string $accessToken): array
    {
        try {
            $response = $this->client->get('2/users/me', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
                'query' => [
                    'user.fields' => 'id,name,username,profile_image_url,description,verified',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['data'])) {
                throw new AuthenticationException('Failed to get user profile from Twitter.');
            }

            $profile = $data['data'];

            return [
                'id' => $profile['id'],
                'name' => $profile['name'],
                'username' => $profile['username'],
                'avatar' => $profile['profile_image_url'] ?? null,
                'description' => $profile['description'] ?? null,
                'verified' => $profile['verified'] ?? false,
            ];
        } catch (\Exception $e) {
            throw new AuthenticationException('Failed to get user profile from Twitter: ' . $e->getMessage());
        }
    }

    /**
     * Get the platform name.
     *
     * @return string
     */
    public function getPlatformName(): string
    {
        return 'twitter';
    }

    /**
     * Get the default scopes for Twitter.
     *
     * @return array
     */
    public function getDefaultScopes(): array
    {
        return [
            'tweet.read',
            'tweet.write',
            'users.read',
            'offline.access',
            'dm.read',
            'dm.write',
            'like.read',
            'like.write',
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
            $response = $this->client->get('2/users/me', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return isset($data['data']['id']);
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

    /**
     * Generate a code verifier for PKCE.
     *
     * @return string
     */
    protected function generateCodeVerifier(): string
    {
        $verifier = bin2hex(random_bytes(32));
        
        // Store code verifier in session for later use
        session(['twitter_oauth_code_verifier' => $verifier]);
        
        return $verifier;
    }

    /**
     * Generate a code challenge for PKCE.
     *
     * @return string
     */
    protected function generateCodeChallenge(): string
    {
        $verifier = $this->generateCodeVerifier();
        $challenge = hash('sha256', $verifier, true);
        
        return rtrim(strtr(base64_encode($challenge), '+/', '-_'), '=');
    }
}
