<?php

namespace VendorName\SocialConnect\Services\LinkedIn;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Config;
use VendorName\SocialConnect\Contracts\CommentManagementInterface;
use VendorName\SocialConnect\Exceptions\CommentException;

class LinkedInCommentService implements CommentManagementInterface
{
    /**
     * The HTTP client instance for LinkedIn API v2.
     *
     * @var \GuzzleHttp\Client
     */
    protected $client;

    /**
     * Create a new LinkedInCommentService instance.
     */
    public function __construct()
    {
        $this->client = new Client([
            "base_uri" => "https://api.linkedin.com/v2/",
            "timeout" => 60,
        ]);
    }

    /**
     * Get Guzzle client configured with Bearer token.
     *
     * @param string $accessToken User or Organization Access Token.
     * @return Client
     */
    protected function getApiClient(string $accessToken): Client
    {
        return new Client([
            "base_uri" => $this->client->getConfig("base_uri"),
            "timeout" => $this->client->getConfig("timeout"),
            "headers" => [
                "Authorization" => "Bearer " . $accessToken,
                "Connection" => "Keep-Alive",
                "X-Restli-Protocol-Version" => "2.0.0",
                "Content-Type" => "application/json",
                "Accept" => "application/json",
            ],
        ]);
    }

    /**
     * Get comments for a specific post (Share or UGC Post).
     *
     * @param string $accessToken User or Organization Access Token.
     * @param string $tokenSecret Ignored.
     * @param string $postId The URN of the Share or UGC Post (e.g., "urn:li:share:{id}" or "urn:li:ugcPost:{id}").
     * @param int $limit Maximum number of comments to return.
     * @param string|null $cursor Pagination marker (start index).
     * @param string $order Sort order (
     * @return array Returns array containing comments and next cursor info.
     * @throws CommentException
     */
    public function getComments(string $accessToken, string $tokenSecret, string $postId, int $limit = 25, ?string $cursor = null, string $order = "chronological"): array
    {
        try {
            $client = $this->getApiClient($accessToken);
            $ugcPostUrn = str_contains($postId, ":ugcPost:") ? $postId : "urn:li:ugcPost:" . explode(":", $postId)[3];

            $params = [
                "q" => "comments",
                "commentedOn" => $ugcPostUrn,
                "count" => $limit,
                "start" => $cursor ?? 0, // LinkedIn uses start index for pagination
                "sort" => ($order === "reverse_chronological") ? "CREATED_DESCENDING" : "CREATED_ASCENDING",
                // Projection to get necessary fields
                "projection" => "(elements(*(*,actor~(*,profilePicture(displayImage~:playableStreams)),commentary,created,likesSummary),paging))",
            ];

            $response = $client->get("socialActions/{$ugcPostUrn}/comments", [
                "query" => $params,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data["elements"])) {
                throw new CommentException("Failed to retrieve comments from LinkedIn.");
            }

            $comments = $data["elements"];
            $start = $data["paging"]["start"] ?? 0;
            $count = $data["paging"]["count"] ?? 0;
            $total = $data["paging"]["total"] ?? 0;
            $nextCursor = ($start + $count < $total) ? ($start + $count) : null;

            // Format the output
            $formattedComments = array_map(function ($comment) {
                return $this->formatCommentData($comment);
            }, $comments);

            return [
                "platform" => "linkedin",
                "post_id" => $postId,
                "comments" => $formattedComments,
                "next_cursor" => $nextCursor, // Next start index
                "raw_response" => $data,
            ];
        } catch (GuzzleException $e) {
            throw new CommentException("Failed to get LinkedIn comments: " . $e->getMessage());
        }
    }

    /**
     * Get replies to a specific comment.
     * LinkedIn API treats replies as comments on the comment URN.
     *
     * @param string $accessToken User or Organization Access Token.
     * @param string $tokenSecret Ignored.
     * @param string $commentId The URN of the parent comment (e.g., "urn:li:comment:(urn:li:activity:{id},123)").
     * @param int $limit Maximum number of replies to return.
     * @param string|null $cursor Pagination marker (start index).
     * @return array Returns array containing replies and next cursor info.
     * @throws CommentException
     */
    public function getCommentReplies(string $accessToken, string $tokenSecret, string $commentId, int $limit = 25, ?string $cursor = null): array
    {
        // Getting replies uses the same endpoint as getComments, but targets the comment URN.
        // The commentId needs to be the full URN like "urn:li:comment:(urn:li:activity:12345,67890)"
        try {
            $client = $this->getApiClient($accessToken);
            $params = [
                "q" => "comments",
                "commentedOn" => $commentId, // Target the parent comment URN
                "count" => $limit,
                "start" => $cursor ?? 0,
                "sort" => "CREATED_ASCENDING",
                "projection" => "(elements(*(*,actor~(*,profilePicture(displayImage~:playableStreams)),commentary,created,likesSummary),paging))",
            ];

            // The endpoint requires encoding the comment URN
            $encodedCommentId = urlencode($commentId);
            $response = $client->get("socialActions/{$encodedCommentId}/comments", [
                "query" => $params,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data["elements"])) {
                throw new CommentException("Failed to retrieve comment replies from LinkedIn.");
            }

            $replies = $data["elements"];
            $start = $data["paging"]["start"] ?? 0;
            $count = $data["paging"]["count"] ?? 0;
            $total = $data["paging"]["total"] ?? 0;
            $nextCursor = ($start + $count < $total) ? ($start + $count) : null;

            // Format the output
            $formattedReplies = array_map(function ($reply) use ($commentId) {
                $formatted = $this->formatCommentData($reply);
                $formatted["parent_id"] = $commentId; // Set parent ID for replies
                return $formatted;
            }, $replies);

            return [
                "platform" => "linkedin",
                "parent_comment_id" => $commentId,
                "comments" => $formattedReplies, // Using "comments" key for consistency
                "next_cursor" => $nextCursor,
                "raw_response" => $data,
            ];
        } catch (GuzzleException $e) {
            throw new CommentException("Failed to get LinkedIn comment replies: " . $e->getMessage());
        }
    }

    /**
     * Post a new comment on a Share or UGC Post.
     *
     * @param string $accessToken User or Organization Access Token.
     * @param string $tokenSecret Ignored.
     * @param string $targetId The URN of the Share or UGC Post.
     * @param string $comment The text content of the comment.
     * @param array $options Additional options (e.g., actor URN).
     * @return array Returns array with platform_comment_id.
     * @throws CommentException
     */
    public function postComment(string $accessToken, string $tokenSecret, string $targetId, string $comment, array $options = []): array
    {
        try {
            $client = $this->getApiClient($accessToken);
            $ugcPostUrn = str_contains($targetId, ":ugcPost:") ? $targetId : "urn:li:ugcPost:" . explode(":", $targetId)[3];

            // Actor URN is required
            $actorUrn = $options["actor_urn"] ?? $this->getAuthenticatedActorUrn($accessToken);

            $payload = [
                "actor" => $actorUrn,
                "object" => $ugcPostUrn,
                "message" => [
                    "text" => $comment,
                ],
                // Add attachments if needed (complex, involves asset uploads)
            ];

            $response = $client->post("socialActions/{$ugcPostUrn}/comments", [
                "json" => $payload,
            ]);

            // Response Location header contains the new comment URN
            $locationHeader = $response->getHeaderLine("X-RestLi-Id") ?: $response->getHeaderLine("Location");
            $commentUrn = $locationHeader;

            if (!$commentUrn) {
                // Fallback: Try parsing body if available (though 201 usually has empty body)
                $data = json_decode($response->getBody()->getContents(), true);
                $commentUrn = $data["id"] ?? null;
            }

            if (!$commentUrn) {
                throw new CommentException("Failed to post LinkedIn comment. No comment URN returned.");
            }

            return [
                "platform" => "linkedin",
                "platform_comment_id" => $commentUrn,
                "target_id" => $targetId,
                "raw_response" => ["CommentURN" => $commentUrn], // Minimal raw response
            ];
        } catch (GuzzleException | \Exception $e) {
            throw new CommentException("Failed to post LinkedIn comment: " . $e->getMessage());
        }
    }

    /**
     * Reply to an existing comment.
     *
     * @param string $accessToken User or Organization Access Token.
     * @param string $tokenSecret Ignored.
     * @param string $commentId The URN of the comment to reply to.
     * @param string $reply The text content of the reply.
     * @param array $options Additional options (e.g., actor URN).
     * @return array Returns array with platform_comment_id.
     * @throws CommentException
     */
    public function replyToComment(string $accessToken, string $tokenSecret, string $commentId, string $reply, array $options = []): array
    {
        // Replying uses the same endpoint as postComment, but targets the comment URN.
        try {
            $client = $this->getApiClient($accessToken);
            $actorUrn = $options["actor_urn"] ?? $this->getAuthenticatedActorUrn($accessToken);

            $payload = [
                "actor" => $actorUrn,
                "object" => $commentId, // Target the parent comment URN
                "message" => [
                    "text" => $reply,
                ],
            ];

            $encodedCommentId = urlencode($commentId);
            $response = $client->post("socialActions/{$encodedCommentId}/comments", [
                "json" => $payload,
            ]);

            $locationHeader = $response->getHeaderLine("X-RestLi-Id") ?: $response->getHeaderLine("Location");
            $replyUrn = $locationHeader;

            if (!$replyUrn) {
                $data = json_decode($response->getBody()->getContents(), true);
                $replyUrn = $data["id"] ?? null;
            }

            if (!$replyUrn) {
                throw new CommentException("Failed to post LinkedIn reply. No reply URN returned.");
            }

            return [
                "platform" => "linkedin",
                "platform_comment_id" => $replyUrn, // URN of the new reply
                "parent_comment_id" => $commentId,
                "raw_response" => ["ReplyURN" => $replyUrn],
            ];
        } catch (GuzzleException | \Exception $e) {
            throw new CommentException("Failed to reply to LinkedIn comment: " . $e->getMessage());
        }
    }

    /**
     * Like or react to a comment.
     *
     * @param string $accessToken User or Organization Access Token.
     * @param string $tokenSecret Ignored.
     * @param string $commentId The URN of the comment to like.
     * @param string $reactionType Ignored (only LIKE is supported).
     * @return bool Returns true on success.
     * @throws CommentException
     */
    public function reactToComment(string $accessToken, string $tokenSecret, string $commentId, string $reactionType = "LIKE"): bool
    {
        try {
            $client = $this->getApiClient($accessToken);
            $actorUrn = $this->getAuthenticatedActorUrn($accessToken);
            $encodedCommentId = urlencode($commentId);

            $payload = [
                "actor" => $actorUrn,
            ];

            // Endpoint to like a comment
            $response = $client->post("socialActions/{$encodedCommentId}/likes", [
                "json" => $payload,
            ]);

            // LinkedIn returns 201 Created on success
            return $response->getStatusCode() === 201;
        } catch (GuzzleException $e) {
            // Handle already liked (e.g., 409 Conflict or similar)
            if ($e->getCode() === 409 || $e->getCode() === 400) { // 400 might indicate already liked
                 return true; // Assume success if already liked
            }
            throw new CommentException("Failed to like LinkedIn comment: " . $e->getMessage());
        }
    }

    /**
     * Remove a reaction (unlike) from a comment.
     *
     * @param string $accessToken User or Organization Access Token.
     * @param string $tokenSecret Ignored.
     * @param string $commentId The URN of the comment to unlike.
     * @param string $reactionType Ignored.
     * @return bool Returns true on success.
     * @throws CommentException
     */
    public function removeCommentReaction(string $accessToken, string $tokenSecret, string $commentId, string $reactionType = "LIKE"): bool
    {
        try {
            $client = $this->getApiClient($accessToken);
            $actorUrn = $this->getAuthenticatedActorUrn($accessToken);
            $encodedCommentId = urlencode($commentId);
            $encodedActorUrn = urlencode($actorUrn);

            // Endpoint to unlike a comment
            $response = $client->delete("socialActions/{$encodedCommentId}/likes/{$encodedActorUrn}");

            // LinkedIn returns 204 No Content on success
            return $response->getStatusCode() === 204;
        } catch (GuzzleException $e) {
            // Handle not found (404 - wasn't liked)
            if ($e->getCode() === 404) {
                return true; // Treat as success if not liked
            }
            throw new CommentException("Failed to unlike LinkedIn comment: " . $e->getMessage());
        }
    }

    /**
     * Delete a comment or reply.
     *
     * @param string $accessToken User or Organization Access Token.
     * @param string $tokenSecret Ignored.
     * @param string $commentId The URN of the comment or reply to delete.
     * @return bool Returns true on success.
     * @throws CommentException
     */
    public function deleteComment(string $accessToken, string $tokenSecret, string $commentId): bool
    {
        // Requires specific permissions (e.g., rw_organization_admin for org comments)
        try {
            $client = $this->getApiClient($accessToken);
            $encodedCommentId = urlencode($commentId);

            // Use the comments endpoint with the comment URN
            $response = $client->delete("comments/{$encodedCommentId}");

            // LinkedIn returns 204 No Content on success
            return $response->getStatusCode() === 204;
        } catch (GuzzleException $e) {
            // Handle forbidden (not owner/admin) or not found
            if ($e->getCode() === 404) {
                return true; // Already deleted
            }
            throw new CommentException("Failed to delete LinkedIn comment: " . $e->getMessage());
        }
    }

    /**
     * Hide a comment (Not directly supported by LinkedIn API).
     *
     * @param string $accessToken
     * @param string $tokenSecret
     * @param string $commentId
     * @return bool
     * @throws CommentException
     */
    public function hideComment(string $accessToken, string $tokenSecret, string $commentId): bool
    {
        throw new CommentException("Hiding comments is not directly supported by the LinkedIn API.");
    }

    /**
     * Unhide a comment (Not directly supported by LinkedIn API).
     *
     * @param string $accessToken
     * @param string $tokenSecret
     * @param string $commentId
     * @return bool
     * @throws CommentException
     */
    public function unhideComment(string $accessToken, string $tokenSecret, string $commentId): bool
    {
        throw new CommentException("Unhiding comments is not directly supported by the LinkedIn API.");
    }

    /**
     * Helper function to format comment data consistently.
     *
     * @param array $comment Raw comment data from API.
     * @return array Formatted comment data.
     */
    protected function formatCommentData(array $comment): array
    {
        $actor = $comment["actor~"] ?? null;
        $likesSummary = $comment["likesSummary"] ?? [];

        return [
            "platform_comment_id" => $comment["urn"] ?? ($comment["id"] ?? null),
            "message" => $comment["commentary"]["text"] ?? null,
            "from" => $actor ? [
                "id" => $actor["urn"] ?? null,
                "name" => ($actor["localizedFirstName"] ?? "") . " " . ($actor["localizedLastName"] ?? ""),
                "avatar" => $actor["profilePicture"]["displayImage~"]["elements"][0]["identifiers"][0]["identifier"] ?? null,
            ] : null,
            "created_time" => isset($comment["created"]["time"]) ? date("c", $comment["created"]["time"] / 1000) : null,
            "like_count" => $likesSummary["totalLikes"] ?? 0,
            "liked_by_viewer" => $likesSummary["likedByCurrentUser"] ?? false,
            "replies" => [], // Replies need to be fetched separately using getCommentReplies
            "reply_count" => $comment["commentsSummary"]["totalFirstLevelComments"] ?? 0,
            "parent_id" => null, // Set by getCommentReplies if it's a reply
        ];
    }

    /**
     * Helper to get the authenticated user or organization URN.
     *
     * @param string $accessToken
     * @return string
     * @throws CommentException
     */
    protected function getAuthenticatedActorUrn(string $accessToken): string
    {
        // This usually requires a call to /me or /organizations based on the token type
        // For simplicity, assume the caller provides it in options if needed, or make a /me call.
        try {
            $client = $this->getApiClient($accessToken);
            $response = $client->get("me", ["query" => ["projection" => "(id)"]]);
            $data = json_decode($response->getBody()->getContents(), true);
            if (!isset($data["id"])) {
                throw new CommentException("Could not retrieve authenticated actor URN.");
            }
            return "urn:li:person:" . $data["id"];
        } catch (GuzzleException $e) {
            // Could be an org token, handle appropriately or require it in options
            throw new CommentException("Failed to get authenticated actor URN: " . $e->getMessage() . ". Please provide actor_urn in options if using an Organization token.");
        }
    }
}
