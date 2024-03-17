<?php

namespace DeliciousBrains\WPMDB\Common;

use DeliciousBrains\WPMDB\Common\Filesystem\Filesystem;
use DeliciousBrains\WPMDB\Common\Migration\MigrationHelper;
use DeliciousBrains\WPMDB\Common\Util\Util;
use WP_Error;

class Debug
{
    const FILE_PREFIX = 'wpmdb-debug-';
    const FALLBACK_ID = 'migration-id-unknown';

    public function __construct()
    {
        add_action('wpmdb_after_finalize_migration', [$this, 'maybe_delete'], 10, 2);
        add_action('wpmdb_remote_finalize', [$this, 'maybe_delete'], 10, 2);
        add_action('wpmdb_deactivate_plugin', [$this, 'delete_all'], 99);
    }

    /**
     * Get the filename for logging debug info.
     *
     * @param string $migration_id Optionally get filename for a specific migration, default current migration.
     *
     * @return false|string
     */
    private static function get_debug_log_path($migration_id = '')
    {
        if (empty($migration_id) || ! is_string($migration_id)) {
            $migration_id = MigrationHelper::get_current_migration_id();
        }

        if (empty($migration_id)) {
            $migration_id = self::FALLBACK_ID;
        }

        $dir = Filesystem::get_upload_info();

        if ( ! is_string($dir) || empty($dir) || ! is_dir($dir)) {
            return false;
        }

        $role     = Util::is_source() ? 'src' : 'dst';
        $filename = trailingslashit($dir) . self::FILE_PREFIX . $role . '-' . $migration_id . '.log';

        return $filename;
    }

    /**
     * Write a formatted line to the file path.
     *
     * @param string $file
     * @param string $line
     *
     * @return bool
     */
    private static function write($file, $line)
    {
        if (empty($file) || empty($line)) {
            return false;
        }

        $resource = static::open_truncated_log($file);

        if ( ! is_resource($resource)) {
            return false;
        }

        $line   = static::line_prefix() . trim($line) . PHP_EOL;
        $result = fwrite($resource, $line);

        fclose($resource);

        return false !== $result;
    }

    /**
     * Get log line prefix.
     *
     * @return string
     */
    private static function line_prefix()
    {
        return '[' . date('c') . '] ';
    }

    /**
     * Get file resource for file name, maybe truncated if it's got a little too large.
     *
     * @param string $file
     *
     * @return false|resource
     */
    private static function open_truncated_log($file)
    {
        $resource = fopen($file, 'a+');

        if ( ! is_resource($resource)) {
            return false;
        }

        $stat = fstat($resource);

        // If the file has got bigger than we'd like, we'll proceed to trim it down, otherwise we're done here.
        if (
            empty($stat['size']) ||
            max(10000, (int)apply_filters('wpmdb_max_debug_log_bytes', 100000000)) > $stat['size']
        ) {
            return $resource;
        }

        // For speed and code sanity we'll trim the file down to a sensible number of lines rather than bytes.
        // There'll be an extra truncate marker line added at the beginning, so line count is for retained lines.
        $lines       = max(10, (int)apply_filters('wpmdb_max_debug_log_lines', 100000));
        $linecounter = $lines;
        $pos         = -strlen(PHP_EOL);
        $beginning   = false;
        $text        = array();

        // Going from end to beginning, collect lines until we hit our limit.
        while ($linecounter > 0) {
            $char = "_";
            while ($char != PHP_EOL) {
                if (fseek($resource, $pos, SEEK_END) == -1) {
                    $beginning = true;
                    break;
                }
                $char = fgetc($resource);
                $pos--;
            }

            $linecounter--;

            if ($beginning) {
                rewind($resource);
            }

            $line = fgets($resource);

            // Found previous truncate point, bail.
            if (false !== strpos($line, '--- WPMDB DEBUG LOG TRUNCATED ---')) {
                break;
            }

            $text[] = $line;

            if ($beginning) {
                break;
            }
        }
        fclose($resource);

        $text[] = static::line_prefix() . '--- WPMDB DEBUG LOG TRUNCATED ---' . PHP_EOL;

        $result = file_put_contents($file, array_reverse($text));

        if (false === $result) {
            return false;
        }

        return fopen($file, 'a+');
    }

    /**
     * Delete the file for logging debug info.
     *
     * @param string $migration_id Optionally delete specific migration's log file.
     *
     * @return bool
     */
    private static function delete($migration_id = '')
    {
        $file = static::get_debug_log_path();

        if (false === $file || ! is_string($file)) {
            return false;
        }

        if ( ! is_writable($file)) {
            return false;
        }

        return unlink($file);
    }

    /**
     * Log some information to our custom temporary debug log file.
     *
     * @param string|mixed|WP_Error $message
     *
     * @return void
     */
    public static function log($message)
    {
        static $bad_file = false;

        if ($bad_file || empty($message)) {
            return;
        }

        $enabled = apply_filters('wpmdb_enable_debug_log', false);

        if (empty($enabled) || ! is_bool($enabled)) {
            return;
        }

        $file = static::get_debug_log_path();

        if (false === $file || ! is_string($file)) {
            $bad_file = true;

            return;
        }

        if (is_wp_error($message)) {
            $line   = 'ERROR CODE: ' . $message->get_error_code();
            $result = static::write($file, $line);
            if (false === $result) {
                return;
            }

            $line   = 'ERROR MESSAGE: ' . $message->get_error_message();
            $result = static::write($file, $line);
            if (false === $result) {
                return;
            }

            $line = 'ERROR DATA: ' . print_r($message->get_error_data(), true);
            static::write($file, $line);

            return;
        }

        $line = is_string($message) ? $message : print_r($message, true);
        static::write($file, $line);
    }

    /**
     * Delete debug log file if migration successful.
     *
     * @param array|WP_Error      $state_data
     * @param array|bool|WP_Error $result
     *
     * @return void
     */
    public function maybe_delete($state_data, $result)
    {
        if (is_wp_error($state_data) || is_wp_error($result)) {
            return;
        }

        if (apply_filters('wpmdb_retain_debug_log', false)) {
            return;
        }

        static::delete();
    }

    /**
     * Delete all debug log files.
     *
     * @return void
     */
    public static function delete_all()
    {
        if (apply_filters('wpmdb_retain_debug_log', false)) {
            return;
        }

        $dir = Filesystem::get_upload_info();

        if ( ! is_string($dir) || empty($dir) || ! is_dir($dir)) {
            return;
        }

        $files = scandir($dir);

        if (empty($files)) {
            return;
        }

        foreach ($files as $file) {
            if (0 !== strpos($file, self::FILE_PREFIX) || '.log' !== substr($file, -4)) {
                continue;
            }

            $filepath = trailingslashit($dir) . $file;

            if (is_dir($filepath) || is_link($filepath) || ! is_writable($filepath)) {
                continue;
            }

            @unlink($filepath);
        }
    }
}
