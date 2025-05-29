<?php

namespace VendorName\SocialConnect\Services\YouTube;

use Google_Client;
use Google_Service_YouTube;
use Google_Service_YouTube_Video;
use Google_Service_YouTube_VideoSnippet;
use Google_Service_YouTube_VideoStatus;
use VendorName\SocialConnect\Contracts\PublishableInterface;
use VendorName\SocialConnect\Exceptions\PublishingException;
use VendorName\SocialConnect\Models\SocialAccount;
use VendorName\SocialConnect\Models\SocialPost;

class YouTubePublishingService implements PublishableInterface
{
    /**
     * The Google client instance.
     *
     * @var \Google_Client
     */
    protected $client;

    /**
     * The YouTube service instance.
     *
     * @var \Google_Service_YouTube
     */
    protected $youtube;

    /**
     * The social account instance.
     *
     * @var \VendorName\SocialConnect\Models\SocialAccount
     */
    protected $account;

    /**
     * Create a new YouTubePublishingService instance.
     *
     * @param \VendorName\SocialConnect\Models\SocialAccount $account
     */
    public function __construct(SocialAccount $account)
    {
        $this->account = $account;
        
        $this->client = new Google_Client();
        $this->client->setAccessToken($this->account->access_token);
        
        // Check if token needs refresh
        if ($this->client->isAccessTokenExpired() && $this->account->refresh_token) {
            $this->client->fetchAccessTokenWithRefreshToken($this->account->refresh_token);
            $tokens = $this->client->getAccessToken();
            
            // Update the account with new tokens
            $this->account->update([
                'access_token' => $tokens['access_token'],
                'token_expires_at' => now()->addSeconds($tokens['expires_in'] ?? 3600),
            ]);
        }
        
        $this->youtube = new Google_Service_YouTube($this->client);
    }

    /**
     * Publish a text post to YouTube.
     * Note: YouTube doesn't support text-only posts, so we'll throw an exception.
     *
     * @param string $content
     * @param array $options
     * @return array
     * @throws \VendorName\SocialConnect\Exceptions\PublishingException
     */
    public function publishText(string $content, array $options = []): array
    {
        throw new PublishingException('YouTube does not support text-only posts. Please use publishVideo instead.');
    }

    /**
     * Publish an image post to YouTube.
     * Note: YouTube doesn't support image-only posts, so we'll throw an exception.
     *
     * @param string $content
     * @param string|array $mediaUrls
     * @param array $options
     * @return array
     * @throws \VendorName\SocialConnect\Exceptions\PublishingException
     */
    public function publishImage(string $content, $mediaUrls, array $options = []): array
    {
        throw new PublishingException('YouTube does not support image-only posts. Please use publishVideo instead.');
    }

    /**
     * Publish a video post to YouTube.
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
            $title = $options['title'] ?? 'Video';
            $description = $options['description'] ?? $content;
            $tags = $options['tags'] ?? [];
            $categoryId = $options['category_id'] ?? 22; // People & Blogs
            $privacyStatus = $options['privacy_status'] ?? 'public';
            
            // Create snippet
            $snippet = new Google_Service_YouTube_VideoSnippet();
            $snippet->setTitle($title);
            $snippet->setDescription($description);
            $snippet->setTags($tags);
            $snippet->setCategoryId($categoryId);
            
            // Create status
            $status = new Google_Service_YouTube_VideoStatus();
            $status->setPrivacyStatus($privacyStatus);
            
            // Create video object
            $video = new Google_Service_YouTube_Video();
            $video->setSnippet($snippet);
            $video->setStatus($status);
            
            // Download the video file
            $tempFile = tempnam(sys_get_temp_dir(), 'youtube_upload_');
            file_put_contents($tempFile, file_get_contents($videoUrl));
            
            // Upload the video
            $response = $this->youtube->videos->insert(
                'snippet,status',
                $video,
                [
                    'data' => file_get_contents($tempFile),
                    'mimeType' => 'video/mp4',
                    'uploadType' => 'multipart',
                ]
            );
            
            // Clean up temp file
            unlink($tempFile);
            
            if (!isset($response['id'])) {
                throw new PublishingException('Failed to publish video to YouTube.');
            }
            
            $videoId = $response['id'];
            $videoUrl = "https://www.youtube.com/watch?v={$videoId}";
            
            // Create social post record
            $post = $this->createSocialPost([
                'platform_post_id' => $videoId,
                'content' => $description,
                'media_urls' => [$videoUrl],
                'post_type' => 'video',
                'status' => 'published',
                'published_at' => now(),
                'post_url' => $videoUrl,
                'metadata' => [
                    'title' => $title,
                    'description' => $description,
                    'tags' => $tags,
                    'category_id' => $categoryId,
                    'privacy_status' => $privacyStatus,
                ],
            ]);
            
            return [
                'id' => $videoId,
                'platform' => 'youtube',
                'type' => 'video',
                'url' => $videoUrl,
            ];
        } catch (\Exception $e) {
            throw new PublishingException('Failed to publish video to YouTube: ' . $e->getMessage());
        }
    }

    /**
     * Publish a link post to YouTube.
     * Note: YouTube doesn't support link-only posts, so we'll throw an exception.
     *
     * @param string $content
     * @param string $url
     * @param array $options
     * @return array
     * @throws \VendorName\SocialConnect\Exceptions\PublishingException
     */
    public function publishLink(string $content, string $url, array $options = []): array
    {
        throw new PublishingException('YouTube does not support link-only posts. Please use publishVideo instead.');
    }

    /**
     * Schedule a post for future publishing.
     * Note: YouTube API doesn't support scheduling directly, so we'll store it locally and publish later.
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
            if (!isset($options['video_url'])) {
                throw new PublishingException('Video URL is required for scheduling YouTube posts.');
            }
            
            $videoUrl = $options['video_url'];
            $title = $options['title'] ?? 'Scheduled Video';
            $description = $options['description'] ?? $content;
            
            // Create a scheduled post record
            $post = $this->createSocialPost([
                'content' => $description,
                'media_urls' => [$videoUrl],
                'post_type' => 'video',
                'status' => 'scheduled',
                'scheduled_at' => $scheduledAt,
                'metadata' => [
                    'title' => $title,
                    'description' => $description,
                    'tags' => $options['tags'] ?? [],
                    'category_id' => $options['category_id'] ?? 22,
                    'privacy_status' => $options['privacy_status'] ?? 'public',
                ],
            ]);
            
            return [
                'id' => $post->id,
                'platform' => 'youtube',
                'type' => 'video',
                'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
            ];
        } catch (\Exception $e) {
            throw new PublishingException('Failed to schedule post on YouTube: ' . $e->getMessage());
        }
    }

    /**
     * Delete a post from YouTube.
     *
     * @param string $postId
     * @return bool
     * @throws \VendorName\SocialConnect\Exceptions\PublishingException
     */
    public function deletePost(string $postId): bool
    {
        try {
            $response = $this->youtube->videos->delete($postId);
            
            // Update social post record
            SocialPost::where('platform_post_id', $postId)
                ->where('platform', 'youtube')
                ->update(['status' => 'deleted']);
            
            return true;
        } catch (\Exception $e) {
            throw new PublishingException('Failed to delete video from YouTube: ' . $e->getMessage());
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
            'platform' => 'youtube',
        ], $data));
    }
}
