<?php

namespace VendorName\SocialConnect\Services\Instagram;

use GuzzleHttp\Client;
use VendorName\SocialConnect\Contracts\PublishableInterface;
use VendorName\SocialConnect\Exceptions\PublishingException;
use VendorName\SocialConnect\Models\SocialAccount;
use VendorName\SocialConnect\Models\SocialPost;

class InstagramPublishingService implements PublishableInterface
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
     * Create a new InstagramPublishingService instance.
     *
     * @param \VendorName\SocialConnect\Models\SocialAccount $account
     */
    public function __construct(SocialAccount $account)
    {
        $this->account = $account;
        $this->client = new Client([
            'base_uri' => 'https://graph.instagram.com/',
            'timeout' => 30,
        ]);
    }

    /**
     * Publish a text post to Instagram.
     * Note: Instagram doesn't support text-only posts, so we'll throw an exception.
     *
     * @param string $content
     * @param array $options
     * @return array
     * @throws \VendorName\SocialConnect\Exceptions\PublishingException
     */
    public function publishText(string $content, array $options = []): array
    {
        throw new PublishingException('Instagram does not support text-only posts. Please use publishImage or publishVideo instead.');
    }

    /**
     * Publish an image post to Instagram.
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
            $igAccountId = $this->getInstagramAccountId();
            $mediaUrls = is_array($mediaUrls) ? $mediaUrls : [$mediaUrls];

            // For a single image
            if (count($mediaUrls) === 1) {
                // Step 1: Create a container for the image
                $response = $this->client->post("v18.0/{$igAccountId}/media", [
                    'form_params' => [
                        'image_url' => $mediaUrls[0],
                        'caption' => $content,
                        'access_token' => $accessToken,
                    ],
                ]);

                $data = json_decode($response->getBody()->getContents(), true);

                if (!isset($data['id'])) {
                    throw new PublishingException('Failed to create media container for Instagram image.');
                }

                $mediaContainerId = $data['id'];

                // Step 2: Publish the container
                $publishResponse = $this->client->post("v18.0/{$igAccountId}/media_publish", [
                    'form_params' => [
                        'creation_id' => $mediaContainerId,
                        'access_token' => $accessToken,
                    ],
                ]);

                $publishData = json_decode($publishResponse->getBody()->getContents(), true);

                if (!isset($publishData['id'])) {
                    throw new PublishingException('Failed to publish image to Instagram.');
                }

                $postId = $publishData['id'];
            } else {
                // For multiple images (carousel)
                $mediaContainerIds = [];

                foreach ($mediaUrls as $mediaUrl) {
                    $response = $this->client->post("v18.0/{$igAccountId}/media", [
                        'form_params' => [
                            'image_url' => $mediaUrl,
                            'is_carousel_item' => 'true',
                            'access_token' => $accessToken,
                        ],
                    ]);

                    $data = json_decode($response->getBody()->getContents(), true);

                    if (!isset($data['id'])) {
                        throw new PublishingException('Failed to create media container for Instagram carousel item.');
                    }

                    $mediaContainerIds[] = $data['id'];
                }

                // Create a carousel container
                $response = $this->client->post("v18.0/{$igAccountId}/media", [
                    'form_params' => [
                        'media_type' => 'CAROUSEL',
                        'children' => implode(',', $mediaContainerIds),
                        'caption' => $content,
                        'access_token' => $accessToken,
                    ],
                ]);

                $data = json_decode($response->getBody()->getContents(), true);

                if (!isset($data['id'])) {
                    throw new PublishingException('Failed to create carousel container for Instagram.');
                }

                $carouselContainerId = $data['id'];

                // Publish the carousel
                $publishResponse = $this->client->post("v18.0/{$igAccountId}/media_publish", [
                    'form_params' => [
                        'creation_id' => $carouselContainerId,
                        'access_token' => $accessToken,
                    ],
                ]);

                $publishData = json_decode($publishResponse->getBody()->getContents(), true);

                if (!isset($publishData['id'])) {
                    throw new PublishingException('Failed to publish carousel to Instagram.');
                }

                $postId = $publishData['id'];
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
                'post_url' => $postData['permalink'] ?? null,
            ]);

            return [
                'id' => $postId,
                'platform' => 'instagram',
                'type' => 'image',
                'url' => $postData['permalink'] ?? null,
            ];
        } catch (\Exception $e) {
            throw new PublishingException('Failed to publish image post to Instagram: ' . $e->getMessage());
        }
    }

    /**
     * Publish a video post to Instagram.
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
            $igAccountId = $this->getInstagramAccountId();

            // Step 1: Create a container for the video
            $response = $this->client->post("v18.0/{$igAccountId}/media", [
                'form_params' => [
                    'media_type' => 'VIDEO',
                    'video_url' => $videoUrl,
                    'caption' => $content,
                    'access_token' => $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['id'])) {
                throw new PublishingException('Failed to create media container for Instagram video.');
            }

            $mediaContainerId = $data['id'];

            // Step 2: Check the status of the container (videos need to be processed)
            $statusChecked = false;
            $maxAttempts = 10;
            $attempts = 0;

            while (!$statusChecked && $attempts < $maxAttempts) {
                $statusResponse = $this->client->get("v18.0/{$mediaContainerId}", [
                    'query' => [
                        'fields' => 'status_code',
                        'access_token' => $accessToken,
                    ],
                ]);

                $statusData = json_decode($statusResponse->getBody()->getContents(), true);

                if (isset($statusData['status_code']) && $statusData['status_code'] === 'FINISHED') {
                    $statusChecked = true;
                } elseif (isset($statusData['status_code']) && $statusData['status_code'] === 'ERROR') {
                    throw new PublishingException('Error processing video for Instagram: ' . ($statusData['error_message'] ?? 'Unknown error'));
                } else {
                    // Wait before checking again
                    sleep(2);
                    $attempts++;
                }
            }

            if (!$statusChecked) {
                throw new PublishingException('Timeout waiting for Instagram video processing.');
            }

            // Step 3: Publish the container
            $publishResponse = $this->client->post("v18.0/{$igAccountId}/media_publish", [
                'form_params' => [
                    'creation_id' => $mediaContainerId,
                    'access_token' => $accessToken,
                ],
            ]);

            $publishData = json_decode($publishResponse->getBody()->getContents(), true);

            if (!isset($publishData['id'])) {
                throw new PublishingException('Failed to publish video to Instagram.');
            }

            $postId = $publishData['id'];

            // Create social post record
            $post = $this->createSocialPost([
                'platform_post_id' => $postId,
                'content' => $content,
                'media_urls' => [$videoUrl],
                'post_type' => 'video',
                'status' => 'published',
                'published_at' => now(),
            ]);

            // Get post URL
            $postData = $this->getPostDetails($postId, $accessToken);
            $post->update([
                'post_url' => $postData['permalink'] ?? null,
            ]);

            return [
                'id' => $postId,
                'platform' => 'instagram',
                'type' => 'video',
                'url' => $postData['permalink'] ?? null,
            ];
        } catch (\Exception $e) {
            throw new PublishingException('Failed to publish video post to Instagram: ' . $e->getMessage());
        }
    }

    /**
     * Publish a link post to Instagram.
     * Note: Instagram doesn't support link posts directly, so we'll throw an exception.
     *
     * @param string $content
     * @param string $url
     * @param array $options
     * @return array
     * @throws \VendorName\SocialConnect\Exceptions\PublishingException
     */
    public function publishLink(string $content, string $url, array $options = []): array
    {
        throw new PublishingException('Instagram does not support direct link posts. You can include the link in the caption of an image or video post.');
    }

    /**
     * Schedule a post for future publishing.
     * Note: Instagram Graph API doesn't support scheduling directly, so we'll store it locally and publish later.
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
            $postType = $options['post_type'] ?? null;
            
            if (!in_array($postType, ['image', 'video'])) {
                throw new PublishingException('Instagram only supports scheduling image or video posts.');
            }
            
            if (!isset($options['media_urls']) || empty($options['media_urls'])) {
                throw new PublishingException('Media URLs are required for scheduling Instagram posts.');
            }
            
            $mediaUrls = is_array($options['media_urls']) ? $options['media_urls'] : [$options['media_urls']];
            
            // Create a scheduled post record
            $post = $this->createSocialPost([
                'content' => $content,
                'media_urls' => $mediaUrls,
                'post_type' => $postType,
                'status' => 'scheduled',
                'scheduled_at' => $scheduledAt,
                'metadata' => [
                    'options' => $options,
                ],
            ]);
            
            return [
                'id' => $post->id,
                'platform' => 'instagram',
                'type' => $postType,
                'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
            ];
        } catch (\Exception $e) {
            throw new PublishingException('Failed to schedule post on Instagram: ' . $e->getMessage());
        }
    }

    /**
     * Delete a post from Instagram.
     *
     * @param string $postId
     * @return bool
     * @throws \VendorName\SocialConnect\Exceptions\PublishingException
     */
    public function deletePost(string $postId): bool
    {
        try {
            $accessToken = $this->account->access_token;
            
            $response = $this->client->delete("v18.0/{$postId}", [
                'query' => [
                    'access_token' => $accessToken,
                ],
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['success']) || $data['success'] !== true) {
                throw new PublishingException('Failed to delete post from Instagram.');
            }
            
            // Update social post record
            SocialPost::where('platform_post_id', $postId)
                ->where('platform', 'instagram')
                ->update(['status' => 'deleted']);
            
            return true;
        } catch (\Exception $e) {
            throw new PublishingException('Failed to delete post from Instagram: ' . $e->getMessage());
        }
    }

    /**
     * Get the Instagram account ID from the account metadata.
     *
     * @return string
     * @throws \VendorName\SocialConnect\Exceptions\PublishingException
     */
    protected function getInstagramAccountId(): string
    {
        $metadata = $this->account->metadata;
        
        if (isset($metadata['id'])) {
            return $metadata['id'];
        }
        
        throw new PublishingException('Instagram account ID not found in account metadata.');
    }

    /**
     * Get post details from Instagram.
     *
     * @param string $postId
     * @param string $accessToken
     * @return array
     */
    protected function getPostDetails(string $postId, string $accessToken): array
    {
        try {
            $response = $this->client->get("v18.0/{$postId}", [
                'query' => [
                    'fields' => 'id,permalink,caption',
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
            'platform' => 'instagram',
        ], $data));
    }
}
