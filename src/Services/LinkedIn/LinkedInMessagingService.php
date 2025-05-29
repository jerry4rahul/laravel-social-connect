<?php

namespace VendorName\SocialConnect\Services\LinkedIn;

use GuzzleHttp\Client;
use VendorName\SocialConnect\Contracts\MessagingInterface;
use VendorName\SocialConnect\Exceptions\MessagingException;
use VendorName\SocialConnect\Models\SocialAccount;
use VendorName\SocialConnect\Models\SocialConversation;
use VendorName\SocialConnect\Models\SocialMessage;

class LinkedInMessagingService implements MessagingInterface
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
     * Create a new LinkedInMessagingService instance.
     *
     * @param \VendorName\SocialConnect\Models\SocialAccount $account
     */
    public function __construct(SocialAccount $account)
    {
        $this->account = $account;
        $this->client = new Client([
            'base_uri' => 'https://api.linkedin.com/v2/',
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
                'count' => $limit,
                'q' => 'findConversations',
            ];
            
            if ($cursor) {
                $params['start'] = $cursor;
            }
            
            $response = $this->client->get('messaging/conversations', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'X-Restli-Protocol-Version' => '2.0.0',
                ],
                'query' => $params,
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['elements'])) {
                throw new MessagingException('Failed to retrieve conversations from LinkedIn.');
            }
            
            $conversations = [];
            $nextCursor = null;
            
            // Check if there are more conversations
            if (isset($data['paging']) && isset($data['paging']['count']) && isset($data['paging']['start']) && count($data['elements']) >= $data['paging']['count']) {
                $nextCursor = $data['paging']['start'] + $data['paging']['count'];
            }
            
            foreach ($data['elements'] as $conversation) {
                $conversationId = $conversation['entityUrn'] ?? null;
                if (!$conversationId) {
                    continue;
                }
                
                // Extract the conversation ID from the URN
                $conversationId = str_replace('urn:li:messaging:conversation:', '', $conversationId);
                
                // Get participants
                $participants = $conversation['participants'] ?? [];
                $recipientId = null;
                $recipientName = null;
                
                foreach ($participants as $participant) {
                    if ($participant['entityUrn'] !== 'urn:li:person:' . $this->getUserId()) {
                        $recipientId = str_replace('urn:li:person:', '', $participant['entityUrn']);
                        
                        // Get recipient profile
                        try {
                            $profileResponse = $this->client->get('people/' . $recipientId, [
                                'headers' => [
                                    'Authorization' => 'Bearer ' . $accessToken,
                                ],
                                'query' => [
                                    'projection' => '(id,firstName,lastName,profilePicture)',
                                ],
                            ]);
                            
                            $profileData = json_decode($profileResponse->getBody()->getContents(), true);
                            
                            $firstName = $profileData['firstName']['localized']['en_US'] ?? '';
                            $lastName = $profileData['lastName']['localized']['en_US'] ?? '';
                            $recipientName = trim($firstName . ' ' . $lastName);
                            
                            $recipientAvatar = null;
                            if (isset($profileData['profilePicture']['displayImage~']['elements'][0]['identifiers'][0]['identifier'])) {
                                $recipientAvatar = $profileData['profilePicture']['displayImage~']['elements'][0]['identifiers'][0]['identifier'];
                            }
                        } catch (\Exception $e) {
                            // Ignore profile fetch errors
                        }
                        
                        break;
                    }
                }
                
                // Get last message
                $lastMessage = null;
                $lastMessageTime = null;
                $unreadCount = 0;
                
                if (isset($conversation['events']) && !empty($conversation['events'])) {
                    $lastEvent = $conversation['events'][0];
                    
                    if (isset($lastEvent['eventContent']['com.linkedin.voyager.messaging.event.MessageEvent'])) {
                        $messageEvent = $lastEvent['eventContent']['com.linkedin.voyager.messaging.event.MessageEvent'];
                        $lastMessage = $messageEvent['body'] ?? null;
                        $lastMessageTime = $lastEvent['createdAt'] ?? null;
                        
                        // Check if message is read
                        if (isset($lastEvent['fromEntity']) && $lastEvent['fromEntity'] !== 'urn:li:person:' . $this->getUserId()) {
                            if (!isset($lastEvent['read']) || !$lastEvent['read']) {
                                $unreadCount++;
                            }
                        }
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
                        'platform' => 'linkedin',
                        'recipient_id' => $recipientId,
                        'recipient_name' => $recipientName,
                        'recipient_avatar' => $recipientAvatar ?? null,
                        'last_message_at' => $lastMessageTime ? new \DateTime('@' . ($lastMessageTime / 1000)) : now(),
                        'is_read' => $unreadCount === 0,
                        'metadata' => [
                            'unread_count' => $unreadCount,
                            'snippet' => $lastMessage,
                        ],
                    ]
                );
                
                $conversations[] = [
                    'id' => $socialConversation->id,
                    'platform_conversation_id' => $conversationId,
                    'recipient_id' => $recipientId,
                    'recipient_name' => $recipientName,
                    'recipient_avatar' => $recipientAvatar ?? null,
                    'last_message_at' => $lastMessageTime ? date('c', $lastMessageTime / 1000) : null,
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
            throw new MessagingException('Failed to get conversations from LinkedIn: ' . $e->getMessage());
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
                'count' => $limit,
                'q' => 'conversation',
            ];
            
            if ($cursor) {
                $params['start'] = $cursor;
            }
            
            $response = $this->client->get('messaging/conversations/' . $conversationId . '/events', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'X-Restli-Protocol-Version' => '2.0.0',
                ],
                'query' => $params,
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['elements'])) {
                throw new MessagingException('Failed to retrieve messages from LinkedIn.');
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
                    'platform' => 'linkedin',
                    'platform_conversation_id' => $conversationId,
                    'last_message_at' => now(),
                ]);
            }
            
            $messages = [];
            $nextCursor = null;
            
            // Check if there are more messages
            if (isset($data['paging']) && isset($data['paging']['count']) && isset($data['paging']['start']) && count($data['elements']) >= $data['paging']['count']) {
                $nextCursor = $data['paging']['start'] + $data['paging']['count'];
            }
            
            foreach ($data['elements'] as $event) {
                // Only process message events
                if (!isset($event['eventContent']['com.linkedin.voyager.messaging.event.MessageEvent'])) {
                    continue;
                }
                
                $messageEvent = $event['eventContent']['com.linkedin.voyager.messaging.event.MessageEvent'];
                $messageId = $event['entityUrn'] ?? null;
                if (!$messageId) {
                    continue;
                }
                
                // Extract the message ID from the URN
                $messageId = str_replace('urn:li:messagingEvent:', '', $messageId);
                
                $messageText = $messageEvent['body'] ?? '';
                $senderId = null;
                if (isset($event['fromEntity'])) {
                    $senderId = str_replace('urn:li:person:', '', $event['fromEntity']);
                }
                
                $createdAt = $event['createdAt'] ?? null;
                $isFromMe = $senderId === $userId;
                
                // Get sender details
                $senderName = null;
                if ($isFromMe) {
                    $senderName = $this->account->name;
                } else if ($senderId) {
                    try {
                        $profileResponse = $this->client->get('people/' . $senderId, [
                            'headers' => [
                                'Authorization' => 'Bearer ' . $accessToken,
                            ],
                            'query' => [
                                'projection' => '(id,firstName,lastName)',
                            ],
                        ]);
                        
                        $profileData = json_decode($profileResponse->getBody()->getContents(), true);
                        
                        $firstName = $profileData['firstName']['localized']['en_US'] ?? '';
                        $lastName = $profileData['lastName']['localized']['en_US'] ?? '';
                        $senderName = trim($firstName . ' ' . $lastName);
                    } catch (\Exception $e) {
                        // Ignore profile fetch errors
                    }
                }
                
                // Process attachments
                $attachments = [];
                
                if (isset($messageEvent['attachments']) && !empty($messageEvent['attachments'])) {
                    foreach ($messageEvent['attachments'] as $attachment) {
                        if (isset($attachment['reference'])) {
                            $attachments[] = [
                                'type' => $attachment['reference']['com.linkedin.voyager.messaging.MessagingAttachmentReference']['type'] ?? 'unknown',
                                'name' => $attachment['reference']['com.linkedin.voyager.messaging.MessagingAttachmentReference']['name'] ?? null,
                                'size' => $attachment['reference']['com.linkedin.voyager.messaging.MessagingAttachmentReference']['size'] ?? null,
                                'url' => $attachment['reference']['com.linkedin.voyager.messaging.MessagingAttachmentReference']['url'] ?? null,
                            ];
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
                        'platform' => 'linkedin',
                        'message' => $messageText,
                        'sender_id' => $senderId,
                        'sender_name' => $senderName,
                        'is_from_me' => $isFromMe,
                        'is_read' => true,
                        'attachments' => $attachments,
                        'metadata' => [
                            'created_at' => $createdAt ? date('c', $createdAt / 1000) : null,
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
                    'created_at' => $createdAt ? date('c', $createdAt / 1000) : null,
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
            throw new MessagingException('Failed to get messages from LinkedIn: ' . $e->getMessage());
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
            
            // Create conversation if it doesn't exist
            $conversationResponse = $this->client->post('messaging/conversations', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'X-Restli-Protocol-Version' => '2.0.0',
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'recipients' => [
                        'urn:li:person:' . $recipientId,
                    ],
                ],
            ]);
            
            $conversationData = json_decode($conversationResponse->getBody()->getContents(), true);
            
            if (!isset($conversationData['entityUrn'])) {
                throw new MessagingException('Failed to create conversation on LinkedIn.');
            }
            
            $conversationId = str_replace('urn:li:messaging:conversation:', '', $conversationData['entityUrn']);
            
            // Send message
            $payload = [
                'com.linkedin.voyager.messaging.create.MessageCreate' => [
                    'body' => $message,
                    'attachments' => [],
                    'attributedBody' => [
                        'text' => $message,
                        'attributes' => [],
                    ],
                    'messageRequestContextUrn' => null,
                ],
            ];
            
            // Add attachments if provided
            if (isset($options['attachment_id'])) {
                $payload['com.linkedin.voyager.messaging.create.MessageCreate']['attachments'][] = [
                    'reference' => $options['attachment_id'],
                ];
            }
            
            $response = $this->client->post('messaging/conversations/' . $conversationId . '/events', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'X-Restli-Protocol-Version' => '2.0.0',
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['entityUrn'])) {
                throw new MessagingException('Failed to send message to LinkedIn.');
            }
            
            $messageId = str_replace('urn:li:messagingEvent:', '', $data['entityUrn']);
            
            // Get recipient details
            $recipientName = null;
            $recipientAvatar = null;
            
            try {
                $profileResponse = $this->client->get('people/' . $recipientId, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $accessToken,
                    ],
                    'query' => [
                        'projection' => '(id,firstName,lastName,profilePicture)',
                    ],
                ]);
                
                $profileData = json_decode($profileResponse->getBody()->getContents(), true);
                
                $firstName = $profileData['firstName']['localized']['en_US'] ?? '';
                $lastName = $profileData['lastName']['localized']['en_US'] ?? '';
                $recipientName = trim($firstName . ' ' . $lastName);
                
                if (isset($profileData['profilePicture']['displayImage~']['elements'][0]['identifiers'][0]['identifier'])) {
                    $recipientAvatar = $profileData['profilePicture']['displayImage~']['elements'][0]['identifiers'][0]['identifier'];
                }
            } catch (\Exception $e) {
                // Ignore profile fetch errors
            }
            
            // Find or create conversation
            $socialConversation = SocialConversation::where('platform_conversation_id', $conversationId)
                ->where('social_account_id', $this->account->id)
                ->first();
            
            if (!$socialConversation) {
                // Create new conversation
                $socialConversation = SocialConversation::create([
                    'user_id' => $this->account->user_id,
                    'social_account_id' => $this->account->id,
                    'platform' => 'linkedin',
                    'platform_conversation_id' => $conversationId,
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
                'platform' => 'linkedin',
                'platform_message_id' => $messageId,
                'message' => $message,
                'sender_id' => $this->getUserId(),
                'sender_name' => $this->account->name,
                'is_from_me' => true,
                'is_read' => true,
                'attachments' => isset($options['attachment_id']) ? [['id' => $options['attachment_id']]] : [],
            ]);
            
            return [
                'success' => true,
                'message_id' => $messageId,
                'conversation_id' => $socialConversation->id,
                'platform_conversation_id' => $conversationId,
                'recipient_id' => $recipientId,
            ];
        } catch (\Exception $e) {
            throw new MessagingException('Failed to send message to LinkedIn: ' . $e->getMessage());
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
            $accessToken = $this->account->access_token;
            
            // Send message
            $payload = [
                'com.linkedin.voyager.messaging.create.MessageCreate' => [
                    'body' => $message,
                    'attachments' => [],
                    'attributedBody' => [
                        'text' => $message,
                        'attributes' => [],
                    ],
                    'messageRequestContextUrn' => null,
                ],
            ];
            
            // Add attachments if provided
            if (isset($options['attachment_id'])) {
                $payload['com.linkedin.voyager.messaging.create.MessageCreate']['attachments'][] = [
                    'reference' => $options['attachment_id'],
                ];
            }
            
            $response = $this->client->post('messaging/conversations/' . $conversationId . '/events', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'X-Restli-Protocol-Version' => '2.0.0',
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['entityUrn'])) {
                throw new MessagingException('Failed to reply to conversation on LinkedIn.');
            }
            
            $messageId = str_replace('urn:li:messagingEvent:', '', $data['entityUrn']);
            
            // Get the conversation from database
            $socialConversation = SocialConversation::where('platform_conversation_id', $conversationId)
                ->where('social_account_id', $this->account->id)
                ->first();
            
            if (!$socialConversation) {
                throw new MessagingException('Conversation not found.');
            }
            
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
                'platform' => 'linkedin',
                'platform_message_id' => $messageId,
                'message' => $message,
                'sender_id' => $this->getUserId(),
                'sender_name' => $this->account->name,
                'is_from_me' => true,
                'is_read' => true,
                'attachments' => isset($options['attachment_id']) ? [['id' => $options['attachment_id']]] : [],
            ]);
            
            return [
                'success' => true,
                'message_id' => $messageId,
                'conversation_id' => $socialConversation->id,
                'platform_conversation_id' => $conversationId,
                'recipient_id' => $socialConversation->recipient_id,
            ];
        } catch (\Exception $e) {
            throw new MessagingException('Failed to reply to conversation on LinkedIn: ' . $e->getMessage());
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
            
            $response = $this->client->post('messaging/conversations/' . $conversationId . '/receipts', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'X-Restli-Protocol-Version' => '2.0.0',
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'read' => true,
                ],
            ]);
            
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
            throw new MessagingException('Failed to mark conversation as read on LinkedIn: ' . $e->getMessage());
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
        
        throw new MessagingException('LinkedIn user ID not found in account metadata.');
    }
}
