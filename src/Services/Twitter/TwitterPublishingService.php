<?php

namespace VendorName\SocialConnect\Services\Twitter;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Subscriber\Oauth\Oauth1;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use VendorName\SocialConnect\Contracts\PublishableInterface;
use VendorName\SocialConnect\Exceptions\PublishingException;

class TwitterPublishingService implements PublishableInterface
{
    /**
     * The HTTP client instance for API v1.1 (uploads).
     *
     * @var \GuzzleHttp\Client
     */
    protected $clientV1Upload;

    /**
     * The HTTP client instance for API v2.
     *
     * @var \GuzzleHttp\Client
     */
    protected $clientV2;

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
     * Create a new TwitterPublishingService instance.
     */
    public function __construct()
    {
        $config = Config::get("social-connect.platforms.twitter");
        $this->consumerKey = $config["consumer_key"];
        $this->consumerSecret = $config["consumer_secret"];

        // Client for API v1.1 Uploads (requires OAuth 1.0a)
        $this->clientV1Upload = new Client([
            "base_uri" => "https://upload.twitter.com/1.1/",
            "timeout" => 120, // Longer timeout for uploads
        ]);

        // Client for API v2 Tweet creation (requires OAuth 1.0a User Context)
        $this->clientV2 = new Client([
            "base_uri" => "https://api.twitter.com/2/",
            "timeout" => 30,
        ]);
    }

    /**
     * Get Guzzle client configured with OAuth 1.0a User Context.
     *
     * @param string $accessToken User Access Token.
     * @param string $tokenSecret User Access Token Secret.
     * @param string $baseUri Base URI for the client.
     * @return Client
     */
    protected function getOAuth1Client(string $accessToken, string $tokenSecret, string $baseUri): Client
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
            "base_uri" => $baseUri,
            "handler" => $stack,
            "auth" => "oauth",
            "timeout" => $baseUri === $this->clientV1Upload->getConfig("base_uri") ? 120 : 30,
        ]);
    }

    /**
     * Publish a text post (Tweet).
     *
     * @param string $accessToken User Access Token.
     * @param string $tokenSecret User Access Token Secret.
     * @param string $targetId User ID (not directly used in v2 tweet creation).
     * @param string $text The text content of the Tweet.
     * @param array $options Additional options (e.g., reply_settings, poll).
     * @return array Returns array with platform_post_id.
     * @throws PublishingException
     */
    public function publishText(string $accessToken, string $tokenSecret, string $targetId, string $text, array $options = []): array
    {
        try {
            $client = $this->getOAuth1Client($accessToken, $tokenSecret, $this->clientV2->getConfig("base_uri"));

            $payload = ["text" => $text];

            // Add other options like reply settings, poll, etc.
            if (isset($options["reply_settings"])) {
                $payload["reply_settings"] = $options["reply_settings"]; // e.g., "mentionedUsers"
            }
            if (isset($options["poll"])) {
                $payload["poll"] = [
                    "options" => $options["poll"]["options"], // array of strings
                    "duration_minutes" => $options["poll"]["duration_minutes"] ?? 1440, // default 1 day
                ];
            }
            // Add direct_message_deep_link, for_super_followers_only, geo, quote_tweet_id etc. if needed

            $response = $client->post("tweets", [
                "json" => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data["data"]["id"])) {
                throw new PublishingException("Failed to publish Tweet. No ID returned.");
            }

            return [
                "platform" => "twitter",
                "platform_post_id" => $data["data"]["id"],
                "raw_response" => $data,
            ];
        } catch (GuzzleException $e) {
            throw new PublishingException("Failed to publish Tweet: " . $e->getMessage());
        }
    }

    /**
     * Publish an image post (Tweet with image(s)).
     *
     * @param string $accessToken User Access Token.
     * @param string $tokenSecret User Access Token Secret.
     * @param string $targetId User ID.
     * @param string $caption The text content of the Tweet.
     * @param string|array $imagePaths Path(s) to the image file(s) (up to 4).
     * @param array $options Additional options.
     * @return array Returns array with platform_post_id.
     * @throws PublishingException
     */
    public function publishImage(string $accessToken, string $tokenSecret, string $targetId, string $caption, $imagePaths, array $options = []): array
    {
        $imagePaths = (array) $imagePaths;
        if (count($imagePaths) > 4) {
            throw new PublishingException("Twitter allows a maximum of 4 images per Tweet.");
        }

        try {
            $mediaIds = [];
            foreach ($imagePaths as $imagePath) {
                $mediaIds[] = $this->uploadMedia($accessToken, $tokenSecret, $imagePath, "image");
            }

            $client = $this->getOAuth1Client($accessToken, $tokenSecret, $this->clientV2->getConfig("base_uri"));
            $payload = [
                "text" => $caption,
                "media" => [
                    "media_ids" => $mediaIds,
                ],
            ];
            // Add other options as needed

            $response = $client->post("tweets", [
                "json" => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data["data"]["id"])) {
                throw new PublishingException("Failed to publish Tweet with image(s). No ID returned.");
            }

            return [
                "platform" => "twitter",
                "platform_post_id" => $data["data"]["id"],
                "raw_response" => $data,
            ];
        } catch (GuzzleException | \Exception $e) {
            throw new PublishingException("Failed to publish Tweet with image(s): " . $e->getMessage());
        }
    }

    /**
     * Publish a video post (Tweet with video).
     *
     * @param string $accessToken User Access Token.
     * @param string $tokenSecret User Access Token Secret.
     * @param string $targetId User ID.
     * @param string $description The text content of the Tweet.
     * @param string $videoPath Path to the video file.
     * @param string|null $thumbnailPath Ignored by Twitter API.
     * @param array $options Additional options.
     * @return array Returns array with platform_post_id.
     * @throws PublishingException
     */
    public function publishVideo(string $accessToken, string $tokenSecret, string $targetId, string $description, string $videoPath, ?string $thumbnailPath = null, array $options = []): array
    {
        try {
            $mediaId = $this->uploadMedia($accessToken, $tokenSecret, $videoPath, "video");

            $client = $this->getOAuth1Client($accessToken, $tokenSecret, $this->clientV2->getConfig("base_uri"));
            $payload = [
                "text" => $description,
                "media" => [
                    "media_ids" => [$mediaId],
                ],
            ];
            // Add other options as needed

            $response = $client->post("tweets", [
                "json" => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data["data"]["id"])) {
                throw new PublishingException("Failed to publish Tweet with video. No ID returned.");
            }

            return [
                "platform" => "twitter",
                "platform_post_id" => $data["data"]["id"],
                "raw_response" => $data,
            ];
        } catch (GuzzleException | \Exception $e) {
            throw new PublishingException("Failed to publish Tweet with video: " . $e->getMessage());
        }
    }

    /**
     * Publish a link post (handled by publishText).
     *
     * @param string $accessToken User Access Token.
     * @param string $tokenSecret User Access Token Secret.
     * @param string $targetId User ID.
     * @param string $text The text content (including the link).
     * @param string $url The URL (should be included in the text).
     * @param array $options Additional options.
     * @return array Returns array with platform_post_id.
     * @throws PublishingException
     */
    public function publishLink(string $accessToken, string $tokenSecret, string $targetId, string $text, string $url, array $options = []): array
    {
        // Links are typically just part of the text in Twitter
        // Ensure the $text parameter contains the $url
        if (strpos($text, $url) === false) {
            $text .= " " . $url; // Append URL if not already in text
        }
        return $this->publishText($accessToken, $tokenSecret, $targetId, $text, $options);
    }

    /**
     * Delete a post (Tweet).
     *
     * @param string $accessToken User Access Token.
     * @param string $tokenSecret User Access Token Secret.
     * @param string $postId The ID of the Tweet to delete.
     * @return bool Returns true on success.
     * @throws PublishingException
     */
    public function deletePost(string $accessToken, string $tokenSecret, string $postId): bool
    {
        try {
            $client = $this->getOAuth1Client($accessToken, $tokenSecret, $this->clientV2->getConfig("base_uri"));

            $response = $client->delete("tweets/{$postId}");

            $data = json_decode($response->getBody()->getContents(), true);

            // Check if deletion was successful
            return isset($data["data"]["deleted"]) && $data["data"]["deleted"];
        } catch (GuzzleException $e) {
            // Handle specific errors like not found (404) or forbidden (403)
            throw new PublishingException("Failed to delete Tweet: " . $e->getMessage());
        }
    }

    /**
     * Upload media (image/video/GIF) using Twitter API v1.1 chunked upload.
     *
     * @param string $accessToken User Access Token.
     * @param string $tokenSecret User Access Token Secret.
     * @param string $mediaPath Path to the media file.
     * @param string $mediaType Type of media (
     * @return string Media ID.
     * @throws PublishingException
     */
    protected function uploadMedia(string $accessToken, string $tokenSecret, string $mediaPath, string $mediaType): string
    {
        if (!Storage::exists($mediaPath)) {
            throw new PublishingException("Media file not found: {$mediaPath}");
        }

        $client = $this->getOAuth1Client($accessToken, $tokenSecret, $this->clientV1Upload->getConfig("base_uri"));
        $filePath = Storage::path($mediaPath);
        $fileSize = filesize($filePath);
        $mimeType = mime_content_type($filePath);
        $mediaCategory = ($mediaType === "video") ? "tweet_video" : (($mediaType === "gif") ? "tweet_gif" : "tweet_image");

        try {
            // INIT
            $initResponse = $client->post("media/upload.json", [
                "form_params" => [
                    "command" => "INIT",
                    "total_bytes" => $fileSize,
                    "media_type" => $mimeType,
                    "media_category" => $mediaCategory,
                ],
            ]);
            $initData = json_decode($initResponse->getBody()->getContents(), true);
            $mediaId = $initData["media_id_string"];

            // APPEND
            $segmentIndex = 0;
            $chunkSize = 4 * 1024 * 1024; // 4MB chunks
            $handle = fopen($filePath, "r");
            while (!feof($handle)) {
                $chunk = fread($handle, $chunkSize);
                $client->post("media/upload.json", [
                    "multipart" => [
                        ["name" => "command", "contents" => "APPEND"],
                        ["name" => "media_id", "contents" => $mediaId],
                        ["name" => "segment_index", "contents" => (string)$segmentIndex],
                        ["name" => "media", "contents" => $chunk, "filename" => basename($mediaPath)],
                    ],
                ]);
                $segmentIndex++;
            }
            fclose($handle);

            // FINALIZE
            $finalizeResponse = $client->post("media/upload.json", [
                "form_params" => [
                    "command" => "FINALIZE",
                    "media_id" => $mediaId,
                ],
            ]);
            $finalizeData = json_decode($finalizeResponse->getBody()->getContents(), true);

            // Check processing status if needed (especially for videos)
            if (isset($finalizeData["processing_info"])) {
                $this->checkMediaProcessingStatus($accessToken, $tokenSecret, $mediaId, $finalizeData["processing_info"]);
            }

            return $mediaId;
        } catch (GuzzleException | \Exception $e) {
            throw new PublishingException("Twitter media upload failed: " . $e->getMessage());
        }
    }

    /**
     * Check media processing status after FINALIZE.
     *
     * @param string $accessToken
     * @param string $tokenSecret
     * @param string $mediaId
     * @param array $processingInfo
     * @throws PublishingException
     */
    protected function checkMediaProcessingStatus(string $accessToken, string $tokenSecret, string $mediaId, array $processingInfo):
    {
        $state = $processingInfo["state"];
        $checkAfterSecs = $processingInfo["check_after_secs"] ?? 5;

        $client = $this->getOAuth1Client($accessToken, $tokenSecret, $this->clientV1Upload->getConfig("base_uri"));

        while ($state === "pending" || $state === "in_progress") {
            sleep($checkAfterSecs);
            try {
                $statusResponse = $client->get("media/upload.json", [
                    "query" => [
                        "command" => "STATUS",
                        "media_id" => $mediaId,
                    ],
                ]);
                $statusData = json_decode($statusResponse->getBody()->getContents(), true);
                $state = $statusData["processing_info"]["state"] ?? "failed";
                $checkAfterSecs = $statusData["processing_info"]["check_after_secs"] ?? $checkAfterSecs * 1.5; // Exponential backoff

                if ($state === "failed") {
                    throw new PublishingException("Twitter media processing failed: " . ($statusData["processing_info"]["error"]["message"] ?? "Unknown error"));
                }
            } catch (GuzzleException $e) {
                throw new PublishingException("Error checking Twitter media processing status: " . $e->getMessage());
            }
        }

        if ($state !== "succeeded") {
             throw new PublishingException("Twitter media processing did not succeed. Final state: {$state}");
        }
    }
}
