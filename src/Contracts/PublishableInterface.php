<?php

namespace VendorName\SocialConnect\Contracts;

interface PublishableInterface
{
    /**
     * Publish a text post to the platform.
     *
     * @param string $content
     * @param array $options
     * @return array
     */
    public function publishText(string $content, array $options = []): array;
    
    /**
     * Publish an image post to the platform.
     *
     * @param string $content
     * @param string|array $mediaUrls
     * @param array $options
     * @return array
     */
    public function publishImage(string $content, $mediaUrls, array $options = []): array;
    
    /**
     * Publish a video post to the platform.
     *
     * @param string $content
     * @param string $videoUrl
     * @param array $options
     * @return array
     */
    public function publishVideo(string $content, string $videoUrl, array $options = []): array;
    
    /**
     * Publish a link post to the platform.
     *
     * @param string $content
     * @param string $url
     * @param array $options
     * @return array
     */
    public function publishLink(string $content, string $url, array $options = []): array;
    
    /**
     * Schedule a post for future publishing.
     *
     * @param string $content
     * @param \DateTime $scheduledAt
     * @param array $options
     * @return array
     */
    public function schedulePost(string $content, \DateTime $scheduledAt, array $options = []): array;
    
    /**
     * Delete a post from the platform.
     *
     * @param string $postId
     * @return bool
     */
    public function deletePost(string $postId): bool;
}
