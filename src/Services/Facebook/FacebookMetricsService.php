<?php

namespace VendorName\SocialConnect\Services\Facebook;

use GuzzleHttp\Client;
use VendorName\SocialConnect\Contracts\MetricsInterface;
use VendorName\SocialConnect\Exceptions\MetricsException;
use VendorName\SocialConnect\Models\SocialAccount;
use VendorName\SocialConnect\Models\SocialMetric;
use VendorName\SocialConnect\Models\SocialPost;

class FacebookMetricsService implements MetricsInterface
{
    /**
     * The HTTP client instance.
     *
     * @var \GuzzleHttp\Client
     */
    protected $client;

    /**
     * The social account instance.
     *
     * @var \VendorName\SocialConnect\Models\SocialAccount
     */
    protected $account;

    /**
     * Create a new FacebookMetricsService instance.
     *
     * @param \VendorName\SocialConnect\Models\SocialAccount $account
     */
    public function __construct(SocialAccount $account)
    {
        $this->account = $account;
        $this->client = new Client([
            'base_uri' => 'https://graph.facebook.com/v18.0/',
            'timeout' => 30,
        ]);
    }

    /**
     * Get account-level metrics.
     *
     * @param string $period
     * @param array $metrics
     * @return array
     * @throws \VendorName\SocialConnect\Exceptions\MetricsException
     */
    public function getAccountMetrics(string $period = 'last_30_days', array $metrics = []): array
    {
        try {
            $pageId = $this->getDefaultPageId();
            $accessToken = $this->getPageAccessToken($pageId);
            
            // Default metrics if none provided
            if (empty($metrics)) {
                $metrics = [
                    'page_impressions',
                    'page_impressions_unique',
                    'page_engaged_users',
                    'page_post_engagements',
                    'page_fans',
                    'page_fan_adds',
                    'page_views_total',
                ];
            }
            
            // Convert period to date range
            $dateRange = $this->convertPeriodToDateRange($period);
            
            // Get insights
            $response = $this->client->get("{$pageId}/insights", [
                'query' => [
                    'metric' => implode(',', $metrics),
                    'period' => 'day',
                    'since' => $dateRange['start'],
                    'until' => $dateRange['end'],
                    'access_token' => $accessToken,
                ],
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['data'])) {
                throw new MetricsException('Failed to retrieve account metrics from Facebook.');
            }
            
            $results = [];
            
            foreach ($data['data'] as $metricData) {
                $metricName = $metricData['name'];
                $metricValues = $metricData['values'];
                
                $results[$metricName] = [
                    'values' => $metricValues,
                    'period' => $period,
                    'title' => $this->getMetricTitle($metricName),
                    'description' => $this->getMetricDescription($metricName),
                ];
                
                // Store in database
                $this->storeAccountMetric($metricName, $metricValues, $dateRange['start_date'], $dateRange['end_date']);
            }
            
            return $results;
        } catch (\Exception $e) {
            throw new MetricsException('Failed to get account metrics from Facebook: ' . $e->getMessage());
        }
    }
    
    /**
     * Get post-level metrics.
     *
     * @param string $postId
     * @param array $metrics
     * @return array
     * @throws \VendorName\SocialConnect\Exceptions\MetricsException
     */
    public function getPostMetrics(string $postId, array $metrics = []): array
    {
        try {
            $accessToken = $this->getPageAccessToken($this->getDefaultPageId());
            
            // Default metrics if none provided
            if (empty($metrics)) {
                $metrics = [
                    'post_impressions',
                    'post_impressions_unique',
                    'post_engaged_users',
                    'post_reactions_by_type_total',
                    'post_clicks',
                    'post_video_views',
                ];
            }
            
            // Get insights
            $response = $this->client->get("{$postId}/insights", [
                'query' => [
                    'metric' => implode(',', $metrics),
                    'access_token' => $accessToken,
                ],
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['data'])) {
                throw new MetricsException('Failed to retrieve post metrics from Facebook.');
            }
            
            $results = [];
            
            foreach ($data['data'] as $metricData) {
                $metricName = $metricData['name'];
                $metricValues = $metricData['values'];
                
                $results[$metricName] = [
                    'values' => $metricValues,
                    'title' => $this->getMetricTitle($metricName),
                    'description' => $this->getMetricDescription($metricName),
                ];
                
                // Store in database
                $this->storePostMetric($postId, $metricName, $metricValues);
            }
            
            return $results;
        } catch (\Exception $e) {
            throw new MetricsException('Failed to get post metrics from Facebook: ' . $e->getMessage());
        }
    }
    
    /**
     * Get metrics for multiple posts.
     *
     * @param array $postIds
     * @param array $metrics
     * @return array
     * @throws \VendorName\SocialConnect\Exceptions\MetricsException
     */
    public function getBulkPostMetrics(array $postIds, array $metrics = []): array
    {
        $results = [];
        
        foreach ($postIds as $postId) {
            $results[$postId] = $this->getPostMetrics($postId, $metrics);
        }
        
        return $results;
    }
    
    /**
     * Get audience demographics.
     *
     * @param array $dimensions
     * @return array
     * @throws \VendorName\SocialConnect\Exceptions\MetricsException
     */
    public function getAudienceDemographics(array $dimensions = []): array
    {
        try {
            $pageId = $this->getDefaultPageId();
            $accessToken = $this->getPageAccessToken($pageId);
            
            // Default dimensions if none provided
            if (empty($dimensions)) {
                $dimensions = [
                    'age',
                    'gender',
                    'country',
                ];
            }
            
            $results = [];
            
            foreach ($dimensions as $dimension) {
                $metric = "page_fans_{$dimension}";
                
                $response = $this->client->get("{$pageId}/insights", [
                    'query' => [
                        'metric' => $metric,
                        'access_token' => $accessToken,
                    ],
                ]);
                
                $data = json_decode($response->getBody()->getContents(), true);
                
                if (isset($data['data'][0]['values'][0]['value'])) {
                    $results[$dimension] = $data['data'][0]['values'][0]['value'];
                    
                    // Store in database
                    $this->storeAccountMetric(
                        "audience_{$dimension}",
                        $data['data'][0]['values'],
                        now()->subDay(),
                        now()
                    );
                }
            }
            
            return $results;
        } catch (\Exception $e) {
            throw new MetricsException('Failed to get audience demographics from Facebook: ' . $e->getMessage());
        }
    }
    
    /**
     * Get account growth metrics over time.
     *
     * @param string $startDate
     * @param string $endDate
     * @param string $interval
     * @return array
     * @throws \VendorName\SocialConnect\Exceptions\MetricsException
     */
    public function getAccountGrowth(string $startDate, string $endDate, string $interval = 'day'): array
    {
        try {
            $pageId = $this->getDefaultPageId();
            $accessToken = $this->getPageAccessToken($pageId);
            
            $metrics = [
                'page_fans',
                'page_fan_adds',
                'page_fan_removes',
            ];
            
            $response = $this->client->get("{$pageId}/insights", [
                'query' => [
                    'metric' => implode(',', $metrics),
                    'period' => $interval,
                    'since' => $startDate,
                    'until' => $endDate,
                    'access_token' => $accessToken,
                ],
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['data'])) {
                throw new MetricsException('Failed to retrieve account growth metrics from Facebook.');
            }
            
            $results = [];
            
            foreach ($data['data'] as $metricData) {
                $metricName = $metricData['name'];
                $metricValues = $metricData['values'];
                
                $results[$metricName] = [
                    'values' => $metricValues,
                    'title' => $this->getMetricTitle($metricName),
                    'description' => $this->getMetricDescription($metricName),
                ];
                
                // Store in database
                $this->storeAccountMetric(
                    $metricName,
                    $metricValues,
                    new \DateTime($startDate),
                    new \DateTime($endDate)
                );
            }
            
            return $results;
        } catch (\Exception $e) {
            throw new MetricsException('Failed to get account growth metrics from Facebook: ' . $e->getMessage());
        }
    }
    
    /**
     * Store account-level metric in the database.
     *
     * @param string $metricType
     * @param array $metricValue
     * @param \DateTime $periodStart
     * @param \DateTime $periodEnd
     * @return \VendorName\SocialConnect\Models\SocialMetric
     */
    protected function storeAccountMetric(string $metricType, array $metricValue, \DateTime $periodStart, \DateTime $periodEnd): SocialMetric
    {
        return SocialMetric::updateOrCreate(
            [
                'user_id' => $this->account->user_id,
                'social_account_id' => $this->account->id,
                'metric_type' => $metricType,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ],
            [
                'metric_value' => $metricValue,
            ]
        );
    }
    
    /**
     * Store post-level metric in the database.
     *
     * @param string $postId
     * @param string $metricType
     * @param array $metricValue
     * @return \VendorName\SocialConnect\Models\SocialMetric
     */
    protected function storePostMetric(string $postId, string $metricType, array $metricValue): SocialMetric
    {
        $socialPost = SocialPost::where('platform_post_id', $postId)
            ->where('platform', 'facebook')
            ->first();
        
        if (!$socialPost) {
            return new SocialMetric();
        }
        
        return SocialMetric::updateOrCreate(
            [
                'user_id' => $this->account->user_id,
                'social_account_id' => $this->account->id,
                'social_post_id' => $socialPost->id,
                'metric_type' => $metricType,
            ],
            [
                'metric_value' => $metricValue,
                'period_start' => now()->subDays(30),
                'period_end' => now(),
            ]
        );
    }
    
    /**
     * Convert period string to date range.
     *
     * @param string $period
     * @return array
     */
    protected function convertPeriodToDateRange(string $period): array
    {
        $endDate = now();
        $startDate = null;
        
        switch ($period) {
            case 'today':
                $startDate = now()->startOfDay();
                break;
            case 'yesterday':
                $startDate = now()->subDay()->startOfDay();
                $endDate = now()->subDay()->endOfDay();
                break;
            case 'last_7_days':
                $startDate = now()->subDays(7);
                break;
            case 'last_14_days':
                $startDate = now()->subDays(14);
                break;
            case 'last_30_days':
                $startDate = now()->subDays(30);
                break;
            case 'last_90_days':
                $startDate = now()->subDays(90);
                break;
            case 'this_month':
                $startDate = now()->startOfMonth();
                break;
            case 'last_month':
                $startDate = now()->subMonth()->startOfMonth();
                $endDate = now()->subMonth()->endOfMonth();
                break;
            default:
                $startDate = now()->subDays(30);
                break;
        }
        
        return [
            'start' => $startDate->format('Y-m-d'),
            'end' => $endDate->format('Y-m-d'),
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];
    }
    
    /**
     * Get the default page ID from the account metadata.
     *
     * @return string
     * @throws \VendorName\SocialConnect\Exceptions\MetricsException
     */
    protected function getDefaultPageId(): string
    {
        $metadata = $this->account->metadata;
        
        if (isset($metadata['pages']) && !empty($metadata['pages'])) {
            return $metadata['pages'][0]['id'];
        }
        
        throw new MetricsException('No Facebook page found for this account.');
    }
    
    /**
     * Get the page access token for a specific page.
     *
     * @param string $pageId
     * @return string
     * @throws \VendorName\SocialConnect\Exceptions\MetricsException
     */
    protected function getPageAccessToken(string $pageId): string
    {
        $metadata = $this->account->metadata;
        
        if (isset($metadata['pages'])) {
            foreach ($metadata['pages'] as $page) {
                if ($page['id'] === $pageId) {
                    return $page['access_token'];
                }
            }
        }
        
        throw new MetricsException('Page access token not found for page ID: ' . $pageId);
    }
    
    /**
     * Get the human-readable title for a metric.
     *
     * @param string $metricName
     * @return string
     */
    protected function getMetricTitle(string $metricName): string
    {
        $titles = [
            'page_impressions' => 'Page Impressions',
            'page_impressions_unique' => 'Unique Page Impressions',
            'page_engaged_users' => 'Page Engaged Users',
            'page_post_engagements' => 'Post Engagements',
            'page_fans' => 'Page Likes',
            'page_fan_adds' => 'New Page Likes',
            'page_fan_removes' => 'Page Unlikes',
            'page_views_total' => 'Page Views',
            'post_impressions' => 'Post Impressions',
            'post_impressions_unique' => 'Unique Post Impressions',
            'post_engaged_users' => 'Post Engaged Users',
            'post_reactions_by_type_total' => 'Post Reactions',
            'post_clicks' => 'Post Clicks',
            'post_video_views' => 'Video Views',
        ];
        
        return $titles[$metricName] ?? $metricName;
    }
    
    /**
     * Get the description for a metric.
     *
     * @param string $metricName
     * @return string
     */
    protected function getMetricDescription(string $metricName): string
    {
        $descriptions = [
            'page_impressions' => 'The number of times any content from your Page or about your Page entered a person\'s screen.',
            'page_impressions_unique' => 'The number of people who had any content from your Page or about your Page enter their screen.',
            'page_engaged_users' => 'The number of people who engaged with your Page.',
            'page_post_engagements' => 'The number of times people have engaged with your posts through likes, comments and shares.',
            'page_fans' => 'The total number of people who have liked your Page.',
            'page_fan_adds' => 'The number of new people who have liked your Page.',
            'page_fan_removes' => 'The number of people who have unliked your Page.',
            'page_views_total' => 'The number of times a Page\'s profile has been viewed.',
            'post_impressions' => 'The number of times your post entered a person\'s screen.',
            'post_impressions_unique' => 'The number of people who had your post enter their screen.',
            'post_engaged_users' => 'The number of people who engaged with your post.',
            'post_reactions_by_type_total' => 'The number of reactions on your post by type.',
            'post_clicks' => 'The number of clicks on your post.',
            'post_video_views' => 'The number of times your video was viewed for at least 3 seconds.',
        ];
        
        return $descriptions[$metricName] ?? '';
    }
}
