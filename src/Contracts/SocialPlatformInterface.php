<?php

namespace VendorName\SocialConnect\Contracts;

interface SocialPlatformInterface
{
    /**
     * Get the authorization URL for the platform.
     *
     * @param array $scopes
     * @param string $redirectUrl
     * @return string
     */
    public function getAuthorizationUrl(array $scopes = [], string $redirectUrl = null): string;
    
    /**
     * Handle the callback from the OAuth provider and retrieve the access token.
     *
     * @param string $code
     * @param string $redirectUrl
     * @return array
     */
    public function handleCallback(string $code, string $redirectUrl = null): array;
    
    /**
     * Refresh the access token using the refresh token.
     *
     * @param string $refreshToken
     * @return array
     */
    public function refreshAccessToken(string $refreshToken): array;
    
    /**
     * Get the user profile from the platform.
     *
     * @param string $accessToken
     * @return array
     */
    public function getUserProfile(string $accessToken): array;
    
    /**
     * Get the platform name.
     *
     * @return string
     */
    public function getPlatformName(): string;
    
    /**
     * Get the default scopes for the platform.
     *
     * @return array
     */
    public function getDefaultScopes(): array;
    
    /**
     * Validate the access token.
     *
     * @param string $accessToken
     * @return bool
     */
    public function validateAccessToken(string $accessToken): bool;
}
