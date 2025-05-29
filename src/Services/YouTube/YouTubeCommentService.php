<?php

namespace VendorName\SocialConnect\Services\YouTube;

use GuzzleHttp\Client;
use VendorName\SocialConnect\Contracts\CommentManagementInterface;
use VendorName\SocialConnect\Exceptions\CommentException;
use VendorName\SocialConnect\Models\SocialAccount;
use VendorName\SocialConnect\Models\SocialComment;
use VendorName\SocialConnect\Models\SocialPost;

class YouTubeCommentService implements CommentManagementInterface
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
     * Create a new YouTubeCommentService instance.
     *
     * @param \VendorName\SocialConnect\Models\SocialAccount $account
     */
    public function __construct(SocialAccount $account)
    {
        $this->account = $account;
        $this->client = new Client([
            'base_uri' => 'https://www.googleapis.com/youtube/v3/',
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
                'part' => 'snippet',
                'videoId' => $postId,
                'maxResults' => $limit,
                'textFormat' => 'plainText',
            ];
            
            if ($cursor) {
                $params['pageToken'] = $cursor;
            }
            
            $response = $this->client->get('commentThreads', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
                'query' => $params,
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['items'])) {
                throw new CommentException('Failed to retrieve comments from YouTube.');
            }
            
            // Get the post from database
            $socialPost = SocialPost::where('platform_post_id', $postId)
                ->where('social_account_id', $this->account->id)
                ->first();
            
            $comments = [];
            $nextCursor = $data['nextPageToken'] ?? null;
            
            foreach ($data['items'] as $thread) {
                $commentId = $thread['id'];
                $comment = $thread['snippet']['topLevelComment']['snippet'] ?? [];
                
                $commentText = $comment['textDisplay'] ?? '';
                $commenterId = $comment['authorChannelId']['value'] ?? null;
                $commenterName = $comment['authorDisplayName'] ?? null;
                $commenterAvatar = $comment['authorProfileImageUrl'] ?? null;
                $createdAt = $comment['publishedAt'] ?? null;
                
                // Get metrics
                $likeCount = $comment['likeCount'] ?? 0;
                $replyCount = $thread['snippet']['totalReplyCount'] ?? 0;
                
                // Store in database
                $socialComment = SocialComment::updateOrCreate(
                    [
                        'social_account_id' => $this->account->id,
                        'platform_comment_id' => $commentId,
                    ],
                    [
                        'user_id' => $this->account->user_id,
                        'social_post_id' => $socialPost ? $socialPost->id : null,
                        'platform' => 'youtube',
                        'platform_post_id' => $postId,
                        'comment' => $commentText,
                        'commenter_id' => $commenterId,
                        'commenter_name' => $commenterName,
                        'is_reply' => false,
                        'like_count' => $likeCount,
                        'reply_count' => $replyCount,
                        'metadata' => [
                            'created_at' => $createdAt,
                            'commenter_avatar' => $commenterAvatar,
                            'updated_at' => $comment['updatedAt'] ?? null,
                            'can_reply' => $thread['snippet']['canReply'] ?? false,
                            'is_public' => $thread['snippet']['isPublic'] ?? true,
                        ],
                    ]
                );
                
                $comments[] = [
                    'id' => $socialComment->id,
                    'platform_comment_id' => $commentId,
                    'comment' => $commentText,
                    'commenter_id' => $commenterId,
                    'commenter_name' => $commenterName,
                    'commenter_avatar' => $commenterAvatar,
                    'created_at' => $createdAt,
                    'like_count' => $likeCount,
                    'reply_count' => $replyCount,
                    'can_reply' => $thread['snippet']['canReply'] ?? false,
                    'is_public' => $thread['snippet']['isPublic'] ?? true,
                ];
            }
            
            return [
                'post_id' => $socialPost ? $socialPost->id : null,
                'platform_post_id' => $postId,
                'comments' => $comments,
                'next_cursor' => $nextCursor,
            ];
        } catch (\Exception $e) {
            throw new CommentException('Failed to get comments from YouTube: ' . $e->getMessage());
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
                'part' => 'snippet',
                'parentId' => $commentId,
                'maxResults' => $limit,
                'textFormat' => 'plainText',
            ];
            
            if ($cursor) {
                $params['pageToken'] = $cursor;
            }
            
            $response = $this->client->get('comments', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
                'query' => $params,
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['items'])) {
                throw new CommentException('Failed to retrieve comment replies from YouTube.');
            }
            
            // Get the parent comment from database
            $parentComment = SocialComment::where('platform_comment_id', $commentId)
                ->where('social_account_id', $this->account->id)
                ->first();
            
            $replies = [];
            $nextCursor = $data['nextPageToken'] ?? null;
            
            foreach ($data['items'] as $reply) {
                $replyId = $reply['id'];
                $replySnippet = $reply['snippet'] ?? [];
                
                $replyText = $replySnippet['textDisplay'] ?? '';
                $commenterId = $replySnippet['authorChannelId']['value'] ?? null;
                $commenterName = $replySnippet['authorDisplayName'] ?? null;
                $commenterAvatar = $replySnippet['authorProfileImageUrl'] ?? null;
                $createdAt = $replySnippet['publishedAt'] ?? null;
                
                // Get metrics
                $likeCount = $replySnippet['likeCount'] ?? 0;
                
                // Store in database
                $socialComment = SocialComment::updateOrCreate(
                    [
                        'social_account_id' => $this->account->id,
                        'platform_comment_id' => $replyId,
                    ],
                    [
                        'user_id' => $this->account->user_id,
                        'social_post_id' => $parentComment ? $parentComment->social_post_id : null,
                        'platform' => 'youtube',
                        'platform_post_id' => $parentComment ? $parentComment->platform_post_id : null,
                        'comment' => $replyText,
                        'commenter_id' => $commenterId,
                        'commenter_name' => $commenterName,
                        'parent_id' => $parentComment ? $parentComment->id : null,
                        'is_reply' => true,
                        'like_count' => $likeCount,
                        'metadata' => [
                            'created_at' => $createdAt,
                            'commenter_avatar' => $commenterAvatar,
                            'updated_at' => $replySnippet['updatedAt'] ?? null,
                        ],
                    ]
                );
                
                $replies[] = [
                    'id' => $socialComment->id,
                    'platform_comment_id' => $replyId,
                    'comment' => $replyText,
                    'commenter_id' => $commenterId,
                    'commenter_name' => $commenterName,
                    'commenter_avatar' => $commenterAvatar,
                    'created_at' => $createdAt,
                    'like_count' => $likeCount,
                ];
            }
            
            return [
                'parent_comment_id' => $parentComment ? $parentComment->id : null,
                'platform_comment_id' => $commentId,
                'replies' => $replies,
                'next_cursor' => $nextCursor,
            ];
        } catch (\Exception $e) {
            throw new CommentException('Failed to get comment replies from YouTube: ' . $e->getMessage());
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
                'snippet' => [
                    'videoId' => $postId,
                    'topLevelComment' => [
                        'snippet' => [
                            'textOriginal' => $comment,
                        ],
                    ],
                ],
            ];
            
            $response = $this->client->post('commentThreads', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'query' => [
                    'part' => 'snippet',
                ],
                'json' => $payload,
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['id'])) {
                throw new CommentException('Failed to post comment to YouTube.');
            }
            
            $commentId = $data['id'];
            $commentSnippet = $data['snippet']['topLevelComment']['snippet'] ?? [];
            
            // Get the post from database
            $socialPost = SocialPost::where('platform_post_id', $postId)
                ->where('social_account_id', $this->account->id)
                ->first();
            
            // Store in database
            $socialComment = SocialComment::create([
                'user_id' => $this->account->user_id,
                'social_account_id' => $this->account->id,
                'social_post_id' => $socialPost ? $socialPost->id : null,
                'platform' => 'youtube',
                'platform_comment_id' => $commentId,
                'platform_post_id' => $postId,
                'comment' => $comment,
                'commenter_id' => $this->getChannelId(),
                'commenter_name' => $this->account->name,
                'is_reply' => false,
                'like_count' => 0,
                'reply_count' => 0,
                'metadata' => [
                    'created_at' => $commentSnippet['publishedAt'] ?? now()->toIso8601String(),
                    'updated_at' => $commentSnippet['updatedAt'] ?? now()->toIso8601String(),
                    'can_reply' => $data['snippet']['canReply'] ?? true,
                    'is_public' => $data['snippet']['isPublic'] ?? true,
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
            throw new CommentException('Failed to post comment to YouTube: ' . $e->getMessage());
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
                'snippet' => [
                    'parentId' => $commentId,
                    'textOriginal' => $reply,
                ],
            ];
            
            $response = $this->client->post('comments', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'query' => [
                    'part' => 'snippet',
                ],
                'json' => $payload,
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['id'])) {
                throw new CommentException('Failed to reply to comment on YouTube.');
            }
            
            $replyId = $data['id'];
            $replySnippet = $data['snippet'] ?? [];
            
            // Get the parent comment from database
            $parentComment = SocialComment::where('platform_comment_id', $commentId)
                ->where('social_account_id', $this->account->id)
                ->first();
            
            // Store in database
            $socialComment = SocialComment::create([
                'user_id' => $this->account->user_id,
                'social_account_id' => $this->account->id,
                'social_post_id' => $parentComment ? $parentComment->social_post_id : null,
                'platform' => 'youtube',
                'platform_comment_id' => $replyId,
                'platform_post_id' => $parentComment ? $parentComment->platform_post_id : null,
                'comment' => $reply,
                'commenter_id' => $this->getChannelId(),
                'commenter_name' => $this->account->name,
                'parent_id' => $parentComment ? $parentComment->id : null,
                'is_reply' => true,
                'like_count' => 0,
                'metadata' => [
                    'created_at' => $replySnippet['publishedAt'] ?? now()->toIso8601String(),
                    'updated_at' => $replySnippet['updatedAt'] ?? now()->toIso8601String(),
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
            throw new CommentException('Failed to reply to comment on YouTube: ' . $e->getMessage());
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
            
            // YouTube API doesn't provide a way to like comments programmatically
            // We'll just update our local database
            
            $socialComment = SocialComment::where('platform_comment_id', $commentId)
                ->where('social_account_id', $this->account->id)
                ->first();
            
            if ($socialComment) {
                $socialComment->increment('like_count');
                
                return true;
            }
            
            return false;
        } catch (\Exception $e) {
            throw new CommentException('Failed to like comment on YouTube: ' . $e->getMessage());
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
            
            // YouTube API doesn't provide a way to unlike comments programmatically
            // We'll just update our local database
            
            $socialComment = SocialComment::where('platform_comment_id', $commentId)
                ->where('social_account_id', $this->account->id)
                ->first();
            
            if ($socialComment && $socialComment->like_count > 0) {
                $socialComment->decrement('like_count');
                
                return true;
            }
            
            return false;
        } catch (\Exception $e) {
            throw new CommentException('Failed to unlike comment on YouTube: ' . $e->getMessage());
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
            
            // Determine if this is a comment or a reply
            $isReply = false;
            $socialComment = SocialComment::where('platform_comment_id', $commentId)
                ->where('social_account_id', $this->account->id)
                ->first();
            
            if ($socialComment && $socialComment->is_reply) {
                $isReply = true;
            }
            
            // Use the appropriate endpoint based on whether it's a comment or reply
            $endpoint = $isReply ? 'comments' : 'commentThreads';
            
            $response = $this->client->delete($endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
                'query' => [
                    'id' => $commentId,
                ],
            ]);
            
            // Delete from database
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
            throw new CommentException('Failed to delete comment on YouTube: ' . $e->getMessage());
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
        try {
            $accessToken = $this->account->access_token;
            
            // YouTube API doesn't provide a direct way to hide comments without deleting them
            // We'll use the setModerationStatus endpoint to set the comment as rejected
            
            $response = $this->client->post('comments/setModerationStatus', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
                'query' => [
                    'id' => $commentId,
                    'moderationStatus' => 'rejected',
                ],
            ]);
            
            // Update in database
            $socialComment = SocialComment::where('platform_comment_id', $commentId)
                ->where('social_account_id', $this->account->id)
                ->first();
            
            if ($socialComment) {
                $socialComment->update([
                    'is_hidden' => true,
                ]);
            }
            
            return true;
        } catch (\Exception $e) {
            throw new CommentException('Failed to hide comment on YouTube: ' . $e->getMessage());
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
        try {
            $accessToken = $this->account->access_token;
            
            // YouTube API doesn't provide a direct way to unhide comments
            // We'll use the setModerationStatus endpoint to set the comment as approved
            
            $response = $this->client->post('comments/setModerationStatus', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
                'query' => [
                    'id' => $commentId,
                    'moderationStatus' => 'published',
                ],
            ]);
            
            // Update in database
            $socialComment = SocialComment::where('platform_comment_id', $commentId)
                ->where('social_account_id', $this->account->id)
                ->first();
            
            if ($socialComment) {
                $socialComment->update([
                    'is_hidden' => false,
                ]);
            }
            
            return true;
        } catch (\Exception $e) {
            throw new CommentException('Failed to unhide comment on YouTube: ' . $e->getMessage());
        }
    }
    
    /**
     * Get the channel ID from the account metadata.
     *
     * @return string
     * @throws \VendorName\SocialConnect\Exceptions\CommentException
     */
    protected function getChannelId(): string
    {
        $metadata = $this->account->metadata;
        
        if (isset($metadata['channel_id'])) {
            return $metadata['channel_id'];
        }
        
        throw new CommentException('YouTube channel ID not found in account metadata.');
    }
}
