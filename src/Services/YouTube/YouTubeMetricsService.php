<?php

namespace VendorName\SocialConnect\Services\YouTube;

use Google_Client;
use Google_Service_YouTube;
use Google_Service_YouTubeAnalytics;
use VendorName\SocialConnect\Contracts\MetricsInterface;
use VendorName\SocialConnect\Exceptions\MetricsException;
use VendorName\SocialConnect\Models\SocialAccount;
use VendorName\SocialConnect\Models\SocialMetric;
use VendorName\SocialConnect\Models\SocialPost;

class YouTubeMetricsService implements MetricsInterface
{
    /**
     * The Google client instance.
     *
     * @var \Google_Client
     */
    protected $client;

    /**
     * The YouTube service instance.
     *
     * @var \Google_Service_YouTube
     */
    protected $youtube;

    /**
     * The YouTube Analytics service instance.
     *
     * @var \Google_Service_YouTubeAnalytics
     */
    protected $youtubeAnalytics;

    /**
     * The social account instance.
     *
     * @var \VendorName\SocialConnect\Models\SocialAccount
     */
    protected $account;

    /**
     * Create a new YouTubeMetricsService instance.
     *
     * @param \VendorName\SocialConnect\Models\SocialAccount $account
     */
    public function __construct(SocialAccount $account)
    {
        $this->account = $account;
        
        $this->client = new Google_Client();
        $this->client->setAccessToken($this->account->access_token);
        
        // Check if token needs refresh
        if ($this->client->isAccessTokenExpired() && $this->account->refresh_token) {
            $this->client->fetchAccessTokenWithRefreshToken($this->account->refresh_token);
            $tokens = $this->client->getAccessToken();
            
            // Update the account with new tokens
            $this->account->update([
                'access_token' => $tokens['access_token'],
                'token_expires_at' => now()->addSeconds($tokens['expires_in'] ?? 3600),
            ]);
        }
        
        $this->youtube = new Google_Service_YouTube($this->client);
        $this->youtubeAnalytics = new Google_Service_YouTubeAnalytics($this->client);
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
            $channelId = $this->getChannelId();
            
            // Convert period to date range
            $dateRange = $this->convertPeriodToDateRange($period);
            
            // Default metrics if none provided
            if (empty($metrics)) {
                $metrics = [
                    'views',
                    'estimatedMinutesWatched',
                    'averageViewDuration',
                    'subscribersGained',
                    'subscribersLost',
                    'likes',
                    'dislikes',
                    'comments',
                    'shares',
                ];
            }
            
            // Get channel statistics
            $channelResponse = $this->youtube->channels->listChannels('statistics', [
                'id' => $channelId,
            ]);
            
            $results = [];
            
            if ($channelResponse->items && count($channelResponse->items) > 0) {
                $channelStats = $channelResponse->items[0]->getStatistics();
                
                $results['subscriber_count'] = [
                    'value' => $channelStats->getSubscriberCount(),
                    'title' => 'Subscribers',
                    'description' => 'Total number of subscribers',
                ];
                
                $results['video_count'] = [
                    'value' => $channelStats->getVideoCount(),
                    'title' => 'Videos',
                    'description' => 'Total number of videos',
                ];
                
                $results['view_count'] = [
                    'value' => $channelStats->getViewCount(),
                    'title' => 'Views',
                    'description' => 'Total number of views',
                ];
                
                // Store in database
                $this->storeAccountMetric(
                    'channel_statistics',
                    [
                        [
                            'subscriber_count' => $channelStats->getSubscriberCount(),
                            'video_count' => $channelStats->getVideoCount(),
                            'view_count' => $channelStats->getViewCount(),
                            'end_time' => now()->format('Y-m-d\TH:i:s\Z'),
                        ]
                    ],
                    now(),
                    now()
                );
            }
            
            // Get analytics data
            $analyticsResponse = $this->youtubeAnalytics->reports->query([
                'ids' => 'channel==' . $channelId,
                'startDate' => $dateRange['start'],
                'endDate' => $dateRange['end'],
                'metrics' => implode(',', $metrics),
                'dimensions' => 'day',
            ]);
            
            if ($analyticsResponse && isset($analyticsResponse['rows']) && !empty($analyticsResponse['rows'])) {
                $columnHeaders = $analyticsResponse['columnHeaders'];
                $rows = $analyticsResponse['rows'];
                
                // Process metrics by day
                $metricsByDay = [];
                
                foreach ($rows as $row) {
                    $day = $row[0]; // First column is the day
                    
                    for ($i = 1; $i < count($row); $i++) {
                        $metricName = $columnHeaders[$i]['name'];
                        
                        if (!isset($metricsByDay[$metricName])) {
                            $metricsByDay[$metricName] = [];
                        }
                        
                        $metricsByDay[$metricName][] = [
                            'value' => $row[$i],
                            'end_time' => $day . 'T23:59:59Z',
                        ];
                    }
                }
                
                // Add metrics to results
                foreach ($metricsByDay as $metricName => $values) {
                    $results[$metricName] = [
                        'values' => $values,
                        'title' => $this->getMetricTitle($metricName),
                        'description' => $this->getMetricDescription($metricName),
                        'period' => $period,
                    ];
                    
                    // Store in database
                    $this->storeAccountMetric(
                        $metricName,
                        $values,
                        $dateRange['start_date'],
                        $dateRange['end_date']
                    );
                }
                
                // Calculate totals
                foreach ($metrics as $metric) {
                    if (isset($metricsByDay[$metric])) {
                        $total = 0;
                        
                        foreach ($metricsByDay[$metric] as $value) {
                            $total += $value['value'];
                        }
                        
                        $results['total_' . $metric] = [
                            'value' => $total,
                            'title' => 'Total ' . $this->getMetricTitle($metric),
                            'description' => 'Total ' . $this->getMetricDescription($metric) . ' for the period',
                        ];
                    }
                }
            }
            
            return $results;
        } catch (\Exception $e) {
            throw new MetricsException('Failed to get account metrics from YouTube: ' . $e->getMessage());
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
            // Default metrics if none provided
            if (empty($metrics)) {
                $metrics = [
                    'views',
                    'estimatedMinutesWatched',
                    'averageViewDuration',
                    'likes',
                    'dislikes',
                    'comments',
                    'shares',
                ];
            }
            
            // Get video statistics
            $videoResponse = $this->youtube->videos->listVideos('statistics', [
                'id' => $postId,
            ]);
            
            $results = [];
            
            if ($videoResponse->items && count($videoResponse->items) > 0) {
                $videoStats = $videoResponse->items[0]->getStatistics();
                
                $results['view_count'] = [
                    'value' => $videoStats->getViewCount(),
                    'title' => 'Views',
                    'description' => 'Number of views',
                ];
                
                $results['like_count'] = [
                    'value' => $videoStats->getLikeCount(),
                    'title' => 'Likes',
                    'description' => 'Number of likes',
                ];
                
                $results['dislike_count'] = [
                    'value' => $videoStats->getDislikeCount(),
                    'title' => 'Dislikes',
                    'description' => 'Number of dislikes',
                ];
                
                $results['comment_count'] = [
                    'value' => $videoStats->getCommentCount(),
                    'title' => 'Comments',
                    'description' => 'Number of comments',
                ];
                
                // Store in database
                $this->storePostMetric(
                    $postId,
                    'video_statistics',
                    [
                        [
                            'view_count' => $videoStats->getViewCount(),
                            'like_count' => $videoStats->getLikeCount(),
                            'dislike_count' => $videoStats->getDislikeCount(),
                            'comment_count' => $videoStats->getCommentCount(),
                            'end_time' => now()->format('Y-m-d\TH:i:s\Z'),
                        ]
                    ]
                );
            }
            
            // Get analytics data
            $dateRange = $this->convertPeriodToDateRange('last_30_days');
            
            $analyticsResponse = $this->youtubeAnalytics->reports->query([
                'ids' => 'channel==' . $this->getChannelId(),
                'startDate' => $dateRange['start'],
                'endDate' => $dateRange['end'],
                'metrics' => implode(',', $metrics),
                'filters' => 'video==' . $postId,
                'dimensions' => 'day',
            ]);
            
            if ($analyticsResponse && isset($analyticsResponse['rows']) && !empty($analyticsResponse['rows'])) {
                $columnHeaders = $analyticsResponse['columnHeaders'];
                $rows = $analyticsResponse['rows'];
                
                // Process metrics by day
                $metricsByDay = [];
                
                foreach ($rows as $row) {
                    $day = $row[0]; // First column is the day
                    
                    for ($i = 1; $i < count($row); $i++) {
                        $metricName = $columnHeaders[$i]['name'];
                        
                        if (!isset($metricsByDay[$metricName])) {
                            $metricsByDay[$metricName] = [];
                        }
                        
                        $metricsByDay[$metricName][] = [
                            'value' => $row[$i],
                            'end_time' => $day . 'T23:59:59Z',
                        ];
                    }
                }
                
                // Add metrics to results
                foreach ($metricsByDay as $metricName => $values) {
                    $results[$metricName . '_by_day'] = [
                        'values' => $values,
                        'title' => $this->getMetricTitle($metricName) . ' by Day',
                        'description' => $this->getMetricDescription($metricName) . ' by day',
                    ];
                    
                    // Store in database
                    $this->storePostMetric(
                        $postId,
                        $metricName . '_by_day',
                        $values
                    );
                }
            }
            
            return $results;
        } catch (\Exception $e) {
            throw new MetricsException('Failed to get post metrics from YouTube: ' . $e->getMessage());
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
            $channelId = $this->getChannelId();
            
            // Default dimensions if none provided
            if (empty($dimensions)) {
                $dimensions = [
                    'gender',
                    'ageGroup',
                    'country',
                ];
            }
            
            $results = [];
            
            foreach ($dimensions as $dimension) {
                $analyticsResponse = $this->youtubeAnalytics->reports->query([
                    'ids' => 'channel==' . $channelId,
                    'startDate' => now()->subDays(30)->format('Y-m-d'),
                    'endDate' => now()->format('Y-m-d'),
                    'metrics' => 'viewerPercentage',
                    'dimensions' => $dimension,
                ]);
                
                if ($analyticsResponse && isset($analyticsResponse['rows']) && !empty($analyticsResponse['rows'])) {
                    $dimensionData = [];
                    
                    foreach ($analyticsResponse['rows'] as $row) {
                        $dimensionData[$row[0]] = $row[1];
                    }
                    
                    $results[$dimension] = $dimensionData;
                    
                    // Store in database
                    $this->storeAccountMetric(
                        'audience_' . $dimension,
                        [['value' => $dimensionData, 'end_time' => now()->format('Y-m-d\TH:i:s\Z')]],
                        now()->subDays(30),
                        now()
                    );
                }
            }
            
            return $results;
        } catch (\Exception $e) {
            throw new MetricsException('Failed to get audience demographics from YouTube: ' . $e->getMessage());
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
            $channelId = $this->getChannelId();
            
            $analyticsResponse = $this->youtubeAnalytics->reports->query([
                'ids' => 'channel==' . $channelId,
                'startDate' => $startDate,
                'endDate' => $endDate,
                'metrics' => 'views,estimatedMinutesWatched,subscribersGained,subscribersLost',
                'dimensions' => 'day',
            ]);
            
            $results = [
                'views' => [
                    'values' => [],
                    'title' => 'Views',
                    'description' => 'Number of views over time',
                ],
                'watch_time' => [
                    'values' => [],
                    'title' => 'Watch Time',
                    'description' => 'Estimated minutes watched over time',
                ],
                'subscribers_gained' => [
                    'values' => [],
                    'title' => 'Subscribers Gained',
                    'description' => 'Number of subscribers gained over time',
                ],
                'subscribers_lost' => [
                    'values' => [],
                    'title' => 'Subscribers Lost',
                    'description' => 'Number of subscribers lost over time',
                ],
                'net_subscribers' => [
                    'values' => [],
                    'title' => 'Net Subscribers',
                    'description' => 'Net change in subscribers over time',
                ],
            ];
            
            if ($analyticsResponse && isset($analyticsResponse['rows']) && !empty($analyticsResponse['rows'])) {
                foreach ($analyticsResponse['rows'] as $row) {
                    $day = $row[0];
                    $views = $row[1];
                    $watchTime = $row[2];
                    $subscribersGained = $row[3];
                    $subscribersLost = $row[4];
                    $netSubscribers = $subscribersGained - $subscribersLost;
                    
                    $results['views']['values'][] = [
                        'value' => $views,
                        'end_time' => $day . 'T23:59:59Z',
                    ];
                    
                    $results['watch_time']['values'][] = [
                        'value' => $watchTime,
                        'end_time' => $day . 'T23:59:59Z',
                    ];
                    
                    $results['subscribers_gained']['values'][] = [
                        'value' => $subscribersGained,
                        'end_time' => $day . 'T23:59:59Z',
                    ];
                    
                    $results['subscribers_lost']['values'][] = [
                        'value' => $subscribersLost,
                        'end_time' => $day . 'T23:59:59Z',
                    ];
                    
                    $results['net_subscribers']['values'][] = [
                        'value' => $netSubscribers,
                        'end_time' => $day . 'T23:59:59Z',
                    ];
                }
                
                // Store in database
                $this->storeAccountMetric(
                    'account_growth',
                    [
                        'views' => $results['views']['values'],
                        'watch_time' => $results['watch_time']['values'],
                        'subscribers_gained' => $results['subscribers_gained']['values'],
                        'subscribers_lost' => $results['subscribers_lost']['values'],
                        'net_subscribers' => $results['net_subscribers']['values'],
                    ],
                    new \DateTime($startDate),
                    new \DateTime($endDate)
                );
            }
            
            return $results;
        } catch (\Exception $e) {
            throw new MetricsException('Failed to get account growth metrics from YouTube: ' . $e->getMessage());
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
            ->where('platform', 'youtube')
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
     * Get the channel ID from the account metadata.
     *
     * @return string
     * @throws \VendorName\SocialConnect\Exceptions\MetricsException
     */
    protected function getChannelId(): string
    {
        $metadata = $this->account->metadata;
        
        if (isset($metadata['channel_id'])) {
            return $metadata['channel_id'];
        }
        
        throw new MetricsException('YouTube channel ID not found in account metadata.');
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
            'views' => 'Views',
            'estimatedMinutesWatched' => 'Watch Time',
            'averageViewDuration' => 'Average View Duration',
            'subscribersGained' => 'Subscribers Gained',
            'subscribersLost' => 'Subscribers Lost',
            'likes' => 'Likes',
            'dislikes' => 'Dislikes',
            'comments' => 'Comments',
            'shares' => 'Shares',
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
            'views' => 'Number of times your content was viewed',
            'estimatedMinutesWatched' => 'Estimated total minutes of watch time',
            'averageViewDuration' => 'Average view duration in seconds',
            'subscribersGained' => 'Number of subscribers gained',
            'subscribersLost' => 'Number of subscribers lost',
            'likes' => 'Number of likes',
            'dislikes' => 'Number of dislikes',
            'comments' => 'Number of comments',
            'shares' => 'Number of times your content was shared',
        ];
        
        return $descriptions[$metricName] ?? '';
    }
}
