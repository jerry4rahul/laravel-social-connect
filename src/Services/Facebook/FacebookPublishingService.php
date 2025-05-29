<?php

namespace VendorName\SocialConnect\Services\Facebook;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use VendorName\SocialConnect\Contracts\PublishableInterface;
use VendorName\SocialConnect\Exceptions\PublishingException;

class FacebookPublishingService implements PublishableInterface
{
    /**
     * The HTTP client instance.
     *
     * @var \GuzzleHttp\Client
     */
    protected $client;

    /**
     * Facebook Graph API version.
     *
     * @var string
     */
    protected $graphVersion;

    /**
     * Create a new FacebookPublishingService instance.
     */
    public function __construct()
    {
        $config = Config::get("social-connect.platforms.facebook");
        $this->graphVersion = $config["graph_version"] ?? "v18.0";

        $this->client = new Client([
            "base_uri" => "https://graph.facebook.com/{$this->graphVersion}/",
            "timeout" => 60, // Increased timeout for potential uploads
        ]);
    }

    /**
     * Publish a text post.
     *
     * @param string $accessToken The access token for the page/user.
     * @param string $targetId The ID of the Facebook Page or User to post to.
     * @param string $text The text content of the post.
     * @param array $options Additional options (e.g., link, scheduled_publish_time).
     * @return array Returns array with platform_post_id.
     * @throws PublishingException
     */
    public function publishText(string $accessToken, string $targetId, string $text, array $options = []): array
    {
        $payload = [
            "message" => $text,
            "access_token" => $accessToken,
        ];

        if (isset($options["link"])) {
            $payload["link"] = $options["link"];
        }

        if (isset($options["scheduled_publish_time"])) {
            $payload["published"] = false;
            $payload["scheduled_publish_time"] = $options["scheduled_publish_time"]; // Unix timestamp or ISO 8601
        }

        try {
            $response = $this->client->post("{$targetId}/feed", [
                "form_params" => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data["id"])) {
                throw new PublishingException("Failed to publish text post to Facebook. No post ID returned.");
            }

            return [
                "platform" => "facebook",
                "platform_post_id" => $data["id"],
            ];
        } catch (GuzzleException $e) {
            throw new PublishingException("Failed to publish text post to Facebook: " . $e->getMessage());
        }
    }

    /**
     * Publish an image post.
     *
     * @param string $accessToken The access token for the page/user.
     * @param string $targetId The ID of the Facebook Page or User to post to.
     * @param string $caption The caption for the image.
     * @param string|array $imagePaths Path(s) to the image file(s) on local storage.
     * @param array $options Additional options (e.g., scheduled_publish_time).
     * @return array Returns array with platform_post_id.
     * @throws PublishingException
     */
    public function publishImage(string $accessToken, string $targetId, string $caption, $imagePaths, array $options = []): array
    {
        $imagePaths = (array) $imagePaths;
        $isMultiImage = count($imagePaths) > 1;

        try {
            if ($isMultiImage) {
                // Multi-image post (requires uploading photos first and then publishing feed with attached_media)
                $attachedMedia = [];
                foreach ($imagePaths as $imagePath) {
                    $uploadResponse = $this->uploadPhoto($accessToken, $targetId, $imagePath, false); // Upload unpublished
                    $attachedMedia[] = ["media_fbid" => $uploadResponse["id"]];
                }

                $payload = [
                    "message" => $caption,
                    "attached_media" => json_encode($attachedMedia),
                    "access_token" => $accessToken,
                ];

                if (isset($options["scheduled_publish_time"])) {
                    $payload["published"] = false;
                    $payload["scheduled_publish_time"] = $options["scheduled_publish_time"];
                }

                $endpoint = "{$targetId}/feed";
            } else {
                // Single image post
                $imagePath = $imagePaths[0];
                $payload = [
                    [
                        "name" => "message",
                        "contents" => $caption,
                    ],
                    [
                        "name" => "source",
                        "contents" => fopen(Storage::path($imagePath), "r"),
                        "filename" => basename($imagePath),
                    ],
                    [
                        "name" => "access_token",
                        "contents" => $accessToken,
                    ],
                ];

                if (isset($options["scheduled_publish_time"])) {
                    $payload[] = ["name" => "published", "contents" => "false"];
                    $payload[] = ["name" => "scheduled_publish_time", "contents" => $options["scheduled_publish_time"]];
                }
                $endpoint = "{$targetId}/photos";
            }

            $response = $this->client->post($endpoint, [
                ($isMultiImage ? "form_params" : "multipart") => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $postId = $data["id"] ?? ($data["post_id"] ?? null); // post_id for single photo, id for feed post

            if (!$postId) {
                throw new PublishingException("Failed to publish image post to Facebook. No post ID returned.");
            }

            return [
                "platform" => "facebook",
                "platform_post_id" => $postId,
            ];
        } catch (GuzzleException | \Exception $e) {
            throw new PublishingException("Failed to publish image post to Facebook: " . $e->getMessage());
        }
    }

    /**
     * Publish a video post.
     *
     * @param string $accessToken The access token for the page/user.
     * @param string $targetId The ID of the Facebook Page or User to post to.
     * @param string $description The description for the video.
     * @param string $videoPath Path to the video file on local storage.
     * @param string|null $thumbnailPath Optional path to the thumbnail file.
     * @param array $options Additional options (e.g., title, scheduled_publish_time).
     * @return array Returns array with platform_post_id.
     * @throws PublishingException
     */
    public function publishVideo(string $accessToken, string $targetId, string $description, string $videoPath, ?string $thumbnailPath = null, array $options = []): array
    {
        // Note: Video uploads can be complex (resumable uploads recommended for large files)
        // This is a simplified example using single request upload.
        try {
            $payload = [
                [
                    "name" => "description",
                    "contents" => $description,
                ],
                [
                    "name" => "source",
                    "contents" => fopen(Storage::path($videoPath), "r"),
                    "filename" => basename($videoPath),
                ],
                [
                    "name" => "access_token",
                    "contents" => $accessToken,
                ],
            ];

            if (isset($options["title"])) {
                $payload[] = ["name" => "title", "contents" => $options["title"]];
            }

            if ($thumbnailPath) {
                 $payload[] = [
                    "name" => "thumb",
                    "contents" => fopen(Storage::path($thumbnailPath), "r"),
                    "filename" => basename($thumbnailPath),
                ];
            }

            if (isset($options["scheduled_publish_time"])) {
                $payload[] = ["name" => "published", "contents" => "false"];
                $payload[] = ["name" => "scheduled_publish_time", "contents" => $options["scheduled_publish_time"]];
            }

            $response = $this->client->post("{$targetId}/videos", [
                "multipart" => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data["id"])) {
                throw new PublishingException("Failed to publish video post to Facebook. No post ID returned.");
            }

            // Note: Video processing takes time. The ID returned here is the video ID, not necessarily the final post ID immediately.
            // The post might appear later after processing.
            return [
                "platform" => "facebook",
                "platform_post_id" => $data["id"], // This is the video ID
                "status" => "processing", // Indicate video needs processing
            ];
        } catch (GuzzleException | \Exception $e) {
            throw new PublishingException("Failed to publish video post to Facebook: " . $e->getMessage());
        }
    }

    /**
     * Publish a link post (handled by publishText with link option).
     *
     * @param string $accessToken The access token for the page/user.
     * @param string $targetId The ID of the Facebook Page or User to post to.
     * @param string $text The text content accompanying the link.
     * @param string $url The URL to share.
     * @param array $options Additional options (e.g., scheduled_publish_time).
     * @return array Returns array with platform_post_id.
     * @throws PublishingException
     */
    public function publishLink(string $accessToken, string $targetId, string $text, string $url, array $options = []): array
    {
        $options["link"] = $url;
        return $this->publishText($accessToken, $targetId, $text, $options);
    }

    /**
     * Delete a post.
     *
     * @param string $accessToken The access token for the page/user.
     * @param string $postId The ID of the post to delete.
     * @return bool Returns true on success.
     * @throws PublishingException
     */
    public function deletePost(string $accessToken, string $postId): bool
    {
        try {
            $response = $this->client->delete($postId, [
                "query" => [
                    "access_token" => $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data["success"]) || !$data["success"]) {
                // Check for specific error messages if needed
                throw new PublishingException("Failed to delete post from Facebook.");
            }

            return true;
        } catch (GuzzleException $e) {
            // Handle cases like post already deleted or insufficient permissions
            throw new PublishingException("Failed to delete post from Facebook: " . $e->getMessage());
        }
    }

    /**
     * Helper function to upload a photo (used for multi-image posts).
     *
     * @param string $accessToken
     * @param string $targetId
     * @param string $imagePath
     * @param bool $published
     * @return array
     * @throws PublishingException
     */
    protected function uploadPhoto(string $accessToken, string $targetId, string $imagePath, bool $published = true): array
    {
        try {
            $payload = [
                [
                    "name" => "source",
                    "contents" => fopen(Storage::path($imagePath), "r"),
                    "filename" => basename($imagePath),
                ],
                [
                    "name" => "access_token",
                    "contents" => $accessToken,
                ],
                [
                    "name" => "published",
                    "contents" => $published ? "true" : "false",
                ],
            ];

            $response = $this->client->post("{$targetId}/photos", [
                "multipart" => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data["id"])) {
                throw new PublishingException("Failed to upload photo to Facebook.");
            }
            return $data;
        } catch (GuzzleException | \Exception $e) {
            throw new PublishingException("Failed to upload photo to Facebook: " . $e->getMessage());
        }
    }
}
