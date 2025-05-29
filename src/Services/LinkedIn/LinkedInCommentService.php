<?php

namespace VendorName\SocialConnect\Services\LinkedIn;

use GuzzleHttp\Client;
use VendorName\SocialConnect\Contracts\CommentManagementInterface;
use VendorName\SocialConnect\Exceptions\CommentException;
use VendorName\SocialConnect\Models\SocialAccount;
use VendorName\SocialConnect\Models\SocialComment;
use VendorName\SocialConnect\Models\SocialPost;

class LinkedInCommentService implements CommentManagementInterface
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
     * Create a new LinkedInCommentService instance.
     *
     * @param \VendorName\SocialConnect\Models\SocialAccount $account
     */
    public function __construct(SocialAccount $account)
    {
        $this->account = $account;
        $this->client = new Client([
            'base_uri' => 'https://api.linkedin.com/v2/',
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
                'count' => $limit,
                'q' => 'comments',
            ];
            
            if ($cursor) {
                $params['start'] = $cursor;
            }
            
            $response = $this->client->get('socialActions/' . $postId . '/comments', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'X-Restli-Protocol-Version' => '2.0.0',
                ],
                'query' => $params,
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['elements'])) {
                throw new CommentException('Failed to retrieve comments from LinkedIn.');
            }
            
            // Get the post from database
            $socialPost = SocialPost::where('platform_post_id', $postId)
                ->where('social_account_id', $this->account->id)
                ->first();
            
            $comments = [];
            $nextCursor = null;
            
            // Check if there are more comments
            if (isset($data['paging']) && isset($data['paging']['count']) && isset($data['paging']['start']) && count($data['elements']) >= $data['paging']['count']) {
                $nextCursor = $data['paging']['start'] + $data['paging']['count'];
            }
            
            foreach ($data['elements'] as $comment) {
                $commentId = $comment['$URN'] ?? null;
                if (!$commentId) {
                    continue;
                }
                
                // Extract the comment ID from the URN
                $commentId = str_replace('urn:li:comment:', '', $commentId);
                
                $commentText = $comment['message']['text'] ?? '';
                $commenterId = null;
                if (isset($comment['actor'])) {
                    $commenterId = str_replace('urn:li:person:', '', $comment['actor']);
                }
                
                $createdAt = $comment['created']['time'] ?? null;
                
                // Get commenter details
                $commenterName = null;
                $commenterAvatar = null;
                
                if ($commenterId) {
                    try {
                        $profileResponse = $this->client->get('people/' . $commenterId, [
                            'headers' => [
                                'Authorization' => 'Bearer ' . $accessToken,
                            ],
                            'query' => [
                                'projection' => '(id,firstName,lastName,profilePicture)',
                            ],
                        ]);
                        
                        $profileData = json_decode($profileResponse->getBody()->getContents(), true);
                        
                        $firstName = $profileData['firstName']['localized']['en_US'] ?? '';
                        $lastName = $profileData['lastName']['localized']['en_US'] ?? '';
                        $commenterName = trim($firstName . ' ' . $lastName);
                        
                        if (isset($profileData['profilePicture']['displayImage~']['elements'][0]['identifiers'][0]['identifier'])) {
                            $commenterAvatar = $profileData['profilePicture']['displayImage~']['elements'][0]['identifiers'][0]['identifier'];
                        }
                    } catch (\Exception $e) {
                        // Ignore profile fetch errors
                    }
                }
                
                // Get metrics
                $likeCount = 0;
                if (isset($comment['likesSummary']['totalLikes'])) {
                    $likeCount = $comment['likesSummary']['totalLikes'];
                }
                
                $replyCount = 0;
                if (isset($comment['commentsSummary']['totalFirstLevelComments'])) {
                    $replyCount = $comment['commentsSummary']['totalFirstLevelComments'];
                }
                
                // Store in database
                $socialComment = SocialComment::updateOrCreate(
                    [
                        'social_account_id' => $this->account->id,
                        'platform_comment_id' => $commentId,
                    ],
                    [
                        'user_id' => $this->account->user_id,
                        'social_post_id' => $socialPost ? $socialPost->id : null,
                        'platform' => 'linkedin',
                        'platform_post_id' => $postId,
                        'comment' => $commentText,
                        'commenter_id' => $commenterId,
                        'commenter_name' => $commenterName,
                        'is_reply' => false,
                        'like_count' => $likeCount,
                        'reply_count' => $replyCount,
                        'metadata' => [
                            'created_at' => $createdAt ? date('c', $createdAt / 1000) : null,
                            'commenter_avatar' => $commenterAvatar,
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
                    'created_at' => $createdAt ? date('c', $createdAt / 1000) : null,
                    'like_count' => $likeCount,
                    'reply_count' => $replyCount,
                ];
            }
            
            return [
                'post_id' => $socialPost ? $socialPost->id : null,
                'platform_post_id' => $postId,
                'comments' => $comments,
                'next_cursor' => $nextCursor,
            ];
        } catch (\Exception $e) {
            throw new CommentException('Failed to get comments from LinkedIn: ' . $e->getMessage());
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
                'count' => $limit,
                'q' => 'comments',
            ];
            
            if ($cursor) {
                $params['start'] = $cursor;
            }
            
            $response = $this->client->get('socialActions/urn:li:comment:' . $commentId . '/comments', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'X-Restli-Protocol-Version' => '2.0.0',
                ],
                'query' => $params,
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['elements'])) {
                throw new CommentException('Failed to retrieve comment replies from LinkedIn.');
            }
            
            // Get the parent comment from database
            $parentComment = SocialComment::where('platform_comment_id', $commentId)
                ->where('social_account_id', $this->account->id)
                ->first();
            
            $replies = [];
            $nextCursor = null;
            
            // Check if there are more replies
            if (isset($data['paging']) && isset($data['paging']['count']) && isset($data['paging']['start']) && count($data['elements']) >= $data['paging']['count']) {
                $nextCursor = $data['paging']['start'] + $data['paging']['count'];
            }
            
            foreach ($data['elements'] as $reply) {
                $replyId = $reply['$URN'] ?? null;
                if (!$replyId) {
                    continue;
                }
                
                // Extract the reply ID from the URN
                $replyId = str_replace('urn:li:comment:', '', $replyId);
                
                $replyText = $reply['message']['text'] ?? '';
                $commenterId = null;
                if (isset($reply['actor'])) {
                    $commenterId = str_replace('urn:li:person:', '', $reply['actor']);
                }
                
                $createdAt = $reply['created']['time'] ?? null;
                
                // Get commenter details
                $commenterName = null;
                $commenterAvatar = null;
                
                if ($commenterId) {
                    try {
                        $profileResponse = $this->client->get('people/' . $commenterId, [
                            'headers' => [
                                'Authorization' => 'Bearer ' . $accessToken,
                            ],
                            'query' => [
                                'projection' => '(id,firstName,lastName,profilePicture)',
                            ],
                        ]);
                        
                        $profileData = json_decode($profileResponse->getBody()->getContents(), true);
                        
                        $firstName = $profileData['firstName']['localized']['en_US'] ?? '';
                        $lastName = $profileData['lastName']['localized']['en_US'] ?? '';
                        $commenterName = trim($firstName . ' ' . $lastName);
                        
                        if (isset($profileData['profilePicture']['displayImage~']['elements'][0]['identifiers'][0]['identifier'])) {
                            $commenterAvatar = $profileData['profilePicture']['displayImage~']['elements'][0]['identifiers'][0]['identifier'];
                        }
                    } catch (\Exception $e) {
                        // Ignore profile fetch errors
                    }
                }
                
                // Get metrics
                $likeCount = 0;
                if (isset($reply['likesSummary']['totalLikes'])) {
                    $likeCount = $reply['likesSummary']['totalLikes'];
                }
                
                // Store in database
                $socialComment = SocialComment::updateOrCreate(
                    [
                        'social_account_id' => $this->account->id,
                        'platform_comment_id' => $replyId,
                    ],
                    [
                        'user_id' => $this->account->user_id,
                        'social_post_id' => $parentComment ? $parentComment->social_post_id : null,
                        'platform' => 'linkedin',
                        'platform_post_id' => $parentComment ? $parentComment->platform_post_id : null,
                        'comment' => $replyText,
                        'commenter_id' => $commenterId,
                        'commenter_name' => $commenterName,
                        'parent_id' => $parentComment ? $parentComment->id : null,
                        'is_reply' => true,
                        'like_count' => $likeCount,
                        'metadata' => [
                            'created_at' => $createdAt ? date('c', $createdAt / 1000) : null,
                            'commenter_avatar' => $commenterAvatar,
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
                    'created_at' => $createdAt ? date('c', $createdAt / 1000) : null,
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
            throw new CommentException('Failed to get comment replies from LinkedIn: ' . $e->getMessage());
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
                'actor' => 'urn:li:person:' . $this->getUserId(),
                'message' => [
                    'text' => $comment,
                ],
            ];
            
            $response = $this->client->post('socialActions/' . $postId . '/comments', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'X-Restli-Protocol-Version' => '2.0.0',
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['$URN'])) {
                throw new CommentException('Failed to post comment to LinkedIn.');
            }
            
            $commentId = str_replace('urn:li:comment:', '', $data['$URN']);
            
            // Get the post from database
            $socialPost = SocialPost::where('platform_post_id', $postId)
                ->where('social_account_id', $this->account->id)
                ->first();
            
            // Store in database
            $socialComment = SocialComment::create([
                'user_id' => $this->account->user_id,
                'social_account_id' => $this->account->id,
                'social_post_id' => $socialPost ? $socialPost->id : null,
                'platform' => 'linkedin',
                'platform_comment_id' => $commentId,
                'platform_post_id' => $postId,
                'comment' => $comment,
                'commenter_id' => $this->getUserId(),
                'commenter_name' => $this->account->name,
                'is_reply' => false,
                'metadata' => [
                    'created_at' => now()->toIso8601String(),
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
            throw new CommentException('Failed to post comment to LinkedIn: ' . $e->getMessage());
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
                'actor' => 'urn:li:person:' . $this->getUserId(),
                'message' => [
                    'text' => $reply,
                ],
            ];
            
            $response = $this->client->post('socialActions/urn:li:comment:' . $commentId . '/comments', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'X-Restli-Protocol-Version' => '2.0.0',
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['$URN'])) {
                throw new CommentException('Failed to reply to comment on LinkedIn.');
            }
            
            $replyId = str_replace('urn:li:comment:', '', $data['$URN']);
            
            // Get the parent comment from database
            $parentComment = SocialComment::where('platform_comment_id', $commentId)
                ->where('social_account_id', $this->account->id)
                ->first();
            
            // Store in database
            $socialComment = SocialComment::create([
                'user_id' => $this->account->user_id,
                'social_account_id' => $this->account->id,
                'social_post_id' => $parentComment ? $parentComment->social_post_id : null,
                'platform' => 'linkedin',
                'platform_comment_id' => $replyId,
                'platform_post_id' => $parentComment ? $parentComment->platform_post_id : null,
                'comment' => $reply,
                'commenter_id' => $this->getUserId(),
                'commenter_name' => $this->account->name,
                'parent_id' => $parentComment ? $parentComment->id : null,
                'is_reply' => true,
                'metadata' => [
                    'created_at' => now()->toIso8601String(),
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
            throw new CommentException('Failed to reply to comment on LinkedIn: ' . $e->getMessage());
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
            
            $payload = [
                'actor' => 'urn:li:person:' . $this->getUserId(),
                'object' => 'urn:li:comment:' . $commentId,
            ];
            
            $response = $this->client->post('reactions?action=create', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'X-Restli-Protocol-Version' => '2.0.0',
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);
            
            // Update in database
            $socialComment = SocialComment::where('platform_comment_id', $commentId)
                ->where('social_account_id', $this->account->id)
                ->first();
            
            if ($socialComment) {
                $socialComment->increment('like_count');
            }
            
            return true;
        } catch (\Exception $e) {
            throw new CommentException('Failed to like comment on LinkedIn: ' . $e->getMessage());
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
            
            $payload = [
                'actor' => 'urn:li:person:' . $this->getUserId(),
                'object' => 'urn:li:comment:' . $commentId,
            ];
            
            $response = $this->client->post('reactions?action=delete', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'X-Restli-Protocol-Version' => '2.0.0',
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);
            
            // Update in database
            $socialComment = SocialComment::where('platform_comment_id', $commentId)
                ->where('social_account_id', $this->account->id)
                ->first();
            
            if ($socialComment && $socialComment->like_count > 0) {
                $socialComment->decrement('like_count');
            }
            
            return true;
        } catch (\Exception $e) {
            throw new CommentException('Failed to unlike comment on LinkedIn: ' . $e->getMessage());
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
            
            $response = $this->client->delete('socialActions/comments/urn:li:comment:' . $commentId, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'X-Restli-Protocol-Version' => '2.0.0',
                ],
            ]);
            
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
            throw new CommentException('Failed to delete comment on LinkedIn: ' . $e->getMessage());
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
        // LinkedIn doesn't have a direct API for hiding comments without deleting them
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
            throw new CommentException('Failed to hide comment on LinkedIn: ' . $e->getMessage());
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
        // LinkedIn doesn't have a direct API for unhiding comments
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
            throw new CommentException('Failed to unhide comment on LinkedIn: ' . $e->getMessage());
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
        
        throw new CommentException('LinkedIn user ID not found in account metadata.');
    }
}
