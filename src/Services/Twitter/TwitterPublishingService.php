<?php

namespace VendorName\SocialConnect\Services\Twitter;

use GuzzleHttp\Client;
use VendorName\SocialConnect\Contracts\PublishableInterface;
use VendorName\SocialConnect\Exceptions\PublishingException;
use VendorName\SocialConnect\Models\SocialAccount;
use VendorName\SocialConnect\Models\SocialPost;

class TwitterPublishingService implements PublishableInterface
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
     * Create a new TwitterPublishingService instance.
     *
     * @param \VendorName\SocialConnect\Models\SocialAccount $account
     */
    public function __construct(SocialAccount $account)
    {
        $this->account = $account;
        $this->client = new Client([
            'base_uri' => 'https://api.twitter.com/',
            'timeout' => 30,
        ]);
    }

    /**
     * Publish a text post to Twitter.
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
            
            $response = $this->client->post('2/tweets', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'text' => $content,
                ],
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['data']['id'])) {
                throw new PublishingException('Failed to publish text post to Twitter.');
            }
            
            $tweetId = $data['data']['id'];
            
            // Create social post record
            $post = $this->createSocialPost([
                'platform_post_id' => $tweetId,
                'content' => $content,
                'post_type' => 'text',
                'status' => 'published',
                'published_at' => now(),
                'post_url' => "https://twitter.com/user/status/{$tweetId}",
            ]);
            
            return [
                'id' => $tweetId,
                'platform' => 'twitter',
                'type' => 'text',
                'url' => "https://twitter.com/user/status/{$tweetId}",
            ];
        } catch (\Exception $e) {
            throw new PublishingException('Failed to publish text post to Twitter: ' . $e->getMessage());
        }
    }

    /**
     * Publish an image post to Twitter.
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
            $mediaUrls = is_array($mediaUrls) ? $mediaUrls : [$mediaUrls];
            $mediaIds = [];
            
            // Upload each image and get media IDs
            foreach ($mediaUrls as $mediaUrl) {
                // Download the image
                $imageContent = file_get_contents($mediaUrl);
                
                // Upload to Twitter
                $uploadResponse = $this->client->post('1.1/media/upload.json', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $accessToken,
                    ],
                    'multipart' => [
                        [
                            'name' => 'media',
                            'contents' => $imageContent,
                            'filename' => basename($mediaUrl),
                        ],
                    ],
                ]);
                
                $uploadData = json_decode($uploadResponse->getBody()->getContents(), true);
                
                if (!isset($uploadData['media_id_string'])) {
                    throw new PublishingException('Failed to upload image to Twitter.');
                }
                
                $mediaIds[] = $uploadData['media_id_string'];
            }
            
            // Create tweet with media
            $response = $this->client->post('2/tweets', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'text' => $content,
                    'media' => [
                        'media_ids' => $mediaIds,
                    ],
                ],
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['data']['id'])) {
                throw new PublishingException('Failed to publish image post to Twitter.');
            }
            
            $tweetId = $data['data']['id'];
            
            // Create social post record
            $post = $this->createSocialPost([
                'platform_post_id' => $tweetId,
                'content' => $content,
                'media_urls' => $mediaUrls,
                'post_type' => 'image',
                'status' => 'published',
                'published_at' => now(),
                'post_url' => "https://twitter.com/user/status/{$tweetId}",
                'metadata' => [
                    'media_ids' => $mediaIds,
                ],
            ]);
            
            return [
                'id' => $tweetId,
                'platform' => 'twitter',
                'type' => 'image',
                'url' => "https://twitter.com/user/status/{$tweetId}",
            ];
        } catch (\Exception $e) {
            throw new PublishingException('Failed to publish image post to Twitter: ' . $e->getMessage());
        }
    }

    /**
     * Publish a video post to Twitter.
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
            
            // Download the video
            $videoContent = file_get_contents($videoUrl);
            $videoSize = strlen($videoContent);
            
            // INIT phase
            $initResponse = $this->client->post('1.1/media/upload.json', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
                'form_params' => [
                    'command' => 'INIT',
                    'total_bytes' => $videoSize,
                    'media_type' => 'video/mp4',
                ],
            ]);
            
            $initData = json_decode($initResponse->getBody()->getContents(), true);
            
            if (!isset($initData['media_id_string'])) {
                throw new PublishingException('Failed to initialize video upload to Twitter.');
            }
            
            $mediaId = $initData['media_id_string'];
            
            // APPEND phase (chunked upload)
            $chunkSize = 1024 * 1024; // 1MB chunks
            $chunks = str_split($videoContent, $chunkSize);
            
            foreach ($chunks as $index => $chunk) {
                $appendResponse = $this->client->post('1.1/media/upload.json', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $accessToken,
                    ],
                    'multipart' => [
                        [
                            'name' => 'command',
                            'contents' => 'APPEND',
                        ],
                        [
                            'name' => 'media_id',
                            'contents' => $mediaId,
                        ],
                        [
                            'name' => 'segment_index',
                            'contents' => $index,
                        ],
                        [
                            'name' => 'media',
                            'contents' => $chunk,
                            'filename' => 'chunk.mp4',
                        ],
                    ],
                ]);
                
                // Check for errors
                if ($appendResponse->getStatusCode() !== 200) {
                    throw new PublishingException('Failed to upload video chunk to Twitter.');
                }
            }
            
            // FINALIZE phase
            $finalizeResponse = $this->client->post('1.1/media/upload.json', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
                'form_params' => [
                    'command' => 'FINALIZE',
                    'media_id' => $mediaId,
                ],
            ]);
            
            $finalizeData = json_decode($finalizeResponse->getBody()->getContents(), true);
            
            if (!isset($finalizeData['media_id_string'])) {
                throw new PublishingException('Failed to finalize video upload to Twitter.');
            }
            
            // Check processing status if needed
            if (isset($finalizeData['processing_info'])) {
                $processingInfo = $finalizeData['processing_info'];
                
                if ($processingInfo['state'] === 'pending' || $processingInfo['state'] === 'in_progress') {
                    $checkAfterSecs = $processingInfo['check_after_secs'] ?? 5;
                    sleep($checkAfterSecs);
                    
                    // Check status
                    $statusResponse = $this->client->get('1.1/media/upload.json', [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $accessToken,
                        ],
                        'query' => [
                            'command' => 'STATUS',
                            'media_id' => $mediaId,
                        ],
                    ]);
                    
                    $statusData = json_decode($statusResponse->getBody()->getContents(), true);
                    
                    if (isset($statusData['processing_info']['state']) && $statusData['processing_info']['state'] === 'failed') {
                        throw new PublishingException('Twitter video processing failed: ' . ($statusData['processing_info']['error']['message'] ?? 'Unknown error'));
                    }
                }
            }
            
            // Create tweet with video
            $response = $this->client->post('2/tweets', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'text' => $content,
                    'media' => [
                        'media_ids' => [$mediaId],
                    ],
                ],
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['data']['id'])) {
                throw new PublishingException('Failed to publish video post to Twitter.');
            }
            
            $tweetId = $data['data']['id'];
            
            // Create social post record
            $post = $this->createSocialPost([
                'platform_post_id' => $tweetId,
                'content' => $content,
                'media_urls' => [$videoUrl],
                'post_type' => 'video',
                'status' => 'published',
                'published_at' => now(),
                'post_url' => "https://twitter.com/user/status/{$tweetId}",
                'metadata' => [
                    'media_id' => $mediaId,
                ],
            ]);
            
            return [
                'id' => $tweetId,
                'platform' => 'twitter',
                'type' => 'video',
                'url' => "https://twitter.com/user/status/{$tweetId}",
            ];
        } catch (\Exception $e) {
            throw new PublishingException('Failed to publish video post to Twitter: ' . $e->getMessage());
        }
    }

    /**
     * Publish a link post to Twitter.
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
            
            // Twitter doesn't have a specific link post type, just include the URL in the text
            $fullContent = $content . ' ' . $url;
            
            $response = $this->client->post('2/tweets', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'text' => $fullContent,
                ],
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['data']['id'])) {
                throw new PublishingException('Failed to publish link post to Twitter.');
            }
            
            $tweetId = $data['data']['id'];
            
            // Create social post record
            $post = $this->createSocialPost([
                'platform_post_id' => $tweetId,
                'content' => $fullContent,
                'post_type' => 'link',
                'status' => 'published',
                'published_at' => now(),
                'post_url' => "https://twitter.com/user/status/{$tweetId}",
                'metadata' => [
                    'link' => $url,
                ],
            ]);
            
            return [
                'id' => $tweetId,
                'platform' => 'twitter',
                'type' => 'link',
                'url' => "https://twitter.com/user/status/{$tweetId}",
            ];
        } catch (\Exception $e) {
            throw new PublishingException('Failed to publish link post to Twitter: ' . $e->getMessage());
        }
    }

    /**
     * Schedule a post for future publishing.
     * Note: Twitter API doesn't support scheduling directly, so we'll store it locally and publish later.
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
            
            // Create a scheduled post record
            $post = $this->createSocialPost([
                'content' => $content,
                'media_urls' => $options['media_urls'] ?? null,
                'post_type' => $postType,
                'status' => 'scheduled',
                'scheduled_at' => $scheduledAt,
                'metadata' => [
                    'options' => $options,
                ],
            ]);
            
            return [
                'id' => $post->id,
                'platform' => 'twitter',
                'type' => $postType,
                'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
            ];
        } catch (\Exception $e) {
            throw new PublishingException('Failed to schedule post on Twitter: ' . $e->getMessage());
        }
    }

    /**
     * Delete a post from Twitter.
     *
     * @param string $postId
     * @return bool
     * @throws \VendorName\SocialConnect\Exceptions\PublishingException
     */
    public function deletePost(string $postId): bool
    {
        try {
            $accessToken = $this->account->access_token;
            
            $response = $this->client->delete("2/tweets/{$postId}", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['data']['deleted']) || $data['data']['deleted'] !== true) {
                throw new PublishingException('Failed to delete post from Twitter.');
            }
            
            // Update social post record
            SocialPost::where('platform_post_id', $postId)
                ->where('platform', 'twitter')
                ->update(['status' => 'deleted']);
            
            return true;
        } catch (\Exception $e) {
            throw new PublishingException('Failed to delete post from Twitter: ' . $e->getMessage());
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
            'platform' => 'twitter',
        ], $data));
    }
}
