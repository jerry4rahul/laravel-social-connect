<?php

namespace VendorName\SocialConnect\Services\Instagram;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Config;
use VendorName\SocialConnect\Contracts\CommentManagementInterface;
use VendorName\SocialConnect\Exceptions\CommentException;

class InstagramCommentService implements CommentManagementInterface
{
    /**
     * The HTTP client instance for Instagram Graph API.
     *
     * @var \GuzzleHttp\Client
     */
    protected $graphClient;

    /**
     * Facebook Graph API version (used for Instagram Graph API).
     *
     * @var string
     */
    protected $graphVersion;

    /**
     * Create a new InstagramCommentService instance.
     */
    public function __construct()
    {
        // Comments use the Instagram Graph API (via Facebook Graph API endpoint)
        $config = Config::get("social-connect.platforms.facebook"); // Use Facebook config for Graph API version
        $this->graphVersion = $config["graph_version"] ?? "v18.0";

        $this->graphClient = new Client([
            "base_uri" => "https://graph.facebook.com/{$this->graphVersion}/",
            "timeout" => 30,
        ]);
    }

    /**
     * Get comments for a specific Instagram media post.
     *
     * @param string $accessToken Facebook Page access token with Instagram permissions.
     * @param string $postId The ID of the Instagram Media Object.
     * @param int $limit Maximum number of comments to return.
     * @param string|null $cursor Pagination cursor.
     * @param string $order Order (not typically supported by IG comments endpoint).
     * @return array Returns array containing comments and next cursor.
     * @throws CommentException
     */
    public function getComments(string $accessToken, string $postId, int $limit = 25, ?string $cursor = null, string $order = "chronological"): array
    {
        try {
            $params = [
                "fields" => "id,text,timestamp,username,from{id,username},like_count,replies{id,text,timestamp,username,like_count}", // Request replies inline if needed
                "access_token" => $accessToken,
                "limit" => $limit,
            ];

            if ($cursor) {
                $params["after"] = $cursor;
            }

            $response = $this->graphClient->get("{$postId}/comments", [
                "query" => $params,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data["data"])) {
                throw new CommentException("Failed to retrieve comments from Instagram.");
            }

            $comments = $data["data"];
            $nextCursor = $data["paging"]["cursors"]["after"] ?? null;

            // Format the output
            $formattedComments = array_map(function ($comment) {
                return $this->formatCommentData($comment);
            }, $comments);

            return [
                "platform" => "instagram",
                "post_id" => $postId,
                "comments" => $formattedComments,
                "next_cursor" => $nextCursor,
                "raw_response" => $data,
            ];
        } catch (GuzzleException $e) {
            throw new CommentException("Failed to get Instagram comments: " . $e->getMessage());
        }
    }

    /**
     * Get replies to a specific comment.
     *
     * @param string $accessToken Facebook Page access token with Instagram permissions.
     * @param string $commentId The ID of the parent comment.
     * @param int $limit Maximum number of replies to return.
     * @param string|null $cursor Pagination cursor.
     * @return array Returns array containing replies and next cursor.
     * @throws CommentException
     */
    public function getCommentReplies(string $accessToken, string $commentId, int $limit = 25, ?string $cursor = null): array
    {
        try {
            $params = [
                "fields" => "id,text,timestamp,username,like_count",
                "access_token" => $accessToken,
                "limit" => $limit,
            ];

            if ($cursor) {
                $params["after"] = $cursor;
            }

            $response = $this->graphClient->get("{$commentId}/replies", [
                "query" => $params,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data["data"])) {
                throw new CommentException("Failed to retrieve comment replies from Instagram.");
            }

            $replies = $data["data"];
            $nextCursor = $data["paging"]["cursors"]["after"] ?? null;

            // Format the output (similar to comments, but simpler structure for replies)
            $formattedReplies = array_map(function ($reply) {
                 return [
                    "platform_comment_id" => $reply["id"],
                    "message" => $reply["text"] ?? null,
                    "from" => [
                        "id" => null, // API doesn't provide 'from' for replies directly
                        "name" => $reply["username"] ?? null,
                        "avatar" => null,
                    ],
                    "created_time" => $reply["timestamp"] ?? null,
                    "like_count" => $reply["like_count"] ?? 0,
                    "parent_id" => $commentId,
                    // Other fields like can_comment, can_like etc. are not available for replies endpoint
                ];
            }, $replies);

            return [
                "platform" => "instagram",
                "parent_comment_id" => $commentId,
                "comments" => $formattedReplies, // Using 'comments' key for consistency
                "next_cursor" => $nextCursor,
                "raw_response" => $data,
            ];
        } catch (GuzzleException $e) {
            throw new CommentException("Failed to get Instagram comment replies: " . $e->getMessage());
        }
    }

    /**
     * Post a new comment on an Instagram media post.
     *
     * @param string $accessToken Facebook Page access token with Instagram permissions.
     * @param string $targetId The ID of the Instagram Media Object.
     * @param string $comment The text content of the comment.
     * @param array $options Additional options (ignored).
     * @return array Returns array with platform_comment_id.
     * @throws CommentException
     */
    public function postComment(string $accessToken, string $targetId, string $comment, array $options = []): array
    {
        try {
            $payload = [
                "message" => $comment,
                "access_token" => $accessToken,
            ];

            $response = $this->graphClient->post("{$targetId}/comments", [
                "form_params" => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data["id"])) {
                throw new CommentException("Failed to post comment to Instagram. No comment ID returned.");
            }

            return [
                "platform" => "instagram",
                "platform_comment_id" => $data["id"],
                "target_id" => $targetId,
                "raw_response" => $data,
            ];
        } catch (GuzzleException $e) {
            throw new CommentException("Failed to post Instagram comment: " . $e->getMessage());
        }
    }

    /**
     * Reply to an existing comment.
     *
     * @param string $accessToken Facebook Page access token with Instagram permissions.
     * @param string $commentId The ID of the comment to reply to.
     * @param string $reply The text content of the reply.
     * @param array $options Additional options (ignored).
     * @return array Returns array with platform_comment_id.
     * @throws CommentException
     */
    public function replyToComment(string $accessToken, string $commentId, string $reply, array $options = []): array
    {
        try {
            $payload = [
                "message" => $reply,
                "access_token" => $accessToken,
            ];

            $response = $this->graphClient->post("{$commentId}/replies", [
                "form_params" => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data["id"])) {
                throw new CommentException("Failed to reply to Instagram comment. No reply ID returned.");
            }

            return [
                "platform" => "instagram",
                "platform_comment_id" => $data["id"], // This is the ID of the reply
                "parent_comment_id" => $commentId,
                "raw_response" => $data,
            ];
        } catch (GuzzleException $e) {
            throw new CommentException("Failed to reply to Instagram comment: " . $e->getMessage());
        }
    }

    /**
     * Like or react to a comment (Not supported by Instagram API).
     *
     * @param string $accessToken
     * @param string $commentId
     * @param string $reactionType
     * @return bool
     * @throws CommentException
     */
    public function reactToComment(string $accessToken, string $commentId, string $reactionType = "LIKE"): bool
    {
        throw new CommentException("Reacting to comments is not supported by the Instagram Graph API.");
    }

    /**
     * Remove a reaction from a comment (Not supported by Instagram API).
     *
     * @param string $accessToken
     * @param string $commentId
     * @param string $reactionType
     * @return bool
     * @throws CommentException
     */
    public function removeCommentReaction(string $accessToken, string $commentId, string $reactionType = "LIKE"): bool
    {
        throw new CommentException("Removing reactions from comments is not supported by the Instagram Graph API.");
    }

    /**
     * Delete a comment or reply.
     *
     * @param string $accessToken Facebook Page access token with Instagram permissions.
     * @param string $commentId The ID of the comment or reply to delete.
     * @return bool Returns true on success.
     * @throws CommentException
     */
    public function deleteComment(string $accessToken, string $commentId): bool
    {
        try {
            $response = $this->graphClient->delete($commentId, [
                "query" => [
                    "access_token" => $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return isset($data["success"]) && $data["success"];
        } catch (GuzzleException $e) {
            throw new CommentException("Failed to delete Instagram comment: " . $e->getMessage());
        }
    }

    /**
     * Hide a comment.
     *
     * @param string $accessToken Facebook Page access token with Instagram permissions.
     * @param string $commentId The ID of the comment to hide.
     * @return bool Returns true on success.
     * @throws CommentException
     */
    public function hideComment(string $accessToken, string $commentId): bool
    {
        try {
            $response = $this->graphClient->post($commentId, [
                "form_params" => [
                    "hide" => "true",
                    "access_token" => $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return isset($data["success"]) && $data["success"];
        } catch (GuzzleException $e) {
            throw new CommentException("Failed to hide Instagram comment: " . $e->getMessage());
        }
    }

    /**
     * Unhide a comment.
     *
     * @param string $accessToken Facebook Page access token with Instagram permissions.
     * @param string $commentId The ID of the comment to unhide.
     * @return bool Returns true on success.
     * @throws CommentException
     */
    public function unhideComment(string $accessToken, string $commentId): bool
    {
        try {
            $response = $this->graphClient->post($commentId, [
                "form_params" => [
                    "hide" => "false",
                    "access_token" => $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return isset($data["success"]) && $data["success"];
        } catch (GuzzleException $e) {
            throw new CommentException("Failed to unhide Instagram comment: " . $e->getMessage());
        }
    }

    /**
     * Helper function to format comment data consistently.
     *
     * @param array $comment Raw comment data from API.
     * @return array Formatted comment data.
     */
    protected function formatCommentData(array $comment): array
    {
        $formattedReplies = [];
        if (isset($comment["replies"]["data"])) {
            $formattedReplies = array_map(function ($reply) use ($comment) {
                 return [
                    "platform_comment_id" => $reply["id"],
                    "message" => $reply["text"] ?? null,
                    "from" => [
                        "id" => null,
                        "name" => $reply["username"] ?? null,
                        "avatar" => null,
                    ],
                    "created_time" => $reply["timestamp"] ?? null,
                    "like_count" => $reply["like_count"] ?? 0,
                    "parent_id" => $comment["id"],
                ];
            }, $comment["replies"]["data"]);
        }

        return [
            "platform_comment_id" => $comment["id"],
            "message" => $comment["text"] ?? null,
            "from" => [
                "id" => $comment["from"]["id"] ?? null,
                "name" => $comment["from"]["username"] ?? ($comment["username"] ?? null),
                "avatar" => null, // Not provided by API
            ],
            "created_time" => $comment["timestamp"] ?? null,
            "like_count" => $comment["like_count"] ?? 0,
            "replies" => $formattedReplies,
            "reply_count" => count($formattedReplies), // Calculate based on fetched replies
            "parent_id" => null, // Top-level comments have null parent
            // Fields like can_comment, can_like, can_hide, is_hidden are not standard in IG comment object
        ];
    }
}
