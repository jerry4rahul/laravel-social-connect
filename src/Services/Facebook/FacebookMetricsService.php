<?php

namespace VendorName\SocialConnect\Services\Facebook;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Config;
use VendorName\SocialConnect\Contracts\MetricsInterface;
use VendorName\SocialConnect\Exceptions\MetricsException;

class FacebookMetricsService implements MetricsInterface
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
     * Create a new FacebookMetricsService instance.
     */
    public function __construct()
    {
        $config = Config::get("social-connect.platforms.facebook");
        $this->graphVersion = $config["graph_version"] ?? "v18.0";

        $this->client = new Client([
            "base_uri" => "https://graph.facebook.com/{$this->graphVersion}/",
            "timeout" => 30,
        ]);
    }

    /**
     * Get account-level metrics (e.g., for a Facebook Page).
     *
     * @param string $accessToken The access token for the page.
     * @param string $targetId The ID of the Facebook Page.
     * @param array $metrics The list of metrics to retrieve (e.g., ["page_fans", "page_impressions"]).
     * @param string|null $since Start date (YYYY-MM-DD or Unix timestamp).
     * @param string|null $until End date (YYYY-MM-DD or Unix timestamp).
     * @param string $period Aggregation period (
     * @return array
     * @throws MetricsException
     */
    public function getAccountMetrics(string $accessToken, string $targetId, array $metrics, ?string $since = null, ?string $until = null, string $period = "day"): array
    {
        try {
            $params = [
                "metric" => implode(",", $metrics),
                "period" => $period,
                "access_token" => $accessToken,
            ];

            if ($since) {
                $params["since"] = $since;
            }
            if ($until) {
                $params["until"] = $until;
            }

            $response = $this->client->get("{$targetId}/insights", [
                "query" => $params,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data["data"])) {
                throw new MetricsException("Failed to retrieve account metrics from Facebook.");
            }

            // Process the data into a more usable format
            $results = [];
            foreach ($data["data"] as $metricData) {
                $metricName = $metricData["name"];
                $results[$metricName] = [
                    "name" => $metricName,
                    "period" => $metricData["period"],
                    "title" => $metricData["title"] ?? null,
                    "description" => $metricData["description"] ?? null,
                    "values" => $metricData["values"] ?? [],
                ];
            }

            return [
                "platform" => "facebook",
                "target_id" => $targetId,
                "metrics" => $results,
                "raw_response" => $data, // Include raw response for flexibility
            ];
        } catch (GuzzleException $e) {
            throw new MetricsException("Failed to get Facebook account metrics: " . $e->getMessage());
        }
    }

    /**
     * Get post-level metrics.
     *
     * @param string $accessToken The access token for the page/user.
     * @param string $postId The ID of the Facebook Post.
     * @param array $metrics The list of metrics to retrieve (e.g., ["post_impressions", "post_engaged_users"]).
     * @return array
     * @throws MetricsException
     */
    public function getPostMetrics(string $accessToken, string $postId, array $metrics): array
    {
        try {
            $params = [
                "metric" => implode(",", $metrics),
                "access_token" => $accessToken,
            ];

            $response = $this->client->get("{$postId}/insights", [
                "query" => $params,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data["data"])) {
                throw new MetricsException("Failed to retrieve post metrics from Facebook.");
            }

            // Process the data
            $results = [];
            foreach ($data["data"] as $metricData) {
                $metricName = $metricData["name"];
                // Post metrics often return a single value for the lifetime
                $value = null;
                if (isset($metricData["values"][0]["value"])) {
                    $value = $metricData["values"][0]["value"];
                }
                $results[$metricName] = [
                    "name" => $metricName,
                    "title" => $metricData["title"] ?? null,
                    "description" => $metricData["description"] ?? null,
                    "value" => $value,
                ];
            }

            return [
                "platform" => "facebook",
                "post_id" => $postId,
                "metrics" => $results,
                "raw_response" => $data,
            ];
        } catch (GuzzleException $e) {
            throw new MetricsException("Failed to get Facebook post metrics: " . $e->getMessage());
        }
    }

    /**
     * Get audience demographics (Example: Page fans by country).
     *
     * @param string $accessToken The access token for the page.
     * @param string $targetId The ID of the Facebook Page.
     * @param string $metric The specific demographic metric (e.g., "page_fans_country").
     * @param string $period Aggregation period (usually "lifetime").
     * @return array
     * @throws MetricsException
     */
    public function getAudienceDemographics(string $accessToken, string $targetId, string $metric = "page_fans_country", string $period = "lifetime"): array
    {
        // Note: Demographics are often retrieved via getAccountMetrics with specific metric names.
        // This is a convenience method for a common use case.
        try {
            $response = $this->client->get("{$targetId}/insights/{$metric}", [
                "query" => [
                    "period" => $period,
                    "access_token" => $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data["data"][0]["values"][0]["value"])) {
                throw new MetricsException("Failed to retrieve audience demographics from Facebook.");
            }

            return [
                "platform" => "facebook",
                "target_id" => $targetId,
                "metric" => $metric,
                "demographics" => $data["data"][0]["values"][0]["value"], // Typically an object with country codes as keys
                "raw_response" => $data,
            ];
        } catch (GuzzleException $e) {
            throw new MetricsException("Failed to get Facebook audience demographics: " . $e->getMessage());
        }
    }

    /**
     * Get historical data for a specific metric.
     *
     * @param string $accessToken The access token for the page.
     * @param string $targetId The ID of the Facebook Page.
     * @param string $metric The metric to retrieve (e.g., "page_fans").
     * @param string $since Start date (YYYY-MM-DD or Unix timestamp).
     * @param string $until End date (YYYY-MM-DD or Unix timestamp).
     * @param string $period Aggregation period (e.g., "day", "week", "month").
     * @return array
     * @throws MetricsException
     */
    public function getHistoricalData(string $accessToken, string $targetId, string $metric, string $since, string $until, string $period = "day"): array
    {
        // This is essentially a specific use case of getAccountMetrics
        try {
            $metricsResult = $this->getAccountMetrics($accessToken, $targetId, [$metric], $since, $until, $period);

            if (!isset($metricsResult["metrics"][$metric])) {
                 throw new MetricsException("Failed to retrieve historical data for metric '{$metric}' from Facebook.");
            }

            return [
                "platform" => "facebook",
                "target_id" => $targetId,
                "metric" => $metric,
                "period" => $period,
                "since" => $since,
                "until" => $until,
                "historical_data" => $metricsResult["metrics"][$metric]["values"],
                "raw_response" => $metricsResult["raw_response"],
            ];

        } catch (GuzzleException | MetricsException $e) {
            // Catch MetricsException from getAccountMetrics as well
            throw new MetricsException("Failed to get Facebook historical data: " . $e->getMessage());
        }
    }
}
