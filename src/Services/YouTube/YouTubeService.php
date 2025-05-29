<?php

namespace VendorName\SocialConnect\Services\YouTube;

use Google_Client;
use Google_Service_YouTube;
use VendorName\SocialConnect\Contracts\SocialPlatformInterface;
use VendorName\SocialConnect\Exceptions\AuthenticationException;

class YouTubeService implements SocialPlatformInterface
{
    /**
     * The Google client instance.
     *
     * @var \Google_Client
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
     * Create a new YouTubeService instance.
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
        
        $this->client = new Google_Client();
        $this->client->setClientId($this->clientId);
        $this->client->setClientSecret($this->clientSecret);
        $this->client->setRedirectUri($this->redirectUrl);
        $this->client->setAccessType('offline');
        $this->client->setPrompt('consent');
    }

    /**
     * Get the authorization URL for YouTube.
     *
     * @param array $scopes
     * @param string $redirectUrl
     * @return string
     */
    public function getAuthorizationUrl(array $scopes = [], string $redirectUrl = null): string
    {
        $scopes = count($scopes) > 0 ? $scopes : $this->getDefaultScopes();
        $redirectUrl = $redirectUrl ?: $this->redirectUrl;
        
        $this->client->setRedirectUri($redirectUrl);
        $this->client->setScopes($scopes);
        
        return $this->client->createAuthUrl();
    }

    /**
     * Handle the callback from YouTube and retrieve the access token.
     *
     * @param string $code
     * @param string $redirectUrl
     * @return array
     * @throws \VendorName\SocialConnect\Exceptions\AuthenticationException
     */
    public function handleCallback(string $code, string $redirectUrl = null): array
    {
        $redirectUrl = $redirectUrl ?: $this->redirectUrl;
        $this->client->setRedirectUri($redirectUrl);

        try {
            $token = $this->client->fetchAccessTokenWithAuthCode($code);

            if (!isset($token['access_token'])) {
                throw new AuthenticationException('Failed to retrieve access token from YouTube.');
            }

            // Get user profile
            $this->client->setAccessToken($token);
            $profile = $this->getUserProfile($token['access_token']);

            return [
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'] ?? null,
                'expires_in' => $token['expires_in'] ?? 3600,
                'token_type' => $token['token_type'] ?? 'Bearer',
                'profile' => $profile,
            ];
        } catch (\Exception $e) {
            throw new AuthenticationException('Failed to authenticate with YouTube: ' . $e->getMessage());
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
            $this->client->refreshToken($refreshToken);
            $token = $this->client->getAccessToken();

            if (!isset($token['access_token'])) {
                throw new AuthenticationException('Failed to refresh access token from YouTube.');
            }

            return [
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'] ?? $refreshToken,
                'expires_in' => $token['expires_in'] ?? 3600,
                'token_type' => $token['token_type'] ?? 'Bearer',
            ];
        } catch (\Exception $e) {
            throw new AuthenticationException('Failed to refresh token with YouTube: ' . $e->getMessage());
        }
    }

    /**
     * Get the user profile from YouTube.
     *
     * @param string $accessToken
     * @return array
     * @throws \VendorName\SocialConnect\Exceptions\AuthenticationException
     */
    public function getUserProfile(string $accessToken): array
    {
        try {
            $this->client->setAccessToken($accessToken);
            $youtube = new Google_Service_YouTube($this->client);
            
            // Get channel information
            $channelsResponse = $youtube->channels->listChannels('snippet,contentDetails,statistics', [
                'mine' => true
            ]);
            
            if (empty($channelsResponse->getItems())) {
                throw new AuthenticationException('No YouTube channel found for this user.');
            }
            
            $channel = $channelsResponse->getItems()[0];
            $snippet = $channel->getSnippet();
            $statistics = $channel->getStatistics();
            
            return [
                'id' => $channel->getId(),
                'name' => $snippet->getTitle(),
                'description' => $snippet->getDescription(),
                'customUrl' => $snippet->getCustomUrl(),
                'publishedAt' => $snippet->getPublishedAt(),
                'thumbnails' => [
                    'default' => $snippet->getThumbnails()->getDefault()->getUrl(),
                    'medium' => $snippet->getThumbnails()->getMedium()->getUrl(),
                    'high' => $snippet->getThumbnails()->getHigh()->getUrl(),
                ],
                'statistics' => [
                    'viewCount' => $statistics->getViewCount(),
                    'subscriberCount' => $statistics->getSubscriberCount(),
                    'videoCount' => $statistics->getVideoCount(),
                ],
            ];
        } catch (\Exception $e) {
            throw new AuthenticationException('Failed to get user profile from YouTube: ' . $e->getMessage());
        }
    }

    /**
     * Get the platform name.
     *
     * @return string
     */
    public function getPlatformName(): string
    {
        return 'youtube';
    }

    /**
     * Get the default scopes for YouTube.
     *
     * @return array
     */
    public function getDefaultScopes(): array
    {
        return [
            'https://www.googleapis.com/auth/youtube',
            'https://www.googleapis.com/auth/youtube.readonly',
            'https://www.googleapis.com/auth/youtube.upload',
            'https://www.googleapis.com/auth/youtube.force-ssl',
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
            $this->client->setAccessToken($accessToken);
            return !$this->client->isAccessTokenExpired();
        } catch (\Exception $e) {
            return false;
        }
    }
}
