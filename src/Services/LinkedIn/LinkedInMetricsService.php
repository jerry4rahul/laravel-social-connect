<?php

namespace VendorName\SocialConnect\Services\LinkedIn;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Config;
use VendorName\SocialConnect\Contracts\MetricsInterface;
use VendorName\SocialConnect\Exceptions\MetricsException;

class LinkedInMetricsService implements MetricsInterface
{
    /**
     * The HTTP client instance for LinkedIn API v2.
     *
     * @var \GuzzleHttp\Client
     */
    protected $client;

    /**
     * Create a new LinkedInMetricsService instance.
     */
    public function __construct()
    {
        $this->client = new Client([
            "base_uri" => "https://api.linkedin.com/v2/",
            "timeout" => 60,
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
        return new Client([
            "base_uri" => $this->client->getConfig("base_uri"),
            "timeout" => $this->client->getConfig("timeout"),
            "headers" => [
                "Authorization" => "Bearer " . $accessToken,
                "Connection" => "Keep-Alive",
                "X-Restli-Protocol-Version" => "2.0.0",
                "Accept" => "application/json",
            ],
        ]);
    }

    /**
     * Get account-level metrics (Organization Brand or Page Analytics).
     *
     * @param string $accessToken Organization Access Token with analytics permissions.
     * @param string $tokenSecret Ignored.
     * @param string $targetId The Organization URN (e.g., "urn:li:organization:{id}").
     * @param array $metrics List of metrics (ignored, fetches standard analytics).
     * @param string|null $since Start date (YYYY-MM-DD).
     * @param string|null $until End date (YYYY-MM-DD).
     * @param string $period Time granularity (
     * @return array
     * @throws MetricsException
     */
    public function getAccountMetrics(string $accessToken, string $tokenSecret, string $targetId, array $metrics = [], ?string $since = null, ?string $until = null, string $period = "day"): array
    {
        // LinkedIn provides analytics via specific endpoints, often requiring time bounds.
        // Example: Fetching follower statistics.
        if (!$since || !$until) {
            // Default to last 30 days if not provided
            $until = date("Y-m-d");
            $since = date("Y-m-d", strtotime("-30 days"));
        }

        try {
            $client = $this->getApiClient($accessToken);
            $timeGranularity = strtoupper($period); // DAY, MONTH
            $startDate = new \DateTime($since);
            $endDate = new \DateTime($until);

            $params = [
                "q" => "organizationalEntity",
                "organizationalEntity" => $targetId,
                "timeIntervals.timeGranularityType" => $timeGranularity,
                "timeIntervals.timeRange.start" => $startDate->getTimestamp() * 1000, // Milliseconds
                "timeIntervals.timeRange.end" => $endDate->getTimestamp() * 1000, // Milliseconds
            ];

            // Example: Fetch follower statistics
            // Different endpoints exist for different metrics (e.g., shareStatistics, pageStatistics)
            $response = $client->get("organizationPageStatistics", [
                "query" => $params,
            ]);
            // Alternative endpoint for follower counts:
            // $response = $client->get("organizationalEntityFollowerStatistics", ["query" => $params]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data["elements"])) {
                throw new MetricsException("Failed to retrieve account metrics (organizationPageStatistics) from LinkedIn.");
            }

            // Process the data - structure varies greatly depending on the endpoint called
            // This is a simplified example for pageStatistics
            $results = [];
            foreach ($data["elements"] as $element) {
                $timeRange = $element["timeRange"];
                $stats = $element["pageStatisticsByShare"] ?? ($element["pageStatisticsByPage"] ?? []); // Structure varies
                $dateKey = date("Y-m-d", ($timeRange["start"] ?? 0) / 1000);

                $results[$dateKey] = [
                    "date" => $dateKey,
                    "views" => $stats["views"]["allPageViews"]["pageViews"] ?? 0,
                    "unique_visitors" => $stats["views"]["allPageViews"]["uniqueVisitors"] ?? 0,
                    // Add more metrics as needed based on the specific endpoint and permissions
                ];
            }

            return [
                "platform" => "linkedin",
                "target_id" => $targetId,
                "metrics_type" => "organizationPageStatistics", // Indicate which endpoint was used
                "metrics" => array_values($results), // Return as a list of daily/monthly stats
                "raw_response" => $data,
            ];
        } catch (GuzzleException | \Exception $e) {
            throw new MetricsException("Failed to get LinkedIn account metrics: " . $e->getMessage());
        }
    }

    /**
     * Get post-level metrics (Share Statistics).
     *
     * @param string $accessToken Organization Access Token.
     * @param string $tokenSecret Ignored.
     * @param string $postId The Share URN (e.g., "urn:li:share:{id}" or "urn:li:ugcPost:{id}").
     * @param array $metrics List of metrics (ignored, fetches standard share stats).
     * @return array
     * @throws MetricsException
     */
    public function getPostMetrics(string $accessToken, string $tokenSecret, string $postId, array $metrics = []): array
    {
        try {
            $client = $this->getApiClient($accessToken);
            $ugcPostUrn = str_contains($postId, ":ugcPost:") ? $postId : "urn:li:ugcPost:" . explode(":", $postId)[3];

            $params = [
                "q" => "share",
                "share" => $ugcPostUrn, // Use the UGC Post URN
            ];

            // Endpoint for total share statistics
            $response = $client->get("totalShareStatistics", [
                "query" => $params,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data["elements"][0])) {
                throw new MetricsException("Failed to retrieve post metrics (totalShareStatistics) from LinkedIn.");
            }

            $stats = $data["elements"][0];
            $results = [
                "impression_count" => [
                    "name" => "impression_count",
                    "value" => $stats["impressionCount"] ?? 0,
                ],
                "click_count" => [
                    "name" => "click_count",
                    "value" => $stats["clickCount"] ?? 0,
                ],
                "engagement" => [
                    "name" => "engagement",
                    "value" => $stats["engagement"] ?? 0.0,
                ],
                "like_count" => [
                    "name" => "like_count",
                    "value" => $stats["likeCount"] ?? 0,
                ],
                "comment_count" => [
                    "name" => "comment_count",
                    "value" => $stats["commentCount"] ?? 0,
                ],
                "share_count" => [
                    "name" => "share_count",
                    "value" => $stats["shareCount"] ?? 0,
                ],
                // Add commentMentionsCount, shareMentionsCount if needed
            ];

            return [
                "platform" => "linkedin",
                "post_id" => $postId,
                "metrics" => $results,
                "raw_response" => $data,
            ];
        } catch (GuzzleException $e) {
            throw new MetricsException("Failed to get LinkedIn post metrics: " . $e->getMessage());
        }
    }

    /**
     * Get audience demographics (Not directly available via standard V2 API in a simple format).
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
        // LinkedIn provides follower demographics via organizationalEntityFollowerStatistics endpoint.
        // Example: Fetch follower counts by country.
        try {
            $client = $this->getApiClient($accessToken);
            $params = [
                "q" => "organizationalEntityFollowerStatistics",
                "organizationalEntity" => $targetId,
                // Specify facets for demographics, e.g., geoCountry
                "facet" => "geoCountry"
                // Other facets: companySize, industry, function, seniority, staffCountRange
            ];

            $response = $client->get("organizationalEntityFollowerStatistics", [
                "query" => $params,
            ]);
            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data["elements"])) {
                 throw new MetricsException("Failed to retrieve audience demographics (follower stats) from LinkedIn.");
            }

            // Process the faceted data
            $demographics = [];
            foreach($data["elements"] as $element) {
                $facetValue = $element["facetValues"][0] ?? null; // e.g., urn:li:geo:103644278
                $count = $element["followerCounts"]["organicFollowerCount"] ?? ($element["followerCounts"]["paidFollowerCount"] ?? 0);
                if ($facetValue) {
                    // You might need another call to resolve the URN (e.g., geo URN to country name)
                    $demographics[$facetValue] = $count;
                }
            }

            return [
                "platform" => "linkedin",
                "target_id" => $targetId,
                "metric" => "follower_demographics_by_geoCountry", // Example metric name
                "demographics" => $demographics,
                "raw_response" => $data,
            ];

        } catch (GuzzleException $e) {
            throw new MetricsException("Failed to get LinkedIn audience demographics: " . $e->getMessage());
        }
    }

    /**
     * Get historical data for a specific metric (Use getAccountMetrics with date range).
     *
     * @param string $accessToken
     * @param string $tokenSecret
     * @param string $targetId
     * @param string $metric The specific metric endpoint/type to query (e.g., "organizationPageStatistics").
     * @param string $since Start date (YYYY-MM-DD).
     * @param string $until End date (YYYY-MM-DD).
     * @param string $period Aggregation period (
     * @return array
     * @throws MetricsException
     */
    public function getHistoricalData(string $accessToken, string $tokenSecret, string $targetId, string $metric, string $since, string $until, string $period = "day"): array
    {
        // This essentially calls getAccountMetrics or a similar time-series endpoint.
        // The `$metric` parameter here could determine which endpoint to call if multiple exist.
        if ($metric === "organizationPageStatistics") {
            // Call getAccountMetrics which already handles time ranges
            try {
                $accountMetrics = $this->getAccountMetrics($accessToken, $tokenSecret, $targetId, [], $since, $until, $period);
                // Reformat slightly if needed
                return [
                    "platform" => "linkedin",
                    "target_id" => $targetId,
                    "metric" => $metric,
                    "period" => $period,
                    "since" => $since,
                    "until" => $until,
                    "historical_data" => $accountMetrics["metrics"], // Already formatted by getAccountMetrics
                    "raw_response" => $accountMetrics["raw_response"],
                ];
            } catch (MetricsException $e) {
                 throw new MetricsException("Failed to get LinkedIn historical data ({$metric}): " . $e->getMessage());
            }
        } else {
            throw new MetricsException("Unsupported historical metric type for LinkedIn: {$metric}");
        }
    }
}
