<?php

namespace VendorName\SocialConnect\Services\Instagram;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use VendorName\SocialConnect\Contracts\PublishableInterface;
use VendorName\SocialConnect\Exceptions\PublishingException;

class InstagramPublishingService implements PublishableInterface
{
    /**
     * The HTTP client instance for Instagram Graph API.
     *
     * @var \GuzzleHttp\Client
     */
    protected $graphClient;

    /**
     * Facebook Graph API version (used for Instagram Graph API).
     *
     * @var string
     */
    protected $graphVersion;

    /**
     * Create a new InstagramPublishingService instance.
     */
    public function __construct()
    {
        // Publishing uses the Instagram Graph API (via Facebook Graph API endpoint)
        $config = Config::get("social-connect.platforms.facebook"); // Use Facebook config for Graph API version
        $this->graphVersion = $config["graph_version"] ?? "v18.0";

        $this->graphClient = new Client([
            "base_uri" => "https://graph.facebook.com/{$this->graphVersion}/",
            "timeout" => 120, // Increased timeout for video uploads
        ]);
    }

    /**
     * Publish a text post (Not directly supported by Instagram API - requires media).
     *
     * @param string $accessToken Facebook Page access token with Instagram permissions.
     * @param string $targetId Instagram Business Account ID.
     * @param string $text The text content (used as caption for media).
     * @param array $options Must include media (image/video path).
     * @return array
     * @throws PublishingException
     */
    public function publishText(string $accessToken, string $targetId, string $text, array $options = []): array
    {
        // Instagram requires media for posts. Use publishImage or publishVideo.
        if (isset($options["image_path"])) {
            return $this->publishImage($accessToken, $targetId, $text, $options["image_path"], $options);
        }
        if (isset($options["video_path"])) {
            return $this->publishVideo($accessToken, $targetId, $text, $options["video_path"], $options["thumbnail_path"] ?? null, $options);
        }
        throw new PublishingException("Instagram requires an image or video to publish a post. Use publishImage or publishVideo methods.");
    }

    /**
     * Publish an image post (single image or carousel).
     *
     * @param string $accessToken Facebook Page access token with Instagram permissions.
     * @param string $targetId Instagram Business Account ID.
     * @param string $caption The caption for the image(s).
     * @param string|array $imagePaths Path(s) to the image file(s) on local storage.
     * @param array $options Additional options (e.g., user_tags).
     * @return array Returns array with platform_post_id.
     * @throws PublishingException
     */
    public function publishImage(string $accessToken, string $targetId, string $caption, $imagePaths, array $options = []): array
    {
        $imagePaths = (array) $imagePaths;
        $isCarousel = count($imagePaths) > 1;

        try {
            $mediaContainerIds = [];
            foreach ($imagePaths as $imagePath) {
                // Step 1: Upload image(s) to Instagram container
                $uploadUrl = $this->getImageUploadUrl($imagePath);
                $containerId = $this->uploadMediaContainer($accessToken, $targetId, $uploadUrl, $isCarousel);
                $mediaContainerIds[] = $containerId;
            }

            if ($isCarousel) {
                // Step 2a: Create carousel container
                $carouselContainerId = $this->createCarouselContainer($accessToken, $targetId, $caption, $mediaContainerIds, $options);
                $publishContainerId = $carouselContainerId;
            } else {
                // Step 2b: Use single image container ID for publishing
                $publishContainerId = $mediaContainerIds[0];
                // Add caption to single image container if needed (alternative to adding during publish)
                // Note: Caption is usually added during the final publish step for single images.
            }

            // Step 3: Publish the container
            $publishResponse = $this->publishMediaContainer($accessToken, $targetId, $publishContainerId, $caption, !$isCarousel ? $options : []);

            return [
                "platform" => "instagram",
                "platform_post_id" => $publishResponse["id"],
            ];
        } catch (GuzzleException | \Exception $e) {
            throw new PublishingException("Failed to publish image post to Instagram: " . $e->getMessage());
        }
    }

    /**
     * Publish a video post.
     *
     * @param string $accessToken Facebook Page access token with Instagram permissions.
     * @param string $targetId Instagram Business Account ID.
     * @param string $caption The caption for the video.
     * @param string $videoPath Path to the video file on local storage.
     * @param string|null $thumbnailPath Optional path to the thumbnail file (ignored by IG API, uses video frame).
     * @param array $options Additional options (e.g., user_tags).
     * @return array Returns array with platform_post_id.
     * @throws PublishingException
     */
    public function publishVideo(string $accessToken, string $targetId, string $caption, string $videoPath, ?string $thumbnailPath = null, array $options = []): array
    {
        try {
            // Step 1: Upload video to Instagram container
            $uploadUrl = $this->getVideoUploadUrl($videoPath);
            $containerId = $this->uploadMediaContainer($accessToken, $targetId, $uploadUrl, false, true);

            // Step 2: Publish the container
            // Caption and other options are added during publish for videos
            $publishResponse = $this->publishMediaContainer($accessToken, $targetId, $containerId, $caption, $options);

            return [
                "platform" => "instagram",
                "platform_post_id" => $publishResponse["id"],
            ];
        } catch (GuzzleException | \Exception $e) {
            throw new PublishingException("Failed to publish video post to Instagram: " . $e->getMessage());
        }
    }

    /**
     * Publish a link post (Not supported by Instagram API).
     *
     * @param string $accessToken
     * @param string $targetId
     * @param string $text
     * @param string $url
     * @param array $options
     * @return array
     * @throws PublishingException
     */
    public function publishLink(string $accessToken, string $targetId, string $text, string $url, array $options = []): array
    {
        throw new PublishingException("Publishing link-only posts is not supported by Instagram.");
    }

    /**
     * Delete a post (Not directly supported by Instagram Graph API for general posts).
     *
     * @param string $accessToken
     * @param string $postId The ID of the media to delete.
     * @return bool
     * @throws PublishingException
     */
    public function deletePost(string $accessToken, string $postId): bool
    {
        // Instagram Graph API generally doesn't allow deleting arbitrary media via API.
        // Some specific types like comments might be deletable.
        // Consider removing this method or clarifying its limitations.
        // For now, throw an exception.
        throw new PublishingException("Deleting posts via the Instagram Graph API is generally not supported.");
        // If deletion was possible, it would look something like:
        /*
        try {
            $response = $this->graphClient->delete($postId, [
                "query" => ["access_token" => $accessToken],
            ]);
            $data = json_decode($response->getBody()->getContents(), true);
            return isset($data["success"]) && $data["success"];
        } catch (GuzzleException $e) {
            throw new PublishingException("Failed to delete Instagram post: " . $e->getMessage());
        }
        */
    }

    // --- Helper Methods for Instagram Content Publishing Flow ---

    protected function getImageUploadUrl(string $imagePath): string
    {
        // Placeholder: In a real scenario, you might need to get a one-time upload URL from IG API first.
        // For simplicity here, we assume direct upload is possible or use a known endpoint.
        // Actual implementation might involve resumable uploads for reliability.
        if (!Storage::exists($imagePath)) {
            throw new PublishingException("Image file not found at path: {$imagePath}");
        }
        return Storage::url($imagePath); // Needs to be a publicly accessible URL
    }

    protected function getVideoUploadUrl(string $videoPath): string
    {
        if (!Storage::exists($videoPath)) {
            throw new PublishingException("Video file not found at path: {$videoPath}");
        }
        return Storage::url($videoPath); // Needs to be a publicly accessible URL
    }

    protected function uploadMediaContainer(string $accessToken, string $targetId, string $mediaUrl, bool $isCarouselItem = false, bool $isVideo = false): string
    {
        $params = [
            "access_token" => $accessToken,
        ];
        if ($isVideo) {
            $params["media_type"] = "VIDEO";
            $params["video_url"] = $mediaUrl;
        } else {
            $params["image_url"] = $mediaUrl;
            if ($isCarouselItem) {
                $params["is_carousel_item"] = "true";
            }
        }

        $response = $this->graphClient->post("{$targetId}/media", [
            "form_params" => $params,
        ]);
        $data = json_decode($response->getBody()->getContents(), true);

        if (!isset($data["id"])) {
            throw new PublishingException("Failed to create Instagram media container.");
        }

        // Check upload status asynchronously
        return $this->checkMediaUploadStatus($accessToken, $data["id"]);
    }

    protected function createCarouselContainer(string $accessToken, string $targetId, string $caption, array $childrenContainerIds, array $options): string
    {
        $params = [
            "caption" => $caption,
            "media_type" => "CAROUSEL",
            "children" => implode(",", $childrenContainerIds),
            "access_token" => $accessToken,
        ];
        // Add user tags if provided in options
        // $params['user_tags'] = json_encode($options['user_tags']);

        $response = $this->graphClient->post("{$targetId}/media", [
            "form_params" => $params,
        ]);
        $data = json_decode($response->getBody()->getContents(), true);

        if (!isset($data["id"])) {
            throw new PublishingException("Failed to create Instagram carousel container.");
        }
        return $data["id"];
    }

    protected function publishMediaContainer(string $accessToken, string $targetId, string $containerId, string $caption, array $options): array
    {
        $params = [
            "creation_id" => $containerId,
            "access_token" => $accessToken,
        ];

        // For single media items, caption/location/tags are added here
        // For carousels, they are added during carousel container creation
        // However, let's add caption here for single items for consistency
        // Check if it's a video or single image container (not carousel)
        // This logic might need refinement based on exact API behavior
        $containerInfo = $this->getMediaContainerStatus($accessToken, $containerId);
        if ($containerInfo["media_type"] !== "CAROUSEL") {
             $params["caption"] = $caption;
             // Add user tags, location etc. from $options if needed
             // $params['user_tags'] = json_encode($options['user_tags']);
             // $params['location_id'] = $options['location_id'];
        }


        $response = $this->graphClient->post("{$targetId}/media_publish", [
            "form_params" => $params,
        ]);
        $data = json_decode($response->getBody()->getContents(), true);

        if (!isset($data["id"])) {
            throw new PublishingException("Failed to publish Instagram media container.");
        }
        return $data; // Contains the ID of the published media post
    }

    protected function checkMediaUploadStatus(string $accessToken, string $containerId, int $maxAttempts = 10, int $delay = 6): string
    {
        $attempts = 0;
        while ($attempts < $maxAttempts) {
            try {
                $statusData = $this->getMediaContainerStatus($accessToken, $containerId);
                $statusCode = $statusData["status_code"] ?? null;

                if ($statusCode === "FINISHED") {
                    return $containerId;
                }
                if ($statusCode === "ERROR" || $statusCode === "EXPIRED") {
                    throw new PublishingException("Instagram media upload failed with status: " . ($statusData["status"] ?? "Unknown Error"));
                }
                // If IN_PROGRESS, wait and retry
            } catch (GuzzleException $e) {
                // Handle exceptions during status check
                throw new PublishingException("Error checking Instagram media upload status: " . $e->getMessage());
            }

            $attempts++;
            sleep($delay);
        }
        throw new PublishingException("Instagram media upload timed out after {$maxAttempts} attempts.");
    }

    protected function getMediaContainerStatus(string $accessToken, string $containerId): array
    {
         $response = $this->graphClient->get($containerId, [
            "query" => [
                "fields" => "status_code,status,media_type",
                "access_token" => $accessToken,
            ],
        ]);
        return json_decode($response->getBody()->getContents(), true);
    }
}
