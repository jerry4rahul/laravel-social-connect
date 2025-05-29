<?php

namespace VendorName\SocialConnect\Services\YouTube;

use GuzzleHttp\Client;
use VendorName\SocialConnect\Contracts\MessagingInterface;
use VendorName\SocialConnect\Exceptions\MessagingException;
use VendorName\SocialConnect\Models\SocialAccount;
use VendorName\SocialConnect\Models\SocialConversation;
use VendorName\SocialConnect\Models\SocialMessage;

class YouTubeMessagingService implements MessagingInterface
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
     * Create a new YouTubeMessagingService instance.
     *
     * @param \VendorName\SocialConnect\Models\SocialAccount $account
     */
    public function __construct(SocialAccount $account)
    {
        $this->account = $account;
        $this->client = new Client([
            'base_uri' => 'https://www.googleapis.com/youtube/v3/',
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
                'part' => 'snippet',
                'maxResults' => $limit,
            ];
            
            if ($cursor) {
                $params['pageToken'] = $cursor;
            }
            
            // YouTube API doesn't have direct messaging like other platforms
            // We'll use the liveChatMessages endpoint to get chat messages from live streams
            $response = $this->client->get('liveChat/messages', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
                'query' => $params,
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['items'])) {
                throw new MessagingException('Failed to retrieve conversations from YouTube.');
            }
            
            $conversations = [];
            $nextCursor = $data['nextPageToken'] ?? null;
            
            // Group messages by author to create "conversations"
            $groupedMessages = [];
            
            foreach ($data['items'] as $message) {
                $authorId = $message['snippet']['authorChannelId'] ?? null;
                if (!$authorId) {
                    continue;
                }
                
                if (!isset($groupedMessages[$authorId])) {
                    $groupedMessages[$authorId] = [
                        'authorId' => $authorId,
                        'authorName' => $message['snippet']['authorDisplayName'] ?? null,
                        'authorProfileImage' => $message['snippet']['authorProfileImageUrl'] ?? null,
                        'lastMessage' => $message['snippet']['displayMessage'] ?? null,
                        'lastMessageTime' => $message['snippet']['publishedAt'] ?? null,
                        'liveChatId' => $message['snippet']['liveChatId'] ?? null,
                    ];
                } else if (strtotime($message['snippet']['publishedAt']) > strtotime($groupedMessages[$authorId]['lastMessageTime'])) {
                    $groupedMessages[$authorId]['lastMessage'] = $message['snippet']['displayMessage'] ?? null;
                    $groupedMessages[$authorId]['lastMessageTime'] = $message['snippet']['publishedAt'] ?? null;
                }
            }
            
            foreach ($groupedMessages as $authorId => $messageGroup) {
                // Create a unique conversation ID
                $conversationId = $messageGroup['liveChatId'] . '_' . $authorId;
                
                // Store in database
                $socialConversation = SocialConversation::updateOrCreate(
                    [
                        'social_account_id' => $this->account->id,
                        'platform_conversation_id' => $conversationId,
                    ],
                    [
                        'user_id' => $this->account->user_id,
                        'platform' => 'youtube',
                        'recipient_id' => $authorId,
                        'recipient_name' => $messageGroup['authorName'],
                        'recipient_avatar' => $messageGroup['authorProfileImage'],
                        'last_message_at' => new \DateTime($messageGroup['lastMessageTime']),
                        'is_read' => true, // YouTube doesn't have read/unread status
                        'metadata' => [
                            'live_chat_id' => $messageGroup['liveChatId'],
                            'snippet' => $messageGroup['lastMessage'],
                        ],
                    ]
                );
                
                $conversations[] = [
                    'id' => $socialConversation->id,
                    'platform_conversation_id' => $conversationId,
                    'recipient_id' => $authorId,
                    'recipient_name' => $messageGroup['authorName'],
                    'recipient_avatar' => $messageGroup['authorProfileImage'],
                    'last_message_at' => $messageGroup['lastMessageTime'],
                    'is_read' => true,
                    'snippet' => $messageGroup['lastMessage'],
                    'live_chat_id' => $messageGroup['liveChatId'],
                ];
            }
            
            return [
                'conversations' => $conversations,
                'next_cursor' => $nextCursor,
            ];
        } catch (\Exception $e) {
            throw new MessagingException('Failed to get conversations from YouTube: ' . $e->getMessage());
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
            
            // Parse the conversation ID to get liveChatId and authorId
            list($liveChatId, $authorId) = explode('_', $conversationId);
            
            $params = [
                'part' => 'snippet',
                'liveChatId' => $liveChatId,
                'maxResults' => $limit,
            ];
            
            if ($cursor) {
                $params['pageToken'] = $cursor;
            }
            
            $response = $this->client->get('liveChat/messages', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
                'query' => $params,
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['items'])) {
                throw new MessagingException('Failed to retrieve messages from YouTube.');
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
                    'platform' => 'youtube',
                    'platform_conversation_id' => $conversationId,
                    'recipient_id' => $authorId,
                    'last_message_at' => now(),
                    'metadata' => [
                        'live_chat_id' => $liveChatId,
                    ],
                ]);
            }
            
            $messages = [];
            $nextCursor = $data['nextPageToken'] ?? null;
            
            // Filter messages by the specific author
            $filteredMessages = array_filter($data['items'], function($message) use ($authorId) {
                return ($message['snippet']['authorChannelId'] ?? null) === $authorId;
            });
            
            foreach ($filteredMessages as $message) {
                $messageId = $message['id'];
                $messageText = $message['snippet']['displayMessage'] ?? '';
                $senderId = $message['snippet']['authorChannelId'] ?? null;
                $senderName = $message['snippet']['authorDisplayName'] ?? null;
                $createdAt = $message['snippet']['publishedAt'] ?? null;
                $isFromMe = false; // All messages are from the author in this case
                
                // Store in database
                $socialMessage = SocialMessage::updateOrCreate(
                    [
                        'social_conversation_id' => $socialConversation->id,
                        'platform_message_id' => $messageId,
                    ],
                    [
                        'user_id' => $this->account->user_id,
                        'social_account_id' => $this->account->id,
                        'platform' => 'youtube',
                        'message' => $messageText,
                        'sender_id' => $senderId,
                        'sender_name' => $senderName,
                        'is_from_me' => $isFromMe,
                        'is_read' => true,
                        'metadata' => [
                            'created_at' => $createdAt,
                            'author_profile_image' => $message['snippet']['authorProfileImageUrl'] ?? null,
                        ],
                    ]
                );
                
                $messages[] = [
                    'id' => $socialMessage->id,
                    'platform_message_id' => $messageId,
                    'message' => $messageText,
                    'sender_id' => $senderId,
                    'sender_name' => $senderName,
                    'sender_avatar' => $message['snippet']['authorProfileImageUrl'] ?? null,
                    'is_from_me' => $isFromMe,
                    'created_at' => $createdAt,
                ];
            }
            
            return [
                'conversation_id' => $socialConversation->id,
                'platform_conversation_id' => $conversationId,
                'messages' => $messages,
                'next_cursor' => $nextCursor,
            ];
        } catch (\Exception $e) {
            throw new MessagingException('Failed to get messages from YouTube: ' . $e->getMessage());
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
            
            // YouTube doesn't support direct messaging between users
            // We can only send messages to live chat
            if (!isset($options['live_chat_id'])) {
                throw new MessagingException('Live chat ID is required to send messages on YouTube.');
            }
            
            $liveChatId = $options['live_chat_id'];
            
            $payload = [
                'snippet' => [
                    'liveChatId' => $liveChatId,
                    'type' => 'textMessageEvent',
                    'textMessageDetails' => [
                        'messageText' => $message,
                    ],
                ],
            ];
            
            $response = $this->client->post('liveChat/messages', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['id'])) {
                throw new MessagingException('Failed to send message to YouTube live chat.');
            }
            
            $messageId = $data['id'];
            $conversationId = $liveChatId . '_' . $recipientId;
            
            // Find or create conversation
            $socialConversation = SocialConversation::where('platform_conversation_id', $conversationId)
                ->where('social_account_id', $this->account->id)
                ->first();
            
            if (!$socialConversation) {
                // Get recipient details if available
                $recipientName = $options['recipient_name'] ?? null;
                $recipientAvatar = $options['recipient_avatar'] ?? null;
                
                // Create new conversation
                $socialConversation = SocialConversation::create([
                    'user_id' => $this->account->user_id,
                    'social_account_id' => $this->account->id,
                    'platform' => 'youtube',
                    'platform_conversation_id' => $conversationId,
                    'recipient_id' => $recipientId,
                    'recipient_name' => $recipientName,
                    'recipient_avatar' => $recipientAvatar,
                    'last_message_at' => now(),
                    'is_read' => true,
                    'metadata' => [
                        'live_chat_id' => $liveChatId,
                    ],
                ]);
            } else {
                // Update existing conversation
                $socialConversation->update([
                    'last_message_at' => now(),
                ]);
            }
            
            // Store message
            $socialMessage = SocialMessage::create([
                'user_id' => $this->account->user_id,
                'social_account_id' => $this->account->id,
                'social_conversation_id' => $socialConversation->id,
                'platform' => 'youtube',
                'platform_message_id' => $messageId,
                'message' => $message,
                'sender_id' => $this->getChannelId(),
                'sender_name' => $this->account->name,
                'is_from_me' => true,
                'is_read' => true,
            ]);
            
            return [
                'success' => true,
                'message_id' => $messageId,
                'conversation_id' => $socialConversation->id,
                'platform_conversation_id' => $conversationId,
                'recipient_id' => $recipientId,
            ];
        } catch (\Exception $e) {
            throw new MessagingException('Failed to send message to YouTube: ' . $e->getMessage());
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
            
            // Parse the conversation ID to get liveChatId
            list($liveChatId, $recipientId) = explode('_', $conversationId);
            
            // Add live_chat_id to options
            $options['live_chat_id'] = $liveChatId;
            
            return $this->sendMessage($recipientId, $message, $options);
        } catch (\Exception $e) {
            throw new MessagingException('Failed to reply to conversation on YouTube: ' . $e->getMessage());
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
            // YouTube doesn't have a concept of read/unread for messages
            // We'll just update our local database
            
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
            throw new MessagingException('Failed to mark conversation as read on YouTube: ' . $e->getMessage());
        }
    }
    
    /**
     * Get the channel ID from the account metadata.
     *
     * @return string
     * @throws \VendorName\SocialConnect\Exceptions\MessagingException
     */
    protected function getChannelId(): string
    {
        $metadata = $this->account->metadata;
        
        if (isset($metadata['channel_id'])) {
            return $metadata['channel_id'];
        }
        
        throw new MessagingException('YouTube channel ID not found in account metadata.');
    }
}
