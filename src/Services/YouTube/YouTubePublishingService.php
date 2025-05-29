<?php

namespace VendorName\SocialConnect\Services\YouTube;

use Google_Client;
use Google_Service_YouTube;
use Google_Service_YouTube_Video;
use Google_Service_YouTube_VideoSnippet;
use Google_Service_YouTube_VideoStatus;
use Google_Http_MediaFileUpload;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use VendorName\SocialConnect\Contracts\PublishableInterface;
use VendorName\SocialConnect\Exceptions\PublishingException;

class YouTubePublishingService implements PublishableInterface
{
    /**
     * Google API Client.
     *
     * @var \Google_Client
     */
    protected $googleClient;

    /**
     * Create a new YouTubePublishingService instance.
     */
    public function __construct()
    {
        // Basic client setup, token will be set per request
        $this->googleClient = new Google_Client();
        $config = Config::get("social-connect.platforms.youtube");
        if (isset($config["client_id"], $config["client_secret"], $config["redirect_uri"])) {
            $this->googleClient->setClientId($config["client_id"]);
            $this->googleClient->setClientSecret($config["client_secret"]);
            $this->googleClient->setRedirectUri($config["redirect_uri"]);
        }
    }

    /**
     * Get Google Client configured with access token.
     *
     * @param string $accessToken
     * @return Google_Client
     * @throws PublishingException
     */
    protected function getApiClient(string $accessToken): Google_Client
    {
        if (empty($accessToken)) {
            throw new PublishingException("YouTube access token is required.");
        }
        $client = clone $this->googleClient;
        $client->setAccessToken($accessToken);
        // Check if token is expired, attempt refresh if refresh token is available (complex logic, omitted for stateless focus)
        // if ($client->isAccessTokenExpired()) {
        //     throw new PublishingException("YouTube access token is expired.");
        // }
        return $client;
    }

    /**
     * Publish a text post (Not directly supported - use Community Posts or Video Descriptions).
     *
     * @param string $accessToken
     * @param string $tokenSecret
     * @param string $targetId Channel ID.
     * @param string $text
     * @param array $options
     * @return array
     * @throws PublishingException
     */
    public function publishText(string $accessToken, string $tokenSecret, string $targetId, string $text, array $options = []): array
    {
        // YouTube doesn't have simple text posts like Twitter/Facebook.
        // Options: Create a Community Post (requires advanced permissions/channel features)
        // or update channel description (not a post).
        // For simplicity, we can throw an exception or return an empty/error state.
        throw new PublishingException("Publishing plain text posts is not directly supported by YouTube API. Consider Community Posts or Video Descriptions.");

        // Example for Community Post (Conceptual - requires specific API access & channel state):
        /*
        try {
            $client = $this->getApiClient($accessToken);
            $youtubeService = new Google_Service_YouTube($client);

            // Community posts are created via youtube.activities.insert
            // This is a simplified structure and might need adjustments
            $activitySnippet = new \Google_Service_YouTube_ActivitySnippet();
            $activitySnippet->setChannelId($targetId);
            $activitySnippet->setDescription($text); // Use description for text content
            $activitySnippet->setType("bulletin"); // Type for text post

            $activityContentDetails = new \Google_Service_YouTube_ActivityContentDetails();
            $bulletinDetails = new \Google_Service_YouTube_ActivityContentDetailsBulletin();
            // Bulletins don't have much structure beyond the description in snippet
            $activityContentDetails->setBulletin($bulletinDetails);

            $activity = new \Google_Service_YouTube_Activity();
            $activity->setSnippet($activitySnippet);
            $activity->setContentDetails($activityContentDetails);

            $response = $youtubeService->activities->insert("snippet,contentDetails", $activity);

            return [
                "platform" => "youtube",
                "platform_post_id" => $response->getId(), // Activity ID
                "raw_response" => $response->toSimpleObject(),
            ];
        } catch (\Exception $e) {
            throw new PublishingException("Failed to publish YouTube community post: " . $e->getMessage());
        }
        */
    }

    /**
     * Publish an image post (Not directly supported - use Community Posts).
     *
     * @param string $accessToken
     * @param string $tokenSecret
     * @param string $targetId Channel ID.
     * @param string $caption
     * @param string|array $imagePaths
     * @param array $options
     * @return array
     * @throws PublishingException
     */
    public function publishImage(string $accessToken, string $tokenSecret, string $targetId, string $caption, $imagePaths, array $options = []): array
    {
        // Similar to text posts, images are typically shared via Community Posts.
        throw new PublishingException("Publishing image posts is not directly supported by YouTube API. Consider Community Posts.");
        // Implementation would involve youtube.activities.insert with type 'social' or similar,
        // potentially linking to an uploaded image or external URL.
    }

    /**
     * Publish a video post (Upload Video).
     *
     * @param string $accessToken User Access Token with youtube.upload scope.
     * @param string $tokenSecret Ignored.
     * @param string $targetId Channel ID (used for context, upload is to the authenticated user's channel).
     * @param string $description Video description.
     * @param string $videoPath Path to the video file.
     * @param string|null $thumbnailPath Optional path to custom thumbnail.
     * @param array $options Additional options (title, tags, categoryId, privacyStatus, etc.).
     * @return array Returns array with platform_post_id (Video ID).
     * @throws PublishingException
     */
    public function publishVideo(string $accessToken, string $tokenSecret, string $targetId, string $description, string $videoPath, ?string $thumbnailPath = null, array $options = []): array
    {
        if (!Storage::exists($videoPath)) {
            throw new PublishingException("Video file not found: {$videoPath}");
        }
        $filePath = Storage::path($videoPath);

        try {
            $client = $this->getApiClient($accessToken);
            $youtubeService = new Google_Service_YouTube($client);

            // 1. Create Video Resource
            $video = new Google_Service_YouTube_Video();

            $snippet = new Google_Service_YouTube_VideoSnippet();
            $snippet->setTitle($options["title"] ?? "Untitled Video");
            $snippet->setDescription($description);
            if (isset($options["tags"]) && is_array($options["tags"])) {
                $snippet->setTags($options["tags"]);
            }
            // Find category ID: https://developers.google.com/youtube/v3/docs/videoCategories/list
            $snippet->setCategoryId($options["categoryId"] ?? "22"); // Default: People & Blogs
            $snippet->setDefaultLanguage($options["defaultLanguage"] ?? "en");
            $snippet->setDefaultAudioLanguage($options["defaultAudioLanguage"] ?? "en");
            $video->setSnippet($snippet);

            $status = new Google_Service_YouTube_VideoStatus();
            $status->setPrivacyStatus($options["privacyStatus"] ?? "private"); // private, public, unlisted
            $status->setEmbeddable($options["embeddable"] ?? true);
            $status->setLicense($options["license"] ?? "youtube");
            $status->setPublicStatsViewable($options["publicStatsViewable"] ?? true);
            if (isset($options["publishAt"])) { // ISO 8601 format (YYYY-MM-DDThh:mm:ss.sssZ)
                $status->setPublishAt($options["publishAt"]);
                $status->setPrivacyStatus("private"); // Must be private if publishAt is set
            }
            $video->setStatus($status);

            // 2. Upload Video File (Resumable Upload)
            $chunkSizeBytes = 1 * 1024 * 1024; // 1MB Chunks
            $client->setDefer(true); // Enable resumable uploads

            $insertRequest = $youtubeService->videos->insert("snippet,status", $video);

            $media = new Google_Http_MediaFileUpload(
                $client,
                $insertRequest,
                mime_content_type($filePath),
                null,
                true, // Resumable
                $chunkSizeBytes
            );
            $media->setFileSize(filesize($filePath));

            // Upload the file chunk by chunk
            $uploadStatus = false;
            $handle = fopen($filePath, "rb");
            while (!$uploadStatus && !feof($handle)) {
                $chunk = fread($handle, $chunkSizeBytes);
                $uploadStatus = $media->nextChunk($chunk);
            }
            fclose($handle);

            $client->setDefer(false); // Disable resumable uploads

            if (!$uploadStatus || !isset($uploadStatus["id"])) {
                throw new PublishingException("YouTube video upload failed.");
            }

            $videoId = $uploadStatus["id"];

            // 3. (Optional) Upload Custom Thumbnail
            if ($thumbnailPath && Storage::exists($thumbnailPath)) {
                try {
                    $thumbFilePath = Storage::path($thumbnailPath);
                    $thumbFileSize = filesize($thumbFilePath);
                    $thumbMimeType = mime_content_type($thumbFilePath);

                    $youtubeService->thumbnails->set(
                        $videoId,
                        [
                            "data" => file_get_contents($thumbFilePath),
                            "mimeType" => $thumbMimeType,
                            "uploadType" => "media",
                        ]
                    );
                } catch (\Exception $thumbException) {
                    // Log thumbnail upload error but don't fail the whole video publish
                    // Log::warning("Failed to upload YouTube custom thumbnail: " . $thumbException->getMessage());
                }
            }

            return [
                "platform" => "youtube",
                "platform_post_id" => $videoId,
                "raw_response" => $uploadStatus->toSimpleObject(),
            ];
        } catch (\Exception $e) {
            throw new PublishingException("Failed to publish YouTube video: " . $e->getMessage());
        }
    }

    /**
     * Publish a link post (Not directly supported - use Community Posts or Video Descriptions).
     *
     * @param string $accessToken
     * @param string $tokenSecret
     * @param string $targetId Channel ID.
     * @param string $text
     * @param string $url
     * @param array $options
     * @return array
     * @throws PublishingException
     */
    public function publishLink(string $accessToken, string $tokenSecret, string $targetId, string $text, string $url, array $options = []): array
    {
        throw new PublishingException("Publishing link posts is not directly supported by YouTube API. Consider Community Posts or adding links to Video Descriptions.");
    }

    /**
     * Delete a post (Video).
     *
     * @param string $accessToken User Access Token with youtube.force-ssl scope.
     * @param string $tokenSecret Ignored.
     * @param string $postId The Video ID to delete.
     * @return bool Returns true on success.
     * @throws PublishingException
     */
    public function deletePost(string $accessToken, string $tokenSecret, string $postId): bool
    {
        try {
            $client = $this->getApiClient($accessToken);
            $youtubeService = new Google_Service_YouTube($client);

            $response = $youtubeService->videos->delete($postId);

            // Delete returns an empty response on success (204 No Content)
            // The library might return null or an empty object.
            // We check for exceptions instead.
            return true;
        } catch (\Google_Service_Exception $e) {
            // Handle specific errors like "videoNotFound"
            if ($e->getCode() == 404) {
                return true; // Already deleted
            }
            throw new PublishingException("Failed to delete YouTube video: " . $e->getMessage());
        } catch (\Exception $e) {
            throw new PublishingException("Failed to delete YouTube video: " . $e->getMessage());
        }
    }
}
