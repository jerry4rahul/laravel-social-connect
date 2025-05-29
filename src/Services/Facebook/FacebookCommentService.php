<?php

namespace VendorName\SocialConnect\Services\Facebook;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Config;
use VendorName\SocialConnect\Contracts\CommentManagementInterface;
use VendorName\SocialConnect\Exceptions\CommentException;

class FacebookCommentService implements CommentManagementInterface
{
    /**
     * The HTTP client instance.
     *
     * @var \GuzzleHttp\Client
     */
    protected $client;

    /**
     * Facebook Graph API version.
     *
     * @var string
     */
    protected $graphVersion;

    /**
     * Create a new FacebookCommentService instance.
     */
    public function __construct()
    {
        $config = Config::get("social-connect.platforms.facebook");
        $this->graphVersion = $config["graph_version"] ?? "v18.0";

        $this->client = new Client([
            "base_uri" => "https://graph.facebook.com/{$this->graphVersion}/",
            "timeout" => 30,
        ]);
    }

    /**
     * Get comments for a specific post.
     *
     * @param string $accessToken The page/user access token.
     * @param string $postId The ID of the Facebook Post.
     * @param int $limit Maximum number of comments to return.
     * @param string|null $cursor Pagination cursor.
     * @param string $order Order of comments (
     * @return array Returns array containing comments and next cursor.
     * @throws CommentException
     */
    public function getComments(string $accessToken, string $postId, int $limit = 25, ?string $cursor = null, string $order = "chronological"): array
    {
        try {
            $params = [
                "fields" => "id,message,from{id,name,picture},created_time,attachment,comment_count,like_count,parent,can_comment,can_like,can_hide,is_hidden,private_reply_conversation",
                "access_token" => $accessToken,
                "limit" => $limit,
                "order" => $order, // chronological or reverse_chronological
            ];

            if ($cursor) {
                $params["after"] = $cursor;
            }

            $response = $this->client->get("{$postId}/comments", [
                "query" => $params,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data["data"])) {
                throw new CommentException("Failed to retrieve comments from Facebook.");
            }

            $comments = $data["data"];
            $nextCursor = $data["paging"]["cursors"]["after"] ?? null;

            // Format the output
            $formattedComments = array_map(function ($comment) {
                return $this->formatCommentData($comment);
            }, $comments);

            return [
                "platform" => "facebook",
                "post_id" => $postId,
                "comments" => $formattedComments,
                "next_cursor" => $nextCursor,
                "raw_response" => $data,
            ];
        } catch (GuzzleException $e) {
            throw new CommentException("Failed to get Facebook comments: " . $e->getMessage());
        }
    }

    /**
     * Get replies to a specific comment.
     *
     * @param string $accessToken The page/user access token.
     * @param string $commentId The ID of the parent comment.
     * @param int $limit Maximum number of replies to return.
     * @param string|null $cursor Pagination cursor.
     * @return array Returns array containing replies and next cursor.
     * @throws CommentException
     */
    public function getCommentReplies(string $accessToken, string $commentId, int $limit = 25, ?string $cursor = null): array
    {
        // Facebook treats replies as comments on the parent comment object
        return $this->getComments($accessToken, $commentId, $limit, $cursor);
    }

    /**
     * Post a new comment on a post or reply to a comment.
     *
     * @param string $accessToken The page/user access token.
     * @param string $targetId The ID of the Post or parent Comment to comment on.
     * @param string $comment The text content of the comment.
     * @param array $options Additional options (e.g., attachment_url).
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

            if (isset($options["attachment_url"])) {
                $payload["attachment_url"] = $options["attachment_url"];
            }
            // Add other options like attachment_id, etc. if needed

            $response = $this->client->post("{$targetId}/comments", [
                "form_params" => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data["id"])) {
                throw new CommentException("Failed to post comment to Facebook. No comment ID returned.");
            }

            return [
                "platform" => "facebook",
                "platform_comment_id" => $data["id"],
                "target_id" => $targetId,
                "raw_response" => $data,
            ];
        } catch (GuzzleException $e) {
            throw new CommentException("Failed to post Facebook comment: " . $e->getMessage());
        }
    }

    /**
     * Reply to an existing comment.
     *
     * @param string $accessToken The page/user access token.
     * @param string $commentId The ID of the comment to reply to.
     * @param string $reply The text content of the reply.
     * @param array $options Additional options.
     * @return array Returns array with platform_comment_id.
     * @throws CommentException
     */
    public function replyToComment(string $accessToken, string $commentId, string $reply, array $options = []): array
    {
        // Replying is the same as posting a comment on the comment object
        return $this->postComment($accessToken, $commentId, $reply, $options);
    }

    /**
     * Like or react to a comment.
     *
     * @param string $accessToken The page/user access token.
     * @param string $commentId The ID of the comment.
     * @param string $reactionType The reaction type (e.g., LIKE, LOVE, WOW, HAHA, SAD, ANGRY).
     * @return bool Returns true on success.
     * @throws CommentException
     */
    public function reactToComment(string $accessToken, string $commentId, string $reactionType = "LIKE"): bool
    {
        try {
            $payload = [
                "type" => strtoupper($reactionType),
                "access_token" => $accessToken,
            ];

            $response = $this->client->post("{$commentId}/likes", [
                "form_params" => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return isset($data["success"]) && $data["success"];
        } catch (GuzzleException $e) {
            throw new CommentException("Failed to react to Facebook comment: " . $e->getMessage());
        }
    }

    /**
     * Remove a reaction from a comment.
     *
     * @param string $accessToken The page/user access token.
     * @param string $commentId The ID of the comment.
     * @param string $reactionType (Optional) Specify reaction type if needed, otherwise removes LIKE.
     * @return bool Returns true on success.
     * @throws CommentException
     */
    public function removeCommentReaction(string $accessToken, string $commentId, string $reactionType = "LIKE"): bool
    {
        try {
            $payload = [
                "access_token" => $accessToken,
            ];
            // Note: Removing specific reactions other than LIKE might not be directly supported or needed.
            // The DELETE request typically removes the user's LIKE reaction.

            $response = $this->client->delete("{$commentId}/likes", [
                "query" => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return isset($data["success"]) && $data["success"];
        } catch (GuzzleException $e) {
            throw new CommentException("Failed to remove reaction from Facebook comment: " . $e->getMessage());
        }
    }

    /**
     * Delete a comment.
     *
     * @param string $accessToken The page/user access token.
     * @param string $commentId The ID of the comment to delete.
     * @return bool Returns true on success.
     * @throws CommentException
     */
    public function deleteComment(string $accessToken, string $commentId): bool
    {
        try {
            $response = $this->client->delete($commentId, [
                "query" => [
                    "access_token" => $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return isset($data["success"]) && $data["success"];
        } catch (GuzzleException $e) {
            throw new CommentException("Failed to delete Facebook comment: " . $e->getMessage());
        }
    }

    /**
     * Hide a comment.
     *
     * @param string $accessToken The page/user access token.
     * @param string $commentId The ID of the comment to hide.
     * @return bool Returns true on success.
     * @throws CommentException
     */
    public function hideComment(string $accessToken, string $commentId): bool
    {
        try {
            $response = $this->client->post($commentId, [
                "form_params" => [
                    "is_hidden" => "true",
                    "access_token" => $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return isset($data["success"]) && $data["success"];
        } catch (GuzzleException $e) {
            throw new CommentException("Failed to hide Facebook comment: " . $e->getMessage());
        }
    }

    /**
     * Unhide a comment.
     *
     * @param string $accessToken The page/user access token.
     * @param string $commentId The ID of the comment to unhide.
     * @return bool Returns true on success.
     * @throws CommentException
     */
    public function unhideComment(string $accessToken, string $commentId): bool
    {
        try {
            $response = $this->client->post($commentId, [
                "form_params" => [
                    "is_hidden" => "false",
                    "access_token" => $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return isset($data["success"]) && $data["success"];
        } catch (GuzzleException $e) {
            throw new CommentException("Failed to unhide Facebook comment: " . $e->getMessage());
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
        return [
            "platform_comment_id" => $comment["id"],
            "message" => $comment["message"] ?? null,
            "from" => [
                "id" => $comment["from"]["id"] ?? null,
                "name" => $comment["from"]["name"] ?? null,
                "avatar" => $comment["from"]["picture"]["data"]["url"] ?? null,
            ],
            "created_time" => $comment["created_time"] ?? null,
            "attachment" => $comment["attachment"] ?? null,
            "comment_count" => $comment["comment_count"] ?? 0,
            "like_count" => $comment["like_count"] ?? 0,
            "parent_id" => $comment["parent"]["id"] ?? null,
            "can_comment" => $comment["can_comment"] ?? false,
            "can_like" => $comment["can_like"] ?? false,
            "can_hide" => $comment["can_hide"] ?? false,
            "is_hidden" => $comment["is_hidden"] ?? false,
            "private_reply_conversation_id" => $comment["private_reply_conversation"]["id"] ?? null,
        ];
    }
}
