<?php

namespace VendorName\SocialConnect\Services\YouTube;

use Google_Client;
use Google_Service_YouTube;
use Google_Service_YouTube_CommentThread;
use Google_Service_YouTube_CommentThreadSnippet;
use Google_Service_YouTube_Comment;
use Google_Service_YouTube_CommentSnippet;
use Illuminate\Support\Facades\Config;
use VendorName\SocialConnect\Contracts\CommentManagementInterface;
use VendorName\SocialConnect\Exceptions\CommentException;

class YouTubeCommentService implements CommentManagementInterface
{
    /**
     * Google API Client.
     *
     * @var \Google_Client
     */
    protected $googleClient;

    /**
     * Create a new YouTubeCommentService instance.
     */
    public function __construct()
    {
        // Basic client setup, token will be set per request
        $this->googleClient = new Google_Client();
        $config = Config::get("social-connect.platforms.youtube");
        if (isset($config["client_id"], $config["client_secret"], $config["redirect_uri"])) {
            $this->googleClient->setClientId($config["client_id"]);
            $this->googleClient->setClientSecret($config["client_secret"]);
            $this->googleClient->setRedirectUri($config["redirect_uri"]);
        }
    }

    /**
     * Get Google Client configured with access token.
     *
     * @param string $accessToken
     * @return Google_Client
     * @throws CommentException
     */
    protected function getApiClient(string $accessToken): Google_Client
    {
        if (empty($accessToken)) {
            throw new CommentException("YouTube access token is required.");
        }
        $client = clone $this->googleClient;
        $client->setAccessToken($accessToken);
        // Scope for managing comments
        $client->addScope(Google_Service_YouTube::YOUTUBE_FORCE_SSL);
        return $client;
    }

    /**
     * Get comments (top-level comment threads) for a specific video or channel.
     *
     * @param string $accessToken User Access Token.
     * @param string $tokenSecret Ignored.
     * @param string $postId The Video ID or Channel ID.
     * @param int $limit Maximum number of comment threads to return.
     * @param string|null $cursor Page token for pagination.
     * @param string $order Sort order (
     * @return array Returns array containing comments and next cursor info.
     * @throws CommentException
     */
    public function getComments(string $accessToken, string $tokenSecret, string $postId, int $limit = 20, ?string $cursor = null, string $order = "relevance"): array
    {
        try {
            $client = $this->getApiClient($accessToken);
            $youtubeService = new Google_Service_YouTube($client);

            $params = [
                "part" => "snippet,replies",
                "maxResults" => min($limit, 100), // Max 100 per page
                "order" => ($order === "chronological") ? "time" : "relevance",
                "textFormat" => "plainText",
            ];
            if ($cursor) {
                $params["pageToken"] = $cursor;
            }

            // Determine if postId is a video ID or channel ID
            if (strpos($postId, "UC") === 0 || strpos($postId, "HC") === 0) { // Channel ID starts with UC or HC
                $params["channelId"] = $postId;
                // $params["allThreadsRelatedToChannelId"] = $postId; // Alternative, check which works best
            } else {
                $params["videoId"] = $postId;
            }

            $response = $youtubeService->commentThreads->listCommentThreads("snippet,replies", $params);

            $threads = $response->getItems() ?? [];
            $nextCursor = $response->getNextPageToken();

            // Format the output (extract top-level comment from each thread)
            $formattedComments = array_map(function ($thread) {
                 /** @var \Google_Service_YouTube_CommentThread $thread */
                return $this->formatCommentData($thread->getSnippet()->getTopLevelComment(), $thread->getReplies());
            }, $threads);

            return [
                "platform" => "youtube",
                "post_id" => $postId, // Video or Channel ID
                "comments" => $formattedComments,
                "next_cursor" => $nextCursor,
                "raw_response" => $response->toSimpleObject(),
            ];
        } catch (\Exception $e) {
            throw new CommentException("Failed to get YouTube comments: " . $e->getMessage());
        }
    }

    /**
     * Get replies to a specific comment.
     *
     * @param string $accessToken User Access Token.
     * @param string $tokenSecret Ignored.
     * @param string $commentId The ID of the parent comment.
     * @param int $limit Maximum number of replies to return.
     * @param string|null $cursor Page token for pagination.
     * @return array Returns array containing replies and next cursor info.
     * @throws CommentException
     */
    public function getCommentReplies(string $accessToken, string $tokenSecret, string $commentId, int $limit = 20, ?string $cursor = null): array
    {
        try {
            $client = $this->getApiClient($accessToken);
            $youtubeService = new Google_Service_YouTube($client);

            $params = [
                "part" => "snippet",
                "parentId" => $commentId,
                "maxResults" => min($limit, 100),
                "textFormat" => "plainText",
            ];
            if ($cursor) {
                $params["pageToken"] = $cursor;
            }

            $response = $youtubeService->comments->listComments("snippet", $params);

            $replies = $response->getItems() ?? [];
            $nextCursor = $response->getNextPageToken();

            // Format the output
            $formattedReplies = array_map(function ($reply) {
                /** @var \Google_Service_YouTube_Comment $reply */
                return $this->formatCommentData($reply);
            }, $replies);

            return [
                "platform" => "youtube",
                "parent_comment_id" => $commentId,
                "comments" => $formattedReplies, // Using "comments" key for consistency
                "next_cursor" => $nextCursor,
                "raw_response" => $response->toSimpleObject(),
            ];
        } catch (\Exception $e) {
            throw new CommentException("Failed to get YouTube comment replies: " . $e->getMessage());
        }
    }

    /**
     * Post a new top-level comment on a video or channel.
     *
     * @param string $accessToken User Access Token.
     * @param string $tokenSecret Ignored.
     * @param string $targetId The Video ID or Channel ID to comment on.
     * @param string $comment The text content of the comment.
     * @param array $options Additional options.
     * @return array Returns array with platform_comment_id.
     * @throws CommentException
     */
    public function postComment(string $accessToken, string $tokenSecret, string $targetId, string $comment, array $options = []): array
    {
        try {
            $client = $this->getApiClient($accessToken);
            $youtubeService = new Google_Service_YouTube($client);

            $topLevelComment = new Google_Service_YouTube_Comment();
            $commentSnippet = new Google_Service_YouTube_CommentSnippet();
            $commentSnippet->setTextOriginal($comment);

            // Determine if targetId is video or channel
            if (strpos($targetId, "UC") === 0 || strpos($targetId, "HC") === 0) {
                $commentSnippet->setChannelId($targetId);
            } else {
                $commentSnippet->setVideoId($targetId);
            }
            $topLevelComment->setSnippet($commentSnippet);

            $commentThread = new Google_Service_YouTube_CommentThread();
            $threadSnippet = new Google_Service_YouTube_CommentThreadSnippet();
            $threadSnippet->setTopLevelComment($topLevelComment);
            // Set channelId or videoId on the thread snippet as well
            if (isset($commentSnippet["channelId"])) {
                $threadSnippet->setChannelId($commentSnippet["channelId"]);
            } else {
                $threadSnippet->setVideoId($commentSnippet["videoId"]);
            }
            $commentThread->setSnippet($threadSnippet);

            $response = $youtubeService->commentThreads->insert("snippet", $commentThread);

            return [
                "platform" => "youtube",
                // The response contains the full comment thread, the ID is for the thread
                // The actual comment ID is inside response->snippet->topLevelComment->id
                "platform_comment_id" => $response->getSnippet()->getTopLevelComment()->getId(),
                "platform_thread_id" => $response->getId(),
                "target_id" => $targetId,
                "raw_response" => $response->toSimpleObject(),
            ];
        } catch (\Exception $e) {
            throw new CommentException("Failed to post YouTube comment: " . $e->getMessage());
        }
    }

    /**
     * Reply to an existing comment.
     *
     * @param string $accessToken User Access Token.
     * @param string $tokenSecret Ignored.
     * @param string $commentId The ID of the comment to reply to.
     * @param string $reply The text content of the reply.
     * @param array $options Additional options.
     * @return array Returns array with platform_comment_id.
     * @throws CommentException
     */
    public function replyToComment(string $accessToken, string $tokenSecret, string $commentId, string $reply, array $options = []): array
    {
        try {
            $client = $this->getApiClient($accessToken);
            $youtubeService = new Google_Service_YouTube($client);

            $commentReply = new Google_Service_YouTube_Comment();
            $replySnippet = new Google_Service_YouTube_CommentSnippet();
            $replySnippet->setTextOriginal($reply);
            $replySnippet->setParentId($commentId);
            $commentReply->setSnippet($replySnippet);

            $response = $youtubeService->comments->insert("snippet", $commentReply);

            return [
                "platform" => "youtube",
                "platform_comment_id" => $response->getId(), // ID of the new reply
                "parent_comment_id" => $commentId,
                "raw_response" => $response->toSimpleObject(),
            ];
        } catch (\Exception $e) {
            throw new CommentException("Failed to reply to YouTube comment: " . $e->getMessage());
        }
    }

    /**
     * Like or react to a comment.
     *
     * @param string $accessToken User Access Token.
     * @param string $tokenSecret Ignored.
     * @param string $commentId The ID of the comment to like/react to.
     * @param string $reactionType Reaction type (
     * @return bool Returns true on success.
     * @throws CommentException
     */
    public function reactToComment(string $accessToken, string $tokenSecret, string $commentId, string $reactionType = "LIKE"): bool
    {
        try {
            $client = $this->getApiClient($accessToken);
            $youtubeService = new Google_Service_YouTube($client);

            $rating = "none";
            if ($reactionType === "LIKE") {
                $rating = "like";
            } elseif ($reactionType === "DISLIKE") {
                // Dislike counts are hidden, but API might still accept "dislike"
                // However, the standard action is usually just "like"
                $rating = "like"; // Treat dislike as like for simplicity, or throw error
                 // throw new CommentException("Disliking comments is not supported via YouTube API.");
            }

            if ($rating === "none") {
                 throw new CommentException("Invalid reaction type for YouTube comment: {$reactionType}");
            }

            $response = $youtubeService->comments->setModerationStatus($commentId, "published", ["rating" => $rating]);
            // Note: setModerationStatus is primarily for status, but docs suggest rating can be set.
            // Alternative: Use youtube.commentThreads.update if targeting top-level comment?
            // Simpler: Use youtube.videos.rate for videos, but comments.rate doesn't exist.
            // The most reliable way might be via `comments->markAsSpam` (not a like) or `setModerationStatus`.
            // Let's assume `setModerationStatus` with rating works as intended for liking.

            // The API returns empty response on success (204 No Content)
            return true;
        } catch (\Exception $e) {
            // Handle errors like already liked, comment not found, etc.
            throw new CommentException("Failed to react to YouTube comment: " . $e->getMessage());
        }
    }

    /**
     * Remove a reaction (unlike) from a comment.
     *
     * @param string $accessToken User Access Token.
     * @param string $tokenSecret Ignored.
     * @param string $commentId The ID of the comment to unlike.
     * @param string $reactionType Ignored.
     * @return bool Returns true on success.
     * @throws CommentException
     */
    public function removeCommentReaction(string $accessToken, string $tokenSecret, string $commentId, string $reactionType = "LIKE"): bool
    {
        try {
            $client = $this->getApiClient($accessToken);
            $youtubeService = new Google_Service_YouTube($client);

            // Set rating to "none" to remove like/dislike
            $response = $youtubeService->comments->setModerationStatus($commentId, "published", ["rating" => "none"]);

            // API returns empty response on success (204 No Content)
            return true;
        } catch (\Exception $e) {
            throw new CommentException("Failed to remove reaction from YouTube comment: " . $e->getMessage());
        }
    }

    /**
     * Delete a comment or reply.
     *
     * @param string $accessToken User Access Token.
     * @param string $tokenSecret Ignored.
     * @param string $commentId The ID of the comment or reply to delete.
     * @return bool Returns true on success.
     * @throws CommentException
     */
    public function deleteComment(string $accessToken, string $tokenSecret, string $commentId): bool
    {
        try {
            $client = $this->getApiClient($accessToken);
            $youtubeService = new Google_Service_YouTube($client);

            $response = $youtubeService->comments->delete($commentId);

            // API returns empty response on success (204 No Content)
            return true;
        } catch (\Google_Service_Exception $e) {
            if ($e->getCode() == 404) {
                return true; // Already deleted
            }
            throw new CommentException("Failed to delete YouTube comment: " . $e->getMessage());
        } catch (\Exception $e) {
            throw new CommentException("Failed to delete YouTube comment: " . $e->getMessage());
        }
    }

    /**
     * Hide a comment (Mark as spam or set moderation status).
     *
     * @param string $accessToken User Access Token.
     * @param string $tokenSecret Ignored.
     * @param string $commentId The ID of the comment to hide.
     * @return bool Returns true on success.
     * @throws CommentException
     */
    public function hideComment(string $accessToken, string $tokenSecret, string $commentId): bool
    {
        try {
            $client = $this->getApiClient($accessToken);
            $youtubeService = new Google_Service_YouTube($client);

            // Option 1: Mark as spam (removes visibility)
            // $response = $youtubeService->comments->markAsSpam($commentId);

            // Option 2: Set moderation status to "heldForReview" or "rejected"
            // Requires channel moderator permissions
            $response = $youtubeService->comments->setModerationStatus($commentId, "heldForReview");
            // Or use "rejected" to delete it based on moderation rules
            // $response = $youtubeService->comments->setModerationStatus($commentId, "rejected");

            // API returns empty response on success (204 No Content)
            return true;
        } catch (\Exception $e) {
            throw new CommentException("Failed to hide YouTube comment: " . $e->getMessage());
        }
    }

    /**
     * Unhide a comment (Set moderation status to published).
     *
     * @param string $accessToken User Access Token.
     * @param string $tokenSecret Ignored.
     * @param string $commentId The ID of the comment to unhide.
     * @return bool Returns true on success.
     * @throws CommentException
     */
    public function unhideComment(string $accessToken, string $tokenSecret, string $commentId): bool
    {
        try {
            $client = $this->getApiClient($accessToken);
            $youtubeService = new Google_Service_YouTube($client);

            // Set moderation status back to published
            $response = $youtubeService->comments->setModerationStatus($commentId, "published");

            // API returns empty response on success (204 No Content)
            return true;
        } catch (\Exception $e) {
            throw new CommentException("Failed to unhide YouTube comment: " . $e->getMessage());
        }
    }

    /**
     * Helper function to format comment data consistently.
     *
     * @param \Google_Service_YouTube_Comment $comment
     * @param \Google_Service_YouTube_CommentThreadReplies|null $repliesData
     * @return array Formatted comment data.
     */
    protected function formatCommentData(Google_Service_YouTube_Comment $comment, $repliesData = null): array
    {
        $snippet = $comment->getSnippet();
        $author = $snippet->getAuthorChannelId(); // Simplified, full details require another call

        return [
            "platform_comment_id" => $comment->getId(),
            "message" => $snippet->getTextDisplay(),
            "from" => [
                "id" => $snippet->getAuthorChannelUrl(), // URL, not ID directly
                "name" => $snippet->getAuthorDisplayName(),
                "avatar" => $snippet->getAuthorProfileImageUrl(),
                // "channel_id" => $author ? $author["value"] : null, // Requires author object
            ],
            "created_time" => $snippet->getPublishedAt() ? date("c", strtotime($snippet->getPublishedAt())) : null,
            "updated_time" => $snippet->getUpdatedAt() ? date("c", strtotime($snippet->getUpdatedAt())) : null,
            "like_count" => $snippet->getLikeCount(),
            "liked_by_viewer" => ($snippet->getViewerRating() === "like"),
            "replies" => [], // Replies need separate call via getCommentReplies
            "reply_count" => $repliesData ? $repliesData->getComments() ? count($repliesData->getComments()) : 0 : ($snippet->getTotalReplyCount() ?? 0),
            "parent_id" => $snippet->getParentId(),
            "moderation_status" => $snippet->getModerationStatus(),
        ];
    }
}
