<?php

namespace VendorName\SocialConnect\Services\Twitter;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Subscriber\Oauth\Oauth1;
use Illuminate\Support\Facades\Config;
use VendorName\SocialConnect\Contracts\CommentManagementInterface;
use VendorName\SocialConnect\Exceptions\CommentException;

class TwitterCommentService implements CommentManagementInterface
{
    /**
     * The HTTP client instance for API v2.
     *
     * @var \GuzzleHttp\Client
     */
    protected $clientV2;

    /**
     * Twitter Consumer Key (API Key).
     *
     * @var string
     */
    protected $consumerKey;

    /**
     * Twitter Consumer Secret (API Secret).
     *
     * @var string
     */
    protected $consumerSecret;

    /**
     * Create a new TwitterCommentService instance.
     */
    public function __construct()
    {
        $config = Config::get("social-connect.platforms.twitter");
        $this->consumerKey = $config["consumer_key"];
        $this->consumerSecret = $config["consumer_secret"];

        // Client for API v2 Tweet interactions (requires OAuth 1.0a User Context)
        $this->clientV2 = new Client([
            "base_uri" => "https://api.twitter.com/2/",
            "timeout" => 30,
        ]);
    }

    /**
     * Get Guzzle client configured with OAuth 1.0a User Context.
     *
     * @param string $accessToken User Access Token.
     * @param string $tokenSecret User Access Token Secret.
     * @return Client
     */
    protected function getOAuth1Client(string $accessToken, string $tokenSecret): Client
    {
        $middleware = new Oauth1([
            "consumer_key" => $this->consumerKey,
            "consumer_secret" => $this->consumerSecret,
            "token" => $accessToken,
            "token_secret" => $tokenSecret,
        ]);
        $stack = HandlerStack::create();
        $stack->push($middleware);

        return new Client([
            "base_uri" => $this->clientV2->getConfig("base_uri"),
            "handler" => $stack,
            "auth" => "oauth",
            "timeout" => 30,
        ]);
    }

    /**
     * Get comments (replies) for a specific Tweet.
     * Note: Twitter API v2 doesn't have a direct "get comments" endpoint.
     * We use the search API to find Tweets replying to the original Tweet.
     *
     * @param string $accessToken User Access Token.
     * @param string $tokenSecret User Access Token Secret.
     * @param string $postId The ID of the original Tweet.
     * @param int $limit Maximum number of replies to return.
     * @param string|null $cursor Pagination token (next_token).
     * @param string $order Ignored (search results are typically relevance/recent).
     * @return array Returns array containing replies and next cursor.
     * @throws CommentException
     */
    public function getComments(string $accessToken, string $tokenSecret, string $postId, int $limit = 20, ?string $cursor = null, string $order = "chronological"): array
    {
        try {
            $client = $this->getOAuth1Client($accessToken, $tokenSecret);
            $query = "conversation_id:{$postId} is:reply"; // Search for replies in the conversation

            $params = [
                "query" => $query,
                "max_results" => min($limit, 100), // Max 100 per page for search
                "tweet.fields" => "created_at,author_id,public_metrics,entities,in_reply_to_user_id",
                "expansions" => "author_id",
                "user.fields" => "name,username,profile_image_url,verified",
            ];

            if ($cursor) {
                $params["next_token"] = $cursor;
            }

            // Use the recent search endpoint (requires Academic Research access for full history)
            // Standard access provides ~7 days of history.
            $response = $client->get("tweets/search/recent", [
                "query" => $params,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            $comments = $data["data"] ?? [];
            $users = [];
            if (isset($data["includes"]["users"])) {
                foreach ($data["includes"]["users"] as $user) {
                    $users[$user["id"]] = $user;
                }
            }
            $nextCursor = $data["meta"]["next_token"] ?? null;

            // Format the output
            $formattedComments = array_map(function ($comment) use ($users, $postId) {
                $author = $users[$comment["author_id"]] ?? null;
                return [
                    "platform_comment_id" => $comment["id"],
                    "message" => $comment["text"] ?? null,
                    "from" => [
                        "id" => $author["id"] ?? null,
                        "name" => $author["name"] ?? null,
                        "username" => $author["username"] ?? null,
                        "avatar" => $author["profile_image_url"] ?? null,
                        "verified" => $author["verified"] ?? false,
                    ],
                    "created_time" => $comment["created_at"] ?? null,
                    "like_count" => $comment["public_metrics"]["like_count"] ?? 0,
                    "reply_count" => $comment["public_metrics"]["reply_count"] ?? 0,
                    "retweet_count" => $comment["public_metrics"]["retweet_count"] ?? 0,
                    "quote_count" => $comment["public_metrics"]["quote_count"] ?? 0,
                    "parent_id" => $postId, // The original tweet ID
                    "entities" => $comment["entities"] ?? null,
                ];
            }, $comments);

            return [
                "platform" => "twitter",
                "post_id" => $postId,
                "comments" => $formattedComments,
                "next_cursor" => $nextCursor,
                "raw_response" => $data,
            ];
        } catch (GuzzleException $e) {
            throw new CommentException("Failed to get Twitter comments (replies): " . $e->getMessage());
        }
    }

    /**
     * Get replies to a specific comment (Tweet).
     * Same logic as getComments, but the $commentId is the Tweet being replied to.
     *
     * @param string $accessToken User Access Token.
     * @param string $tokenSecret User Access Token Secret.
     * @param string $commentId The ID of the Tweet (comment) to get replies for.
     * @param int $limit Maximum number of replies to return.
     * @param string|null $cursor Pagination token.
     * @return array Returns array containing replies and next cursor.
     * @throws CommentException
     */
    public function getCommentReplies(string $accessToken, string $tokenSecret, string $commentId, int $limit = 20, ?string $cursor = null): array
    {
        // Getting replies to a reply is the same as getting comments for that reply Tweet ID.
        return $this->getComments($accessToken, $tokenSecret, $commentId, $limit, $cursor);
    }

    /**
     * Post a new comment (reply) to a Tweet.
     *
     * @param string $accessToken User Access Token.
     * @param string $tokenSecret User Access Token Secret.
     * @param string $targetId The ID of the Tweet to reply to.
     * @param string $comment The text content of the reply.
     * @param array $options Additional options (e.g., media_ids).
     * @return array Returns array with platform_comment_id (the new Tweet ID).
     * @throws CommentException
     */
    public function postComment(string $accessToken, string $tokenSecret, string $targetId, string $comment, array $options = []): array
    {
        try {
            $client = $this->getOAuth1Client($accessToken, $tokenSecret);

            $payload = [
                "text" => $comment,
                "reply" => [
                    "in_reply_to_tweet_id" => $targetId,
                ],
            ];

            // Add media if provided
            if (isset($options["media_ids"]) && is_array($options["media_ids"])) {
                $payload["media"] = ["media_ids" => $options["media_ids"]];
            }

            $response = $client->post("tweets", [
                "json" => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data["data"]["id"])) {
                throw new CommentException("Failed to post reply Tweet. No ID returned.");
            }

            return [
                "platform" => "twitter",
                "platform_comment_id" => $data["data"]["id"], // ID of the new reply Tweet
                "target_id" => $targetId, // ID of the Tweet being replied to
                "raw_response" => $data,
            ];
        } catch (GuzzleException $e) {
            throw new CommentException("Failed to post Twitter reply: " . $e->getMessage());
        }
    }

    /**
     * Reply to an existing comment (Tweet).
     * Same as postComment.
     *
     * @param string $accessToken User Access Token.
     * @param string $tokenSecret User Access Token Secret.
     * @param string $commentId The ID of the Tweet (comment) to reply to.
     * @param string $reply The text content of the reply.
     * @param array $options Additional options.
     * @return array Returns array with platform_comment_id.
     * @throws CommentException
     */
    public function replyToComment(string $accessToken, string $tokenSecret, string $commentId, string $reply, array $options = []): array
    {
        return $this->postComment($accessToken, $tokenSecret, $commentId, $reply, $options);
    }

    /**
     * Like a comment (Tweet).
     *
     * @param string $accessToken User Access Token.
     * @param string $tokenSecret User Access Token Secret.
     * @param string $commentId The ID of the Tweet (comment) to like.
     * @param string $reactionType Ignored (only LIKE is supported).
     * @return bool Returns true on success.
     * @throws CommentException
     */
    public function reactToComment(string $accessToken, string $tokenSecret, string $commentId, string $reactionType = "LIKE"): bool
    {
        try {
            $client = $this->getOAuth1Client($accessToken, $tokenSecret);
            // Need the authenticated user's ID
            $authUserId = $this->getAuthenticatedUserId($accessToken, $tokenSecret);

            $response = $client->post("users/{$authUserId}/likes", [
                "json" => [
                    "tweet_id" => $commentId,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return isset($data["data"]["liked"]) && $data["data"]["liked"];
        } catch (GuzzleException $e) {
            // Handle potential errors like already liked (403 Forbidden)
            if ($e->getCode() === 403) {
                // Check if the error message indicates it's already liked
                $errorBody = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : "";
                if (str_contains($errorBody, "already liked")) {
                    return true; // Treat as success if already liked
                }
            }
            throw new CommentException("Failed to like Twitter comment (Tweet): " . $e->getMessage());
        }
    }

    /**
     * Unlike a comment (Tweet).
     *
     * @param string $accessToken User Access Token.
     * @param string $tokenSecret User Access Token Secret.
     * @param string $commentId The ID of the Tweet (comment) to unlike.
     * @param string $reactionType Ignored.
     * @return bool Returns true on success.
     * @throws CommentException
     */
    public function removeCommentReaction(string $accessToken, string $tokenSecret, string $commentId, string $reactionType = "LIKE"): bool
    {
        try {
            $client = $this->getOAuth1Client($accessToken, $tokenSecret);
            // Need the authenticated user's ID
            $authUserId = $this->getAuthenticatedUserId($accessToken, $tokenSecret);

            $response = $client->delete("users/{$authUserId}/likes/{$commentId}");

            $data = json_decode($response->getBody()->getContents(), true);

            // Check if unliking was successful
            return isset($data["data"]["liked"]) && !$data["data"]["liked"];
        } catch (GuzzleException $e) {
             // Handle potential errors like not liked (404 Not Found or similar)
            if ($e->getCode() === 404) {
                 return true; // Treat as success if already not liked
            }
            throw new CommentException("Failed to unlike Twitter comment (Tweet): " . $e->getMessage());
        }
    }

    /**
     * Delete a comment (Tweet) - requires the Tweet to be owned by the authenticated user.
     *
     * @param string $accessToken User Access Token.
     * @param string $tokenSecret User Access Token Secret.
     * @param string $commentId The ID of the Tweet (comment) to delete.
     * @return bool Returns true on success.
     * @throws CommentException
     */
    public function deleteComment(string $accessToken, string $tokenSecret, string $commentId): bool
    {
        // Deleting a comment is deleting the reply Tweet
        try {
            $client = $this->getOAuth1Client($accessToken, $tokenSecret);

            $response = $client->delete("tweets/{$commentId}");

            $data = json_decode($response->getBody()->getContents(), true);

            return isset($data["data"]["deleted"]) && $data["data"]["deleted"];
        } catch (GuzzleException $e) {
            // Handle forbidden (not owner) or not found errors
            throw new CommentException("Failed to delete Twitter comment (Tweet): " . $e->getMessage());
        }
    }

    /**
     * Hide a comment (reply Tweet) - uses the hide reply endpoint.
     *
     * @param string $accessToken User Access Token.
     * @param string $tokenSecret User Access Token Secret.
     * @param string $commentId The ID of the reply Tweet to hide.
     * @return bool Returns true on success.
     * @throws CommentException
     */
    public function hideComment(string $accessToken, string $tokenSecret, string $commentId): bool
    {
        try {
            $client = $this->getOAuth1Client($accessToken, $tokenSecret);

            $response = $client->put("tweets/{$commentId}/hidden", [
                "json" => ["hidden" => true],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return isset($data["data"]["hidden"]) && $data["data"]["hidden"];
        } catch (GuzzleException $e) {
            // Handle errors like not the author of the parent tweet, tweet not found, etc.
            throw new CommentException("Failed to hide Twitter reply: " . $e->getMessage());
        }
    }

    /**
     * Unhide a comment (reply Tweet).
     *
     * @param string $accessToken User Access Token.
     * @param string $tokenSecret User Access Token Secret.
     * @param string $commentId The ID of the reply Tweet to unhide.
     * @return bool Returns true on success.
     * @throws CommentException
     */
    public function unhideComment(string $accessToken, string $tokenSecret, string $commentId): bool
    {
        try {
            $client = $this->getOAuth1Client($accessToken, $tokenSecret);

            $response = $client->put("tweets/{$commentId}/hidden", [
                "json" => ["hidden" => false],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return isset($data["data"]["hidden"]) && !$data["data"]["hidden"];
        } catch (GuzzleException $e) {
            throw new CommentException("Failed to unhide Twitter reply: " . $e->getMessage());
        }
    }

    /**
     * Helper to get the authenticated user's ID.
     *
     * @param string $accessToken
     * @param string $tokenSecret
     * @return string
     * @throws CommentException
     */
    protected function getAuthenticatedUserId(string $accessToken, string $tokenSecret): string
    {
        try {
            $client = $this->getOAuth1Client($accessToken, $tokenSecret);
            $response = $client->get("users/me", ["query" => ["user.fields" => "id"]]);
            $data = json_decode($response->getBody()->getContents(), true);
            if (!isset($data["data"]["id"])) {
                throw new CommentException("Could not retrieve authenticated user ID.");
            }
            return $data["data"]["id"];
        } catch (GuzzleException $e) {
            throw new CommentException("Failed to get authenticated user ID: " . $e->getMessage());
        }
    }
}
