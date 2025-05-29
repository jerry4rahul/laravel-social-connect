<?php

namespace VendorName\SocialConnect\Services\LinkedIn;

use GuzzleHttp\Client;
use VendorName\SocialConnect\Contracts\MetricsInterface;
use VendorName\SocialConnect\Exceptions\MetricsException;
use VendorName\SocialConnect\Models\SocialAccount;
use VendorName\SocialConnect\Models\SocialMetric;
use VendorName\SocialConnect\Models\SocialPost;

class LinkedInMetricsService implements MetricsInterface
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
     * Create a new LinkedInMetricsService instance.
     *
     * @param \VendorName\SocialConnect\Models\SocialAccount $account
     */
    public function __construct(SocialAccount $account)
    {
        $this->account = $account;
        $this->client = new Client([
            'base_uri' => 'https://api.linkedin.com/',
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
            $organizationId = $this->getOrganizationId();
            
            // Convert period to date range
            $dateRange = $this->convertPeriodToDateRange($period);
            
            // Default metrics if none provided
            if (empty($metrics)) {
                $metrics = [
                    'page_statistics',
                    'follower_statistics',
                    'share_statistics',
                ];
            }
            
            $results = [];
            
            // Get page statistics
            if (in_array('page_statistics', $metrics)) {
                $pageStatsResponse = $this->client->get("v2/organizationalEntityFollowerStatistics", [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $accessToken,
                        'X-Restli-Protocol-Version' => '2.0.0',
                    ],
                    'query' => [
                        'q' => 'organizationalEntity',
                        'organizationalEntity' => $organizationId,
                    ],
                ]);
                
                $pageStatsData = json_decode($pageStatsResponse->getBody()->getContents(), true);
                
                if (isset($pageStatsData['elements']) && !empty($pageStatsData['elements'])) {
                    $pageStats = $pageStatsData['elements'][0];
                    
                    $results['follower_count'] = [
                        'value' => $pageStats['totalFollowerCount'] ?? 0,
                        'title' => 'Followers',
                        'description' => 'Total number of followers',
                    ];
                    
                    // Store in database
                    $this->storeAccountMetric(
                        'follower_count',
                        [['value' => $pageStats['totalFollowerCount'] ?? 0, 'end_time' => now()->format('Y-m-d\TH:i:s\Z')]],
                        $dateRange['start_date'],
                        $dateRange['end_date']
                    );
                    
                    // Process follower counts by time
                    if (isset($pageStats['followerCountsByTime']) && !empty($pageStats['followerCountsByTime'])) {
                        $followerCountsByTime = [];
                        
                        foreach ($pageStats['followerCountsByTime'] as $timeStat) {
                            $followerCountsByTime[] = [
                                'value' => $timeStat['followerCounts']['organicFollowerCount'] ?? 0,
                                'end_time' => $timeStat['timeRange']['end'] ?? now()->format('Y-m-d\TH:i:s\Z'),
                            ];
                        }
                        
                        $results['follower_counts_by_time'] = [
                            'values' => $followerCountsByTime,
                            'title' => 'Followers Over Time',
                            'description' => 'Follower count changes over time',
                        ];
                        
                        // Store in database
                        $this->storeAccountMetric(
                            'follower_counts_by_time',
                            $followerCountsByTime,
                            $dateRange['start_date'],
                            $dateRange['end_date']
                        );
                    }
                }
            }
            
            // Get follower statistics
            if (in_array('follower_statistics', $metrics)) {
                $followerStatsResponse = $this->client->get("v2/organizationalEntityFollowerStatistics", [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $accessToken,
                        'X-Restli-Protocol-Version' => '2.0.0',
                    ],
                    'query' => [
                        'q' => 'organizationalEntity',
                        'organizationalEntity' => $organizationId,
                    ],
                ]);
                
                $followerStatsData = json_decode($followerStatsResponse->getBody()->getContents(), true);
                
                if (isset($followerStatsData['elements']) && !empty($followerStatsData['elements'])) {
                    $followerStats = $followerStatsData['elements'][0];
                    
                    // Process follower demographics
                    if (isset($followerStats['followerCountsByStaffCountRange'])) {
                        $results['follower_by_company_size'] = [
                            'values' => $followerStats['followerCountsByStaffCountRange'],
                            'title' => 'Followers by Company Size',
                            'description' => 'Distribution of followers by company size',
                        ];
                        
                        // Store in database
                        $this->storeAccountMetric(
                            'follower_by_company_size',
                            $followerStats['followerCountsByStaffCountRange'],
                            $dateRange['start_date'],
                            $dateRange['end_date']
                        );
                    }
                    
                    if (isset($followerStats['followerCountsByIndustry'])) {
                        $results['follower_by_industry'] = [
                            'values' => $followerStats['followerCountsByIndustry'],
                            'title' => 'Followers by Industry',
                            'description' => 'Distribution of followers by industry',
                        ];
                        
                        // Store in database
                        $this->storeAccountMetric(
                            'follower_by_industry',
                            $followerStats['followerCountsByIndustry'],
                            $dateRange['start_date'],
                            $dateRange['end_date']
                        );
                    }
                    
                    if (isset($followerStats['followerCountsByRegion'])) {
                        $results['follower_by_region'] = [
                            'values' => $followerStats['followerCountsByRegion'],
                            'title' => 'Followers by Region',
                            'description' => 'Distribution of followers by region',
                        ];
                        
                        // Store in database
                        $this->storeAccountMetric(
                            'follower_by_region',
                            $followerStats['followerCountsByRegion'],
                            $dateRange['start_date'],
                            $dateRange['end_date']
                        );
                    }
                    
                    if (isset($followerStats['followerCountsBySeniority'])) {
                        $results['follower_by_seniority'] = [
                            'values' => $followerStats['followerCountsBySeniority'],
                            'title' => 'Followers by Seniority',
                            'description' => 'Distribution of followers by seniority',
                        ];
                        
                        // Store in database
                        $this->storeAccountMetric(
                            'follower_by_seniority',
                            $followerStats['followerCountsBySeniority'],
                            $dateRange['start_date'],
                            $dateRange['end_date']
                        );
                    }
                    
                    if (isset($followerStats['followerCountsByFunction'])) {
                        $results['follower_by_function'] = [
                            'values' => $followerStats['followerCountsByFunction'],
                            'title' => 'Followers by Function',
                            'description' => 'Distribution of followers by job function',
                        ];
                        
                        // Store in database
                        $this->storeAccountMetric(
                            'follower_by_function',
                            $followerStats['followerCountsByFunction'],
                            $dateRange['start_date'],
                            $dateRange['end_date']
                        );
                    }
                }
            }
            
            // Get share statistics
            if (in_array('share_statistics', $metrics)) {
                $shareStatsResponse = $this->client->get("v2/organizationalEntityShareStatistics", [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $accessToken,
                        'X-Restli-Protocol-Version' => '2.0.0',
                    ],
                    'query' => [
                        'q' => 'organizationalEntity',
                        'organizationalEntity' => $organizationId,
                        'timeIntervals.timeGranularityType' => 'DAY',
                        'timeIntervals.timeRange.start' => $dateRange['start'],
                        'timeIntervals.timeRange.end' => $dateRange['end'],
                    ],
                ]);
                
                $shareStatsData = json_decode($shareStatsResponse->getBody()->getContents(), true);
                
                if (isset($shareStatsData['elements']) && !empty($shareStatsData['elements'])) {
                    $shareStats = $shareStatsData['elements'][0];
                    
                    if (isset($shareStats['totalShareStatistics'])) {
                        $totalShareStats = $shareStats['totalShareStatistics'];
                        
                        $results['share_count'] = [
                            'value' => $totalShareStats['shareCount'] ?? 0,
                            'title' => 'Share Count',
                            'description' => 'Total number of shares',
                        ];
                        
                        $results['share_impressions'] = [
                            'value' => $totalShareStats['impressionCount'] ?? 0,
                            'title' => 'Share Impressions',
                            'description' => 'Total number of impressions from shares',
                        ];
                        
                        $results['share_clicks'] = [
                            'value' => $totalShareStats['clickCount'] ?? 0,
                            'title' => 'Share Clicks',
                            'description' => 'Total number of clicks on shares',
                        ];
                        
                        $results['share_engagement'] = [
                            'value' => $totalShareStats['engagement'] ?? 0,
                            'title' => 'Share Engagement',
                            'description' => 'Total engagement on shares',
                        ];
                        
                        // Store in database
                        $this->storeAccountMetric(
                            'share_statistics',
                            [
                                [
                                    'share_count' => $totalShareStats['shareCount'] ?? 0,
                                    'impression_count' => $totalShareStats['impressionCount'] ?? 0,
                                    'click_count' => $totalShareStats['clickCount'] ?? 0,
                                    'engagement' => $totalShareStats['engagement'] ?? 0,
                                    'end_time' => now()->format('Y-m-d\TH:i:s\Z'),
                                ]
                            ],
                            $dateRange['start_date'],
                            $dateRange['end_date']
                        );
                    }
                    
                    if (isset($shareStats['shareStatisticsByTimeInterval']) && !empty($shareStats['shareStatisticsByTimeInterval'])) {
                        $shareStatsByTime = [];
                        
                        foreach ($shareStats['shareStatisticsByTimeInterval'] as $timeStat) {
                            $shareStatsByTime[] = [
                                'share_count' => $timeStat['shareStatistics']['shareCount'] ?? 0,
                                'impression_count' => $timeStat['shareStatistics']['impressionCount'] ?? 0,
                                'click_count' => $timeStat['shareStatistics']['clickCount'] ?? 0,
                                'engagement' => $timeStat['shareStatistics']['engagement'] ?? 0,
                                'end_time' => $timeStat['timeRange']['end'] ?? now()->format('Y-m-d\TH:i:s\Z'),
                            ];
                        }
                        
                        $results['share_statistics_by_time'] = [
                            'values' => $shareStatsByTime,
                            'title' => 'Share Statistics Over Time',
                            'description' => 'Share statistics changes over time',
                        ];
                        
                        // Store in database
                        $this->storeAccountMetric(
                            'share_statistics_by_time',
                            $shareStatsByTime,
                            $dateRange['start_date'],
                            $dateRange['end_date']
                        );
                    }
                }
            }
            
            return $results;
        } catch (\Exception $e) {
            throw new MetricsException('Failed to get account metrics from LinkedIn: ' . $e->getMessage());
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
            
            // Get share statistics
            $response = $this->client->get("v2/socialActions/{$postId}", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'X-Restli-Protocol-Version' => '2.0.0',
                ],
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            $results = [];
            
            if (isset($data['likesSummary'])) {
                $results['likes_count'] = [
                    'value' => $data['likesSummary']['count'] ?? 0,
                    'title' => 'Likes',
                    'description' => 'Number of likes on the post',
                ];
                
                // Store in database
                $this->storePostMetric($postId, 'likes_count', [
                    ['value' => $data['likesSummary']['count'] ?? 0, 'end_time' => now()->format('Y-m-d\TH:i:s\Z')]
                ]);
            }
            
            if (isset($data['commentsSummary'])) {
                $results['comments_count'] = [
                    'value' => $data['commentsSummary']['count'] ?? 0,
                    'title' => 'Comments',
                    'description' => 'Number of comments on the post',
                ];
                
                // Store in database
                $this->storePostMetric($postId, 'comments_count', [
                    ['value' => $data['commentsSummary']['count'] ?? 0, 'end_time' => now()->format('Y-m-d\TH:i:s\Z')]
                ]);
            }
            
            // Get share statistics
            $shareStatsResponse = $this->client->get("v2/organizationalEntityShareStatistics", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'X-Restli-Protocol-Version' => '2.0.0',
                ],
                'query' => [
                    'q' => 'organizationalEntity',
                    'organizationalEntity' => $this->getOrganizationId(),
                    'shares[0]' => $postId,
                ],
            ]);
            
            $shareStatsData = json_decode($shareStatsResponse->getBody()->getContents(), true);
            
            if (isset($shareStatsData['elements']) && !empty($shareStatsData['elements'])) {
                $shareStats = $shareStatsData['elements'][0];
                
                if (isset($shareStats['shareStatistics'])) {
                    $stats = $shareStats['shareStatistics'];
                    
                    $results['impressions'] = [
                        'value' => $stats['impressionCount'] ?? 0,
                        'title' => 'Impressions',
                        'description' => 'Number of impressions for the post',
                    ];
                    
                    $results['clicks'] = [
                        'value' => $stats['clickCount'] ?? 0,
                        'title' => 'Clicks',
                        'description' => 'Number of clicks on the post',
                    ];
                    
                    $results['engagement'] = [
                        'value' => $stats['engagement'] ?? 0,
                        'title' => 'Engagement',
                        'description' => 'Engagement rate for the post',
                    ];
                    
                    // Store in database
                    $this->storePostMetric($postId, 'impressions', [
                        ['value' => $stats['impressionCount'] ?? 0, 'end_time' => now()->format('Y-m-d\TH:i:s\Z')]
                    ]);
                    
                    $this->storePostMetric($postId, 'clicks', [
                        ['value' => $stats['clickCount'] ?? 0, 'end_time' => now()->format('Y-m-d\TH:i:s\Z')]
                    ]);
                    
                    $this->storePostMetric($postId, 'engagement', [
                        ['value' => $stats['engagement'] ?? 0, 'end_time' => now()->format('Y-m-d\TH:i:s\Z')]
                    ]);
                }
            }
            
            return $results;
        } catch (\Exception $e) {
            throw new MetricsException('Failed to get post metrics from LinkedIn: ' . $e->getMessage());
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
            $organizationId = $this->getOrganizationId();
            
            // Default dimensions if none provided
            if (empty($dimensions)) {
                $dimensions = [
                    'industry',
                    'seniority',
                    'region',
                    'company_size',
                    'function',
                ];
            }
            
            $response = $this->client->get("v2/organizationalEntityFollowerStatistics", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'X-Restli-Protocol-Version' => '2.0.0',
                ],
                'query' => [
                    'q' => 'organizationalEntity',
                    'organizationalEntity' => $organizationId,
                ],
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            $results = [];
            
            if (isset($data['elements']) && !empty($data['elements'])) {
                $followerStats = $data['elements'][0];
                
                if (in_array('industry', $dimensions) && isset($followerStats['followerCountsByIndustry'])) {
                    $results['industry'] = $followerStats['followerCountsByIndustry'];
                }
                
                if (in_array('seniority', $dimensions) && isset($followerStats['followerCountsBySeniority'])) {
                    $results['seniority'] = $followerStats['followerCountsBySeniority'];
                }
                
                if (in_array('region', $dimensions) && isset($followerStats['followerCountsByRegion'])) {
                    $results['region'] = $followerStats['followerCountsByRegion'];
                }
                
                if (in_array('company_size', $dimensions) && isset($followerStats['followerCountsByStaffCountRange'])) {
                    $results['company_size'] = $followerStats['followerCountsByStaffCountRange'];
                }
                
                if (in_array('function', $dimensions) && isset($followerStats['followerCountsByFunction'])) {
                    $results['function'] = $followerStats['followerCountsByFunction'];
                }
                
                // Store in database
                $this->storeAccountMetric(
                    'audience_demographics',
                    [['value' => $results, 'end_time' => now()->format('Y-m-d\TH:i:s\Z')]],
                    now()->subDay(),
                    now()
                );
            }
            
            return $results;
        } catch (\Exception $e) {
            throw new MetricsException('Failed to get audience demographics from LinkedIn: ' . $e->getMessage());
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
            $organizationId = $this->getOrganizationId();
            
            $response = $this->client->get("v2/organizationalEntityFollowerStatistics", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'X-Restli-Protocol-Version' => '2.0.0',
                ],
                'query' => [
                    'q' => 'organizationalEntity',
                    'organizationalEntity' => $organizationId,
                    'timeIntervals.timeGranularityType' => strtoupper($interval),
                    'timeIntervals.timeRange.start' => $startDate,
                    'timeIntervals.timeRange.end' => $endDate,
                ],
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            $results = [
                'follower_count' => [
                    'values' => [],
                    'title' => 'Followers',
                    'description' => 'Number of followers over time',
                ],
            ];
            
            if (isset($data['elements']) && !empty($data['elements'])) {
                $followerStats = $data['elements'][0];
                
                if (isset($followerStats['followerCountsByTime']) && !empty($followerStats['followerCountsByTime'])) {
                    foreach ($followerStats['followerCountsByTime'] as $timeStat) {
                        $results['follower_count']['values'][] = [
                            'value' => $timeStat['followerCounts']['organicFollowerCount'] ?? 0,
                            'end_time' => $timeStat['timeRange']['end'] ?? now()->format('Y-m-d\TH:i:s\Z'),
                        ];
                    }
                    
                    // Store in database
                    $this->storeAccountMetric(
                        'follower_growth',
                        $results['follower_count']['values'],
                        new \DateTime($startDate),
                        new \DateTime($endDate)
                    );
                }
            }
            
            return $results;
        } catch (\Exception $e) {
            throw new MetricsException('Failed to get account growth metrics from LinkedIn: ' . $e->getMessage());
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
            ->where('platform', 'linkedin')
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
     * Get the organization ID from the account metadata.
     *
     * @return string
     * @throws \VendorName\SocialConnect\Exceptions\MetricsException
     */
    protected function getOrganizationId(): string
    {
        $metadata = $this->account->metadata;
        
        if (isset($metadata['organization_id'])) {
            return "urn:li:organization:{$metadata['organization_id']}";
        }
        
        if (isset($metadata['id'])) {
            return "urn:li:person:{$metadata['id']}";
        }
        
        throw new MetricsException('LinkedIn organization ID not found in account metadata.');
    }
}
