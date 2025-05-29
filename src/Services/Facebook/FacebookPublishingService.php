<?php

namespace VendorName\SocialConnect\Services\Facebook;

use GuzzleHttp\Client;
use VendorName\SocialConnect\Contracts\PublishableInterface;
use VendorName\SocialConnect\Exceptions\PublishingException;
use VendorName\SocialConnect\Models\SocialAccount;
use VendorName\SocialConnect\Models\SocialPost;

class FacebookPublishingService implements PublishableInterface
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
     * Create a new FacebookPublishingService instance.
     *
     * @param \VendorName\SocialConnect\Models\SocialAccount $account
     */
    public function __construct(SocialAccount $account)
    {
        $this->account = $account;
        $this->client = new Client([
            'base_uri' => 'https://graph.facebook.com/v18.0/',
            'timeout' => 30,
        ]);
    }

    /**
     * Publish a text post to Facebook.
     *
     * @param string $content
     * @param array $options
     * @return array
     * @throws \VendorName\SocialConnect\Exceptions\PublishingException
     */
    public function publishText(string $content, array $options = []): array
    {
        try {
            $pageId = $options['page_id'] ?? $this->getDefaultPageId();
            $accessToken = $this->getPageAccessToken($pageId);

            $response = $this->client->post("{$pageId}/feed", [
                'form_params' => [
                    'message' => $content,
                    'access_token' => $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['id'])) {
                throw new PublishingException('Failed to publish text post to Facebook.');
            }

            // Create social post record
            $post = $this->createSocialPost([
                'platform_post_id' => $data['id'],
                'content' => $content,
                'post_type' => 'text',
                'status' => 'published',
                'published_at' => now(),
            ]);

            // Get post URL
            $postData = $this->getPostDetails($data['id'], $accessToken);
            $post->update([
                'post_url' => $postData['permalink_url'] ?? null,
            ]);

            return [
                'id' => $data['id'],
                'platform' => 'facebook',
                'type' => 'text',
                'url' => $postData['permalink_url'] ?? null,
            ];
        } catch (\Exception $e) {
            throw new PublishingException('Failed to publish text post to Facebook: ' . $e->getMessage());
        }
    }

    /**
     * Publish an image post to Facebook.
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
            $pageId = $options['page_id'] ?? $this->getDefaultPageId();
            $accessToken = $this->getPageAccessToken($pageId);
            $mediaUrls = is_array($mediaUrls) ? $mediaUrls : [$mediaUrls];

            // For a single image
            if (count($mediaUrls) === 1) {
                $response = $this->client->post("{$pageId}/photos", [
                    'form_params' => [
                        'message' => $content,
                        'url' => $mediaUrls[0],
                        'access_token' => $accessToken,
                    ],
                ]);

                $data = json_decode($response->getBody()->getContents(), true);

                if (!isset($data['id']) && !isset($data['post_id'])) {
                    throw new PublishingException('Failed to publish image post to Facebook.');
                }

                $postId = $data['post_id'] ?? $data['id'];
            } else {
                // For multiple images, we need to upload each one and then create a post with them
                $attachedMedia = [];

                foreach ($mediaUrls as $mediaUrl) {
                    $uploadResponse = $this->client->post("{$pageId}/photos", [
                        'form_params' => [
                            'url' => $mediaUrl,
                            'published' => 'false',
                            'access_token' => $accessToken,
                        ],
                    ]);

                    $uploadData = json_decode($uploadResponse->getBody()->getContents(), true);

                    if (!isset($uploadData['id'])) {
                        throw new PublishingException('Failed to upload image to Facebook.');
                    }

                    $attachedMedia[] = [
                        'media_fbid' => $uploadData['id'],
                    ];
                }

                // Create a post with all uploaded images
                $response = $this->client->post("{$pageId}/feed", [
                    'form_params' => [
                        'message' => $content,
                        'attached_media' => json_encode($attachedMedia),
                        'access_token' => $accessToken,
                    ],
                ]);

                $data = json_decode($response->getBody()->getContents(), true);

                if (!isset($data['id'])) {
                    throw new PublishingException('Failed to publish multi-image post to Facebook.');
                }

                $postId = $data['id'];
            }

            // Create social post record
            $post = $this->createSocialPost([
                'platform_post_id' => $postId,
                'content' => $content,
                'media_urls' => $mediaUrls,
                'post_type' => 'image',
                'status' => 'published',
                'published_at' => now(),
            ]);

            // Get post URL
            $postData = $this->getPostDetails($postId, $accessToken);
            $post->update([
                'post_url' => $postData['permalink_url'] ?? null,
            ]);

            return [
                'id' => $postId,
                'platform' => 'facebook',
                'type' => 'image',
                'url' => $postData['permalink_url'] ?? null,
            ];
        } catch (\Exception $e) {
            throw new PublishingException('Failed to publish image post to Facebook: ' . $e->getMessage());
        }
    }

    /**
     * Publish a video post to Facebook.
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
            $pageId = $options['page_id'] ?? $this->getDefaultPageId();
            $accessToken = $this->getPageAccessToken($pageId);
            $title = $options['title'] ?? '';
            $description = $options['description'] ?? $content;

            $response = $this->client->post("{$pageId}/videos", [
                'form_params' => [
                    'description' => $description,
                    'title' => $title,
                    'file_url' => $videoUrl,
                    'access_token' => $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['id'])) {
                throw new PublishingException('Failed to publish video post to Facebook.');
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
                    'title' => $title,
                    'description' => $description,
                ],
            ]);

            // Get post URL (may need to wait for video processing)
            $postData = $this->getPostDetails($data['id'], $accessToken);
            $post->update([
                'post_url' => $postData['permalink_url'] ?? null,
            ]);

            return [
                'id' => $data['id'],
                'platform' => 'facebook',
                'type' => 'video',
                'url' => $postData['permalink_url'] ?? null,
            ];
        } catch (\Exception $e) {
            throw new PublishingException('Failed to publish video post to Facebook: ' . $e->getMessage());
        }
    }

    /**
     * Publish a link post to Facebook.
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
            $pageId = $options['page_id'] ?? $this->getDefaultPageId();
            $accessToken = $this->getPageAccessToken($pageId);

            $response = $this->client->post("{$pageId}/feed", [
                'form_params' => [
                    'message' => $content,
                    'link' => $url,
                    'access_token' => $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['id'])) {
                throw new PublishingException('Failed to publish link post to Facebook.');
            }

            // Create social post record
            $post = $this->createSocialPost([
                'platform_post_id' => $data['id'],
                'content' => $content,
                'post_type' => 'link',
                'status' => 'published',
                'published_at' => now(),
                'metadata' => [
                    'link' => $url,
                ],
            ]);

            // Get post URL
            $postData = $this->getPostDetails($data['id'], $accessToken);
            $post->update([
                'post_url' => $postData['permalink_url'] ?? null,
            ]);

            return [
                'id' => $data['id'],
                'platform' => 'facebook',
                'type' => 'link',
                'url' => $postData['permalink_url'] ?? null,
            ];
        } catch (\Exception $e) {
            throw new PublishingException('Failed to publish link post to Facebook: ' . $e->getMessage());
        }
    }

    /**
     * Schedule a post for future publishing.
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
            $pageId = $options['page_id'] ?? $this->getDefaultPageId();
            $accessToken = $this->getPageAccessToken($pageId);
            $postType = $options['post_type'] ?? 'text';
            
            $params = [
                'message' => $content,
                'published' => 'false',
                'scheduled_publish_time' => $scheduledAt->getTimestamp(),
                'access_token' => $accessToken,
            ];
            
            // Add media if provided
            if (isset($options['media_urls'])) {
                if ($postType === 'image') {
                    // For scheduling images, we need a different approach
                    return $this->scheduleImagePost($content, $options['media_urls'], $scheduledAt, $options);
                } elseif ($postType === 'video') {
                    // For scheduling videos
                    return $this->scheduleVideoPost($content, $options['media_urls'][0], $scheduledAt, $options);
                }
            }
            
            // Add link if provided
            if (isset($options['link'])) {
                $params['link'] = $options['link'];
            }
            
            $response = $this->client->post("{$pageId}/feed", [
                'form_params' => $params,
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['id'])) {
                throw new PublishingException('Failed to schedule post on Facebook.');
            }
            
            // Create social post record
            $post = $this->createSocialPost([
                'platform_post_id' => $data['id'],
                'content' => $content,
                'post_type' => $postType,
                'status' => 'scheduled',
                'scheduled_at' => $scheduledAt,
                'metadata' => [
                    'link' => $options['link'] ?? null,
                ],
            ]);
            
            return [
                'id' => $data['id'],
                'platform' => 'facebook',
                'type' => $postType,
                'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
            ];
        } catch (\Exception $e) {
            throw new PublishingException('Failed to schedule post on Facebook: ' . $e->getMessage());
        }
    }

    /**
     * Schedule an image post for future publishing.
     *
     * @param string $content
     * @param array|string $mediaUrls
     * @param \DateTime $scheduledAt
     * @param array $options
     * @return array
     * @throws \VendorName\SocialConnect\Exceptions\PublishingException
     */
    protected function scheduleImagePost(string $content, $mediaUrls, \DateTime $scheduledAt, array $options = []): array
    {
        try {
            $pageId = $options['page_id'] ?? $this->getDefaultPageId();
            $accessToken = $this->getPageAccessToken($pageId);
            $mediaUrls = is_array($mediaUrls) ? $mediaUrls : [$mediaUrls];
            
            // For a single image
            if (count($mediaUrls) === 1) {
                $response = $this->client->post("{$pageId}/photos", [
                    'form_params' => [
                        'message' => $content,
                        'url' => $mediaUrls[0],
                        'published' => 'false',
                        'scheduled_publish_time' => $scheduledAt->getTimestamp(),
                        'access_token' => $accessToken,
                    ],
                ]);
                
                $data = json_decode($response->getBody()->getContents(), true);
                
                if (!isset($data['id'])) {
                    throw new PublishingException('Failed to schedule image post on Facebook.');
                }
                
                $postId = $data['id'];
            } else {
                // For multiple images, we need to upload each one and then create a scheduled post with them
                $attachedMedia = [];
                
                foreach ($mediaUrls as $mediaUrl) {
                    $uploadResponse = $this->client->post("{$pageId}/photos", [
                        'form_params' => [
                            'url' => $mediaUrl,
                            'published' => 'false',
                            'access_token' => $accessToken,
                        ],
                    ]);
                    
                    $uploadData = json_decode($uploadResponse->getBody()->getContents(), true);
                    
                    if (!isset($uploadData['id'])) {
                        throw new PublishingException('Failed to upload image to Facebook for scheduling.');
                    }
                    
                    $attachedMedia[] = [
                        'media_fbid' => $uploadData['id'],
                    ];
                }
                
                // Create a scheduled post with all uploaded images
                $response = $this->client->post("{$pageId}/feed", [
                    'form_params' => [
                        'message' => $content,
                        'attached_media' => json_encode($attachedMedia),
                        'published' => 'false',
                        'scheduled_publish_time' => $scheduledAt->getTimestamp(),
                        'access_token' => $accessToken,
                    ],
                ]);
                
                $data = json_decode($response->getBody()->getContents(), true);
                
                if (!isset($data['id'])) {
                    throw new PublishingException('Failed to schedule multi-image post on Facebook.');
                }
                
                $postId = $data['id'];
            }
            
            // Create social post record
            $post = $this->createSocialPost([
                'platform_post_id' => $postId,
                'content' => $content,
                'media_urls' => $mediaUrls,
                'post_type' => 'image',
                'status' => 'scheduled',
                'scheduled_at' => $scheduledAt,
            ]);
            
            return [
                'id' => $postId,
                'platform' => 'facebook',
                'type' => 'image',
                'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
            ];
        } catch (\Exception $e) {
            throw new PublishingException('Failed to schedule image post on Facebook: ' . $e->getMessage());
        }
    }

    /**
     * Schedule a video post for future publishing.
     *
     * @param string $content
     * @param string $videoUrl
     * @param \DateTime $scheduledAt
     * @param array $options
     * @return array
     * @throws \VendorName\SocialConnect\Exceptions\PublishingException
     */
    protected function scheduleVideoPost(string $content, string $videoUrl, \DateTime $scheduledAt, array $options = []): array
    {
        try {
            $pageId = $options['page_id'] ?? $this->getDefaultPageId();
            $accessToken = $this->getPageAccessToken($pageId);
            $title = $options['title'] ?? '';
            $description = $options['description'] ?? $content;
            
            $response = $this->client->post("{$pageId}/videos", [
                'form_params' => [
                    'description' => $description,
                    'title' => $title,
                    'file_url' => $videoUrl,
                    'published' => 'false',
                    'scheduled_publish_time' => $scheduledAt->getTimestamp(),
                    'access_token' => $accessToken,
                ],
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['id'])) {
                throw new PublishingException('Failed to schedule video post on Facebook.');
            }
            
            // Create social post record
            $post = $this->createSocialPost([
                'platform_post_id' => $data['id'],
                'content' => $content,
                'media_urls' => [$videoUrl],
                'post_type' => 'video',
                'status' => 'scheduled',
                'scheduled_at' => $scheduledAt,
                'metadata' => [
                    'title' => $title,
                    'description' => $description,
                ],
            ]);
            
            return [
                'id' => $data['id'],
                'platform' => 'facebook',
                'type' => 'video',
                'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
            ];
        } catch (\Exception $e) {
            throw new PublishingException('Failed to schedule video post on Facebook: ' . $e->getMessage());
        }
    }

    /**
     * Delete a post from Facebook.
     *
     * @param string $postId
     * @return bool
     * @throws \VendorName\SocialConnect\Exceptions\PublishingException
     */
    public function deletePost(string $postId): bool
    {
        try {
            $accessToken = $this->account->access_token;
            
            $response = $this->client->delete("{$postId}", [
                'query' => [
                    'access_token' => $accessToken,
                ],
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['success']) || $data['success'] !== true) {
                throw new PublishingException('Failed to delete post from Facebook.');
            }
            
            // Update social post record
            SocialPost::where('platform_post_id', $postId)
                ->where('platform', 'facebook')
                ->update(['status' => 'deleted']);
            
            return true;
        } catch (\Exception $e) {
            throw new PublishingException('Failed to delete post from Facebook: ' . $e->getMessage());
        }
    }

    /**
     * Get the default page ID from the account metadata.
     *
     * @return string
     * @throws \VendorName\SocialConnect\Exceptions\PublishingException
     */
    protected function getDefaultPageId(): string
    {
        $metadata = $this->account->metadata;
        
        if (isset($metadata['pages']) && !empty($metadata['pages'])) {
            return $metadata['pages'][0]['id'];
        }
        
        throw new PublishingException('No Facebook page found for this account.');
    }

    /**
     * Get the page access token for a specific page.
     *
     * @param string $pageId
     * @return string
     * @throws \VendorName\SocialConnect\Exceptions\PublishingException
     */
    protected function getPageAccessToken(string $pageId): string
    {
        $metadata = $this->account->metadata;
        
        if (isset($metadata['pages'])) {
            foreach ($metadata['pages'] as $page) {
                if ($page['id'] === $pageId) {
                    return $page['access_token'];
                }
            }
        }
        
        throw new PublishingException('Page access token not found for page ID: ' . $pageId);
    }

    /**
     * Get post details from Facebook.
     *
     * @param string $postId
     * @param string $accessToken
     * @return array
     */
    protected function getPostDetails(string $postId, string $accessToken): array
    {
        try {
            $response = $this->client->get("{$postId}", [
                'query' => [
                    'fields' => 'permalink_url',
                    'access_token' => $accessToken,
                ],
            ]);
            
            return json_decode($response->getBody()->getContents(), true);
        } catch (\Exception $e) {
            return [];
        }
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
            'platform' => 'facebook',
        ], $data));
    }
}
