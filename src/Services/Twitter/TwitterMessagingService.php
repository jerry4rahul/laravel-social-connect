<?php

namespace VendorName\SocialConnect\Services\Twitter;

use GuzzleHttp\Client;
use VendorName\SocialConnect\Contracts\MessagingInterface;
use VendorName\SocialConnect\Exceptions\MessagingException;
use VendorName\SocialConnect\Models\SocialAccount;
use VendorName\SocialConnect\Models\SocialConversation;
use VendorName\SocialConnect\Models\SocialMessage;

class TwitterMessagingService implements MessagingInterface
{
    /**
     * The HTTP client instance.
     *
     * @var \GuzzleHttp\Client
     */
    protected $client;

    /**
     * The social account instance.
     *
     * @var \VendorName\SocialConnect\Models\SocialAccount
     */
    protected $account;

    /**
     * Create a new TwitterMessagingService instance.
     *
     * @param \VendorName\SocialConnect\Models\SocialAccount $account
     */
    public function __construct(SocialAccount $account)
    {
        $this->account = $account;
        $this->client = new Client([
            'base_uri' => 'https://api.twitter.com/',
            'timeout' => 30,
        ]);
    }

    /**
     * Get conversations for the account.
     *
     * @param int $limit
     * @param string|null $cursor
     * @return array
     * @throws \VendorName\SocialConnect\Exceptions\MessagingException
     */
    public function getConversations(int $limit = 20, ?string $cursor = null): array
    {
        try {
            $accessToken = $this->account->access_token;
            
            $params = [
                'max_results' => $limit,
            ];
            
            if ($cursor) {
                $params['pagination_token'] = $cursor;
            }
            
            $response = $this->client->get('2/dm_conversations/with', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
                'query' => $params,
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['data'])) {
                throw new MessagingException('Failed to retrieve conversations from Twitter.');
            }
            
            $conversations = [];
            $nextCursor = $data['meta']['next_token'] ?? null;
            
            foreach ($data['data'] as $conversation) {
                $conversationId = $conversation['dm_conversation_id'];
                
                // Get participant info
                $participantId = $conversation['participant_id'];
                
                // Get user details
                $userResponse = $this->client->get("2/users/{$participantId}", [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $accessToken,
                    ],
                    'query' => [
                        'user.fields' => 'name,username,profile_image_url',
                    ],
                ]);
                
                $userData = json_decode($userResponse->getBody()->getContents(), true);
                $recipientName = $userData['data']['name'] ?? null;
                $recipientUsername = $userData['data']['username'] ?? null;
                $recipientAvatar = $userData['data']['profile_image_url'] ?? null;
                
                // Get last message
                $messagesResponse = $this->client->get("2/dm_conversations/{$conversationId}/dm_events", [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $accessToken,
                    ],
                    'query' => [
                        'max_results' => 1,
                        'event_types' => 'MessageCreate',
                    ],
                ]);
                
                $messagesData = json_decode($messagesResponse->getBody()->getContents(), true);
                $lastMessage = null;
                $lastMessageTime = null;
                $unreadCount = 0;
                
                if (isset($messagesData['data']) && !empty($messagesData['data'])) {
                    $lastMessageEvent = $messagesData['data'][0];
                    $lastMessage = $lastMessageEvent['text'] ?? null;
                    $lastMessageTime = $lastMessageEvent['created_at'] ?? null;
                    
                    // Check if message is read
                    if (isset($lastMessageEvent['sender_id']) && $lastMessageEvent['sender_id'] !== $this->getUserId()) {
                        $unreadCount = 1;
                    }
                }
                
                // Store in database
                $socialConversation = SocialConversation::updateOrCreate(
                    [
                        'social_account_id' => $this->account->id,
                        'platform_conversation_id' => $conversationId,
                    ],
                    [
                        'user_id' => $this->account->user_id,
                        'platform' => 'twitter',
                        'recipient_id' => $participantId,
                        'recipient_name' => $recipientName,
                        'recipient_avatar' => $recipientAvatar,
                        'last_message_at' => $lastMessageTime ? new \DateTime($lastMessageTime) : now(),
                        'is_read' => $unreadCount === 0,
                        'metadata' => [
                            'recipient_username' => $recipientUsername,
                            'unread_count' => $unreadCount,
                            'snippet' => $lastMessage,
                        ],
                    ]
                );
                
                $conversations[] = [
                    'id' => $socialConversation->id,
                    'platform_conversation_id' => $conversationId,
                    'recipient_id' => $participantId,
                    'recipient_name' => $recipientName,
                    'recipient_username' => $recipientUsername,
                    'recipient_avatar' => $recipientAvatar,
                    'last_message_at' => $lastMessageTime,
                    'is_read' => $unreadCount === 0,
                    'snippet' => $lastMessage,
                    'unread_count' => $unreadCount,
                ];
            }
            
            return [
                'conversations' => $conversations,
                'next_cursor' => $nextCursor,
            ];
        } catch (\Exception $e) {
            throw new MessagingException('Failed to get conversations from Twitter: ' . $e->getMessage());
        }
    }
    
    /**
     * Get messages for a specific conversation.
     *
     * @param string $conversationId
     * @param int $limit
     * @param string|null $cursor
     * @return array
     * @throws \VendorName\SocialConnect\Exceptions\MessagingException
     */
    public function getMessages(string $conversationId, int $limit = 20, ?string $cursor = null): array
    {
        try {
            $accessToken = $this->account->access_token;
            $userId = $this->getUserId();
            
            $params = [
                'max_results' => $limit,
                'event_types' => 'MessageCreate',
            ];
            
            if ($cursor) {
                $params['pagination_token'] = $cursor;
            }
            
            $response = $this->client->get("2/dm_conversations/{$conversationId}/dm_events", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
                'query' => $params,
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['data'])) {
                throw new MessagingException('Failed to retrieve messages from Twitter.');
            }
            
            // Get the conversation from database
            $socialConversation = SocialConversation::where('platform_conversation_id', $conversationId)
                ->where('social_account_id', $this->account->id)
                ->first();
            
            if (!$socialConversation) {
                // Create the conversation if it doesn't exist
                $socialConversation = SocialConversation::create([
                    'user_id' => $this->account->user_id,
                    'social_account_id' => $this->account->id,
                    'platform' => 'twitter',
                    'platform_conversation_id' => $conversationId,
                    'last_message_at' => now(),
                ]);
            }
            
            $messages = [];
            $nextCursor = $data['meta']['next_token'] ?? null;
            
            foreach ($data['data'] as $message) {
                if ($message['event_type'] !== 'MessageCreate') {
                    continue;
                }
                
                $messageId = $message['id'];
                $messageText = $message['text'] ?? '';
                $senderId = $message['sender_id'] ?? null;
                $createdAt = $message['created_at'] ?? null;
                $isFromMe = $senderId === $userId;
                
                // Get sender details
                $senderName = null;
                
                if (isset($data['includes']['users'])) {
                    foreach ($data['includes']['users'] as $user) {
                        if ($user['id'] === $senderId) {
                            $senderName = $user['name'] ?? null;
                            break;
                        }
                    }
                }
                
                // Process attachments
                $attachments = [];
                
                if (isset($message['attachments'])) {
                    foreach ($message['attachments'] as $attachment) {
                        if (isset($attachment['media_key']) && isset($data['includes']['media'])) {
                            foreach ($data['includes']['media'] as $media) {
                                if ($media['media_key'] === $attachment['media_key']) {
                                    $attachments[] = [
                                        'type' => $media['type'],
                                        'url' => $media['url'] ?? null,
                                        'preview_url' => $media['preview_image_url'] ?? null,
                                    ];
                                    break;
                                }
                            }
                        }
                    }
                }
                
                // Store in database
                $socialMessage = SocialMessage::updateOrCreate(
                    [
                        'social_conversation_id' => $socialConversation->id,
                        'platform_message_id' => $messageId,
                    ],
                    [
                        'user_id' => $this->account->user_id,
                        'social_account_id' => $this->account->id,
                        'platform' => 'twitter',
                        'message' => $messageText,
                        'sender_id' => $senderId,
                        'sender_name' => $senderName,
                        'is_from_me' => $isFromMe,
                        'is_read' => true,
                        'attachments' => $attachments,
                        'metadata' => [
                            'created_at' => $createdAt,
                        ],
                    ]
                );
                
                $messages[] = [
                    'id' => $socialMessage->id,
                    'platform_message_id' => $messageId,
                    'message' => $messageText,
                    'sender_id' => $senderId,
                    'sender_name' => $senderName,
                    'is_from_me' => $isFromMe,
                    'created_at' => $createdAt,
                    'attachments' => $attachments,
                ];
            }
            
            // Mark conversation as read
            $socialConversation->update([
                'is_read' => true,
                'last_message_at' => now(),
            ]);
            
            return [
                'conversation_id' => $socialConversation->id,
                'platform_conversation_id' => $conversationId,
                'messages' => $messages,
                'next_cursor' => $nextCursor,
            ];
        } catch (\Exception $e) {
            throw new MessagingException('Failed to get messages from Twitter: ' . $e->getMessage());
        }
    }
    
    /**
     * Send a new message to a recipient.
     *
     * @param string $recipientId
     * @param string $message
     * @param array $options
     * @return array
     * @throws \VendorName\SocialConnect\Exceptions\MessagingException
     */
    public function sendMessage(string $recipientId, string $message, array $options = []): array
    {
        try {
            $accessToken = $this->account->access_token;
            
            $payload = [
                'text' => $message,
            ];
            
            // Add attachments if provided
            if (isset($options['media_id'])) {
                $payload['attachments'] = [
                    'media_ids' => [$options['media_id']],
                ];
            }
            
            $response = $this->client->post("2/dm_conversations/with/{$recipientId}/messages", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['data']['dm_conversation_id'])) {
                throw new MessagingException('Failed to send message to Twitter.');
            }
            
            $conversationId = $data['data']['dm_conversation_id'];
            $messageId = $data['data']['dm_event_id'];
            
            // Find or create conversation
            $socialConversation = SocialConversation::where('platform_conversation_id', $conversationId)
                ->where('social_account_id', $this->account->id)
                ->first();
            
            if (!$socialConversation) {
                // Get recipient details
                $recipientResponse = $this->client->get("2/users/{$recipientId}", [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $accessToken,
                    ],
                    'query' => [
                        'user.fields' => 'name,username,profile_image_url',
                    ],
                ]);
                
                $recipientData = json_decode($recipientResponse->getBody()->getContents(), true);
                $recipientName = $recipientData['data']['name'] ?? null;
                $recipientUsername = $recipientData['data']['username'] ?? null;
                $recipientAvatar = $recipientData['data']['profile_image_url'] ?? null;
                
                // Create new conversation
                $socialConversation = SocialConversation::create([
                    'user_id' => $this->account->user_id,
                    'social_account_id' => $this->account->id,
                    'platform' => 'twitter',
                    'platform_conversation_id' => $conversationId,
                    'recipient_id' => $recipientId,
                    'recipient_name' => $recipientName,
                    'recipient_avatar' => $recipientAvatar,
                    'last_message_at' => now(),
                    'is_read' => true,
                    'metadata' => [
                        'recipient_username' => $recipientUsername,
                    ],
                ]);
            } else {
                // Update existing conversation
                $socialConversation->update([
                    'last_message_at' => now(),
                    'is_read' => true,
                ]);
            }
            
            // Store message
            $socialMessage = SocialMessage::create([
                'user_id' => $this->account->user_id,
                'social_account_id' => $this->account->id,
                'social_conversation_id' => $socialConversation->id,
                'platform' => 'twitter',
                'platform_message_id' => $messageId,
                'message' => $message,
                'sender_id' => $this->getUserId(),
                'sender_name' => $this->account->name,
                'is_from_me' => true,
                'is_read' => true,
                'attachments' => isset($options['media_id']) ? [['media_id' => $options['media_id']]] : [],
            ]);
            
            return [
                'success' => true,
                'message_id' => $messageId,
                'conversation_id' => $socialConversation->id,
                'platform_conversation_id' => $conversationId,
                'recipient_id' => $recipientId,
            ];
        } catch (\Exception $e) {
            throw new MessagingException('Failed to send message to Twitter: ' . $e->getMessage());
        }
    }
    
    /**
     * Reply to an existing conversation.
     *
     * @param string $conversationId
     * @param string $message
     * @param array $options
     * @return array
     * @throws \VendorName\SocialConnect\Exceptions\MessagingException
     */
    public function replyToConversation(string $conversationId, string $message, array $options = []): array
    {
        try {
            $socialConversation = SocialConversation::where('platform_conversation_id', $conversationId)
                ->where('social_account_id', $this->account->id)
                ->first();
            
            if (!$socialConversation) {
                throw new MessagingException('Conversation not found.');
            }
            
            $accessToken = $this->account->access_token;
            
            $payload = [
                'text' => $message,
            ];
            
            // Add attachments if provided
            if (isset($options['media_id'])) {
                $payload['attachments'] = [
                    'media_ids' => [$options['media_id']],
                ];
            }
            
            $response = $this->client->post("2/dm_conversations/{$conversationId}/messages", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['data']['dm_event_id'])) {
                throw new MessagingException('Failed to reply to conversation on Twitter.');
            }
            
            $messageId = $data['data']['dm_event_id'];
            
            // Update conversation
            $socialConversation->update([
                'last_message_at' => now(),
                'is_read' => true,
            ]);
            
            // Store message
            $socialMessage = SocialMessage::create([
                'user_id' => $this->account->user_id,
                'social_account_id' => $this->account->id,
                'social_conversation_id' => $socialConversation->id,
                'platform' => 'twitter',
                'platform_message_id' => $messageId,
                'message' => $message,
                'sender_id' => $this->getUserId(),
                'sender_name' => $this->account->name,
                'is_from_me' => true,
                'is_read' => true,
                'attachments' => isset($options['media_id']) ? [['media_id' => $options['media_id']]] : [],
            ]);
            
            return [
                'success' => true,
                'message_id' => $messageId,
                'conversation_id' => $socialConversation->id,
                'platform_conversation_id' => $conversationId,
                'recipient_id' => $socialConversation->recipient_id,
            ];
        } catch (\Exception $e) {
            throw new MessagingException('Failed to reply to conversation on Twitter: ' . $e->getMessage());
        }
    }
    
    /**
     * Mark a conversation as read.
     *
     * @param string $conversationId
     * @return bool
     * @throws \VendorName\SocialConnect\Exceptions\MessagingException
     */
    public function markConversationAsRead(string $conversationId): bool
    {
        try {
            // Twitter API v2 doesn't have a specific endpoint to mark conversations as read
            // We'll update our local database to reflect this
            
            $socialConversation = SocialConversation::where('platform_conversation_id', $conversationId)
                ->where('social_account_id', $this->account->id)
                ->first();
            
            if ($socialConversation) {
                $socialConversation->update([
                    'is_read' => true,
                ]);
                
                return true;
            }
            
            return false;
        } catch (\Exception $e) {
            throw new MessagingException('Failed to mark conversation as read on Twitter: ' . $e->getMessage());
        }
    }
    
    /**
     * Get the user ID from the account metadata.
     *
     * @return string
     * @throws \VendorName\SocialConnect\Exceptions\MessagingException
     */
    protected function getUserId(): string
    {
        $metadata = $this->account->metadata;
        
        if (isset($metadata['id'])) {
            return $metadata['id'];
        }
        
        throw new MessagingException('Twitter user ID not found in account metadata.');
    }
}
