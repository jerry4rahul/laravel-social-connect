<?php

namespace VendorName\SocialConnect\Services\Twitter;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Subscriber\Oauth\Oauth1;
use Illuminate\Support\Facades\Config;
use VendorName\SocialConnect\Contracts\MetricsInterface;
use VendorName\SocialConnect\Exceptions\MetricsException;

class TwitterMetricsService implements MetricsInterface
{
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
     * Create a new TwitterMetricsService instance.
     */
    public function __construct()
    {
        $config = Config::get("social-connect.platforms.twitter");
        $this->consumerKey = $config["consumer_key"];
        $this->consumerSecret = $config["consumer_secret"];

        // Client for API v2 calls (Bearer token or User Context OAuth 2.0 / OAuth 1.0a)
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
     * @return Client
     */
    protected function getOAuth1Client(string $accessToken, string $tokenSecret): Client
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
            "base_uri" => $this->clientV2->getConfig("base_uri"),
            "handler" => $stack,
            "auth" => "oauth",
            "timeout" => 30,
        ]);
    }

    /**
     * Get account-level metrics (User metrics from API v2).
     *
     * @param string $accessToken User Access Token.
     * @param string $tokenSecret User Access Token Secret.
     * @param string $targetId The User ID for which to fetch metrics.
     * @param array $metrics List of metrics (ignored, fetches standard public_metrics).
     * @param string|null $since Ignored.
     * @param string|null $until Ignored.
     * @param string $period Ignored.
     * @return array
     * @throws MetricsException
     */
    public function getAccountMetrics(string $accessToken, string $tokenSecret, string $targetId, array $metrics = [], ?string $since = null, ?string $until = null, string $period = "day"): array
    {
        // Twitter API v2 provides public metrics as part of the user object.
        // We fetch the user object and extract public_metrics.
        try {
            $client = $this->getOAuth1Client($accessToken, $tokenSecret);

            $response = $client->get("users/{$targetId}", [
                "query" => [
                    "user.fields" => "public_metrics",
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data["data"]["public_metrics"])) {
                throw new MetricsException("Failed to retrieve user public metrics from Twitter.");
            }

            $publicMetrics = $data["data"]["public_metrics"];

            // Format the output to somewhat match the interface
            $results = [];
            foreach ($publicMetrics as $key => $value) {
                $results[$key] = [
                    "name" => $key,
                    "title" => ucwords(str_replace("_", " ", $key)),
                    "description" => "Public metric for the user.",
                    "value" => $value,
                ];
            }

            return [
                "platform" => "twitter",
                "target_id" => $targetId,
                "metrics" => $results,
                "raw_response" => $data,
            ];
        } catch (GuzzleException $e) {
            throw new MetricsException("Failed to get Twitter account metrics: " . $e->getMessage());
        }
    }

    /**
     * Get post-level metrics (Tweet metrics from API v2).
     *
     * @param string $accessToken User Access Token.
     * @param string $tokenSecret User Access Token Secret.
     * @param string $postId The ID of the Tweet.
     * @param array $metrics List of metrics (e.g., ["impression_count", "like_count", "reply_count", "retweet_count"]).
     * @return array
     * @throws MetricsException
     */
    public function getPostMetrics(string $accessToken, string $tokenSecret, string $postId, array $metrics): array
    {
        // Filter for valid public and non-public metrics
        $validMetrics = ["impression_count", "like_count", "reply_count", "retweet_count", "quote_count", "url_link_clicks", "user_profile_clicks"]; // Add others if needed
        $requestedMetrics = array_intersect($metrics, $validMetrics);

        if (empty($requestedMetrics)) {
            throw new MetricsException("No valid metrics requested for Twitter post.");
        }

        try {
            $client = $this->getOAuth1Client($accessToken, $tokenSecret);

            $params = [
                // Combine public, non_public, organic (if needed and available)
                "tweet.fields" => "public_metrics,non_public_metrics",
            ];

            $response = $client->get("tweets/{$postId}", [
                "query" => $params,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data["data"])) {
                throw new MetricsException("Failed to retrieve Tweet metrics from Twitter.");
            }

            $tweetData = $data["data"];
            $allMetrics = array_merge(
                $tweetData["public_metrics"] ?? [],
                $tweetData["non_public_metrics"] ?? []
                // $tweetData["organic_metrics"] ?? [] // Requires specific access
            );

            // Filter for the requested metrics
            $results = [];
            foreach ($requestedMetrics as $metricName) {
                if (isset($allMetrics[$metricName])) {
                    $results[$metricName] = [
                        "name" => $metricName,
                        "title" => ucwords(str_replace("_", " ", $metricName)),
                        "description" => "Metric for the Tweet.",
                        "value" => $allMetrics[$metricName],
                    ];
                } else {
                    // Metric requested but not returned (might need different permissions or not exist)
                    $results[$metricName] = [
                        "name" => $metricName,
                        "title" => ucwords(str_replace("_", " ", $metricName)),
                        "description" => "Metric not available or requires different permissions.",
                        "value" => null,
                    ];
                }
            }

            return [
                "platform" => "twitter",
                "post_id" => $postId,
                "metrics" => $results,
                "raw_response" => $data,
            ];
        } catch (GuzzleException $e) {
            throw new MetricsException("Failed to get Twitter post metrics: " . $e->getMessage());
        }
    }

    /**
     * Get audience demographics (Not directly available via Twitter API v2 in a standard format).
     *
     * @param string $accessToken
     * @param string $tokenSecret
     * @param string $targetId
     * @param string $metric
     * @param string $period
     * @return array
     * @throws MetricsException
     */
    public function getAudienceDemographics(string $accessToken, string $tokenSecret, string $targetId, string $metric = "", string $period = "lifetime"): array
    {
        // Twitter API v2 does not provide aggregated audience demographic insights like Facebook/Instagram.
        // Analytics data might be available via Ads API or premium endpoints.
        throw new MetricsException("Audience demographics are not directly available via the standard Twitter API v2.");
    }

    /**
     * Get historical data for a specific metric (Not directly available via Twitter API v2).
     *
     * @param string $accessToken
     * @param string $tokenSecret
     * @param string $targetId
     * @param string $metric
     * @param string $since
     * @param string $until
     * @param string $period
     * @return array
     * @throws MetricsException
     */
    public function getHistoricalData(string $accessToken, string $tokenSecret, string $targetId, string $metric, string $since, string $until, string $period = "day"): array
    {
        // Twitter API v2 does not provide historical time series data for user/tweet metrics via standard endpoints.
        // This might require premium access or manual aggregation over time.
        throw new MetricsException("Historical metric data is not directly available via the standard Twitter API v2.");
    }
}
