<?php

namespace VendorName\SocialConnect;

use Illuminate\Support\ServiceProvider;
use VendorName\SocialConnect\Contracts\CommentManagementInterface;
use VendorName\SocialConnect\Contracts\MessagingInterface;
use VendorName\SocialConnect\Contracts\MetricsInterface;
use VendorName\SocialConnect\Contracts\PublishableInterface;
use VendorName\SocialConnect\Contracts\SocialPlatformInterface;
use VendorName\SocialConnect\Models\SocialAccount;

class SocialConnectServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        // Publish configuration
        $this->publishes([
            __DIR__ . '/../config/social-connect.php' => config_path('social-connect.php'),
        ], 'config');

        // Publish migrations
        $this->publishes([
            __DIR__ . '/../database/migrations/' => database_path('migrations'),
        ], 'migrations');

        // Load routes
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');

        // Load views
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'social-connect');
        
        // Publish views
        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/social-connect'),
        ], 'views');
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        // Merge configuration
        $this->mergeConfigFrom(
            __DIR__ . '/../config/social-connect.php', 'social-connect'
        );

        // Register the main service
        $this->app->singleton('social-connect', function ($app) {
            return new SocialConnectManager($app);
        });

        // Register platform services
        $this->app->bind(SocialPlatformInterface::class, function ($app, $parameters) {
            $account = $parameters['account'] ?? null;
            
            if (!$account instanceof SocialAccount) {
                throw new \InvalidArgumentException('A valid social account must be provided.');
            }
            
            switch ($account->platform) {
                case 'facebook':
                    return new Services\Facebook\FacebookService($account);
                case 'instagram':
                    return new Services\Instagram\InstagramService($account);
                case 'twitter':
                    return new Services\Twitter\TwitterService($account);
                case 'linkedin':
                    return new Services\LinkedIn\LinkedInService($account);
                case 'youtube':
                    return new Services\YouTube\YouTubeService($account);
                default:
                    throw new \InvalidArgumentException("Unsupported platform: {$account->platform}");
            }
        });

        // Register publishing services
        $this->app->bind(PublishableInterface::class, function ($app, $parameters) {
            $account = $parameters['account'] ?? null;
            
            if (!$account instanceof SocialAccount) {
                throw new \InvalidArgumentException('A valid social account must be provided.');
            }
            
            switch ($account->platform) {
                case 'facebook':
                    return new Services\Facebook\FacebookPublishingService($account);
                case 'instagram':
                    return new Services\Instagram\InstagramPublishingService($account);
                case 'twitter':
                    return new Services\Twitter\TwitterPublishingService($account);
                case 'linkedin':
                    return new Services\LinkedIn\LinkedInPublishingService($account);
                case 'youtube':
                    return new Services\YouTube\YouTubePublishingService($account);
                default:
                    throw new \InvalidArgumentException("Unsupported platform: {$account->platform}");
            }
        });

        // Register metrics services
        $this->app->bind(MetricsInterface::class, function ($app, $parameters) {
            $account = $parameters['account'] ?? null;
            
            if (!$account instanceof SocialAccount) {
                throw new \InvalidArgumentException('A valid social account must be provided.');
            }
            
            switch ($account->platform) {
                case 'facebook':
                    return new Services\Facebook\FacebookMetricsService($account);
                case 'instagram':
                    return new Services\Instagram\InstagramMetricsService($account);
                case 'twitter':
                    return new Services\Twitter\TwitterMetricsService($account);
                case 'linkedin':
                    return new Services\LinkedIn\LinkedInMetricsService($account);
                case 'youtube':
                    return new Services\YouTube\YouTubeMetricsService($account);
                default:
                    throw new \InvalidArgumentException("Unsupported platform: {$account->platform}");
            }
        });

        // Register messaging services
        $this->app->bind(MessagingInterface::class, function ($app, $parameters) {
            $account = $parameters['account'] ?? null;
            
            if (!$account instanceof SocialAccount) {
                throw new \InvalidArgumentException('A valid social account must be provided.');
            }
            
            switch ($account->platform) {
                case 'facebook':
                    return new Services\Facebook\FacebookMessagingService($account);
                case 'instagram':
                    return new Services\Instagram\InstagramMessagingService($account);
                case 'twitter':
                    return new Services\Twitter\TwitterMessagingService($account);
                case 'linkedin':
                    return new Services\LinkedIn\LinkedInMessagingService($account);
                case 'youtube':
                    return new Services\YouTube\YouTubeMessagingService($account);
                default:
                    throw new \InvalidArgumentException("Unsupported platform: {$account->platform}");
            }
        });

        // Register comment management services
        $this->app->bind(CommentManagementInterface::class, function ($app, $parameters) {
            $account = $parameters['account'] ?? null;
            
            if (!$account instanceof SocialAccount) {
                throw new \InvalidArgumentException('A valid social account must be provided.');
            }
            
            switch ($account->platform) {
                case 'facebook':
                    return new Services\Facebook\FacebookCommentService($account);
                case 'instagram':
                    return new Services\Instagram\InstagramCommentService($account);
                case 'twitter':
                    return new Services\Twitter\TwitterCommentService($account);
                case 'linkedin':
                    return new Services\LinkedIn\LinkedInCommentService($account);
                case 'youtube':
                    return new Services\YouTube\YouTubeCommentService($account);
                default:
                    throw new \InvalidArgumentException("Unsupported platform: {$account->platform}");
            }
        });
    }
}
