<?php

namespace VendorName\SocialConnect\Services\LinkedIn;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Config;
use VendorName\SocialConnect\Contracts\MessagingInterface;
use VendorName\SocialConnect\Exceptions\MessagingException;

class LinkedInMessagingService implements MessagingInterface
{
    /**
     * The HTTP client instance for LinkedIn API v2.
     *
     * @var \GuzzleHttp\Client
     */
    protected $client;

    /**
     * Create a new LinkedInMessagingService instance.
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
     * @param string $accessToken User Access Token.
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
     * Get conversations (Messaging API).
     *
     * @param string $accessToken User Access Token with messaging permissions.
     * @param string $tokenSecret Ignored.
     * @param string $targetId The User URN (urn:li:person:{id}) of the authenticated user.
     * @param int $limit Maximum number of conversations to return.
     * @param string|null $cursor Pagination marker (not standard cursor, often uses createdBefore timestamp).
     * @return array Returns array containing conversations and next cursor info.
     * @throws MessagingException
     */
    public function getConversations(string $accessToken, string $tokenSecret, string $targetId, int $limit = 20, ?string $cursor = null): array
    {
        try {
            $client = $this->getApiClient($accessToken);
            $params = [
                "q" => "participants",
                "participants" => $targetId, // Filter by the authenticated user
                "sort" => "LAST_MODIFIED",
                "count" => $limit,
                // LinkedIn uses createdBefore for pagination typically
                // "createdBefore" => $cursor, // Assuming cursor is a timestamp in milliseconds
            ];
            if ($cursor && is_numeric($cursor)) {
                 $params["createdBefore"] = $cursor;
            }

            // Projection to get needed fields
            $params["projection"] = "(elements(*(id,created,lastModified,participantsDetails*~(localizedFirstName,localizedLastName,profilePicture(displayImage~:playableStreams)),lastActivityAt,read,totalMessageCount,versionTag)),paging)";

            $response = $client->get("conversations", [
                "query" => $params,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data["elements"])) {
                throw new MessagingException("Failed to retrieve conversations from LinkedIn.");
            }

            $conversations = $data["elements"];
            // Determine next cursor (e.g., last conversation's lastModified timestamp)
            $nextCursor = null;
            if (!empty($conversations)) {
                $lastConversation = end($conversations);
                $nextCursor = $lastConversation["lastModified"] ?? null;
            }

            // Format the output
            $formattedConversations = array_map(function ($conv) {
                $participants = [];
                foreach ($conv["participantsDetails"] ?? [] as $detail) {
                    $participants[] = [
                        "id" => $detail["entityUrn"],
                        "name" => ($detail["localizedFirstName"] ?? "") . " " . ($detail["localizedLastName"] ?? ""),
                        "avatar" => $detail["profilePicture"]["displayImage~"]["elements"][0]["identifiers"][0]["identifier"] ?? null,
                    ];
                }
                return [
                    "platform_conversation_id" => $conv["id"], // e.g., urn:li:conversation:123
                    "participants" => $participants,
                    "updated_time" => isset($conv["lastModified"]) ? date("c", $conv["lastModified"] / 1000) : null,
                    "snippet" => null, // Not directly available
                    "unread_count" => $conv["read"] ? 0 : ($conv["totalMessageCount"] ?? 1), // Approximation
                    "message_count" => $conv["totalMessageCount"] ?? 0,
                    "can_reply" => true, // Assume true unless API indicates otherwise
                ];
            }, $conversations);

            return [
                "platform" => "linkedin",
                "user_id" => $targetId,
                "conversations" => $formattedConversations,
                "next_cursor" => $nextCursor, // Timestamp for createdBefore
                "raw_response" => $data,
            ];
        } catch (GuzzleException $e) {
            throw new MessagingException("Failed to get LinkedIn conversations: " . $e->getMessage());
        }
    }

    /**
     * Get messages for a specific conversation.
     *
     * @param string $accessToken User Access Token.
     * @param string $tokenSecret Ignored.
     * @param string $conversationId The Conversation URN (e.g., "urn:li:conversation:{id}").
     * @param int $limit Maximum number of messages to return.
     * @param string|null $cursor Pagination marker (createdBefore timestamp).
     * @return array Returns array containing messages and next cursor info.
     * @throws MessagingException
     */
    public function getMessages(string $accessToken, string $tokenSecret, string $conversationId, int $limit = 20, ?string $cursor = null): array
    {
        try {
            $client = $this->getApiClient($accessToken);
            $params = [
                "q" => "conversation",
                "conversation" => $conversationId,
                "sort" => "CREATED", // Get newest first
                "count" => $limit,
            ];
            if ($cursor && is_numeric($cursor)) {
                 $params["createdBefore"] = $cursor;
            }

            // Projection for message details
            $params["projection"] = "(elements(*(id,created,sender~(localizedFirstName,localizedLastName,profilePicture(displayImage~:playableStreams)),body,attachments*~(*))),paging)";

            $response = $client->get("messages", [
                "query" => $params,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data["elements"])) {
                throw new MessagingException("Failed to retrieve messages from LinkedIn conversation.");
            }

            $messages = $data["elements"];
            $nextCursor = null;
            if (!empty($messages)) {
                $lastMessage = end($messages);
                $nextCursor = $lastMessage["created"] ?? null;
            }

            // Format the output
            $formattedMessages = array_map(function ($msg) {
                $sender = $msg["sender"] ?? null;
                return [
                    "platform_message_id" => $msg["id"], // e.g., urn:li:message:123
                    "created_time" => isset($msg["created"]) ? date("c", $msg["created"] / 1000) : null,
                    "from" => $sender ? [
                        "id" => $sender["entityUrn"],
                        "name" => ($sender["localizedFirstName"] ?? "") . " " . ($sender["localizedLastName"] ?? ""),
                        "avatar" => $sender["profilePicture"]["displayImage~"]["elements"][0]["identifiers"][0]["identifier"] ?? null,
                    ] : null,
                    "to" => [], // Not directly available in message object, inferred from conversation
                    "message" => $msg["body"]["text"] ?? null,
                    "attachments" => $msg["attachments"] ?? [],
                    "shares" => [], // Shares not typically part of message object
                    "sticker" => null, // No stickers on LinkedIn
                ];
            }, $messages);

            return [
                "platform" => "linkedin",
                "conversation_id" => $conversationId,
                "messages" => $formattedMessages,
                "next_cursor" => $nextCursor, // Timestamp for createdBefore
                "raw_response" => $data,
            ];
        } catch (GuzzleException $e) {
            throw new MessagingException("Failed to get LinkedIn messages: " . $e->getMessage());
        }
    }

    /**
     * Send a new message or reply to a conversation.
     *
     * @param string $accessToken User Access Token.
     * @param string $tokenSecret Ignored.
     * @param string $targetId Authenticated User URN (for context).
     * @param string $recipientId Conversation URN OR Recipient User URN (for new conversation).
     * @param string $message The text message content.
     * @param array $options Additional options (e.g., conversation_urn for reply).
     * @return array Returns array with platform_message_id.
     * @throws MessagingException
     */
    public function sendMessage(string $accessToken, string $tokenSecret, string $targetId, string $recipientId, string $message, array $options = []): array
    {
        // LinkedIn uses the same endpoint for new messages and replies.
        // If conversation_urn is provided in options, it's a reply.
        // If recipientId is a user URN, it starts a new conversation.
        try {
            $client = $this->getApiClient($accessToken);

            $payload = [
                "recipients" => [], // Will be populated based on context
                "body" => [
                    "text" => $message,
                ],
                // "sender" => $targetId, // Optional: Specify sender if needed
            ];

            if (isset($options["conversation_urn"])) {
                // Replying to an existing conversation
                $payload["conversation"] = $options["conversation_urn"];
                // Recipients are inferred from the conversation, but can be specified if needed
            } else {
                // Starting a new conversation
                // recipientId should be the User URN
                if (!str_contains($recipientId, "urn:li:person:")) {
                    throw new MessagingException("Recipient ID must be a valid User URN (urn:li:person:{id}) for starting a new conversation.");
                }
                $payload["recipients"] = [$recipientId];
            }

            // Add attachments if provided
            // Requires uploading media first and getting URNs - complex, omitted for brevity
            // if (isset($options["attachment_urns"])) {
            //     $payload["attachments"] = array_map(fn($urn) => ["entity" => $urn], $options["attachment_urns"]);
            // }

            $response = $client->post("messages", [
                "json" => $payload,
            ]);

            // Response contains the Location header with the new message URN
            $locationHeader = $response->getHeaderLine("Location");
            $messageUrn = $locationHeader ?: null;

            if (!$messageUrn) {
                // Fallback: Try parsing body if available (though 201 usually has empty body)
                $data = json_decode($response->getBody()->getContents(), true);
                $messageUrn = $data["id"] ?? null;
            }

            if (!$messageUrn) {
                throw new MessagingException("Failed to send LinkedIn message. No message URN returned.");
            }

            return [
                "platform" => "linkedin",
                "platform_message_id" => $messageUrn,
                "recipient_id" => $recipientId, // Contextual recipient
                "raw_response" => ["LocationHeader" => $locationHeader], // Minimal raw response
            ];
        } catch (GuzzleException $e) {
            throw new MessagingException("Failed to send LinkedIn message: " . $e->getMessage());
        }
    }

    /**
     * Reply to an existing conversation.
     *
     * @param string $accessToken User Access Token.
     * @param string $tokenSecret Ignored.
     * @param string $conversationId The Conversation URN.
     * @param string $message The text message content.
     * @param array $options Additional options.
     * @return array Returns array with platform_message_id.
     * @throws MessagingException
     */
    public function replyToConversation(string $accessToken, string $tokenSecret, string $conversationId, string $message, array $options = []): array
    {
        // Use sendMessage with the conversation_urn option
        $options["conversation_urn"] = $conversationId;
        // Target ID (sender) and recipient ID (conversation) are passed
        return $this->sendMessage($accessToken, $tokenSecret, "", $conversationId, $message, $options);
    }

    /**
     * Mark a conversation as read.
     *
     * @param string $accessToken User Access Token.
     * @param string $tokenSecret Ignored.
     * @param string $conversationId The Conversation URN.
     * @return bool Returns true on success.
     * @throws MessagingException
     */
    public function markConversationAsRead(string $accessToken, string $tokenSecret, string $conversationId): bool
    {
        try {
            $client = $this->getApiClient($accessToken);
            // LinkedIn uses a PATCH request with a specific JSON structure
            $payload = [
                "patch" => [
                    "$set" => [
                        "read" => true,
                    ],
                ],
            ];

            $response = $client->post("conversations/{$conversationId}", [
                "headers" => ["X-Restli-Method" => "PARTIAL_UPDATE"], // Crucial header for PATCH
                "json" => $payload,
            ]);

            // LinkedIn returns 204 No Content on success
            return $response->getStatusCode() === 204;
        } catch (GuzzleException $e) {
            throw new MessagingException("Failed to mark LinkedIn conversation as read: " . $e->getMessage());
        }
    }
}
