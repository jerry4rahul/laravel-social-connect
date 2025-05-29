<?php

namespace VendorName\SocialConnect\Services\Instagram;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Config;
use VendorName\SocialConnect\Contracts\MetricsInterface;
use VendorName\SocialConnect\Exceptions\MetricsException;

class InstagramMetricsService implements MetricsInterface
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
     * Create a new InstagramMetricsService instance.
     */
    public function __construct()
    {
        // Insights use the Instagram Graph API (via Facebook Graph API endpoint)
        $config = Config::get("social-connect.platforms.facebook"); // Use Facebook config for Graph API version
        $this->graphVersion = $config["graph_version"] ?? "v18.0";

        $this->graphClient = new Client([
            "base_uri" => "https://graph.facebook.com/{$this->graphVersion}/",
            "timeout" => 30,
        ]);
    }

    /**
     * Get account-level metrics (for Instagram Business Account).
     *
     * @param string $accessToken Facebook Page access token with Instagram permissions.
     * @param string $targetId Instagram Business Account ID.
     * @param array $metrics List of metrics (e.g., ["impressions", "reach", "profile_views"]).
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

            // Instagram Graph API uses UTC time and specific date formats might be needed
            if ($since) {
                $params["since"] = is_numeric($since) ? $since : strtotime($since);
            }
            if ($until) {
                $params["until"] = is_numeric($until) ? $until : strtotime($until);
            }

            $response = $this->graphClient->get("{$targetId}/insights", [
                "query" => $params,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data["data"])) {
                throw new MetricsException("Failed to retrieve account metrics from Instagram.");
            }

            // Process the data
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
                "platform" => "instagram",
                "target_id" => $targetId,
                "metrics" => $results,
                "raw_response" => $data,
            ];
        } catch (GuzzleException $e) {
            throw new MetricsException("Failed to get Instagram account metrics: " . $e->getMessage());
        }
    }

    /**
     * Get post-level metrics (for Instagram Media Object).
     *
     * @param string $accessToken Facebook Page access token with Instagram permissions.
     * @param string $postId Instagram Media ID.
     * @param array $metrics List of metrics (e.g., ["engagement", "impressions", "reach", "saved"]).
     * @return array
     * @throws MetricsException
     */
    public function getPostMetrics(string $accessToken, string $postId, array $metrics): array
    {
        // Note: For stories, use metric names like `impressions`, `reach`, `taps_forward`, `taps_back`, `exits`, `replies`
        try {
            $params = [
                "metric" => implode(",", $metrics),
                "access_token" => $accessToken,
            ];

            $response = $this->graphClient->get("{$postId}/insights", [
                "query" => $params,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data["data"])) {
                throw new MetricsException("Failed to retrieve post metrics from Instagram.");
            }

            // Process the data
            $results = [];
            foreach ($data["data"] as $metricData) {
                $metricName = $metricData["name"];
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
                "platform" => "instagram",
                "post_id" => $postId,
                "metrics" => $results,
                "raw_response" => $data,
            ];
        } catch (GuzzleException $e) {
            throw new MetricsException("Failed to get Instagram post metrics: " . $e->getMessage());
        }
    }

    /**
     * Get audience demographics (Example: Follower demographics by city, country, age, gender).
     *
     * @param string $accessToken Facebook Page access token with Instagram permissions.
     * @param string $targetId Instagram Business Account ID.
     * @param string $metric Specific demographic metric (e.g., "audience_city", "audience_country", "audience_gender_age").
     * @param string $period Aggregation period (usually "lifetime").
     * @return array
     * @throws MetricsException
     */
    public function getAudienceDemographics(string $accessToken, string $targetId, string $metric = "audience_gender_age", string $period = "lifetime"): array
    {
        // Demographics are retrieved via getAccountMetrics
        try {
            $metricsResult = $this->getAccountMetrics($accessToken, $targetId, [$metric], null, null, $period);

            if (!isset($metricsResult["metrics"][$metric]["values"][0]["value"])) {
                throw new MetricsException("Failed to retrieve audience demographics metric '{$metric}' from Instagram.");
            }

            return [
                "platform" => "instagram",
                "target_id" => $targetId,
                "metric" => $metric,
                "demographics" => $metricsResult["metrics"][$metric]["values"][0]["value"], // Structure depends on the metric
                "raw_response" => $metricsResult["raw_response"],
            ];
        } catch (GuzzleException | MetricsException $e) {
            throw new MetricsException("Failed to get Instagram audience demographics: " . $e->getMessage());
        }
    }

    /**
     * Get historical data for a specific metric.
     *
     * @param string $accessToken Facebook Page access token with Instagram permissions.
     * @param string $targetId Instagram Business Account ID.
     * @param string $metric The metric to retrieve (e.g., "impressions").
     * @param string $since Start date (YYYY-MM-DD or Unix timestamp).
     * @param string $until End date (YYYY-MM-DD or Unix timestamp).
     * @param string $period Aggregation period (e.g., "day", "week", "month").
     * @return array
     * @throws MetricsException
     */
    public function getHistoricalData(string $accessToken, string $targetId, string $metric, string $since, string $until, string $period = "day"): array
    {
        // This uses getAccountMetrics internally
        try {
            $metricsResult = $this->getAccountMetrics($accessToken, $targetId, [$metric], $since, $until, $period);

            if (!isset($metricsResult["metrics"][$metric])) {
                 throw new MetricsException("Failed to retrieve historical data for metric '{$metric}' from Instagram.");
            }

            return [
                "platform" => "instagram",
                "target_id" => $targetId,
                "metric" => $metric,
                "period" => $period,
                "since" => $since,
                "until" => $until,
                "historical_data" => $metricsResult["metrics"][$metric]["values"],
                "raw_response" => $metricsResult["raw_response"],
            ];

        } catch (GuzzleException | MetricsException $e) {
            throw new MetricsException("Failed to get Instagram historical data: " . $e->getMessage());
        }
    }
}
