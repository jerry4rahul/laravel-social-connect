<?php

namespace VendorName\SocialConnect\Services\LinkedIn;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use VendorName\SocialConnect\Contracts\PublishableInterface;
use VendorName\SocialConnect\Exceptions\PublishingException;

class LinkedInPublishingService implements PublishableInterface
{
    /**
     * The HTTP client instance for LinkedIn API v2.
     *
     * @var \GuzzleHttp\Client
     */
    protected $client;

    /**
     * Create a new LinkedInPublishingService instance.
     */
    public function __construct()
    {
        $this->client = new Client([
            "base_uri" => "https://api.linkedin.com/v2/",
            "timeout" => 120, // Longer timeout for potential uploads
        ]);
    }

    /**
     * Get Guzzle client configured with Bearer token.
     *
     * @param string $accessToken User or Organization Access Token.
     * @return Client
     */
    protected function getApiClient(string $accessToken): Client
    {
        // Return a new client instance or configure the existing one
        // Creating new ensures headers are fresh for each request
        return new Client([
            "base_uri" => $this->client->getConfig("base_uri"),
            "timeout" => $this->client->getConfig("timeout"),
            "headers" => [
                "Authorization" => "Bearer " . $accessToken,
                "Connection" => "Keep-Alive",
                "X-Restli-Protocol-Version" => "2.0.0",
                "Content-Type" => "application/json",
                "Accept" => "application/json",
            ],
        ]);
    }

    /**
     * Publish a text post (Share) as a User or Organization.
     *
     * @param string $accessToken User or Organization Access Token.
     * @param string $tokenSecret Ignored (OAuth 2.0).
     * @param string $targetId The URN of the author (e.g., "urn:li:person:{id}" or "urn:li:organization:{id}").
     * @param string $text The text content of the share.
     * @param array $options Additional options (e.g., visibility, lifecycleState).
     * @return array Returns array with platform_post_id (Share URN).
     * @throws PublishingException
     */
    public function publishText(string $accessToken, string $tokenSecret, string $targetId, string $text, array $options = []): array
    {
        try {
            $client = $this->getApiClient($accessToken);
            $payload = [
                "author" => $targetId,
                "lifecycleState" => $options["lifecycleState"] ?? "PUBLISHED", // or DRAFT
                "specificContent" => [
                    "com.linkedin.ugc.ShareContent" => [
                        "shareCommentary" => [
                            "text" => $text,
                        ],
                        "shareMediaCategory" => "NONE",
                    ],
                ],
                "visibility" => [
                    "com.linkedin.ugc.MemberNetworkVisibility" => $options["visibility"] ?? "PUBLIC", // CONNECTIONS, PUBLIC, LOGGED_IN
                ],
            ];

            $response = $client->post("ugcPosts", [
                "json" => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $postId = $data["id"] ?? null; // Share URN (e.g., urn:li:share:12345)

            if (!$postId) {
                throw new PublishingException("Failed to publish LinkedIn text share. No ID returned.");
            }

            return [
                "platform" => "linkedin",
                "platform_post_id" => $postId,
                "raw_response" => $data,
            ];
        } catch (GuzzleException $e) {
            throw new PublishingException("Failed to publish LinkedIn text share: " . $e->getMessage());
        }
    }

    /**
     * Publish an image post (Share with image(s)).
     *
     * @param string $accessToken User or Organization Access Token.
     * @param string $tokenSecret Ignored.
     * @param string $targetId Author URN.
     * @param string $caption The text content of the share.
     * @param string|array $imagePaths Path(s) to the image file(s).
     * @param array $options Additional options.
     * @return array Returns array with platform_post_id.
     * @throws PublishingException
     */
    public function publishImage(string $accessToken, string $tokenSecret, string $targetId, string $caption, $imagePaths, array $options = []): array
    {
        $imagePaths = (array) $imagePaths;
        try {
            $mediaUrns = [];
            foreach ($imagePaths as $imagePath) {
                $mediaUrns[] = $this->uploadMedia($accessToken, $targetId, $imagePath, "image");
            }

            $client = $this->getApiClient($accessToken);
            $mediaContent = array_map(function ($urn) {
                return ["status" => "READY", "media" => $urn];
            }, $mediaUrns);

            $payload = [
                "author" => $targetId,
                "lifecycleState" => $options["lifecycleState"] ?? "PUBLISHED",
                "specificContent" => [
                    "com.linkedin.ugc.ShareContent" => [
                        "shareCommentary" => [
                            "text" => $caption,
                        ],
                        "shareMediaCategory" => "IMAGE",
                        "media" => $mediaContent,
                    ],
                ],
                "visibility" => [
                    "com.linkedin.ugc.MemberNetworkVisibility" => $options["visibility"] ?? "PUBLIC",
                ],
            ];

            $response = $client->post("ugcPosts", [
                "json" => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $postId = $data["id"] ?? null;

            if (!$postId) {
                throw new PublishingException("Failed to publish LinkedIn image share. No ID returned.");
            }

            return [
                "platform" => "linkedin",
                "platform_post_id" => $postId,
                "raw_response" => $data,
            ];
        } catch (GuzzleException | \Exception $e) {
            throw new PublishingException("Failed to publish LinkedIn image share: " . $e->getMessage());
        }
    }

    /**
     * Publish a video post (Share with video).
     *
     * @param string $accessToken User or Organization Access Token.
     * @param string $tokenSecret Ignored.
     * @param string $targetId Author URN.
     * @param string $description The text content of the share.
     * @param string $videoPath Path to the video file.
     * @param string|null $thumbnailPath Ignored (LinkedIn generates thumbnails).
     * @param array $options Additional options.
     * @return array Returns array with platform_post_id.
     * @throws PublishingException
     */
    public function publishVideo(string $accessToken, string $tokenSecret, string $targetId, string $description, string $videoPath, ?string $thumbnailPath = null, array $options = []): array
    {
        try {
            $mediaUrn = $this->uploadMedia($accessToken, $targetId, $videoPath, "video");

            $client = $this->getApiClient($accessToken);
            $payload = [
                "author" => $targetId,
                "lifecycleState" => $options["lifecycleState"] ?? "PUBLISHED",
                "specificContent" => [
                    "com.linkedin.ugc.ShareContent" => [
                        "shareCommentary" => [
                            "text" => $description,
                        ],
                        "shareMediaCategory" => "VIDEO",
                        "media" => [
                            [
                                "status" => "READY",
                                "media" => $mediaUrn,
                                // "title" => $options["video_title"] ?? basename($videoPath), // Optional video title
                            ],
                        ],
                    ],
                ],
                "visibility" => [
                    "com.linkedin.ugc.MemberNetworkVisibility" => $options["visibility"] ?? "PUBLIC",
                ],
            ];

            $response = $client->post("ugcPosts", [
                "json" => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $postId = $data["id"] ?? null;

            if (!$postId) {
                throw new PublishingException("Failed to publish LinkedIn video share. No ID returned.");
            }

            return [
                "platform" => "linkedin",
                "platform_post_id" => $postId,
                "raw_response" => $data,
            ];
        } catch (GuzzleException | \Exception $e) {
            throw new PublishingException("Failed to publish LinkedIn video share: " . $e->getMessage());
        }
    }

    /**
     * Publish a link post (Share with article).
     *
     * @param string $accessToken User or Organization Access Token.
     * @param string $tokenSecret Ignored.
     * @param string $targetId Author URN.
     * @param string $text The text content of the share.
     * @param string $url The URL to share.
     * @param array $options Additional options (e.g., thumbnail_url, title).
     * @return array Returns array with platform_post_id.
     * @throws PublishingException
     */
    public function publishLink(string $accessToken, string $tokenSecret, string $targetId, string $text, string $url, array $options = []): array
    {
        try {
            $client = $this->getApiClient($accessToken);
            $payload = [
                "author" => $targetId,
                "lifecycleState" => $options["lifecycleState"] ?? "PUBLISHED",
                "specificContent" => [
                    "com.linkedin.ugc.ShareContent" => [
                        "shareCommentary" => [
                            "text" => $text,
                        ],
                        "shareMediaCategory" => "ARTICLE",
                        "media" => [
                            [
                                "status" => "READY",
                                "originalUrl" => $url,
                                // Optional: Provide title and thumbnail for better preview
                                // "title" => ["text" => $options["link_title"] ?? ""],
                                // "thumbnails" => isset($options["link_thumbnail_url"]) ? [[ "url" => $options["link_thumbnail_url"] ]] : [],
                            ],
                        ],
                    ],
                ],
                "visibility" => [
                    "com.linkedin.ugc.MemberNetworkVisibility" => $options["visibility"] ?? "PUBLIC",
                ],
            ];

            $response = $client->post("ugcPosts", [
                "json" => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $postId = $data["id"] ?? null;

            if (!$postId) {
                throw new PublishingException("Failed to publish LinkedIn link share. No ID returned.");
            }

            return [
                "platform" => "linkedin",
                "platform_post_id" => $postId,
                "raw_response" => $data,
            ];
        } catch (GuzzleException $e) {
            throw new PublishingException("Failed to publish LinkedIn link share: " . $e->getMessage());
        }
    }

    /**
     * Delete a post (Share).
     *
     * @param string $accessToken User or Organization Access Token.
     * @param string $tokenSecret Ignored.
     * @param string $postId The URN of the Share to delete (e.g., "urn:li:share:{id}" or "urn:li:ugcPost:{id}").
     * @return bool Returns true on success.
     * @throws PublishingException
     */
    public function deletePost(string $accessToken, string $tokenSecret, string $postId): bool
    {
        try {
            $client = $this->getApiClient($accessToken);
            // The endpoint depends on the type of URN, ugcPosts is more common now
            $endpoint = str_contains($postId, ":ugcPost:") ? "ugcPosts/{$postId}" : "shares/{$postId}";

            $response = $client->delete($endpoint);

            // LinkedIn DELETE returns 204 No Content on success
            return $response->getStatusCode() === 204;
        } catch (GuzzleException $e) {
            // Handle not found (404) or forbidden (403)
            if ($e->getCode() === 404) {
                return true; // Already deleted
            }
            throw new PublishingException("Failed to delete LinkedIn post: " . $e->getMessage());
        }
    }

    /**
     * Upload media (image/video) to LinkedIn.
     *
     * @param string $accessToken
     * @param string $authorUrn
     * @param string $mediaPath
     * @param string $mediaType (
     * @return string Media Asset URN.
     * @throws PublishingException
     */
    protected function uploadMedia(string $accessToken, string $authorUrn, string $mediaPath, string $mediaType): string
    {
        if (!Storage::exists($mediaPath)) {
            throw new PublishingException("Media file not found: {$mediaPath}");
        }
        $filePath = Storage::path($mediaPath);
        $fileSize = filesize($filePath);

        try {
            $client = $this->getApiClient($accessToken);

            // 1. Register Upload
            $registerPayload = [
                "registerUploadRequest" => [
                    "owner" => $authorUrn,
                    "recipes" => ["urn:li:digitalmediaRecipe:feedshare-" . strtolower($mediaType)],
                    "serviceRelationships" => [
                        [
                            "relationshipType" => "OWNER",
                            "identifier" => "urn:li:userGeneratedContent",
                        ],
                    ],
                    // Optional: Add fileSizeBytes for validation
                    // "fileSizeBytes" => $fileSize,
                ],
            ];
            $registerResponse = $client->post("assets?action=registerUpload", [
                "json" => $registerPayload,
            ]);
            $registerData = json_decode($registerResponse->getBody()->getContents(), true);

            if (!isset($registerData["value"]["uploadMechanism"]["com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest"]["uploadUrl"])) {
                throw new PublishingException("Failed to register LinkedIn media upload.");
            }

            $uploadUrl = $registerData["value"]["uploadMechanism"]["com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest"]["uploadUrl"];
            $assetUrn = $registerData["value"]["asset"];

            // 2. Upload Media File
            $uploadClient = new Client(["timeout" => 180]); // Use a separate client for the actual upload potentially without auth headers
            $uploadResponse = $uploadClient->put($uploadUrl, [
                "headers" => [
                    "Authorization" => "Bearer " . $accessToken, // Header might be needed depending on URL type
                    "Content-Type" => mime_content_type($filePath),
                    // "Content-Length" => $fileSize, // Guzzle adds this automatically
                ],
                "body" => fopen($filePath, "r"),
            ]);

            if ($uploadResponse->getStatusCode() < 200 || $uploadResponse->getStatusCode() >= 300) {
                throw new PublishingException("LinkedIn media file upload failed with status: " . $uploadResponse->getStatusCode());
            }

            // 3. Verify Upload (Optional but recommended for large files/videos)
            // You might need to poll the asset status endpoint: GET /v2/assets/{assetURN}

            return $assetUrn;
        } catch (GuzzleException | \Exception $e) {
            throw new PublishingException("LinkedIn media upload process failed: " . $e->getMessage());
        }
    }
}
