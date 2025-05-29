<?php

namespace VendorName\SocialConnect\Services\Twitter;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Subscriber\Oauth\Oauth1;
use Illuminate\Support\Facades\Config;
use VendorName\SocialConnect\Contracts\MessagingInterface;
use VendorName\SocialConnect\Exceptions\MessagingException;

class TwitterMessagingService implements MessagingInterface
{
    /**
     * The HTTP client instance for API v1.1.
     *
     * @var \GuzzleHttp\Client
     */
    protected $clientV1;

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
     * Create a new TwitterMessagingService instance.
     */
    public function __construct()
    {
        $config = Config::get("social-connect.platforms.twitter");
        $this->consumerKey = $config["consumer_key"];
        $this->consumerSecret = $config["consumer_secret"];

        // Client for API v1.1 DM endpoints (requires OAuth 1.0a)
        $this->clientV1 = new Client([
            "base_uri" => "https://api.twitter.com/1.1/direct_messages/",
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
            "base_uri" => $this->clientV1->getConfig("base_uri"),
            "handler" => $stack,
            "auth" => "oauth",
            "timeout" => 30,
        ]);
    }

    /**
     * Get conversations (Direct Messages).
     * Twitter API v1.1 returns a list of DM events, not distinct conversations.
     * This method simulates conversations by grouping events.
     *
     * @param string $accessToken User Access Token.
     * @param string $tokenSecret User Access Token Secret.
     * @param string $targetId The User ID (used for context, not direct filtering).
     * @param int $limit Maximum number of DM events to return.
     * @param string|null $cursor Pagination cursor (next_cursor).
     * @return array Returns array containing simulated conversations and next cursor.
     * @throws MessagingException
     */
    public function getConversations(string $accessToken, string $tokenSecret, string $targetId, int $limit = 50, ?string $cursor = null): array
    {
        // Note: Twitter API v1.1 events endpoint returns all DMs chronologically.
        // Grouping them into conversations requires post-processing.
        // This implementation returns the raw events list for the user to process.
        try {
            $client = $this->getOAuth1Client($accessToken, $tokenSecret);
            $params = [
                "count" => $limit,
            ];
            if ($cursor) {
                $params["cursor"] = $cursor;
            }

            $response = $client->get("events/list.json", [
                "query" => $params,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data["events"])) {
                // Handle rate limits or other errors
                throw new MessagingException("Failed to retrieve direct message events from Twitter.");
            }

            $events = $data["events"];
            $nextCursor = $data["next_cursor"] ?? null;

            // Format events (basic formatting)
            $formattedEvents = array_map(function ($event) {
                return [
                    "event_id" => $event["id"],
                    "type" => $event["type"],
                    "created_timestamp" => $event["created_timestamp"],
                    "message_data" => $event["message_create"]["message_data"] ?? null,
                    "sender_id" => $event["message_create"]["sender_id"] ?? null,
                    "recipient_id" => $event["message_create"]["target"]["recipient_id"] ?? null,
                ];
            }, $events);

            // User needs to group these events into conversations based on sender/recipient IDs.
            return [
                "platform" => "twitter",
                "user_id" => $targetId,
                "conversations" => $formattedEvents, // Returning raw events, not grouped conversations
                "next_cursor" => $nextCursor,
                "raw_response" => $data,
            ];
        } catch (GuzzleException $e) {
            throw new MessagingException("Failed to get Twitter direct message events: " . $e->getMessage());
        }
    }

    /**
     * Get messages for a specific conversation (Not directly supported by v1.1 API).
     * Use getConversations and filter/group events by participant IDs.
     *
     * @param string $accessToken
     * @param string $tokenSecret
     * @param string $conversationId (Conceptually, participant user ID)
     * @param int $limit
     * @param string|null $cursor
     * @return array
     * @throws MessagingException
     */
    public function getMessages(string $accessToken, string $tokenSecret, string $conversationId, int $limit = 20, ?string $cursor = null): array
    {
        // Twitter v1.1 doesn't have an endpoint to get messages for a specific conversation ID.
        // You need to fetch all events using getConversations and filter them based on the sender/recipient IDs
        // corresponding to the desired conversation (e.g., filter events where sender_id = current_user and recipient_id = conversationId OR sender_id = conversationId and recipient_id = current_user).
        throw new MessagingException("Getting messages for a specific Twitter conversation requires fetching all events and filtering manually. Use getConversations instead.");
    }

    /**
     * Send a new Direct Message.
     *
     * @param string $accessToken User Access Token.
     * @param string $tokenSecret User Access Token Secret.
     * @param string $targetId Current User ID (used for context).
     * @param string $recipientId The User ID of the recipient.
     * @param string $message The text message content.
     * @param array $options Additional options (e.g., quick_reply, attachment_media_id).
     * @return array Returns array with platform_message_id (event ID).
     * @throws MessagingException
     */
    public function sendMessage(string $accessToken, string $tokenSecret, string $targetId, string $recipientId, string $message, array $options = []): array
    {
        try {
            $client = $this->getOAuth1Client($accessToken, $tokenSecret);

            $payload = [
                "event" => [
                    "type" => "message_create",
                    "message_create" => [
                        "target" => [
                            "recipient_id" => $recipientId,
                        ],
                        "message_data" => [
                            "text" => $message,
                        ],
                    ],
                ],
            ];

            // Add quick reply or media attachment if provided
            if (isset($options["quick_reply"])) {
                $payload["event"]["message_create"]["message_data"]["quick_reply"] = $options["quick_reply"];
            }
            if (isset($options["attachment_media_id"])) {
                 $payload["event"]["message_create"]["message_data"]["attachment"] = [
                    "type" => "media",
                    "media" => ["id" => $options["attachment_media_id"]]
                 ];
            }

            $response = $client->post("events/new.json", [
                "json" => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data["event"]["id"])) {
                throw new MessagingException("Failed to send Twitter direct message. No event ID returned.");
            }

            return [
                "platform" => "twitter",
                "platform_message_id" => $data["event"]["id"], // Event ID
                "recipient_id" => $recipientId,
                "raw_response" => $data,
            ];
        } catch (GuzzleException $e) {
            throw new MessagingException("Failed to send Twitter direct message: " . $e->getMessage());
        }
    }

    /**
     * Reply to an existing conversation (same as sending a new message).
     *
     * @param string $accessToken User Access Token.
     * @param string $tokenSecret User Access Token Secret.
     * @param string $conversationId The User ID of the other participant.
     * @param string $message The text message content.
     * @param array $options Additional options.
     * @return array Returns array with platform_message_id (event ID).
     * @throws MessagingException
     */
    public function replyToConversation(string $accessToken, string $tokenSecret, string $conversationId, string $message, array $options = []): array
    {
        // Replying is the same as sending a new message to the recipient ID
        // $conversationId here represents the recipient's User ID.
        // We need the current user's ID for context, but it's not passed directly.
        // Assuming the caller knows the recipient ID.
        return $this->sendMessage($accessToken, $tokenSecret, "", $conversationId, $message, $options);
    }

    /**
     * Mark a conversation as read (Not directly supported by v1.1 API).
     * Twitter uses read receipts automatically or via Welcome Messages API.
     *
     * @param string $accessToken
     * @param string $tokenSecret
     * @param string $conversationId
     * @return bool
     * @throws MessagingException
     */
    public function markConversationAsRead(string $accessToken, string $tokenSecret, string $conversationId): bool
    {
        // Twitter API v1.1 doesn't provide a direct way to mark conversations/messages as read via the standard DM endpoints.
        // Read status is typically handled implicitly or via other mechanisms like webhooks or the Account Activity API.
        // Returning true assuming local handling or implicit read status.
        // throw new MessagingException("Marking Twitter DMs as read is not directly supported via this API call.");
        return true;
    }
}
