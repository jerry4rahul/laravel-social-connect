<?php

namespace VendorName\SocialConnect\Services\Instagram;

use GuzzleHttp\Client;
use VendorName\SocialConnect\Contracts\MetricsInterface;
use VendorName\SocialConnect\Exceptions\MetricsException;
use VendorName\SocialConnect\Models\SocialAccount;
use VendorName\SocialConnect\Models\SocialMetric;
use VendorName\SocialConnect\Models\SocialPost;

class InstagramMetricsService implements MetricsInterface
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
     * Create a new InstagramMetricsService instance.
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
            $accessToken = $this->account->access_token;
            $igAccountId = $this->getInstagramAccountId();
            
            // Default metrics if none provided
            if (empty($metrics)) {
                $metrics = [
                    'impressions',
                    'reach',
                    'profile_views',
                    'follower_count',
                ];
            }
            
            // Convert period to date range
            $dateRange = $this->convertPeriodToDateRange($period);
            
            // Get insights
            $response = $this->client->get("{$igAccountId}/insights", [
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
                throw new MetricsException('Failed to retrieve account metrics from Instagram.');
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
            
            // Get additional account info (followers, etc.)
            $accountResponse = $this->client->get("{$igAccountId}", [
                'query' => [
                    'fields' => 'followers_count,media_count,follows_count',
                    'access_token' => $accessToken,
                ],
            ]);
            
            $accountData = json_decode($accountResponse->getBody()->getContents(), true);
            
            if (isset($accountData['followers_count'])) {
                $results['followers_count'] = [
                    'value' => $accountData['followers_count'],
                    'title' => 'Followers',
                    'description' => 'Total number of followers',
                ];
                
                // Store in database
                $this->storeAccountMetric('followers_count', [
                    ['value' => $accountData['followers_count'], 'end_time' => now()->format('Y-m-d\TH:i:s\Z')]
                ], now(), now());
            }
            
            if (isset($accountData['media_count'])) {
                $results['media_count'] = [
                    'value' => $accountData['media_count'],
                    'title' => 'Media Count',
                    'description' => 'Total number of media items',
                ];
                
                // Store in database
                $this->storeAccountMetric('media_count', [
                    ['value' => $accountData['media_count'], 'end_time' => now()->format('Y-m-d\TH:i:s\Z')]
                ], now(), now());
            }
            
            return $results;
        } catch (\Exception $e) {
            throw new MetricsException('Failed to get account metrics from Instagram: ' . $e->getMessage());
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
            $accessToken = $this->account->access_token;
            
            // Default metrics if none provided
            if (empty($metrics)) {
                $metrics = [
                    'engagement',
                    'impressions',
                    'reach',
                    'saved',
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
                throw new MetricsException('Failed to retrieve post metrics from Instagram.');
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
            
            // Get additional post info (likes, comments, etc.)
            $postResponse = $this->client->get("{$postId}", [
                'query' => [
                    'fields' => 'like_count,comments_count',
                    'access_token' => $accessToken,
                ],
            ]);
            
            $postData = json_decode($postResponse->getBody()->getContents(), true);
            
            if (isset($postData['like_count'])) {
                $results['like_count'] = [
                    'value' => $postData['like_count'],
                    'title' => 'Likes',
                    'description' => 'Number of likes on the post',
                ];
                
                // Store in database
                $this->storePostMetric($postId, 'like_count', [
                    ['value' => $postData['like_count'], 'end_time' => now()->format('Y-m-d\TH:i:s\Z')]
                ]);
            }
            
            if (isset($postData['comments_count'])) {
                $results['comments_count'] = [
                    'value' => $postData['comments_count'],
                    'title' => 'Comments',
                    'description' => 'Number of comments on the post',
                ];
                
                // Store in database
                $this->storePostMetric($postId, 'comments_count', [
                    ['value' => $postData['comments_count'], 'end_time' => now()->format('Y-m-d\TH:i:s\Z')]
                ]);
            }
            
            return $results;
        } catch (\Exception $e) {
            throw new MetricsException('Failed to get post metrics from Instagram: ' . $e->getMessage());
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
            $accessToken = $this->account->access_token;
            $igAccountId = $this->getInstagramAccountId();
            
            // Default dimensions if none provided
            if (empty($dimensions)) {
                $dimensions = [
                    'audience_gender_age',
                    'audience_country',
                    'audience_city',
                ];
            }
            
            $results = [];
            
            foreach ($dimensions as $dimension) {
                $response = $this->client->get("{$igAccountId}/insights", [
                    'query' => [
                        'metric' => $dimension,
                        'period' => 'lifetime',
                        'access_token' => $accessToken,
                    ],
                ]);
                
                $data = json_decode($response->getBody()->getContents(), true);
                
                if (isset($data['data'][0]['values'][0]['value'])) {
                    $results[$dimension] = $data['data'][0]['values'][0]['value'];
                    
                    // Store in database
                    $this->storeAccountMetric(
                        $dimension,
                        $data['data'][0]['values'],
                        now()->subDay(),
                        now()
                    );
                }
            }
            
            return $results;
        } catch (\Exception $e) {
            throw new MetricsException('Failed to get audience demographics from Instagram: ' . $e->getMessage());
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
            $accessToken = $this->account->access_token;
            $igAccountId = $this->getInstagramAccountId();
            
            $metrics = [
                'follower_count',
                'impressions',
                'reach',
            ];
            
            $response = $this->client->get("{$igAccountId}/insights", [
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
                throw new MetricsException('Failed to retrieve account growth metrics from Instagram.');
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
            throw new MetricsException('Failed to get account growth metrics from Instagram: ' . $e->getMessage());
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
            ->where('platform', 'instagram')
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
     * Get the Instagram account ID from the account metadata.
     *
     * @return string
     * @throws \VendorName\SocialConnect\Exceptions\MetricsException
     */
    protected function getInstagramAccountId(): string
    {
        $metadata = $this->account->metadata;
        
        if (isset($metadata['id'])) {
            return $metadata['id'];
        }
        
        throw new MetricsException('Instagram account ID not found in account metadata.');
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
            'impressions' => 'Impressions',
            'reach' => 'Reach',
            'profile_views' => 'Profile Views',
            'follower_count' => 'Followers',
            'engagement' => 'Engagement',
            'saved' => 'Saves',
            'video_views' => 'Video Views',
            'audience_gender_age' => 'Audience Gender and Age',
            'audience_country' => 'Audience Countries',
            'audience_city' => 'Audience Cities',
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
            'impressions' => 'The number of times your content was shown to users.',
            'reach' => 'The number of unique accounts that saw your content.',
            'profile_views' => 'The number of times your profile was viewed.',
            'follower_count' => 'The total number of followers.',
            'engagement' => 'The number of unique accounts that liked, commented on, or saved your post.',
            'saved' => 'The number of unique accounts that saved your post.',
            'video_views' => 'The number of times your video was played for at least 3 seconds.',
            'audience_gender_age' => 'The distribution of your audience by gender and age.',
            'audience_country' => 'The distribution of your audience by country.',
            'audience_city' => 'The distribution of your audience by city.',
        ];
        
        return $descriptions[$metricName] ?? '';
    }
}
