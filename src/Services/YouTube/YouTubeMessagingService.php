<?php

namespace VendorName\SocialConnect\Services\YouTube;

use Google_Client;
use Google_Service_YouTube;
use Illuminate\Support\Facades\Config;
use VendorName\SocialConnect\Contracts\MessagingInterface;
use VendorName\SocialConnect\Exceptions\MessagingException;

class YouTubeMessagingService implements MessagingInterface
{
    /**
     * Google API Client.
     *
     * @var \Google_Client
     */
    protected $googleClient;

    /**
     * Create a new YouTubeMessagingService instance.
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
     * @throws MessagingException
     */
    protected function getApiClient(string $accessToken): Google_Client
    {
        if (empty($accessToken)) {
            throw new MessagingException("YouTube access token is required.");
        }
        $client = clone $this->googleClient;
        $client->setAccessToken($accessToken);
        $client->addScope(Google_Service_YouTube::YOUTUBE_FORCE_SSL); // Scope needed for live chat
        return $client;
    }

    /**
     * Get conversations (Not applicable to YouTube standard messaging).
     *
     * @param string $accessToken
     * @param string $tokenSecret
     * @param string $targetId Channel ID.
     * @param int $limit
     * @param string|null $cursor
     * @return array
     * @throws MessagingException
     */
    public function getConversations(string $accessToken, string $tokenSecret, string $targetId, int $limit = 20, ?string $cursor = null): array
    {
        // YouTube doesn't have a direct conversation/DM inbox API like other platforms.
        // Messaging is primarily through Live Chat during broadcasts.
        throw new MessagingException("Getting conversation lists is not supported by the YouTube API. Use getMessages for Live Chat retrieval.");
    }

    /**
     * Get messages for a specific conversation (Live Chat Messages).
     *
     * @param string $accessToken User Access Token with youtube.force-ssl scope.
     * @param string $tokenSecret Ignored.
     * @param string $conversationId The Live Chat ID (usually obtained from a liveBroadcast resource).
     * @param int $limit Maximum number of messages to return.
     * @param string|null $cursor Page token for pagination (nextPageToken).
     * @return array Returns array containing messages and next cursor info.
     * @throws MessagingException
     */
    public function getMessages(string $accessToken, string $tokenSecret, string $conversationId, int $limit = 50, ?string $cursor = null): array
    {
        // This retrieves messages from a specific Live Chat
        try {
            $client = $this->getApiClient($accessToken);
            $youtubeService = new Google_Service_YouTube($client);

            $params = [
                "liveChatId" => $conversationId,
                "part" => "id,snippet,authorDetails",
                "maxResults" => min($limit, 2000), // Max 2000 per page for live chat
                // "profileImageSize" => 88, // Optional: specify avatar size
            ];
            if ($cursor) {
                $params["pageToken"] = $cursor;
            }

            $response = $youtubeService->liveChatMessages->listLiveChatMessages($conversationId, "snippet,authorDetails", $params);

            $messages = $response->getItems() ?? [];
            $nextCursor = $response->getNextPageToken();
            // pollingIntervalMillis suggests how often to poll for new messages
            $pollingInterval = $response->getPollingIntervalMillis();

            // Format the output
            $formattedMessages = array_map(function ($msg) {
                /** @var \Google_Service_YouTube_LiveChatMessage $msg */
                $snippet = $msg->getSnippet();
                $author = $msg->getAuthorDetails();
                return [
                    "platform_message_id" => $msg->getId(),
                    "created_time" => $snippet->getPublishedAt() ? date("c", strtotime($snippet->getPublishedAt())) : null,
                    "from" => [
                        "id" => $author->getChannelId(),
                        "name" => $author->getDisplayName(),
                        "avatar" => $author->getProfileImageUrl(),
                        "is_moderator" => $author->getIsChatModerator(),
                        "is_owner" => $author->getIsChatOwner(),
                        "is_sponsor" => $author->getIsChatSponsor(),
                        "is_verified" => $author->getIsVerified(),
                    ],
                    "to" => [], // Not applicable for broadcast chat
                    "message" => $snippet->getDisplayMessage(),
                    "type" => $snippet->getType(), // e.g., textMessageEvent, superChatEvent
                    // Include details based on type if needed (e.g., amount for superChatEvent)
                    "raw_snippet" => $snippet->toSimpleObject(),
                ];
            }, $messages);

            return [
                "platform" => "youtube",
                "conversation_id" => $conversationId, // Live Chat ID
                "messages" => $formattedMessages,
                "next_cursor" => $nextCursor,
                "polling_interval_ms" => $pollingInterval,
                "raw_response" => $response->toSimpleObject(),
            ];
        } catch (\Exception $e) {
            throw new MessagingException("Failed to get YouTube live chat messages: " . $e->getMessage());
        }
    }

    /**
     * Send a new message or reply (Post message to Live Chat).
     *
     * @param string $accessToken User Access Token.
     * @param string $tokenSecret Ignored.
     * @param string $targetId Ignored (message is sent to the live chat).
     * @param string $recipientId The Live Chat ID to post the message to.
     * @param string $message The text message content.
     * @param array $options Additional options.
     * @return array Returns array with platform_message_id.
     * @throws MessagingException
     */
    public function sendMessage(string $accessToken, string $tokenSecret, string $targetId, string $recipientId, string $message, array $options = []): array
    {
        // This posts a message to a specific Live Chat
        try {
            $client = $this->getApiClient($accessToken);
            $youtubeService = new Google_Service_YouTube($client);

            $liveChatMessage = new \Google_Service_YouTube_LiveChatMessage();
            $snippet = new \Google_Service_YouTube_LiveChatMessageSnippet();
            $snippet->setType("textMessageEvent");
            $snippet->setLiveChatId($recipientId);

            $textDetails = new \Google_Service_YouTube_LiveChatTextMessageDetails();
            $textDetails->setMessageText($message);
            $snippet->setTextMessageDetails($textDetails);

            $liveChatMessage->setSnippet($snippet);

            $response = $youtubeService->liveChatMessages->insert("snippet", $liveChatMessage);

            return [
                "platform" => "youtube",
                "platform_message_id" => $response->getId(),
                "recipient_id" => $recipientId, // Live Chat ID
                "raw_response" => $response->toSimpleObject(),
            ];
        } catch (\Exception $e) {
            // Handle specific errors, e.g., chat disabled, user banned, rate limits
            throw new MessagingException("Failed to send YouTube live chat message: " . $e->getMessage());
        }
    }

    /**
     * Reply to an existing conversation (Not applicable to YouTube Live Chat).
     *
     * @param string $accessToken
     * @param string $tokenSecret
     * @param string $conversationId
     * @param string $message
     * @param array $options
     * @return array
     * @throws MessagingException
     */
    public function replyToConversation(string $accessToken, string $tokenSecret, string $conversationId, string $message, array $options = []): array
    {
        // Replies are just new messages in the same live chat
        return $this->sendMessage($accessToken, $tokenSecret, "", $conversationId, $message, $options);
    }

    /**
     * Mark a conversation as read (Not applicable to YouTube Live Chat).
     *
     * @param string $accessToken
     * @param string $tokenSecret
     * @param string $conversationId
     * @return bool
     * @throws MessagingException
     */
    public function markConversationAsRead(string $accessToken, string $tokenSecret, string $conversationId): bool
    {
        // Live chat doesn't have a concept of marking conversations as read via API
        throw new MessagingException("Marking conversations as read is not supported for YouTube Live Chat.");
    }
}
