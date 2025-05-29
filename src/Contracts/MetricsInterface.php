<?php

namespace VendorName\SocialConnect\Contracts;

interface MetricsInterface
{
    /**
     * Get account-level metrics.
     *
     * @param string $period
     * @param array $metrics
     * @return array
     */
    public function getAccountMetrics(string $period = 'last_30_days', array $metrics = []): array;
    
    /**
     * Get post-level metrics.
     *
     * @param string $postId
     * @param array $metrics
     * @return array
     */
    public function getPostMetrics(string $postId, array $metrics = []): array;
    
    /**
     * Get metrics for multiple posts.
     *
     * @param array $postIds
     * @param array $metrics
     * @return array
     */
    public function getBulkPostMetrics(array $postIds, array $metrics = []): array;
    
    /**
     * Get audience demographics.
     *
     * @param array $dimensions
     * @return array
     */
    public function getAudienceDemographics(array $dimensions = []): array;
    
    /**
     * Get account growth metrics over time.
     *
     * @param string $startDate
     * @param string $endDate
     * @param string $interval
     * @return array
     */
    public function getAccountGrowth(string $startDate, string $endDate, string $interval = 'day'): array;
}
