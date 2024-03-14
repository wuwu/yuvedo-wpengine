<?php

namespace DeliciousBrains\WPMDB\Pro\Compatibility\Layers\Platforms;

abstract class AbstractPlatform implements PlatformInterface
{
    /**
     * @var string
     */
    protected static $key = '';

    public function __construct()
    {
        add_filter('wpmdb_hosting_platform', [$this, 'filter_platform']);
    }

    /**
     * Get the platform's key.
     *
     * @return string
     */
    public static function get_key()
    {
        return static::$key;
    }

    /**
     * Are we running on this platform?
     *
     * @return bool
     */
    public static function is_platform()
    {
        return false;
    }

    /**
     * Filters the current platform key.
     *
     * @param string $platform
     *
     * @return string
     */
    public function filter_platform($platform)
    {
        return $platform;
    }
}
