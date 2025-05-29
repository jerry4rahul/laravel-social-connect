<?php

namespace VendorName\SocialConnect\Services\YouTube;

use Google_Client;
use Google_Service_YouTubeAnalytics;
use Google_Service_YouTube;
use Illuminate\Support\Facades\Config;
use VendorName\SocialConnect\Contracts\MetricsInterface;
use VendorName\SocialConnect\Exceptions\MetricsException;

class YouTubeMetricsService implements MetricsInterface
{
    /**
     * Google API Client.
     *
     * @var \Google_Client
     */
    protected $googleClient;

    /**
     * Create a new YouTubeMetricsService instance.
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
     * @throws MetricsException
     */
    protected function getApiClient(string $accessToken): Google_Client
    {
        if (empty($accessToken)) {
            throw new MetricsException("YouTube access token is required.");
        }
        $client = clone $this->googleClient;
        $client->setAccessToken($accessToken);
        // Add required scopes if not already present (though they should be from auth)
        $client->addScope([
            "https://www.googleapis.com/auth/youtube.readonly",
            "https://www.googleapis.com/auth/yt-analytics.readonly",
            "https://www.googleapis.com/auth/yt-analytics-monetary.readonly", // If needed
        ]);
        return $client;
    }

    /**
     * Get account-level metrics (Channel Analytics).
     *
     * @param string $accessToken User Access Token with analytics scopes.
     * @param string $tokenSecret Ignored.
     * @param string $targetId Channel ID (e.g., "UC...").
     * @param array $metrics List of metrics (e.g., ["views", "likes", "subscribersGained"]).
     * @param string|null $since Start date (YYYY-MM-DD).
     * @param string|null $until End date (YYYY-MM-DD).
     * @param string $period Ignored (YouTube Analytics API uses date range).
     * @return array
     * @throws MetricsException
     */
    public function getAccountMetrics(string $accessToken, string $tokenSecret, string $targetId, array $metrics = [], ?string $since = null, ?string $until = null, string $period = "day"): array
    {
        if (empty($metrics)) {
            $metrics = ["views", "likes", "dislikes", "comments", "shares", "estimatedMinutesWatched", "averageViewDuration", "subscribersGained", "subscribersLost"];
        }
        if (!$since || !$until) {
            // Default to last 28 days if not provided
            $until = date("Y-m-d", strtotime("-1 day")); // Data is usually delayed
            $since = date("Y-m-d", strtotime("-29 days"));
        }

        try {
            $client = $this->getApiClient($accessToken);
            $youtubeAnalyticsService = new Google_Service_YouTubeAnalytics($client);

            $response = $youtubeAnalyticsService->reports->query(
                "channel==" . $targetId, // ids
                $since, // startDate
                $until, // endDate
                implode(",", $metrics), // metrics
                [
                    // Optional parameters like dimensions, filters, sort
                    // "dimensions" => "day", // To get daily breakdown
                ]
            );

            $results = [
                "platform" => "youtube",
                "target_id" => $targetId,
                "since" => $since,
                "until" => $until,
                "metrics" => [],
                "raw_response" => $response->toSimpleObject(),
            ];

            // Process the response
            $headers = array_map(fn($header) => $header->getName(), $response->getColumnHeaders());
            $rows = $response->getRows() ?? [];

            // If no dimensions, there should be one row with totals
            if (!empty($rows) && count($rows[0]) === count($headers)) {
                $results["metrics"] = array_combine($headers, $rows[0]);
            }
            // If dimensions were used (e.g., "day"), process multiple rows
            // else if (!empty($rows)) {
            //     $dailyData = [];
            //     $dateIndex = array_search("day", $headers);
            //     foreach ($rows as $row) {
            //         $dayData = array_combine($headers, $row);
            //         $date = $dayData["day"];
            //         unset($dayData["day"]);
            //         $dailyData[$date] = $dayData;
            //     }
            //     $results["metrics"] = $dailyData; // Or structure as needed
            // }

            return $results;
        } catch (\Exception $e) {
            throw new MetricsException("Failed to get YouTube account metrics: " . $e->getMessage());
        }
    }

    /**
     * Get post-level metrics (Video Analytics).
     *
     * @param string $accessToken User Access Token.
     * @param string $tokenSecret Ignored.
     * @param string $postId The Video ID.
     * @param array $metrics List of metrics.
     * @return array
     * @throws MetricsException
     */
    public function getPostMetrics(string $accessToken, string $tokenSecret, string $postId, array $metrics = []): array
    {
        if (empty($metrics)) {
            $metrics = ["views", "likes", "dislikes", "comments", "shares", "estimatedMinutesWatched", "averageViewDuration"];
        }

        // YouTube Analytics API requires start and end dates even for video totals
        // Use channel lifetime or a very long period for totals
        $since = "2005-02-14"; // YouTube launch date
        $until = date("Y-m-d");

        try {
            $client = $this->getApiClient($accessToken);
            $youtubeAnalyticsService = new Google_Service_YouTubeAnalytics($client);

            $response = $youtubeAnalyticsService->reports->query(
                "channel==MINE", // Use MINE for authenticated user
                $since,
                $until,
                implode(",", $metrics),
                [
                    "filters" => "video==" . $postId,
                ]
            );

            $results = [
                "platform" => "youtube",
                "post_id" => $postId,
                "metrics" => [],
                "raw_response" => $response->toSimpleObject(),
            ];

            $headers = array_map(fn($header) => $header->getName(), $response->getColumnHeaders());
            $rows = $response->getRows() ?? [];

            if (!empty($rows) && count($rows[0]) === count($headers)) {
                $results["metrics"] = array_combine($headers, $rows[0]);
            }

            return $results;
        } catch (\Exception $e) {
            throw new MetricsException("Failed to get YouTube post metrics: " . $e->getMessage());
        }
    }

    /**
     * Get audience demographics (Channel Analytics with dimensions).
     *
     * @param string $accessToken User Access Token.
     * @param string $tokenSecret Ignored.
     * @param string $targetId Channel ID.
     * @param string $metric Metric to analyze (e.g., "views", "estimatedMinutesWatched").
     * @param string $period Dimension for grouping (e.g., "ageGroup", "gender", "country").
     * @return array
     * @throws MetricsException
     */
    public function getAudienceDemographics(string $accessToken, string $tokenSecret, string $targetId, string $metric = "views", string $period = "ageGroup"): array
    {
        // Use channel lifetime or a recent period for demographics
        $until = date("Y-m-d", strtotime("-1 day"));
        $since = date("Y-m-d", strtotime("-29 days")); // e.g., last 28 days

        // Map period to valid YouTube Analytics dimensions
        $validDimensions = ["ageGroup", "gender", "country", "insightTrafficSourceType"];
        if (!in_array($period, $validDimensions)) {
            throw new MetricsException("Invalid dimension for YouTube audience demographics: {$period}. Valid dimensions are: " . implode(", ", $validDimensions));
        }

        try {
            $client = $this->getApiClient($accessToken);
            $youtubeAnalyticsService = new Google_Service_YouTubeAnalytics($client);

            $response = $youtubeAnalyticsService->reports->query(
                "channel==" . $targetId,
                $since,
                $until,
                $metric,
                [
                    "dimensions" => $period,
                    "sort" => "-" . $metric, // Sort by the metric descending
                ]
            );

            $results = [
                "platform" => "youtube",
                "target_id" => $targetId,
                "metric" => $metric,
                "dimension" => $period,
                "since" => $since,
                "until" => $until,
                "demographics" => [],
                "raw_response" => $response->toSimpleObject(),
            ];

            $headers = array_map(fn($header) => $header->getName(), $response->getColumnHeaders());
            $rows = $response->getRows() ?? [];

            if (!empty($rows)) {
                $dimensionIndex = array_search($period, $headers);
                $metricIndex = array_search($metric, $headers);

                if ($dimensionIndex !== false && $metricIndex !== false) {
                    foreach ($rows as $row) {
                        $results["demographics"][$row[$dimensionIndex]] = $row[$metricIndex];
                    }
                }
            }

            return $results;
        } catch (\Exception $e) {
            throw new MetricsException("Failed to get YouTube audience demographics: " . $e->getMessage());
        }
    }

    /**
     * Get historical data for a specific metric (Use getAccountMetrics with date range and daily dimension).
     *
     * @param string $accessToken User Access Token.
     * @param string $tokenSecret Ignored.
     * @param string $targetId Channel ID.
     * @param string $metric The specific metric to query (e.g., "views").
     * @param string $since Start date (YYYY-MM-DD).
     * @param string $until End date (YYYY-MM-DD).
     * @param string $period Ignored (always fetches daily).
     * @return array
     * @throws MetricsException
     */
    public function getHistoricalData(string $accessToken, string $tokenSecret, string $targetId, string $metric, string $since, string $until, string $period = "day"): array
    {
        try {
            $client = $this->getApiClient($accessToken);
            $youtubeAnalyticsService = new Google_Service_YouTubeAnalytics($client);

            $response = $youtubeAnalyticsService->reports->query(
                "channel==" . $targetId,
                $since,
                $until,
                $metric,
                [
                    "dimensions" => "day", // Group by day
                    "sort" => "day", // Sort by date ascending
                ]
            );

            $results = [
                "platform" => "youtube",
                "target_id" => $targetId,
                "metric" => $metric,
                "since" => $since,
                "until" => $until,
                "historical_data" => [],
                "raw_response" => $response->toSimpleObject(),
            ];

            $headers = array_map(fn($header) => $header->getName(), $response->getColumnHeaders());
            $rows = $response->getRows() ?? [];

            if (!empty($rows)) {
                $dateIndex = array_search("day", $headers);
                $metricIndex = array_search($metric, $headers);

                if ($dateIndex !== false && $metricIndex !== false) {
                    foreach ($rows as $row) {
                        $results["historical_data"][$row[$dateIndex]] = $row[$metricIndex];
                    }
                }
            }

            return $results;
        } catch (\Exception $e) {
            throw new MetricsException("Failed to get YouTube historical data: " . $e->getMessage());
        }
    }
}
