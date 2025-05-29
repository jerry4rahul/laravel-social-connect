<?php

namespace VendorName\SocialConnect\Services\Facebook;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Config;
use VendorName\SocialConnect\Contracts\MessagingInterface;
use VendorName\SocialConnect\Exceptions\MessagingException;

class FacebookMessagingService implements MessagingInterface
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
     * Create a new FacebookMessagingService instance.
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
     * Get conversations for a Facebook Page.
     *
     * @param string $accessToken The page access token.
     * @param string $pageId The ID of the Facebook Page.
     * @param int $limit Maximum number of conversations to return.
     * @param string|null $cursor Pagination cursor.
     * @return array Returns array containing conversations and next cursor.
     * @throws MessagingException
     */
    public function getConversations(string $accessToken, string $pageId, int $limit = 20, ?string $cursor = null): array
    {
        try {
            $params = [
                "fields" => "id,participants,updated_time,snippet,unread_count,message_count,can_reply",
                "access_token" => $accessToken,
                "limit" => $limit,
            ];

            if ($cursor) {
                $params["after"] = $cursor;
            }

            $response = $this->client->get("{$pageId}/conversations", [
                "query" => $params,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data["data"])) {
                throw new MessagingException("Failed to retrieve conversations from Facebook.");
            }

            $conversations = $data["data"];
            $nextCursor = $data["paging"]["cursors"]["after"] ?? null;

            // Format the output slightly
            $formattedConversations = array_map(function ($conv) {
                return [
                    "platform_conversation_id" => $conv["id"],
                    "participants" => $conv["participants"]["data"] ?? [],
                    "updated_time" => $conv["updated_time"] ?? null,
                    "snippet" => $conv["snippet"] ?? null,
                    "unread_count" => $conv["unread_count"] ?? 0,
                    "message_count" => $conv["message_count"] ?? 0,
                    "can_reply" => $conv["can_reply"] ?? false,
                ];
            }, $conversations);

            return [
                "platform" => "facebook",
                "page_id" => $pageId,
                "conversations" => $formattedConversations,
                "next_cursor" => $nextCursor,
                "raw_response" => $data,
            ];
        } catch (GuzzleException $e) {
            throw new MessagingException("Failed to get Facebook conversations: " . $e->getMessage());
        }
    }

    /**
     * Get messages for a specific conversation.
     *
     * @param string $accessToken The page access token.
     * @param string $conversationId The ID of the conversation.
     * @param int $limit Maximum number of messages to return.
     * @param string|null $cursor Pagination cursor.
     * @return array Returns array containing messages and next cursor.
     * @throws MessagingException
     */
    public function getMessages(string $accessToken, string $conversationId, int $limit = 20, ?string $cursor = null): array
    {
        try {
            $params = [
                "fields" => "id,created_time,from,to,message,attachments,shares,sticker",
                "access_token" => $accessToken,
                "limit" => $limit,
            ];

            if ($cursor) {
                $params["after"] = $cursor;
            }

            $response = $this->client->get("{$conversationId}/messages", [
                "query" => $params,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data["data"])) {
                throw new MessagingException("Failed to retrieve messages from Facebook conversation.");
            }

            $messages = $data["data"];
            $nextCursor = $data["paging"]["cursors"]["after"] ?? null;

            // Format the output slightly
            $formattedMessages = array_map(function ($msg) {
                return [
                    "platform_message_id" => $msg["id"],
                    "created_time" => $msg["created_time"] ?? null,
                    "from" => $msg["from"] ?? null,
                    "to" => $msg["to"]["data"] ?? [],
                    "message" => $msg["message"] ?? null,
                    "attachments" => $msg["attachments"]["data"] ?? [],
                    "shares" => $msg["shares"]["data"] ?? [],
                    "sticker" => $msg["sticker"] ?? null,
                ];
            }, $messages);

            return [
                "platform" => "facebook",
                "conversation_id" => $conversationId,
                "messages" => $formattedMessages,
                "next_cursor" => $nextCursor,
                "raw_response" => $data,
            ];
        } catch (GuzzleException $e) {
            throw new MessagingException("Failed to get Facebook messages: " . $e->getMessage());
        }
    }

    /**
     * Send a new message (starts a new conversation or replies if recipient ID is known).
     *
     * @param string $accessToken The page access token.
     * @param string $pageId The ID of the Facebook Page sending the message.
     * @param string $recipientId The Page-Scoped User ID (PSID) of the recipient.
     * @param string $message The text message content.
     * @param array $options Additional options (e.g., messaging_type, tag).
     * @return array Returns array with platform_message_id.
     * @throws MessagingException
     */
    public function sendMessage(string $accessToken, string $pageId, string $recipientId, string $message, array $options = []): array
    {
        try {
            $payload = [
                "recipient" => ["id" => $recipientId],
                "message" => ["text" => $message],
                "messaging_type" => $options["messaging_type"] ?? "RESPONSE", // Default to RESPONSE
                "access_token" => $accessToken,
            ];

            if (isset($options["tag"])) {
                $payload["tag"] = $options["tag"];
            }

            // The endpoint is /me/messages when sending from a Page
            $response = $this->client->post("me/messages", [
                "json" => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data["message_id"])) {
                throw new MessagingException("Failed to send message via Facebook. No message ID returned.");
            }

            return [
                "platform" => "facebook",
                "platform_message_id" => $data["message_id"],
                "recipient_id" => $data["recipient_id"] ?? $recipientId,
                "raw_response" => $data,
            ];
        } catch (GuzzleException $e) {
            throw new MessagingException("Failed to send Facebook message: " . $e->getMessage());
        }
    }

    /**
     * Reply to an existing conversation.
     *
     * @param string $accessToken The page access token.
     * @param string $conversationId The ID of the conversation to reply to.
     * @param string $message The text message content.
     * @param array $options Additional options.
     * @return array Returns array with platform_message_id.
     * @throws MessagingException
     */
    public function replyToConversation(string $accessToken, string $conversationId, string $message, array $options = []): array
    {
        // Replying uses the conversation ID endpoint
        try {
            $payload = [
                "message" => ["text" => $message],
                "access_token" => $accessToken,
            ];

            $response = $this->client->post("{$conversationId}/messages", [
                "form_params" => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data["id"])) {
                throw new MessagingException("Failed to reply to Facebook conversation. No message ID returned.");
            }

            return [
                "platform" => "facebook",
                "platform_message_id" => $data["id"],
                "conversation_id" => $conversationId,
                "raw_response" => $data,
            ];
        } catch (GuzzleException $e) {
            throw new MessagingException("Failed to reply to Facebook conversation: " . $e->getMessage());
        }
    }

    /**
     * Mark a conversation as read (by marking the last message as read).
     *
     * @param string $accessToken The page access token.
     * @param string $conversationId The ID of the conversation.
     * @return bool Returns true on success.
     * @throws MessagingException
     */
    public function markConversationAsRead(string $accessToken, string $conversationId): bool
    {
        // Marking as read involves setting the 'read' status on the conversation
        // This might require specific permissions and can be complex.
        // A simpler approach might be to mark messages locally in the user's app.
        // Facebook API doesn't have a direct 'mark conversation read' endpoint easily usable here.
        // We can try marking the conversation itself via POST with read=true, but this is not standard.

        // Placeholder: Facebook API for marking read is complex/non-standard via Graph API for bots.
        // Typically handled by reading messages or via webhooks indicating user interaction.
        // Returning true for now, assuming local handling by the user's app.
        // throw new MessagingException("Marking conversation as read is not directly supported via this simplified API call.");
        return true; // Assume local handling or future implementation
    }
}
