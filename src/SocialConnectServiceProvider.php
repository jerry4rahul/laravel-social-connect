<?php

namespace VendorName\SocialConnect;

use Illuminate\Support\ServiceProvider;
use VendorName\SocialConnect\Services\Facebook\FacebookService;
use VendorName\SocialConnect\Services\Facebook\FacebookPublishingService;
use VendorName\SocialConnect\Services\Facebook\FacebookMetricsService;
use VendorName\SocialConnect\Services\Facebook\FacebookMessagingService;
use VendorName\SocialConnect\Services\Facebook\FacebookCommentService;
use VendorName\SocialConnect\Services\Instagram\InstagramService;
use VendorName\SocialConnect\Services\Instagram\InstagramPublishingService;
use VendorName\SocialConnect\Services\Instagram\InstagramMetricsService;
use VendorName\SocialConnect\Services\Instagram\InstagramMessagingService;
use VendorName\SocialConnect\Services\Instagram\InstagramCommentService;
use VendorName\SocialConnect\Services\Twitter\TwitterService;
use VendorName\SocialConnect\Services\Twitter\TwitterPublishingService;
use VendorName\SocialConnect\Services\Twitter\TwitterMetricsService;
use VendorName\SocialConnect\Services\Twitter\TwitterMessagingService;
use VendorName\SocialConnect\Services\Twitter\TwitterCommentService;
use VendorName\SocialConnect\Services\LinkedIn\LinkedInService;
use VendorName\SocialConnect\Services\LinkedIn\LinkedInPublishingService;
use VendorName\SocialConnect\Services\LinkedIn\LinkedInMetricsService;
use VendorName\SocialConnect\Services\LinkedIn\LinkedInMessagingService;
use VendorName\SocialConnect\Services\LinkedIn\LinkedInCommentService;
use VendorName\SocialConnect\Services\YouTube\YouTubeService;
use VendorName\SocialConnect\Services\YouTube\YouTubePublishingService;
use VendorName\SocialConnect\Services\YouTube\YouTubeMetricsService;
use VendorName\SocialConnect\Services\YouTube\YouTubeMessagingService;
use VendorName\SocialConnect\Services\YouTube\YouTubeCommentService;

class SocialConnectServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__."/../config/social-connect.php", "social-connect"
        );

        // Register the main SocialConnectManager
        $this->app->singleton(SocialConnectManager::class, function ($app) {
            return new SocialConnectManager($app);
        });

        // Bind individual stateless service implementations
        // These can be resolved directly or via the manager
        $this->registerPlatformServices();
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__."/../config/social-connect.php" => config_path("social-connect.php"),
            ], "config");

            // No migrations to publish anymore
            // $this->publishes([
            //     __DIR__."/../database/migrations/" => database_path("migrations"),
            // ], "migrations");
        }

        // No routes or views defined in this stateless version
    }

    /**
     * Register the individual platform service bindings.
     */
    protected function registerPlatformServices()
    {
        // Facebook
        $this->app->singleton(FacebookService::class);
        $this->app->singleton(FacebookPublishingService::class);
        $this->app->singleton(FacebookMetricsService::class);
        $this->app->singleton(FacebookMessagingService::class);
        $this->app->singleton(FacebookCommentService::class);

        // Instagram
        $this->app->singleton(InstagramService::class);
        $this->app->singleton(InstagramPublishingService::class);
        $this->app->singleton(InstagramMetricsService::class);
        $this->app->singleton(InstagramMessagingService::class);
        $this->app->singleton(InstagramCommentService::class);

        // Twitter
        $this->app->singleton(TwitterService::class);
        $this->app->singleton(TwitterPublishingService::class);
        $this->app->singleton(TwitterMetricsService::class);
        $this->app->singleton(TwitterMessagingService::class);
        $this->app->singleton(TwitterCommentService::class);

        // LinkedIn
        $this->app->singleton(LinkedInService::class);
        $this->app->singleton(LinkedInPublishingService::class);
        $this->app->singleton(LinkedInMetricsService::class);
        $this->app->singleton(LinkedInMessagingService::class);
        $this->app->singleton(LinkedInCommentService::class);

        // YouTube
        $this->app->singleton(YouTubeService::class);
        $this->app->singleton(YouTubePublishingService::class);
        $this->app->singleton(YouTubeMetricsService::class);
        $this->app->singleton(YouTubeMessagingService::class);
        $this->app->singleton(YouTubeCommentService::class);
    }
}
