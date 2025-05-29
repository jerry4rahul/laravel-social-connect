<?php

namespace VendorName\SocialConnect\Services\Facebook;

use GuzzleHttp\Client;
use VendorName\SocialConnect\Contracts\CommentManagementInterface;
use VendorName\SocialConnect\Exceptions\CommentException;
use VendorName\SocialConnect\Models\SocialAccount;
use VendorName\SocialConnect\Models\SocialComment;
use VendorName\SocialConnect\Models\SocialPost;

class FacebookCommentService implements CommentManagementInterface
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
     * Create a new FacebookCommentService instance.
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
                'fields' => 'id,message,from,created_time,like_count,comment_count,attachment',
                'limit' => $limit,
            ];
            
            if ($cursor) {
                $params['after'] = $cursor;
            }
            
            $response = $this->client->get("{$postId}/comments", [
                'query' => array_merge($params, ['access_token' => $accessToken]),
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['data'])) {
                throw new CommentException('Failed to retrieve comments from Facebook.');
            }
            
            // Get the post from database
            $socialPost = SocialPost::where('platform_post_id', $postId)
                ->where('social_account_id', $this->account->id)
                ->first();
            
            $comments = [];
            $nextCursor = $data['paging']['cursors']['after'] ?? null;
            
            foreach ($data['data'] as $comment) {
                $commenterId = $comment['from']['id'] ?? null;
                $commenterName = $comment['from']['name'] ?? null;
                
                // Store in database
                $socialComment = SocialComment::updateOrCreate(
                    [
                        'social_account_id' => $this->account->id,
                        'platform_comment_id' => $comment['id'],
                    ],
                    [
                        'user_id' => $this->account->user_id,
                        'social_post_id' => $socialPost ? $socialPost->id : null,
                        'platform' => 'facebook',
                        'platform_post_id' => $postId,
                        'comment' => $comment['message'] ?? '',
                        'commenter_id' => $commenterId,
                        'commenter_name' => $commenterName,
                        'is_reply' => false,
                        'like_count' => $comment['like_count'] ?? 0,
                        'reply_count' => $comment['comment_count'] ?? 0,
                        'metadata' => [
                            'created_time' => $comment['created_time'] ?? null,
                            'attachment' => $comment['attachment'] ?? null,
                        ],
                    ]
                );
                
                $comments[] = [
                    'id' => $socialComment->id,
                    'platform_comment_id' => $comment['id'],
                    'comment' => $comment['message'] ?? '',
                    'commenter_id' => $commenterId,
                    'commenter_name' => $commenterName,
                    'created_at' => $comment['created_time'] ?? null,
                    'like_count' => $comment['like_count'] ?? 0,
                    'reply_count' => $comment['comment_count'] ?? 0,
                    'attachment' => $comment['attachment'] ?? null,
                ];
            }
            
            return [
                'post_id' => $socialPost ? $socialPost->id : null,
                'platform_post_id' => $postId,
                'comments' => $comments,
                'next_cursor' => $nextCursor,
            ];
        } catch (\Exception $e) {
            throw new CommentException('Failed to get comments from Facebook: ' . $e->getMessage());
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
                'fields' => 'id,message,from,created_time,like_count,attachment',
                'limit' => $limit,
            ];
            
            if ($cursor) {
                $params['after'] = $cursor;
            }
            
            $response = $this->client->get("{$commentId}/comments", [
                'query' => array_merge($params, ['access_token' => $accessToken]),
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['data'])) {
                throw new CommentException('Failed to retrieve comment replies from Facebook.');
            }
            
            // Get the parent comment from database
            $parentComment = SocialComment::where('platform_comment_id', $commentId)
                ->where('social_account_id', $this->account->id)
                ->first();
            
            $replies = [];
            $nextCursor = $data['paging']['cursors']['after'] ?? null;
            
            foreach ($data['data'] as $reply) {
                $commenterId = $reply['from']['id'] ?? null;
                $commenterName = $reply['from']['name'] ?? null;
                
                // Store in database
                $socialComment = SocialComment::updateOrCreate(
                    [
                        'social_account_id' => $this->account->id,
                        'platform_comment_id' => $reply['id'],
                    ],
                    [
                        'user_id' => $this->account->user_id,
                        'social_post_id' => $parentComment ? $parentComment->social_post_id : null,
                        'platform' => 'facebook',
                        'platform_post_id' => $parentComment ? $parentComment->platform_post_id : null,
                        'comment' => $reply['message'] ?? '',
                        'commenter_id' => $commenterId,
                        'commenter_name' => $commenterName,
                        'parent_id' => $parentComment ? $parentComment->id : null,
                        'is_reply' => true,
                        'like_count' => $reply['like_count'] ?? 0,
                        'metadata' => [
                            'created_time' => $reply['created_time'] ?? null,
                            'attachment' => $reply['attachment'] ?? null,
                        ],
                    ]
                );
                
                $replies[] = [
                    'id' => $socialComment->id,
                    'platform_comment_id' => $reply['id'],
                    'comment' => $reply['message'] ?? '',
                    'commenter_id' => $commenterId,
                    'commenter_name' => $commenterName,
                    'created_at' => $reply['created_time'] ?? null,
                    'like_count' => $reply['like_count'] ?? 0,
                    'attachment' => $reply['attachment'] ?? null,
                ];
            }
            
            return [
                'parent_comment_id' => $parentComment ? $parentComment->id : null,
                'platform_comment_id' => $commentId,
                'replies' => $replies,
                'next_cursor' => $nextCursor,
            ];
        } catch (\Exception $e) {
            throw new CommentException('Failed to get comment replies from Facebook: ' . $e->getMessage());
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
            
            $params = [
                'message' => $comment,
                'access_token' => $accessToken,
            ];
            
            // Add attachment if provided
            if (isset($options['attachment_url'])) {
                $params['attachment_url'] = $options['attachment_url'];
            }
            
            $response = $this->client->post("{$postId}/comments", [
                'form_params' => $params,
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['id'])) {
                throw new CommentException('Failed to post comment to Facebook.');
            }
            
            // Get the post from database
            $socialPost = SocialPost::where('platform_post_id', $postId)
                ->where('social_account_id', $this->account->id)
                ->first();
            
            // Get page details
            $pageId = $this->getPageId();
            
            // Store in database
            $socialComment = SocialComment::create([
                'user_id' => $this->account->user_id,
                'social_account_id' => $this->account->id,
                'social_post_id' => $socialPost ? $socialPost->id : null,
                'platform' => 'facebook',
                'platform_comment_id' => $data['id'],
                'platform_post_id' => $postId,
                'comment' => $comment,
                'commenter_id' => $pageId,
                'commenter_name' => $this->account->name,
                'is_reply' => false,
                'metadata' => [
                    'created_time' => now()->toIso8601String(),
                    'attachment' => isset($options['attachment_url']) ? ['url' => $options['attachment_url']] : null,
                ],
            ]);
            
            return [
                'success' => true,
                'comment_id' => $socialComment->id,
                'platform_comment_id' => $data['id'],
                'post_id' => $socialPost ? $socialPost->id : null,
                'platform_post_id' => $postId,
            ];
        } catch (\Exception $e) {
            throw new CommentException('Failed to post comment to Facebook: ' . $e->getMessage());
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
            
            $params = [
                'message' => $reply,
                'access_token' => $accessToken,
            ];
            
            // Add attachment if provided
            if (isset($options['attachment_url'])) {
                $params['attachment_url'] = $options['attachment_url'];
            }
            
            $response = $this->client->post("{$commentId}/comments", [
                'form_params' => $params,
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['id'])) {
                throw new CommentException('Failed to reply to comment on Facebook.');
            }
            
            // Get the parent comment from database
            $parentComment = SocialComment::where('platform_comment_id', $commentId)
                ->where('social_account_id', $this->account->id)
                ->first();
            
            // Get page details
            $pageId = $this->getPageId();
            
            // Store in database
            $socialComment = SocialComment::create([
                'user_id' => $this->account->user_id,
                'social_account_id' => $this->account->id,
                'social_post_id' => $parentComment ? $parentComment->social_post_id : null,
                'platform' => 'facebook',
                'platform_comment_id' => $data['id'],
                'platform_post_id' => $parentComment ? $parentComment->platform_post_id : null,
                'comment' => $reply,
                'commenter_id' => $pageId,
                'commenter_name' => $this->account->name,
                'parent_id' => $parentComment ? $parentComment->id : null,
                'is_reply' => true,
                'metadata' => [
                    'created_time' => now()->toIso8601String(),
                    'attachment' => isset($options['attachment_url']) ? ['url' => $options['attachment_url']] : null,
                ],
            ]);
            
            // Update parent comment reply count
            if ($parentComment) {
                $parentComment->increment('reply_count');
            }
            
            return [
                'success' => true,
                'comment_id' => $socialComment->id,
                'platform_comment_id' => $data['id'],
                'parent_comment_id' => $parentComment ? $parentComment->id : null,
                'parent_platform_comment_id' => $commentId,
            ];
        } catch (\Exception $e) {
            throw new CommentException('Failed to reply to comment on Facebook: ' . $e->getMessage());
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
            
            $response = $this->client->post("{$commentId}/likes", [
                'query' => ['access_token' => $accessToken],
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['success']) || !$data['success']) {
                throw new CommentException('Failed to like comment on Facebook.');
            }
            
            // Update in database
            $socialComment = SocialComment::where('platform_comment_id', $commentId)
                ->where('social_account_id', $this->account->id)
                ->first();
            
            if ($socialComment) {
                $socialComment->increment('like_count');
                
                // Update reactions in metadata
                $metadata = $socialComment->metadata ?? [];
                $reactions = $metadata['reactions'] ?? [];
                $reactions[$reactionType] = ($reactions[$reactionType] ?? 0) + 1;
                $metadata['reactions'] = $reactions;
                
                $socialComment->update([
                    'metadata' => $metadata,
                ]);
            }
            
            return true;
        } catch (\Exception $e) {
            throw new CommentException('Failed to like comment on Facebook: ' . $e->getMessage());
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
            
            $response = $this->client->delete("{$commentId}/likes", [
                'query' => ['access_token' => $accessToken],
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['success']) || !$data['success']) {
                throw new CommentException('Failed to unlike comment on Facebook.');
            }
            
            // Update in database
            $socialComment = SocialComment::where('platform_comment_id', $commentId)
                ->where('social_account_id', $this->account->id)
                ->first();
            
            if ($socialComment && $socialComment->like_count > 0) {
                $socialComment->decrement('like_count');
                
                // Update reactions in metadata
                $metadata = $socialComment->metadata ?? [];
                $reactions = $metadata['reactions'] ?? [];
                
                if (isset($reactions[$reactionType]) && $reactions[$reactionType] > 0) {
                    $reactions[$reactionType]--;
                    $metadata['reactions'] = $reactions;
                    
                    $socialComment->update([
                        'metadata' => $metadata,
                    ]);
                }
            }
            
            return true;
        } catch (\Exception $e) {
            throw new CommentException('Failed to unlike comment on Facebook: ' . $e->getMessage());
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
            
            $response = $this->client->delete($commentId, [
                'query' => ['access_token' => $accessToken],
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['success']) || !$data['success']) {
                throw new CommentException('Failed to delete comment on Facebook.');
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
            throw new CommentException('Failed to delete comment on Facebook: ' . $e->getMessage());
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
            
            $response = $this->client->post("{$commentId}/hide", [
                'query' => [
                    'access_token' => $accessToken,
                    'hide' => true,
                ],
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['success']) || !$data['success']) {
                throw new CommentException('Failed to hide comment on Facebook.');
            }
            
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
            throw new CommentException('Failed to hide comment on Facebook: ' . $e->getMessage());
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
            
            $response = $this->client->post("{$commentId}/hide", [
                'query' => [
                    'access_token' => $accessToken,
                    'hide' => false,
                ],
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['success']) || !$data['success']) {
                throw new CommentException('Failed to unhide comment on Facebook.');
            }
            
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
            throw new CommentException('Failed to unhide comment on Facebook: ' . $e->getMessage());
        }
    }
    
    /**
     * Get the page ID from the account metadata.
     *
     * @return string
     * @throws \VendorName\SocialConnect\Exceptions\CommentException
     */
    protected function getPageId(): string
    {
        $metadata = $this->account->metadata;
        
        if (isset($metadata['page_id'])) {
            return $metadata['page_id'];
        }
        
        throw new CommentException('Facebook page ID not found in account metadata.');
    }
}
