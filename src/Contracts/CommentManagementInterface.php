<?php

namespace VendorName\SocialConnect\Contracts;

interface CommentManagementInterface
{
    /**
     * Get comments for a specific post.
     *
     * @param string $postId
     * @param int $limit
     * @param string|null $cursor
     * @return array
     */
    public function getComments(string $postId, int $limit = 20, ?string $cursor = null): array;
    
    /**
     * Get replies to a specific comment.
     *
     * @param string $commentId
     * @param int $limit
     * @param string|null $cursor
     * @return array
     */
    public function getCommentReplies(string $commentId, int $limit = 20, ?string $cursor = null): array;
    
    /**
     * Post a new comment on a post.
     *
     * @param string $postId
     * @param string $comment
     * @param array $options
     * @return array
     */
    public function postComment(string $postId, string $comment, array $options = []): array;
    
    /**
     * Reply to an existing comment.
     *
     * @param string $commentId
     * @param string $reply
     * @param array $options
     * @return array
     */
    public function replyToComment(string $commentId, string $reply, array $options = []): array;
    
    /**
     * Like or react to a comment.
     *
     * @param string $commentId
     * @param string $reactionType
     * @return bool
     */
    public function reactToComment(string $commentId, string $reactionType = 'like'): bool;
    
    /**
     * Remove a reaction from a comment.
     *
     * @param string $commentId
     * @param string $reactionType
     * @return bool
     */
    public function removeCommentReaction(string $commentId, string $reactionType = 'like'): bool;
    
    /**
     * Delete a comment.
     *
     * @param string $commentId
     * @return bool
     */
    public function deleteComment(string $commentId): bool;
    
    /**
     * Hide a comment.
     *
     * @param string $commentId
     * @return bool
     */
    public function hideComment(string $commentId): bool;
    
    /**
     * Unhide a comment.
     *
     * @param string $commentId
     * @return bool
     */
    public function unhideComment(string $commentId): bool;
}
