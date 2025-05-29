<?php

namespace VendorName\SocialConnect\Services\Twitter;

use GuzzleHttp\Client;
use VendorName\SocialConnect\Contracts\MetricsInterface;
use VendorName\SocialConnect\Exceptions\MetricsException;
use VendorName\SocialConnect\Models\SocialAccount;
use VendorName\SocialConnect\Models\SocialMetric;
use VendorName\SocialConnect\Models\SocialPost;

class TwitterMetricsService implements MetricsInterface
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
     * Create a new TwitterMetricsService instance.
     *
     * @param \VendorName\SocialConnect\Models\SocialAccount $account
     */
    public function __construct(SocialAccount $account)
    {
        $this->account = $account;
        $this->client = new Client([
            'base_uri' => 'https://api.twitter.com/',
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
            $userId = $this->getUserId();
            
            // Convert period to date range
            $dateRange = $this->convertPeriodToDateRange($period);
            
            // Get user profile data
            $profileResponse = $this->client->get("2/users/{$userId}", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
                'query' => [
                    'user.fields' => 'public_metrics,description,created_at',
                ],
            ]);
            
            $profileData = json_decode($profileResponse->getBody()->getContents(), true);
            
            if (!isset($profileData['data'])) {
                throw new MetricsException('Failed to retrieve account data from Twitter.');
            }
            
            $results = [];
            
            // Extract public metrics
            if (isset($profileData['data']['public_metrics'])) {
                $publicMetrics = $profileData['data']['public_metrics'];
                
                foreach ($publicMetrics as $metricName => $metricValue) {
                    $results[$metricName] = [
                        'value' => $metricValue,
                        'title' => $this->getMetricTitle($metricName),
                        'description' => $this->getMetricDescription($metricName),
                    ];
                    
                    // Store in database
                    $this->storeAccountMetric(
                        $metricName,
                        [['value' => $metricValue, 'end_time' => now()->format('Y-m-d\TH:i:s\Z')]],
                        $dateRange['start_date'],
                        $dateRange['end_date']
                    );
                }
            }
            
            // Get tweet metrics for the period
            $tweetsResponse = $this->client->get("2/users/{$userId}/tweets", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
                'query' => [
                    'max_results' => 100,
                    'tweet.fields' => 'public_metrics,created_at',
                    'start_time' => $dateRange['start_date']->format('Y-m-d\TH:i:s\Z'),
                    'end_time' => $dateRange['end_date']->format('Y-m-d\TH:i:s\Z'),
                ],
            ]);
            
            $tweetsData = json_decode($tweetsResponse->getBody()->getContents(), true);
            
            if (isset($tweetsData['data']) && is_array($tweetsData['data'])) {
                // Aggregate tweet metrics
                $aggregatedMetrics = [
                    'total_tweets' => count($tweetsData['data']),
                    'total_likes' => 0,
                    'total_retweets' => 0,
                    'total_replies' => 0,
                    'total_impressions' => 0,
                ];
                
                foreach ($tweetsData['data'] as $tweet) {
                    if (isset($tweet['public_metrics'])) {
                        $aggregatedMetrics['total_likes'] += $tweet['public_metrics']['like_count'] ?? 0;
                        $aggregatedMetrics['total_retweets'] += $tweet['public_metrics']['retweet_count'] ?? 0;
                        $aggregatedMetrics['total_replies'] += $tweet['public_metrics']['reply_count'] ?? 0;
                        $aggregatedMetrics['total_impressions'] += $tweet['public_metrics']['impression_count'] ?? 0;
                    }
                }
                
                foreach ($aggregatedMetrics as $metricName => $metricValue) {
                    $results[$metricName] = [
                        'value' => $metricValue,
                        'title' => $this->getMetricTitle($metricName),
                        'description' => $this->getMetricDescription($metricName),
                        'period' => $period,
                    ];
                    
                    // Store in database
                    $this->storeAccountMetric(
                        $metricName,
                        [['value' => $metricValue, 'end_time' => now()->format('Y-m-d\TH:i:s\Z')]],
                        $dateRange['start_date'],
                        $dateRange['end_date']
                    );
                }
            }
            
            return $results;
        } catch (\Exception $e) {
            throw new MetricsException('Failed to get account metrics from Twitter: ' . $e->getMessage());
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
            
            // Get tweet data
            $response = $this->client->get("2/tweets/{$postId}", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
                'query' => [
                    'tweet.fields' => 'public_metrics,created_at,non_public_metrics,organic_metrics,promoted_metrics',
                ],
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['data'])) {
                throw new MetricsException('Failed to retrieve post metrics from Twitter.');
            }
            
            $results = [];
            
            // Extract public metrics
            if (isset($data['data']['public_metrics'])) {
                $publicMetrics = $data['data']['public_metrics'];
                
                foreach ($publicMetrics as $metricName => $metricValue) {
                    $results[$metricName] = [
                        'value' => $metricValue,
                        'title' => $this->getMetricTitle($metricName),
                        'description' => $this->getMetricDescription($metricName),
                    ];
                    
                    // Store in database
                    $this->storePostMetric($postId, $metricName, [
                        ['value' => $metricValue, 'end_time' => now()->format('Y-m-d\TH:i:s\Z')]
                    ]);
                }
            }
            
            // Extract non-public metrics if available
            if (isset($data['data']['non_public_metrics'])) {
                $nonPublicMetrics = $data['data']['non_public_metrics'];
                
                foreach ($nonPublicMetrics as $metricName => $metricValue) {
                    $results[$metricName] = [
                        'value' => $metricValue,
                        'title' => $this->getMetricTitle($metricName),
                        'description' => $this->getMetricDescription($metricName),
                    ];
                    
                    // Store in database
                    $this->storePostMetric($postId, $metricName, [
                        ['value' => $metricValue, 'end_time' => now()->format('Y-m-d\TH:i:s\Z')]
                    ]);
                }
            }
            
            // Extract organic metrics if available
            if (isset($data['data']['organic_metrics'])) {
                $organicMetrics = $data['data']['organic_metrics'];
                
                foreach ($organicMetrics as $metricName => $metricValue) {
                    $results[$metricName] = [
                        'value' => $metricValue,
                        'title' => $this->getMetricTitle("organic_{$metricName}"),
                        'description' => $this->getMetricDescription("organic_{$metricName}"),
                    ];
                    
                    // Store in database
                    $this->storePostMetric($postId, "organic_{$metricName}", [
                        ['value' => $metricValue, 'end_time' => now()->format('Y-m-d\TH:i:s\Z')]
                    ]);
                }
            }
            
            // Extract promoted metrics if available
            if (isset($data['data']['promoted_metrics'])) {
                $promotedMetrics = $data['data']['promoted_metrics'];
                
                foreach ($promotedMetrics as $metricName => $metricValue) {
                    $results[$metricName] = [
                        'value' => $metricValue,
                        'title' => $this->getMetricTitle("promoted_{$metricName}"),
                        'description' => $this->getMetricDescription("promoted_{$metricName}"),
                    ];
                    
                    // Store in database
                    $this->storePostMetric($postId, "promoted_{$metricName}", [
                        ['value' => $metricValue, 'end_time' => now()->format('Y-m-d\TH:i:s\Z')]
                    ]);
                }
            }
            
            return $results;
        } catch (\Exception $e) {
            throw new MetricsException('Failed to get post metrics from Twitter: ' . $e->getMessage());
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
            $userId = $this->getUserId();
            
            // Twitter API v2 doesn't provide detailed audience demographics
            // We'll return a simplified version based on followers
            
            $response = $this->client->get("2/users/{$userId}/followers", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
                'query' => [
                    'max_results' => 100,
                    'user.fields' => 'location,description,public_metrics',
                ],
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($data['data'])) {
                throw new MetricsException('Failed to retrieve audience data from Twitter.');
            }
            
            $results = [
                'sample_size' => count($data['data']),
                'locations' => [],
            ];
            
            // Extract locations
            foreach ($data['data'] as $follower) {
                if (isset($follower['location']) && !empty($follower['location'])) {
                    $location = $follower['location'];
                    
                    if (!isset($results['locations'][$location])) {
                        $results['locations'][$location] = 0;
                    }
                    
                    $results['locations'][$location]++;
                }
            }
            
            // Sort locations by count
            arsort($results['locations']);
            
            // Store in database
            $this->storeAccountMetric(
                'audience_demographics',
                [['value' => $results, 'end_time' => now()->format('Y-m-d\TH:i:s\Z')]],
                now()->subDay(),
                now()
            );
            
            return $results;
        } catch (\Exception $e) {
            throw new MetricsException('Failed to get audience demographics from Twitter: ' . $e->getMessage());
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
            // Twitter API v2 doesn't provide historical follower counts
            // We'll use the stored metrics in our database
            
            $metrics = SocialMetric::where('social_account_id', $this->account->id)
                ->where('metric_type', 'followers_count')
                ->whereBetween('period_start', [new \DateTime($startDate), new \DateTime($endDate)])
                ->orderBy('period_start')
                ->get();
            
            $results = [
                'followers_count' => [
                    'values' => [],
                    'title' => 'Followers',
                    'description' => 'Number of followers over time',
                ],
            ];
            
            foreach ($metrics as $metric) {
                foreach ($metric->metric_value as $value) {
                    $results['followers_count']['values'][] = [
                        'value' => $value['value'],
                        'end_time' => $value['end_time'],
                    ];
                }
            }
            
            // If we don't have any stored metrics, get the current count
            if (empty($results['followers_count']['values'])) {
                $currentMetrics = $this->getAccountMetrics('today');
                
                if (isset($currentMetrics['followers_count'])) {
                    $results['followers_count']['values'][] = [
                        'value' => $currentMetrics['followers_count']['value'],
                        'end_time' => now()->format('Y-m-d\TH:i:s\Z'),
                    ];
                }
            }
            
            return $results;
        } catch (\Exception $e) {
            throw new MetricsException('Failed to get account growth metrics from Twitter: ' . $e->getMessage());
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
            ->where('platform', 'twitter')
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
     * Get the user ID from the account metadata.
     *
     * @return string
     * @throws \VendorName\SocialConnect\Exceptions\MetricsException
     */
    protected function getUserId(): string
    {
        $metadata = $this->account->metadata;
        
        if (isset($metadata['id'])) {
            return $metadata['id'];
        }
        
        throw new MetricsException('Twitter user ID not found in account metadata.');
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
            'followers_count' => 'Followers',
            'following_count' => 'Following',
            'tweet_count' => 'Tweets',
            'listed_count' => 'Lists',
            'like_count' => 'Likes',
            'retweet_count' => 'Retweets',
            'reply_count' => 'Replies',
            'quote_count' => 'Quotes',
            'impression_count' => 'Impressions',
            'total_tweets' => 'Total Tweets',
            'total_likes' => 'Total Likes',
            'total_retweets' => 'Total Retweets',
            'total_replies' => 'Total Replies',
            'total_impressions' => 'Total Impressions',
            'organic_impression_count' => 'Organic Impressions',
            'organic_like_count' => 'Organic Likes',
            'organic_retweet_count' => 'Organic Retweets',
            'organic_reply_count' => 'Organic Replies',
            'promoted_impression_count' => 'Promoted Impressions',
            'promoted_like_count' => 'Promoted Likes',
            'promoted_retweet_count' => 'Promoted Retweets',
            'promoted_reply_count' => 'Promoted Replies',
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
            'followers_count' => 'The number of users who follow this account.',
            'following_count' => 'The number of users this account is following.',
            'tweet_count' => 'The number of tweets posted by this account.',
            'listed_count' => 'The number of lists that include this account.',
            'like_count' => 'The number of likes this tweet has received.',
            'retweet_count' => 'The number of times this tweet has been retweeted.',
            'reply_count' => 'The number of replies this tweet has received.',
            'quote_count' => 'The number of times this tweet has been quoted.',
            'impression_count' => 'The number of times this tweet has been viewed.',
            'total_tweets' => 'The total number of tweets posted during the specified period.',
            'total_likes' => 'The total number of likes received during the specified period.',
            'total_retweets' => 'The total number of retweets received during the specified period.',
            'total_replies' => 'The total number of replies received during the specified period.',
            'total_impressions' => 'The total number of impressions received during the specified period.',
            'organic_impression_count' => 'The number of times this tweet has been viewed organically.',
            'organic_like_count' => 'The number of organic likes this tweet has received.',
            'organic_retweet_count' => 'The number of organic retweets this tweet has received.',
            'organic_reply_count' => 'The number of organic replies this tweet has received.',
            'promoted_impression_count' => 'The number of times this tweet has been viewed through promotion.',
            'promoted_like_count' => 'The number of likes this tweet has received through promotion.',
            'promoted_retweet_count' => 'The number of retweets this tweet has received through promotion.',
            'promoted_reply_count' => 'The number of replies this tweet has received through promotion.',
        ];
        
        return $descriptions[$metricName] ?? '';
    }
}
