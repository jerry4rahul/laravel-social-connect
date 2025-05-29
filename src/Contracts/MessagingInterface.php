<?php

namespace VendorName\SocialConnect\Contracts;

interface MessagingInterface
{
    /**
     * Get conversations for the account.
     *
     * @param int $limit
     * @param string|null $cursor
     * @return array
     */
    public function getConversations(int $limit = 20, ?string $cursor = null): array;
    
    /**
     * Get messages for a specific conversation.
     *
     * @param string $conversationId
     * @param int $limit
     * @param string|null $cursor
     * @return array
     */
    public function getMessages(string $conversationId, int $limit = 20, ?string $cursor = null): array;
    
    /**
     * Send a new message to a recipient.
     *
     * @param string $recipientId
     * @param string $message
     * @param array $options
     * @return array
     */
    public function sendMessage(string $recipientId, string $message, array $options = []): array;
    
    /**
     * Reply to an existing conversation.
     *
     * @param string $conversationId
     * @param string $message
     * @param array $options
     * @return array
     */
    public function replyToConversation(string $conversationId, string $message, array $options = []): array;
    
    /**
     * Mark a conversation as read.
     *
     * @param string $conversationId
     * @return bool
     */
    public function markConversationAsRead(string $conversationId): bool;
}
