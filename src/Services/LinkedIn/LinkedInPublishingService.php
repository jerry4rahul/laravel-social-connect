<?php

namespace VendorName\SocialConnect\Services\LinkedIn;

use GuzzleHttp\Client;
use VendorName\SocialConnect\Contracts\PublishableInterface;
use VendorName\SocialConnect\Exceptions\PublishingException;
use VendorName\SocialConnect\Models\SocialAccount;
use VendorName\SocialConnect\Models\SocialPost;

class LinkedInPublishingService implements PublishableInterface
{
    /**
     * The HTTP client instance.
     *
     * @var \GuzzleHttp\Client
     */
    protected $client;

    /**
     * The social account instance.
     *
     * @var \VendorName\SocialConnect\Models\SocialAccount
     */
    protected $account;

    /**
     * Create a new LinkedInPublishingService instance.
     *
     * @param \VendorName\SocialConnect\Models\SocialAccount $account
     */
    public function __construct(SocialAccount $account)
    {
        $this->account = $account;
        $this->client = new Client([
            'base_uri' => 'https://api.linkedin.com/',
            'timeout' => 30,
        ]);
    }

    /**
     * Publish a text post to LinkedIn.
     *
     * @param string $content
     * @param array $options
     * @return array
     * @throws \VendorName\SocialConnect\Exceptions\PublishingException
     */
    public function publishText(string $content, array $options = []): array
    {
        try {
            $accessToken = $this->account->access_token;
            $authorId = $options['company_id'] ?? $this->getUserUrn();
            $isCompanyPost = isset($options['company_id']);
            
            // Prepare the post payload
            $payload = [
                'author' => $authorId,
                'lifecycleState' => 'PUBLISHED',
                'specificContent' => [
                    'com.linkedin.ugc.ShareContent' => [
                        'shareCommentary' => [
                            'text' => $content
                        ],
                        'shareMediaCategory' => 'NONE'
                    ]
                ],
                'visibility' => [
                    'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC'
                ]
            ];
            
            // Send the request
            $response = $this->client->post('v2/ugcPosts', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                    'X-Restli-Protocol-Version' => '2.0.0',
                ],
                'json' => $payload,
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['id'])) {
                throw new PublishingException('Failed to publish text post to LinkedIn.');
            }
            
            // Create social post record
            $post = $this->createSocialPost([
                'platform_post_id' => $data['id'],
                'content' => $content,
                'post_type' => 'text',
                'status' => 'published',
                'published_at' => now(),
                'metadata' => [
                    'author_id' => $authorId,
                    'is_company_post' => $isCompanyPost,
                ],
            ]);
            
            return [
                'id' => $data['id'],
                'platform' => 'linkedin',
                'type' => 'text',
                'url' => null, // LinkedIn API doesn't return the post URL directly
            ];
        } catch (\Exception $e) {
            throw new PublishingException('Failed to publish text post to LinkedIn: ' . $e->getMessage());
        }
    }

    /**
     * Publish an image post to LinkedIn.
     *
     * @param string $content
     * @param string|array $mediaUrls
     * @param array $options
     * @return array
     * @throws \VendorName\SocialConnect\Exceptions\PublishingException
     */
    public function publishImage(string $content, $mediaUrls, array $options = []): array
    {
        try {
            $accessToken = $this->account->access_token;
            $authorId = $options['company_id'] ?? $this->getUserUrn();
            $isCompanyPost = isset($options['company_id']);
            $mediaUrls = is_array($mediaUrls) ? $mediaUrls : [$mediaUrls];
            
            // Register image upload
            $mediaAssets = [];
            
            foreach ($mediaUrls as $mediaUrl) {
                // Step 1: Register the image upload
                $registerResponse = $this->client->post('v2/assets?action=registerUpload', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $accessToken,
                        'Content-Type' => 'application/json',
                        'X-Restli-Protocol-Version' => '2.0.0',
                    ],
                    'json' => [
                        'registerUploadRequest' => [
                            'recipes' => [
                                'com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest' => [
                                    'uploadMedia' => 'urn:li:digitalmediaUploadType:feedshare-image',
                                ],
                            ],
                            'owner' => $authorId,
                            'serviceRelationships' => [
                                [
                                    'relationshipType' => 'OWNER',
                                    'identifier' => 'urn:li:userGeneratedContent',
                                ],
                            ],
                        ],
                    ],
                ]);
                
                $registerData = json_decode($registerResponse->getBody()->getContents(), true);
                
                if (!isset($registerData['value']['uploadMechanism']['com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest']['uploadUrl'])) {
                    throw new PublishingException('Failed to register image upload with LinkedIn.');
                }
                
                $uploadUrl = $registerData['value']['uploadMechanism']['com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest']['uploadUrl'];
                $assetId = $registerData['value']['asset'];
                
                // Step 2: Upload the image
                $imageContent = file_get_contents($mediaUrl);
                
                $uploadClient = new Client();
                $uploadResponse = $uploadClient->put($uploadUrl, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $accessToken,
                    ],
                    'body' => $imageContent,
                ]);
                
                if ($uploadResponse->getStatusCode() !== 201) {
                    throw new PublishingException('Failed to upload image to LinkedIn.');
                }
                
                $mediaAssets[] = $assetId;
            }
            
            // Step 3: Create the post with the uploaded image(s)
            $mediaItems = [];
            
            foreach ($mediaAssets as $assetId) {
                $mediaItems[] = [
                    'status' => 'READY',
                    'description' => [
                        'text' => $options['image_description'] ?? 'Image',
                    ],
                    'media' => $assetId,
                ];
            }
            
            $payload = [
                'author' => $authorId,
                'lifecycleState' => 'PUBLISHED',
                'specificContent' => [
                    'com.linkedin.ugc.ShareContent' => [
                        'shareCommentary' => [
                            'text' => $content,
                        ],
                        'shareMediaCategory' => 'IMAGE',
                        'media' => $mediaItems,
                    ],
                ],
                'visibility' => [
                    'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
                ],
            ];
            
            $response = $this->client->post('v2/ugcPosts', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                    'X-Restli-Protocol-Version' => '2.0.0',
                ],
                'json' => $payload,
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['id'])) {
                throw new PublishingException('Failed to publish image post to LinkedIn.');
            }
            
            // Create social post record
            $post = $this->createSocialPost([
                'platform_post_id' => $data['id'],
                'content' => $content,
                'media_urls' => $mediaUrls,
                'post_type' => 'image',
                'status' => 'published',
                'published_at' => now(),
                'metadata' => [
                    'author_id' => $authorId,
                    'is_company_post' => $isCompanyPost,
                    'asset_ids' => $mediaAssets,
                ],
            ]);
            
            return [
                'id' => $data['id'],
                'platform' => 'linkedin',
                'type' => 'image',
                'url' => null, // LinkedIn API doesn't return the post URL directly
            ];
        } catch (\Exception $e) {
            throw new PublishingException('Failed to publish image post to LinkedIn: ' . $e->getMessage());
        }
    }

    /**
     * Publish a video post to LinkedIn.
     *
     * @param string $content
     * @param string $videoUrl
     * @param array $options
     * @return array
     * @throws \VendorName\SocialConnect\Exceptions\PublishingException
     */
    public function publishVideo(string $content, string $videoUrl, array $options = []): array
    {
        try {
            $accessToken = $this->account->access_token;
            $authorId = $options['company_id'] ?? $this->getUserUrn();
            $isCompanyPost = isset($options['company_id']);
            $title = $options['title'] ?? 'Video';
            
            // Step 1: Register the video upload
            $registerResponse = $this->client->post('v2/assets?action=registerUpload', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                    'X-Restli-Protocol-Version' => '2.0.0',
                ],
                'json' => [
                    'registerUploadRequest' => [
                        'recipes' => [
                            'com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest' => [
                                'uploadMedia' => 'urn:li:digitalmediaUploadType:feedshare-video',
                            ],
                        ],
                        'owner' => $authorId,
                        'serviceRelationships' => [
                            [
                                'relationshipType' => 'OWNER',
                                'identifier' => 'urn:li:userGeneratedContent',
                            ],
                        ],
                    ],
                ],
            ]);
            
            $registerData = json_decode($registerResponse->getBody()->getContents(), true);
            
            if (!isset($registerData['value']['uploadMechanism']['com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest']['uploadUrl'])) {
                throw new PublishingException('Failed to register video upload with LinkedIn.');
            }
            
            $uploadUrl = $registerData['value']['uploadMechanism']['com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest']['uploadUrl'];
            $assetId = $registerData['value']['asset'];
            
            // Step 2: Upload the video
            $videoContent = file_get_contents($videoUrl);
            
            $uploadClient = new Client();
            $uploadResponse = $uploadClient->put($uploadUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'video/mp4',
                ],
                'body' => $videoContent,
            ]);
            
            if ($uploadResponse->getStatusCode() !== 201) {
                throw new PublishingException('Failed to upload video to LinkedIn.');
            }
            
            // Step 3: Create the post with the uploaded video
            $payload = [
                'author' => $authorId,
                'lifecycleState' => 'PUBLISHED',
                'specificContent' => [
                    'com.linkedin.ugc.ShareContent' => [
                        'shareCommentary' => [
                            'text' => $content,
                        ],
                        'shareMediaCategory' => 'VIDEO',
                        'media' => [
                            [
                                'status' => 'READY',
                                'description' => [
                                    'text' => $options['video_description'] ?? 'Video',
                                ],
                                'media' => $assetId,
                                'title' => [
                                    'text' => $title,
                                ],
                            ],
                        ],
                    ],
                ],
                'visibility' => [
                    'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
                ],
            ];
            
            $response = $this->client->post('v2/ugcPosts', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                    'X-Restli-Protocol-Version' => '2.0.0',
                ],
                'json' => $payload,
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['id'])) {
                throw new PublishingException('Failed to publish video post to LinkedIn.');
            }
            
            // Create social post record
            $post = $this->createSocialPost([
                'platform_post_id' => $data['id'],
                'content' => $content,
                'media_urls' => [$videoUrl],
                'post_type' => 'video',
                'status' => 'published',
                'published_at' => now(),
                'metadata' => [
                    'author_id' => $authorId,
                    'is_company_post' => $isCompanyPost,
                    'asset_id' => $assetId,
                    'title' => $title,
                ],
            ]);
            
            return [
                'id' => $data['id'],
                'platform' => 'linkedin',
                'type' => 'video',
                'url' => null, // LinkedIn API doesn't return the post URL directly
            ];
        } catch (\Exception $e) {
            throw new PublishingException('Failed to publish video post to LinkedIn: ' . $e->getMessage());
        }
    }

    /**
     * Publish a link post to LinkedIn.
     *
     * @param string $content
     * @param string $url
     * @param array $options
     * @return array
     * @throws \VendorName\SocialConnect\Exceptions\PublishingException
     */
    public function publishLink(string $content, string $url, array $options = []): array
    {
        try {
            $accessToken = $this->account->access_token;
            $authorId = $options['company_id'] ?? $this->getUserUrn();
            $isCompanyPost = isset($options['company_id']);
            $title = $options['title'] ?? '';
            $description = $options['description'] ?? '';
            
            // Prepare the post payload
            $payload = [
                'author' => $authorId,
                'lifecycleState' => 'PUBLISHED',
                'specificContent' => [
                    'com.linkedin.ugc.ShareContent' => [
                        'shareCommentary' => [
                            'text' => $content,
                        ],
                        'shareMediaCategory' => 'ARTICLE',
                        'media' => [
                            [
                                'status' => 'READY',
                                'originalUrl' => $url,
                                'title' => [
                                    'text' => $title,
                                ],
                                'description' => [
                                    'text' => $description,
                                ],
                            ],
                        ],
                    ],
                ],
                'visibility' => [
                    'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
                ],
            ];
            
            // Send the request
            $response = $this->client->post('v2/ugcPosts', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                    'X-Restli-Protocol-Version' => '2.0.0',
                ],
                'json' => $payload,
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['id'])) {
                throw new PublishingException('Failed to publish link post to LinkedIn.');
            }
            
            // Create social post record
            $post = $this->createSocialPost([
                'platform_post_id' => $data['id'],
                'content' => $content,
                'post_type' => 'link',
                'status' => 'published',
                'published_at' => now(),
                'metadata' => [
                    'author_id' => $authorId,
                    'is_company_post' => $isCompanyPost,
                    'link' => $url,
                    'title' => $title,
                    'description' => $description,
                ],
            ]);
            
            return [
                'id' => $data['id'],
                'platform' => 'linkedin',
                'type' => 'link',
                'url' => null, // LinkedIn API doesn't return the post URL directly
            ];
        } catch (\Exception $e) {
            throw new PublishingException('Failed to publish link post to LinkedIn: ' . $e->getMessage());
        }
    }

    /**
     * Schedule a post for future publishing.
     * Note: LinkedIn API doesn't support scheduling directly, so we'll store it locally and publish later.
     *
     * @param string $content
     * @param \DateTime $scheduledAt
     * @param array $options
     * @return array
     * @throws \VendorName\SocialConnect\Exceptions\PublishingException
     */
    public function schedulePost(string $content, \DateTime $scheduledAt, array $options = []): array
    {
        try {
            $postType = $options['post_type'] ?? 'text';
            $authorId = $options['company_id'] ?? $this->getUserUrn();
            $isCompanyPost = isset($options['company_id']);
            
            // Create a scheduled post record
            $post = $this->createSocialPost([
                'content' => $content,
                'media_urls' => $options['media_urls'] ?? null,
                'post_type' => $postType,
                'status' => 'scheduled',
                'scheduled_at' => $scheduledAt,
                'metadata' => [
                    'author_id' => $authorId,
                    'is_company_post' => $isCompanyPost,
                    'options' => $options,
                ],
            ]);
            
            return [
                'id' => $post->id,
                'platform' => 'linkedin',
                'type' => $postType,
                'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
            ];
        } catch (\Exception $e) {
            throw new PublishingException('Failed to schedule post on LinkedIn: ' . $e->getMessage());
        }
    }

    /**
     * Delete a post from LinkedIn.
     *
     * @param string $postId
     * @return bool
     * @throws \VendorName\SocialConnect\Exceptions\PublishingException
     */
    public function deletePost(string $postId): bool
    {
        try {
            $accessToken = $this->account->access_token;
            
            $response = $this->client->delete("v2/ugcPosts/{$postId}", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'X-Restli-Protocol-Version' => '2.0.0',
                ],
            ]);
            
            if ($response->getStatusCode() !== 204) {
                throw new PublishingException('Failed to delete post from LinkedIn.');
            }
            
            // Update social post record
            SocialPost::where('platform_post_id', $postId)
                ->where('platform', 'linkedin')
                ->update(['status' => 'deleted']);
            
            return true;
        } catch (\Exception $e) {
            throw new PublishingException('Failed to delete post from LinkedIn: ' . $e->getMessage());
        }
    }

    /**
     * Get the user URN from the account metadata.
     *
     * @return string
     * @throws \VendorName\SocialConnect\Exceptions\PublishingException
     */
    protected function getUserUrn(): string
    {
        $metadata = $this->account->metadata;
        
        if (isset($metadata['id'])) {
            return 'urn:li:person:' . $metadata['id'];
        }
        
        throw new PublishingException('LinkedIn user ID not found in account metadata.');
    }

    /**
     * Create a social post record.
     *
     * @param array $data
     * @return \VendorName\SocialConnect\Models\SocialPost
     */
    protected function createSocialPost(array $data): SocialPost
    {
        return SocialPost::create(array_merge([
            'user_id' => $this->account->user_id,
            'social_account_id' => $this->account->id,
            'platform' => 'linkedin',
        ], $data));
    }
}
