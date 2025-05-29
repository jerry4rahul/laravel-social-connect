<?php

namespace VendorName\SocialConnect;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use VendorName\SocialConnect\Contracts\CommentManagementInterface;
use VendorName\SocialConnect\Contracts\MessagingInterface;
use VendorName\SocialConnect\Contracts\MetricsInterface;
use VendorName\SocialConnect\Contracts\PublishableInterface;
use VendorName\SocialConnect\Contracts\SocialPlatformInterface;
use VendorName\SocialConnect\Exceptions\SocialConnectException;

class SocialConnectManager
{
    /**
     * The application instance.
     *
     * @var \Illuminate\Contracts\Foundation\Application
     */
    protected $app;

    /**
     * The array of resolved platform services.
     *
     * @var array
     */
    protected $services = [];

    /**
     * Create a new SocialConnect manager instance.
     *
     * @param \Illuminate\Contracts\Foundation\Application $app
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Get the configuration for a specific platform.
     *
     * @param string $platform
     * @return array
     * @throws InvalidArgumentException
     */
    protected function getConfig(string $platform): array
    {
        $config = Config::get("social-connect.platforms." . strtolower($platform));
        if (!$config) {
            throw new InvalidArgumentException("Configuration for platform [{$platform}] not found.");
        }
        return $config;
    }

    /**
     * Resolve the given platform service type.
     *
     * @param string $platform The platform name (e.g., facebook, twitter).
     * @param string $serviceType The type of service (e.g., auth, publisher, metrics, messenger, commenter).
     * @return mixed The resolved service instance.
     * @throws InvalidArgumentException
     */
    protected function resolve(string $platform, string $serviceType)
    {
        $platform = strtolower($platform);
        $cacheKey = "{$platform}.{$serviceType}";

        if (isset($this->services[$cacheKey])) {
            return $this->services[$cacheKey];
        }

        $config = $this->getConfig($platform);
        $serviceClass = $config["services"][strtolower($serviceType)] ?? null;

        if (!$serviceClass || !class_exists($serviceClass)) {
            throw new InvalidArgumentException("Service class for [{$serviceType}] on platform [{$platform}] is not defined or invalid.");
        }

        return $this->services[$cacheKey] = $this->app->make($serviceClass);
    }

    /**
     * Get the authentication service instance for a platform.
     *
     * @param string $platform
     * @return SocialPlatformInterface
     */
    public function authenticator(string $platform): SocialPlatformInterface
    {
        return $this->resolve($platform, "auth");
    }

    /**
     * Get the publishing service instance for a platform.
     *
     * @param string $platform
     * @return PublishableInterface
     */
    public function publisher(string $platform): PublishableInterface
    {
        return $this->resolve($platform, "publisher");
    }

    /**
     * Get the metrics service instance for a platform.
     *
     * @param string $platform
     * @return MetricsInterface
     */
    public function metrics(string $platform): MetricsInterface
    {
        return $this->resolve($platform, "metrics");
    }

    /**
     * Get the messaging service instance for a platform.
     *
     * @param string $platform
     * @return MessagingInterface
     */
    public function messenger(string $platform): MessagingInterface
    {
        return $this->resolve($platform, "messenger");
    }

    /**
     * Get the comment management service instance for a platform.
     *
     * @param string $platform
     * @return CommentManagementInterface
     */
    public function commenter(string $platform): CommentManagementInterface
    {
        return $this->resolve($platform, "commenter");
    }

    /**
     * Dynamically call the default driver instance.
     *
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        // This manager primarily acts as a factory for the specific service types.
        // Direct calls like SocialConnect::publishText(...) are less intuitive in stateless mode
        // as credentials need to be passed explicitly.
        // Recommend using the specific service instances obtained via authenticator(), publisher(), etc.
        throw new \BadMethodCallException("Direct calls on SocialConnectManager are not supported in stateless mode. Please resolve the specific service (e.g., publisher(\'facebook\')->publishText(...)).");
    }
}
