<?php

namespace VendorName\SocialConnect;

use Illuminate\Support\Facades\App;
use VendorName\SocialConnect\Contracts\CommentManagementInterface;
use VendorName\SocialConnect\Contracts\MessagingInterface;
use VendorName\SocialConnect\Contracts\MetricsInterface;
use VendorName\SocialConnect\Contracts\PublishableInterface;
use VendorName\SocialConnect\Contracts\SocialPlatformInterface;
use VendorName\SocialConnect\Models\SocialAccount;

class SocialConnectManager
{
    /**
     * The application instance.
     *
     * @var \Illuminate\Foundation\Application
     */
    protected $app;

    /**
     * Create a new SocialConnectManager instance.
     *
     * @param \Illuminate\Foundation\Application $app
     */
    public function __construct($app)
    {
        $this->app = $app;
    }

    /**
     * Get a social platform service instance.
     *
     * @param \VendorName\SocialConnect\Models\SocialAccount|int $account
     * @return \VendorName\SocialConnect\Contracts\SocialPlatformInterface
     */
    public function platform($account)
    {
        $account = $this->resolveAccount($account);
        
        return App::make(SocialPlatformInterface::class, ['account' => $account]);
    }

    /**
     * Get a publishing service instance.
     *
     * @param \VendorName\SocialConnect\Models\SocialAccount|int $account
     * @return \VendorName\SocialConnect\Contracts\PublishableInterface
     */
    public function publisher($account)
    {
        $account = $this->resolveAccount($account);
        
        return App::make(PublishableInterface::class, ['account' => $account]);
    }

    /**
     * Get a metrics service instance.
     *
     * @param \VendorName\SocialConnect\Models\SocialAccount|int $account
     * @return \VendorName\SocialConnect\Contracts\MetricsInterface
     */
    public function metrics($account)
    {
        $account = $this->resolveAccount($account);
        
        return App::make(MetricsInterface::class, ['account' => $account]);
    }

    /**
     * Get a messaging service instance.
     *
     * @param \VendorName\SocialConnect\Models\SocialAccount|int $account
     * @return \VendorName\SocialConnect\Contracts\MessagingInterface
     */
    public function messaging($account)
    {
        $account = $this->resolveAccount($account);
        
        return App::make(MessagingInterface::class, ['account' => $account]);
    }

    /**
     * Get a comment management service instance.
     *
     * @param \VendorName\SocialConnect\Models\SocialAccount|int $account
     * @return \VendorName\SocialConnect\Contracts\CommentManagementInterface
     */
    public function comments($account)
    {
        $account = $this->resolveAccount($account);
        
        return App::make(CommentManagementInterface::class, ['account' => $account]);
    }

    /**
     * Resolve the account instance.
     *
     * @param \VendorName\SocialConnect\Models\SocialAccount|int $account
     * @return \VendorName\SocialConnect\Models\SocialAccount
     */
    protected function resolveAccount($account)
    {
        if (is_numeric($account)) {
            $account = SocialAccount::findOrFail($account);
        }
        
        if (!$account instanceof SocialAccount) {
            throw new \InvalidArgumentException('A valid social account must be provided.');
        }
        
        return $account;
    }
}
