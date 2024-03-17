<?php

namespace DeliciousBrains\WPMDB\Pro\Compatibility\Layers\Platforms;

class Flywheel extends AbstractPlatform
{
    /**
     * @var string
     */
    protected static $key = 'flywheel';

    /**
     * Are we running on this platform?
     *
     * @return bool
     */
    public static function is_platform()
    {
        return defined('FLYWHEEL_CONFIG_DIR');
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
        if (static::is_platform()) {
            return static::get_key();
        }

        return $platform;
    }
}
