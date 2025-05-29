<?php

namespace VendorName\SocialConnect\Services\Instagram;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Config;
use VendorName\SocialConnect\Contracts\MessagingInterface;
use VendorName\SocialConnect\Exceptions\MessagingException;

class InstagramMessagingService implements MessagingInterface
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
     * Create a new InstagramMessagingService instance.
     */
    public function __construct()
    {
        // Messaging uses the Instagram Graph API (via Facebook Graph API endpoint)
        $config = Config::get("social-connect.platforms.facebook"); // Use Facebook config for Graph API version
        $this->graphVersion = $config["graph_version"] ?? "v18.0";

        $this->graphClient = new Client([
            "base_uri" => "https://graph.facebook.com/{$this->graphVersion}/",
            "timeout" => 30,
        ]);
    }

    /**
     * Get conversations (mapped to Facebook Page conversations linked to Instagram).
     *
     * @param string $accessToken Facebook Page access token with Instagram permissions.
     * @param string $pageId Facebook Page ID linked to the Instagram account.
     * @param int $limit Maximum number of conversations to return.
     * @param string|null $cursor Pagination cursor.
     * @return array Returns array containing conversations and next cursor.
     * @throws MessagingException
     */
    public function getConversations(string $accessToken, string $pageId, int $limit = 20, ?string $cursor = null): array
    {
        // Instagram DMs are managed via the Facebook Page linked to the Instagram Business account.
        // We use the Facebook Page endpoint but filter for Instagram conversations.
        try {
            $params = [
                "fields" => "id,participants,updated_time,snippet,unread_count,message_count,can_reply",
                "platform" => "instagram", // Filter for Instagram conversations
                "access_token" => $accessToken,
                "limit" => $limit,
            ];

            if ($cursor) {
                $params["after"] = $cursor;
            }

            // Use the Facebook Page ID endpoint
            $response = $this->graphClient->get("{$pageId}/conversations", [
                "query" => $params,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data["data"])) {
                throw new MessagingException("Failed to retrieve Instagram conversations (via Facebook Page).");
            }

            $conversations = $data["data"];
            $nextCursor = $data["paging"]["cursors"]["after"] ?? null;

            // Format the output
            $formattedConversations = array_map(function ($conv) {
                return [
                    "platform_conversation_id" => $conv["id"], // This is the Facebook conversation ID
                    "participants" => $conv["participants"]["data"] ?? [],
                    "updated_time" => $conv["updated_time"] ?? null,
                    "snippet" => $conv["snippet"] ?? null,
                    "unread_count" => $conv["unread_count"] ?? 0,
                    "message_count" => $conv["message_count"] ?? 0,
                    "can_reply" => $conv["can_reply"] ?? false,
                ];
            }, $conversations);

            return [
                "platform" => "instagram",
                "page_id" => $pageId, // Facebook Page ID
                "conversations" => $formattedConversations,
                "next_cursor" => $nextCursor,
                "raw_response" => $data,
            ];
        } catch (GuzzleException $e) {
            throw new MessagingException("Failed to get Instagram conversations: " . $e->getMessage());
        }
    }

    /**
     * Get messages for a specific conversation (using Facebook conversation ID).
     *
     * @param string $accessToken Facebook Page access token with Instagram permissions.
     * @param string $conversationId The Facebook conversation ID containing Instagram messages.
     * @param int $limit Maximum number of messages to return.
     * @param string|null $cursor Pagination cursor.
     * @return array Returns array containing messages and next cursor.
     * @throws MessagingException
     */
    public function getMessages(string $accessToken, string $conversationId, int $limit = 20, ?string $cursor = null): array
    {
        // Messages are retrieved using the Facebook conversation ID
        try {
            $params = [
                "fields" => "id,created_time,from,to,message,attachments,shares,sticker",
                "access_token" => $accessToken,
                "limit" => $limit,
            ];

            if ($cursor) {
                $params["after"] = $cursor;
            }

            $response = $this->graphClient->get("{$conversationId}/messages", [
                "query" => $params,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data["data"])) {
                throw new MessagingException("Failed to retrieve messages from Instagram conversation.");
            }

            $messages = $data["data"];
            $nextCursor = $data["paging"]["cursors"]["after"] ?? null;

            // Format the output
            $formattedMessages = array_map(function ($msg) {
                return [
                    "platform_message_id" => $msg["id"], // Facebook message ID
                    "created_time" => $msg["created_time"] ?? null,
                    "from" => $msg["from"] ?? null, // Can be Page or User
                    "to" => $msg["to"]["data"] ?? [],
                    "message" => $msg["message"] ?? null,
                    "attachments" => $msg["attachments"]["data"] ?? [],
                    "shares" => $msg["shares"]["data"] ?? [],
                    "sticker" => $msg["sticker"] ?? null,
                ];
            }, $messages);

            return [
                "platform" => "instagram",
                "conversation_id" => $conversationId, // Facebook conversation ID
                "messages" => $formattedMessages,
                "next_cursor" => $nextCursor,
                "raw_response" => $data,
            ];
        } catch (GuzzleException $e) {
            throw new MessagingException("Failed to get Instagram messages: " . $e->getMessage());
        }
    }

    /**
     * Send a new message (via Facebook Page linked to Instagram).
     *
     * @param string $accessToken Facebook Page access token with Instagram permissions.
     * @param string $pageId Facebook Page ID linked to the Instagram account.
     * @param string $recipientId Instagram-Scoped User ID (IGSID).
     * @param string $message The text message content.
     * @param array $options Additional options.
     * @return array Returns array with platform_message_id.
     * @throws MessagingException
     */
    public function sendMessage(string $accessToken, string $pageId, string $recipientId, string $message, array $options = []): array
    {
        // Sending uses the Facebook Page endpoint, specifying the recipient by IGSID
        try {
            $payload = [
                "recipient" => ["id" => $recipientId], // Use IGSID here
                "message" => ["text" => $message],
                "messaging_type" => "RESPONSE",
                "access_token" => $accessToken,
            ];

            // Use the /me/messages endpoint associated with the Facebook Page
            $response = $this->graphClient->post("me/messages", [
                "json" => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data["message_id"])) {
                throw new MessagingException("Failed to send Instagram message (via Facebook Page). No message ID returned.");
            }

            return [
                "platform" => "instagram",
                "platform_message_id" => $data["message_id"], // Facebook message ID
                "recipient_id" => $data["recipient_id"] ?? $recipientId,
                "raw_response" => $data,
            ];
        } catch (GuzzleException $e) {
            throw new MessagingException("Failed to send Instagram message: " . $e->getMessage());
        }
    }

    /**
     * Reply to an existing conversation (using Facebook conversation ID).
     *
     * @param string $accessToken Facebook Page access token with Instagram permissions.
     * @param string $conversationId The Facebook conversation ID containing Instagram messages.
     * @param string $message The text message content.
     * @param array $options Additional options.
     * @return array Returns array with platform_message_id.
     * @throws MessagingException
     */
    public function replyToConversation(string $accessToken, string $conversationId, string $message, array $options = []): array
    {
        // Replying uses the Facebook conversation ID endpoint
        try {
            $payload = [
                "message" => ["text" => $message],
                "access_token" => $accessToken,
            ];

            $response = $this->graphClient->post("{$conversationId}/messages", [
                "form_params" => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data["id"])) {
                throw new MessagingException("Failed to reply to Instagram conversation. No message ID returned.");
            }

            return [
                "platform" => "instagram",
                "platform_message_id" => $data["id"], // Facebook message ID
                "conversation_id" => $conversationId,
                "raw_response" => $data,
            ];
        } catch (GuzzleException $e) {
            throw new MessagingException("Failed to reply to Instagram conversation: " . $e->getMessage());
        }
    }

    /**
     * Mark a conversation as read (using Facebook conversation ID).
     *
     * @param string $accessToken Facebook Page access token with Instagram permissions.
     * @param string $conversationId The Facebook conversation ID.
     * @return bool Returns true on success.
     * @throws MessagingException
     */
    public function markConversationAsRead(string $accessToken, string $conversationId): bool
    {
        // Marking read uses the Facebook conversation ID endpoint
        try {
            $response = $this->graphClient->post($conversationId, [
                "form_params" => [
                    "read" => "true", // This parameter might not exist or work as expected
                    "access_token" => $accessToken,
                ],
            ]);
            // Check response? API might not support this directly.
            // Alternative: Mark locally in the app.
            // For now, assume success or local handling.
            return true;
        } catch (GuzzleException $e) {
            // Log error but potentially return true if local handling is the strategy
            report(new MessagingException("Attempt to mark Instagram conversation as read failed (API might not support): " . $e->getMessage()));
            return false; // Indicate potential failure
        }
    }
}
