<?php

namespace VendorName\SocialConnect\Services\Twitter;

use GuzzleHttp\Client;
use VendorName\SocialConnect\Contracts\CommentManagementInterface;
use VendorName\SocialConnect\Exceptions\CommentException;
use VendorName\SocialConnect\Models\SocialAccount;
use VendorName\SocialConnect\Models\SocialComment;
use VendorName\SocialConnect\Models\SocialPost;

class TwitterCommentService implements CommentManagementInterface
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
     * Create a new TwitterCommentService instance.
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
     * Get comments for a specific post.
     *
     * @param string $postId
     * @param int $limit
     * @param string|null $cursor
     * @return array
     * @throws \VendorName\SocialConnect\Exceptions\CommentException
     */
    public function getComments(string $postId, int $limit = 20, ?string $cursor = null): array
    {
        try {
            $accessToken = $this->account->access_token;
            
            $params = [
                'max_results' => $limit,
                'tweet.fields' => 'created_at,public_metrics',
                'user.fields' => 'name,username,profile_image_url',
                'expansions' => 'author_id',
            ];
            
            if ($cursor) {
                $params['pagination_token'] = $cursor;
            }
            
            $response = $this->client->get("2/tweets/{$postId}/replies", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
                'query' => $params,
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['data'])) {
                throw new CommentException('Failed to retrieve comments from Twitter.');
            }
            
            // Get the post from database
            $socialPost = SocialPost::where('platform_post_id', $postId)
                ->where('social_account_id', $this->account->id)
                ->first();
            
            $comments = [];
            $nextCursor = $data['meta']['next_token'] ?? null;
            
            // Create a map of user IDs to user data
            $users = [];
            if (isset($data['includes']['users'])) {
                foreach ($data['includes']['users'] as $user) {
                    $users[$user['id']] = $user;
                }
            }
            
            foreach ($data['data'] as $comment) {
                $commentId = $comment['id'];
                $commentText = $comment['text'] ?? '';
                $commenterId = $comment['author_id'] ?? null;
                $createdAt = $comment['created_at'] ?? null;
                
                // Get commenter details
                $commenterName = null;
                $commenterUsername = null;
                $commenterAvatar = null;
                
                if (isset($users[$commenterId])) {
                    $commenterName = $users[$commenterId]['name'] ?? null;
                    $commenterUsername = $users[$commenterId]['username'] ?? null;
                    $commenterAvatar = $users[$commenterId]['profile_image_url'] ?? null;
                }
                
                // Get metrics
                $likeCount = $comment['public_metrics']['like_count'] ?? 0;
                $replyCount = $comment['public_metrics']['reply_count'] ?? 0;
                $retweetCount = $comment['public_metrics']['retweet_count'] ?? 0;
                
                // Store in database
                $socialComment = SocialComment::updateOrCreate(
                    [
                        'social_account_id' => $this->account->id,
                        'platform_comment_id' => $commentId,
                    ],
                    [
                        'user_id' => $this->account->user_id,
                        'social_post_id' => $socialPost ? $socialPost->id : null,
                        'platform' => 'twitter',
                        'platform_post_id' => $postId,
                        'comment' => $commentText,
                        'commenter_id' => $commenterId,
                        'commenter_name' => $commenterName,
                        'is_reply' => false,
                        'like_count' => $likeCount,
                        'reply_count' => $replyCount,
                        'metadata' => [
                            'created_at' => $createdAt,
                            'commenter_username' => $commenterUsername,
                            'commenter_avatar' => $commenterAvatar,
                            'retweet_count' => $retweetCount,
                        ],
                    ]
                );
                
                $comments[] = [
                    'id' => $socialComment->id,
                    'platform_comment_id' => $commentId,
                    'comment' => $commentText,
                    'commenter_id' => $commenterId,
                    'commenter_name' => $commenterName,
                    'commenter_username' => $commenterUsername,
                    'commenter_avatar' => $commenterAvatar,
                    'created_at' => $createdAt,
                    'like_count' => $likeCount,
                    'reply_count' => $replyCount,
                    'retweet_count' => $retweetCount,
                ];
            }
            
            return [
                'post_id' => $socialPost ? $socialPost->id : null,
                'platform_post_id' => $postId,
                'comments' => $comments,
                'next_cursor' => $nextCursor,
            ];
        } catch (\Exception $e) {
            throw new CommentException('Failed to get comments from Twitter: ' . $e->getMessage());
        }
    }
    
    /**
     * Get replies to a specific comment.
     *
     * @param string $commentId
     * @param int $limit
     * @param string|null $cursor
     * @return array
     * @throws \VendorName\SocialConnect\Exceptions\CommentException
     */
    public function getCommentReplies(string $commentId, int $limit = 20, ?string $cursor = null): array
    {
        try {
            $accessToken = $this->account->access_token;
            
            $params = [
                'max_results' => $limit,
                'tweet.fields' => 'created_at,public_metrics',
                'user.fields' => 'name,username,profile_image_url',
                'expansions' => 'author_id',
            ];
            
            if ($cursor) {
                $params['pagination_token'] = $cursor;
            }
            
            $response = $this->client->get("2/tweets/{$commentId}/replies", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
                'query' => $params,
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['data'])) {
                throw new CommentException('Failed to retrieve comment replies from Twitter.');
            }
            
            // Get the parent comment from database
            $parentComment = SocialComment::where('platform_comment_id', $commentId)
                ->where('social_account_id', $this->account->id)
                ->first();
            
            $replies = [];
            $nextCursor = $data['meta']['next_token'] ?? null;
            
            // Create a map of user IDs to user data
            $users = [];
            if (isset($data['includes']['users'])) {
                foreach ($data['includes']['users'] as $user) {
                    $users[$user['id']] = $user;
                }
            }
            
            foreach ($data['data'] as $reply) {
                $replyId = $reply['id'];
                $replyText = $reply['text'] ?? '';
                $commenterId = $reply['author_id'] ?? null;
                $createdAt = $reply['created_at'] ?? null;
                
                // Get commenter details
                $commenterName = null;
                $commenterUsername = null;
                $commenterAvatar = null;
                
                if (isset($users[$commenterId])) {
                    $commenterName = $users[$commenterId]['name'] ?? null;
                    $commenterUsername = $users[$commenterId]['username'] ?? null;
                    $commenterAvatar = $users[$commenterId]['profile_image_url'] ?? null;
                }
                
                // Get metrics
                $likeCount = $reply['public_metrics']['like_count'] ?? 0;
                $replyCount = $reply['public_metrics']['reply_count'] ?? 0;
                $retweetCount = $reply['public_metrics']['retweet_count'] ?? 0;
                
                // Store in database
                $socialComment = SocialComment::updateOrCreate(
                    [
                        'social_account_id' => $this->account->id,
                        'platform_comment_id' => $replyId,
                    ],
                    [
                        'user_id' => $this->account->user_id,
                        'social_post_id' => $parentComment ? $parentComment->social_post_id : null,
                        'platform' => 'twitter',
                        'platform_post_id' => $parentComment ? $parentComment->platform_post_id : null,
                        'comment' => $replyText,
                        'commenter_id' => $commenterId,
                        'commenter_name' => $commenterName,
                        'parent_id' => $parentComment ? $parentComment->id : null,
                        'is_reply' => true,
                        'like_count' => $likeCount,
                        'reply_count' => $replyCount,
                        'metadata' => [
                            'created_at' => $createdAt,
                            'commenter_username' => $commenterUsername,
                            'commenter_avatar' => $commenterAvatar,
                            'retweet_count' => $retweetCount,
                        ],
                    ]
                );
                
                $replies[] = [
                    'id' => $socialComment->id,
                    'platform_comment_id' => $replyId,
                    'comment' => $replyText,
                    'commenter_id' => $commenterId,
                    'commenter_name' => $commenterName,
                    'commenter_username' => $commenterUsername,
                    'commenter_avatar' => $commenterAvatar,
                    'created_at' => $createdAt,
                    'like_count' => $likeCount,
                    'reply_count' => $replyCount,
                    'retweet_count' => $retweetCount,
                ];
            }
            
            return [
                'parent_comment_id' => $parentComment ? $parentComment->id : null,
                'platform_comment_id' => $commentId,
                'replies' => $replies,
                'next_cursor' => $nextCursor,
            ];
        } catch (\Exception $e) {
            throw new CommentException('Failed to get comment replies from Twitter: ' . $e->getMessage());
        }
    }
    
    /**
     * Post a new comment on a post.
     *
     * @param string $postId
     * @param string $comment
     * @param array $options
     * @return array
     * @throws \VendorName\SocialConnect\Exceptions\CommentException
     */
    public function postComment(string $postId, string $comment, array $options = []): array
    {
        try {
            $accessToken = $this->account->access_token;
            
            $payload = [
                'text' => $comment,
                'reply' => [
                    'in_reply_to_tweet_id' => $postId,
                ],
            ];
            
            // Add media if provided
            if (isset($options['media_ids'])) {
                $payload['media'] = [
                    'media_ids' => $options['media_ids'],
                ];
            }
            
            $response = $this->client->post('2/tweets', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['data']['id'])) {
                throw new CommentException('Failed to post comment to Twitter.');
            }
            
            $commentId = $data['data']['id'];
            
            // Get the post from database
            $socialPost = SocialPost::where('platform_post_id', $postId)
                ->where('social_account_id', $this->account->id)
                ->first();
            
            // Get user details
            $userId = $this->getUserId();
            
            // Store in database
            $socialComment = SocialComment::create([
                'user_id' => $this->account->user_id,
                'social_account_id' => $this->account->id,
                'social_post_id' => $socialPost ? $socialPost->id : null,
                'platform' => 'twitter',
                'platform_comment_id' => $commentId,
                'platform_post_id' => $postId,
                'comment' => $comment,
                'commenter_id' => $userId,
                'commenter_name' => $this->account->name,
                'is_reply' => false,
                'metadata' => [
                    'created_at' => now()->toIso8601String(),
                    'media_ids' => $options['media_ids'] ?? null,
                ],
            ]);
            
            return [
                'success' => true,
                'comment_id' => $socialComment->id,
                'platform_comment_id' => $commentId,
                'post_id' => $socialPost ? $socialPost->id : null,
                'platform_post_id' => $postId,
            ];
        } catch (\Exception $e) {
            throw new CommentException('Failed to post comment to Twitter: ' . $e->getMessage());
        }
    }
    
    /**
     * Reply to an existing comment.
     *
     * @param string $commentId
     * @param string $reply
     * @param array $options
     * @return array
     * @throws \VendorName\SocialConnect\Exceptions\CommentException
     */
    public function replyToComment(string $commentId, string $reply, array $options = []): array
    {
        try {
            $accessToken = $this->account->access_token;
            
            $payload = [
                'text' => $reply,
                'reply' => [
                    'in_reply_to_tweet_id' => $commentId,
                ],
            ];
            
            // Add media if provided
            if (isset($options['media_ids'])) {
                $payload['media'] = [
                    'media_ids' => $options['media_ids'],
                ];
            }
            
            $response = $this->client->post('2/tweets', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['data']['id'])) {
                throw new CommentException('Failed to reply to comment on Twitter.');
            }
            
            $replyId = $data['data']['id'];
            
            // Get the parent comment from database
            $parentComment = SocialComment::where('platform_comment_id', $commentId)
                ->where('social_account_id', $this->account->id)
                ->first();
            
            // Get user details
            $userId = $this->getUserId();
            
            // Store in database
            $socialComment = SocialComment::create([
                'user_id' => $this->account->user_id,
                'social_account_id' => $this->account->id,
                'social_post_id' => $parentComment ? $parentComment->social_post_id : null,
                'platform' => 'twitter',
                'platform_comment_id' => $replyId,
                'platform_post_id' => $parentComment ? $parentComment->platform_post_id : null,
                'comment' => $reply,
                'commenter_id' => $userId,
                'commenter_name' => $this->account->name,
                'parent_id' => $parentComment ? $parentComment->id : null,
                'is_reply' => true,
                'metadata' => [
                    'created_at' => now()->toIso8601String(),
                    'media_ids' => $options['media_ids'] ?? null,
                ],
            ]);
            
            // Update parent comment reply count
            if ($parentComment) {
                $parentComment->increment('reply_count');
            }
            
            return [
                'success' => true,
                'comment_id' => $socialComment->id,
                'platform_comment_id' => $replyId,
                'parent_comment_id' => $parentComment ? $parentComment->id : null,
                'parent_platform_comment_id' => $commentId,
            ];
        } catch (\Exception $e) {
            throw new CommentException('Failed to reply to comment on Twitter: ' . $e->getMessage());
        }
    }
    
    /**
     * Like or react to a comment.
     *
     * @param string $commentId
     * @param string $reactionType
     * @return bool
     * @throws \VendorName\SocialConnect\Exceptions\CommentException
     */
    public function reactToComment(string $commentId, string $reactionType = 'like'): bool
    {
        try {
            $accessToken = $this->account->access_token;
            $userId = $this->getUserId();
            
            $response = $this->client->post("2/users/{$userId}/likes", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'tweet_id' => $commentId,
                ],
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['data']['liked']) || !$data['data']['liked']) {
                throw new CommentException('Failed to like comment on Twitter.');
            }
            
            // Update in database
            $socialComment = SocialComment::where('platform_comment_id', $commentId)
                ->where('social_account_id', $this->account->id)
                ->first();
            
            if ($socialComment) {
                $socialComment->increment('like_count');
            }
            
            return true;
        } catch (\Exception $e) {
            throw new CommentException('Failed to like comment on Twitter: ' . $e->getMessage());
        }
    }
    
    /**
     * Remove a reaction from a comment.
     *
     * @param string $commentId
     * @param string $reactionType
     * @return bool
     * @throws \VendorName\SocialConnect\Exceptions\CommentException
     */
    public function removeCommentReaction(string $commentId, string $reactionType = 'like'): bool
    {
        try {
            $accessToken = $this->account->access_token;
            $userId = $this->getUserId();
            
            $response = $this->client->delete("2/users/{$userId}/likes/{$commentId}", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['data']['liked']) || $data['data']['liked']) {
                throw new CommentException('Failed to unlike comment on Twitter.');
            }
            
            // Update in database
            $socialComment = SocialComment::where('platform_comment_id', $commentId)
                ->where('social_account_id', $this->account->id)
                ->first();
            
            if ($socialComment && $socialComment->like_count > 0) {
                $socialComment->decrement('like_count');
            }
            
            return true;
        } catch (\Exception $e) {
            throw new CommentException('Failed to unlike comment on Twitter: ' . $e->getMessage());
        }
    }
    
    /**
     * Delete a comment.
     *
     * @param string $commentId
     * @return bool
     * @throws \VendorName\SocialConnect\Exceptions\CommentException
     */
    public function deleteComment(string $commentId): bool
    {
        try {
            $accessToken = $this->account->access_token;
            
            $response = $this->client->delete("2/tweets/{$commentId}", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['data']['deleted']) || !$data['data']['deleted']) {
                throw new CommentException('Failed to delete comment on Twitter.');
            }
            
            // Delete from database
            $socialComment = SocialComment::where('platform_comment_id', $commentId)
                ->where('social_account_id', $this->account->id)
                ->first();
            
            if ($socialComment) {
                // If this is a reply, decrement parent's reply count
                if ($socialComment->is_reply && $socialComment->parent_id) {
                    $parentComment = SocialComment::find($socialComment->parent_id);
                    
                    if ($parentComment && $parentComment->reply_count > 0) {
                        $parentComment->decrement('reply_count');
                    }
                }
                
                $socialComment->delete();
            }
            
            return true;
        } catch (\Exception $e) {
            throw new CommentException('Failed to delete comment on Twitter: ' . $e->getMessage());
        }
    }
    
    /**
     * Hide a comment.
     *
     * @param string $commentId
     * @return bool
     * @throws \VendorName\SocialConnect\Exceptions\CommentException
     */
    public function hideComment(string $commentId): bool
    {
        // Twitter doesn't have a direct API for hiding comments without deleting them
        // We'll mark it as hidden in our database but not perform any API action
        try {
            $socialComment = SocialComment::where('platform_comment_id', $commentId)
                ->where('social_account_id', $this->account->id)
                ->first();
            
            if ($socialComment) {
                $socialComment->update([
                    'is_hidden' => true,
                ]);
                
                return true;
            }
            
            return false;
        } catch (\Exception $e) {
            throw new CommentException('Failed to hide comment on Twitter: ' . $e->getMessage());
        }
    }
    
    /**
     * Unhide a comment.
     *
     * @param string $commentId
     * @return bool
     * @throws \VendorName\SocialConnect\Exceptions\CommentException
     */
    public function unhideComment(string $commentId): bool
    {
        // Twitter doesn't have a direct API for unhiding comments
        // We'll mark it as not hidden in our database but not perform any API action
        try {
            $socialComment = SocialComment::where('platform_comment_id', $commentId)
                ->where('social_account_id', $this->account->id)
                ->first();
            
            if ($socialComment) {
                $socialComment->update([
                    'is_hidden' => false,
                ]);
                
                return true;
            }
            
            return false;
        } catch (\Exception $e) {
            throw new CommentException('Failed to unhide comment on Twitter: ' . $e->getMessage());
        }
    }
    
    /**
     * Get the user ID from the account metadata.
     *
     * @return string
     * @throws \VendorName\SocialConnect\Exceptions\CommentException
     */
    protected function getUserId(): string
    {
        $metadata = $this->account->metadata;
        
        if (isset($metadata['id'])) {
            return $metadata['id'];
        }
        
        throw new CommentException('Twitter user ID not found in account metadata.');
    }
}
