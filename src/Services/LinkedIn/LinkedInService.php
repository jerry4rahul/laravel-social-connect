<?php

namespace VendorName\SocialConnect\Services\LinkedIn;

use GuzzleHttp\Client;
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
     * Create a new LinkedInService instance.
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
            'base_uri' => 'https://api.linkedin.com/',
            'timeout' => 30,
        ]);
    }

    /**
     * Get the authorization URL for LinkedIn.
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
        session(['linkedin_oauth_state' => $state]);

        $params = [
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUrl,
            'scope' => implode(' ', $scopes),
            'state' => $state,
        ];

        return 'https://www.linkedin.com/oauth/v2/authorization?' . http_build_query($params);
    }

    /**
     * Handle the callback from LinkedIn and retrieve the access token.
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
            $response = $this->client->post('oauth/v2/accessToken', [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'form_params' => [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $redirectUrl,
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['access_token'])) {
                throw new AuthenticationException('Failed to retrieve access token from LinkedIn.');
            }

            // Get user profile
            $profile = $this->getUserProfile($data['access_token']);

            return [
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'] ?? null,
                'expires_in' => $data['expires_in'] ?? 86400, // Default to 24 hours
                'token_type' => $data['token_type'] ?? 'bearer',
                'profile' => $profile,
            ];
        } catch (\Exception $e) {
            throw new AuthenticationException('Failed to authenticate with LinkedIn: ' . $e->getMessage());
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
            $response = $this->client->post('oauth/v2/accessToken', [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'form_params' => [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['access_token'])) {
                throw new AuthenticationException('Failed to refresh access token from LinkedIn.');
            }

            return [
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'] ?? $refreshToken,
                'expires_in' => $data['expires_in'] ?? 86400,
                'token_type' => $data['token_type'] ?? 'bearer',
            ];
        } catch (\Exception $e) {
            throw new AuthenticationException('Failed to refresh token with LinkedIn: ' . $e->getMessage());
        }
    }

    /**
     * Get the user profile from LinkedIn.
     *
     * @param string $accessToken
     * @return array
     * @throws \VendorName\SocialConnect\Exceptions\AuthenticationException
     */
    public function getUserProfile(string $accessToken): array
    {
        try {
            // Get basic profile
            $response = $this->client->get('v2/me', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'X-Restli-Protocol-Version' => '2.0.0',
                ],
            ]);

            $profile = json_decode($response->getBody()->getContents(), true);

            // Get email address
            $emailResponse = $this->client->get('v2/emailAddress?q=members&projection=(elements*(handle~))', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'X-Restli-Protocol-Version' => '2.0.0',
                ],
            ]);

            $emailData = json_decode($emailResponse->getBody()->getContents(), true);
            $email = $emailData['elements'][0]['handle~']['emailAddress'] ?? null;

            // Get profile picture
            $pictureResponse = $this->client->get('v2/me?projection=(profilePicture(displayImage~:playableStreams))', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'X-Restli-Protocol-Version' => '2.0.0',
                ],
            ]);

            $pictureData = json_decode($pictureResponse->getBody()->getContents(), true);
            $picture = null;
            
            if (isset($pictureData['profilePicture']['displayImage~']['elements'])) {
                foreach ($pictureData['profilePicture']['displayImage~']['elements'] as $element) {
                    if (isset($element['identifiers'][0]['identifier'])) {
                        $picture = $element['identifiers'][0]['identifier'];
                        break;
                    }
                }
            }

            // Get company pages if available
            $companyPages = $this->getCompanyPages($accessToken);

            return [
                'id' => $profile['id'],
                'firstName' => $profile['localizedFirstName'] ?? null,
                'lastName' => $profile['localizedLastName'] ?? null,
                'name' => ($profile['localizedFirstName'] ?? '') . ' ' . ($profile['localizedLastName'] ?? ''),
                'email' => $email,
                'avatar' => $picture,
                'companyPages' => $companyPages,
            ];
        } catch (\Exception $e) {
            throw new AuthenticationException('Failed to get user profile from LinkedIn: ' . $e->getMessage());
        }
    }

    /**
     * Get the user's company pages.
     *
     * @param string $accessToken
     * @return array
     */
    public function getCompanyPages(string $accessToken): array
    {
        try {
            $response = $this->client->get('v2/organizationalEntityAcls?q=roleAssignee', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'X-Restli-Protocol-Version' => '2.0.0',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $pages = [];

            if (isset($data['elements'])) {
                foreach ($data['elements'] as $element) {
                    if (isset($element['organizationalTarget'])) {
                        $orgUrn = $element['organizationalTarget'];
                        $orgId = str_replace('urn:li:organization:', '', $orgUrn);
                        
                        // Get organization details
                        $orgResponse = $this->client->get("v2/organizations/{$orgId}", [
                            'headers' => [
                                'Authorization' => 'Bearer ' . $accessToken,
                                'X-Restli-Protocol-Version' => '2.0.0',
                            ],
                        ]);
                        
                        $orgData = json_decode($orgResponse->getBody()->getContents(), true);
                        
                        $pages[] = [
                            'id' => $orgId,
                            'name' => $orgData['localizedName'] ?? null,
                            'vanityName' => $orgData['vanityName'] ?? null,
                            'logoUrl' => $orgData['logoV2']['original'] ?? null,
                            'role' => $element['role'],
                        ];
                    }
                }
            }

            return $pages;
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
        return 'linkedin';
    }

    /**
     * Get the default scopes for LinkedIn.
     *
     * @return array
     */
    public function getDefaultScopes(): array
    {
        return [
            'r_liteprofile',
            'r_emailaddress',
            'w_member_social',
            'r_organization_social',
            'rw_organization_admin',
            'w_organization_social',
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
            $response = $this->client->get('v2/me', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'X-Restli-Protocol-Version' => '2.0.0',
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
