<?php

namespace VendorName\SocialConnect\Services\Instagram;

use GuzzleHttp\Client;
use VendorName\SocialConnect\Contracts\MessagingInterface;
use VendorName\SocialConnect\Exceptions\MessagingException;
use VendorName\SocialConnect\Models\SocialAccount;
use VendorName\SocialConnect\Models\SocialConversation;
use VendorName\SocialConnect\Models\SocialMessage;

class InstagramMessagingService implements MessagingInterface
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
     * Create a new InstagramMessagingService instance.
     *
     * @param \VendorName\SocialConnect\Models\SocialAccount $account
     */
    public function __construct(SocialAccount $account)
    {
        $this->account = $account;
        $this->client = new Client([
            'base_uri' => 'https://graph.facebook.com/v18.0/',
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
            $igAccountId = $this->getInstagramAccountId();
            $accessToken = $this->account->access_token;
            
            $params = [
                'fields' => 'id,participants,updated_time,messages{id,from,to,message,created_time}',
                'limit' => $limit,
            ];
            
            if ($cursor) {
                $params['after'] = $cursor;
            }
            
            $response = $this->client->get("{$igAccountId}/conversations", [
                'query' => array_merge($params, ['access_token' => $accessToken]),
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['data'])) {
                throw new MessagingException('Failed to retrieve conversations from Instagram.');
            }
            
            $conversations = [];
            $nextCursor = $data['paging']['cursors']['after'] ?? null;
            
            foreach ($data['data'] as $conversation) {
                // Get participant info
                $participants = $conversation['participants']['data'] ?? [];
                $recipientId = null;
                $recipientName = null;
                
                foreach ($participants as $participant) {
                    if ($participant['id'] !== $igAccountId) {
                        $recipientId = $participant['id'];
                        $recipientName = $participant['username'] ?? null;
                        break;
                    }
                }
                
                // Get last message for snippet
                $lastMessage = null;
                $unreadCount = 0;
                
                if (isset($conversation['messages']['data']) && !empty($conversation['messages']['data'])) {
                    $lastMessage = $conversation['messages']['data'][0]['message'] ?? null;
                    
                    // Count unread messages
                    foreach ($conversation['messages']['data'] as $message) {
                        if (($message['from']['id'] ?? null) !== $igAccountId) {
                            $unreadCount++;
                        }
                    }
                }
                
                // Store in database
                $socialConversation = SocialConversation::updateOrCreate(
                    [
                        'social_account_id' => $this->account->id,
                        'platform_conversation_id' => $conversation['id'],
                    ],
                    [
                        'user_id' => $this->account->user_id,
                        'platform' => 'instagram',
                        'recipient_id' => $recipientId,
                        'recipient_name' => $recipientName,
                        'last_message_at' => isset($conversation['updated_time']) ? new \DateTime($conversation['updated_time']) : now(),
                        'is_read' => $unreadCount === 0,
                        'metadata' => [
                            'unread_count' => $unreadCount,
                            'snippet' => $lastMessage,
                        ],
                    ]
                );
                
                $conversations[] = [
                    'id' => $socialConversation->id,
                    'platform_conversation_id' => $conversation['id'],
                    'recipient_id' => $recipientId,
                    'recipient_name' => $recipientName,
                    'last_message_at' => $conversation['updated_time'] ?? null,
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
            throw new MessagingException('Failed to get conversations from Instagram: ' . $e->getMessage());
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
            $igAccountId = $this->getInstagramAccountId();
            
            $params = [
                'fields' => 'messages{id,from,to,message,created_time,attachments}',
                'limit' => $limit,
            ];
            
            if ($cursor) {
                $params['after'] = $cursor;
            }
            
            $response = $this->client->get("{$conversationId}", [
                'query' => array_merge($params, ['access_token' => $accessToken]),
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['messages']['data'])) {
                throw new MessagingException('Failed to retrieve messages from Instagram.');
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
                    'platform' => 'instagram',
                    'platform_conversation_id' => $conversationId,
                    'last_message_at' => now(),
                ]);
            }
            
            $messages = [];
            $nextCursor = $data['messages']['paging']['cursors']['after'] ?? null;
            
            foreach ($data['messages']['data'] as $message) {
                $senderId = $message['from']['id'] ?? null;
                $senderName = $message['from']['username'] ?? null;
                $isFromMe = $senderId === $igAccountId;
                
                // Store in database
                $socialMessage = SocialMessage::updateOrCreate(
                    [
                        'social_conversation_id' => $socialConversation->id,
                        'platform_message_id' => $message['id'],
                    ],
                    [
                        'user_id' => $this->account->user_id,
                        'social_account_id' => $this->account->id,
                        'platform' => 'instagram',
                        'message' => $message['message'] ?? '',
                        'sender_id' => $senderId,
                        'sender_name' => $senderName,
                        'is_from_me' => $isFromMe,
                        'is_read' => true,
                        'attachments' => $message['attachments']['data'] ?? [],
                        'metadata' => [
                            'created_time' => $message['created_time'] ?? null,
                        ],
                    ]
                );
                
                $messages[] = [
                    'id' => $socialMessage->id,
                    'platform_message_id' => $message['id'],
                    'message' => $message['message'] ?? '',
                    'sender_id' => $senderId,
                    'sender_name' => $senderName,
                    'is_from_me' => $isFromMe,
                    'created_at' => $message['created_time'] ?? null,
                    'attachments' => $message['attachments']['data'] ?? [],
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
            throw new MessagingException('Failed to get messages from Instagram: ' . $e->getMessage());
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
            $igAccountId = $this->getInstagramAccountId();
            
            $payload = [
                'recipient' => [
                    'id' => $recipientId,
                ],
                'message' => [
                    'text' => $message,
                ],
            ];
            
            // Add attachments if provided
            if (isset($options['attachment'])) {
                $payload['message'] = [
                    'attachment' => $options['attachment'],
                ];
            }
            
            $response = $this->client->post("{$igAccountId}/messages", [
                'query' => ['access_token' => $accessToken],
                'json' => $payload,
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['message_id'])) {
                throw new MessagingException('Failed to send message to Instagram.');
            }
            
            // Find or create conversation
            $socialConversation = SocialConversation::where('recipient_id', $recipientId)
                ->where('social_account_id', $this->account->id)
                ->where('platform', 'instagram')
                ->first();
            
            if (!$socialConversation) {
                // Get recipient details
                $recipientResponse = $this->client->get($recipientId, [
                    'query' => [
                        'fields' => 'username,profile_pic',
                        'access_token' => $accessToken,
                    ],
                ]);
                
                $recipientData = json_decode($recipientResponse->getBody()->getContents(), true);
                $recipientName = $recipientData['username'] ?? null;
                $recipientAvatar = $recipientData['profile_pic'] ?? null;
                
                // Create new conversation
                $socialConversation = SocialConversation::create([
                    'user_id' => $this->account->user_id,
                    'social_account_id' => $this->account->id,
                    'platform' => 'instagram',
                    'platform_conversation_id' => $data['message_id'], // Temporary ID
                    'recipient_id' => $recipientId,
                    'recipient_name' => $recipientName,
                    'recipient_avatar' => $recipientAvatar,
                    'last_message_at' => now(),
                    'is_read' => true,
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
                'platform' => 'instagram',
                'platform_message_id' => $data['message_id'],
                'message' => $message,
                'sender_id' => $igAccountId,
                'sender_name' => $this->account->name,
                'is_from_me' => true,
                'is_read' => true,
                'attachments' => isset($options['attachment']) ? [$options['attachment']] : [],
            ]);
            
            return [
                'success' => true,
                'message_id' => $data['message_id'],
                'conversation_id' => $socialConversation->id,
                'recipient_id' => $recipientId,
            ];
        } catch (\Exception $e) {
            throw new MessagingException('Failed to send message to Instagram: ' . $e->getMessage());
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
            
            return $this->sendMessage($socialConversation->recipient_id, $message, $options);
        } catch (\Exception $e) {
            throw new MessagingException('Failed to reply to conversation on Instagram: ' . $e->getMessage());
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
            $accessToken = $this->account->access_token;
            $igAccountId = $this->getInstagramAccountId();
            
            $response = $this->client->post("{$igAccountId}/messages", [
                'query' => ['access_token' => $accessToken],
                'json' => [
                    'conversation_id' => $conversationId,
                    'read_watermark' => now()->timestamp,
                ],
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['success']) || !$data['success']) {
                throw new MessagingException('Failed to mark conversation as read on Instagram.');
            }
            
            // Update in database
            $socialConversation = SocialConversation::where('platform_conversation_id', $conversationId)
                ->where('social_account_id', $this->account->id)
                ->first();
            
            if ($socialConversation) {
                $socialConversation->update([
                    'is_read' => true,
                ]);
            }
            
            return true;
        } catch (\Exception $e) {
            throw new MessagingException('Failed to mark conversation as read on Instagram: ' . $e->getMessage());
        }
    }
    
    /**
     * Get the Instagram account ID from the account metadata.
     *
     * @return string
     * @throws \VendorName\SocialConnect\Exceptions\MessagingException
     */
    protected function getInstagramAccountId(): string
    {
        $metadata = $this->account->metadata;
        
        if (isset($metadata['instagram_business_account_id'])) {
            return $metadata['instagram_business_account_id'];
        }
        
        throw new MessagingException('Instagram account ID not found in account metadata.');
    }
}
