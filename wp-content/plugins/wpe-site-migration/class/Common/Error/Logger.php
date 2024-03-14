<?php

namespace DeliciousBrains\WPMDB\Common\Error;

use DeliciousBrains\WPMDB\Common\Util\Util;
use WP_Error;

/**
 * Adds log messages about migrations
 */
class Logger
{

    /**
     * register hooks
     **/
    public function register()
    {
        add_filter('wpmdb_initiate_migration', [$this, 'initiate']);
        add_action('wpmdb_after_finalize_migration', [$this, 'complete'], 20, 2);
        add_action('wpmdb_cancellation', [$this, 'cancellation']);
        add_action('wpmdb_respond_remote_initiate', [$this, 'remoteInitiate']);
        add_action('wpmdb_remote_finalize', [$this, 'remoteFinalize'], 10, 2);
        add_action('wpmdb_respond_to_push_cancellation', [$this, 'remoteCancellation']);
    }

    /**
     * Logs structured message to error log.
     *
     * @param array $args       type and location keys required, target key optional.
     * @param array $state_data Optional.
     **/
    private function logMessage($args, $state_data = [])
    {
        // Bare minimum that is required.
        if ( ! isset($args['type'], $args['location'])) {
            return;
        }

        $state_data = Util::merge_existing_state_data($state_data);

        $log_message = 'WPMDB: ';
        $stats       = [
            'type'     => $args['type'],
            'location' => $args['location'],
        ];

        if (isset($args['target'])) {
            $stats['target'] = $args['target'];
        } elseif (isset($state_data['intent'])) {
            $stats['target'] = ('pull' === $state_data['intent'] && 'local' === $args['location']) || ('push' === $state_data['intent'] && 'remote' === $args['location']);
        }

        if (isset($state_data['site_url'], $state_data['url'])) {
            $stats['sites'] = [
                'local'  => $state_data['site_url'],
                'remote' => $state_data['url'],
            ];
        }

        if (isset($state_data['migration_state_id'])) {
            $stats['migration_id'] = $state_data['migration_state_id'];
        }

        if (isset($state_data['remote_state_id'])) {
            $stats['migration_id'] = $state_data['remote_state_id'];
        }

        error_log($log_message . json_encode($stats));
    }

    /**
     * Log initiate migration.
     *
     * @param array $state_data
     *
     * @return array
     *
     * @handles wpmdb_initiate_migration
     **/
    public function initiate($state_data)
    {
        if (is_wp_error($state_data)) {
            return $state_data;
        }

        $args = [
            'type'     => 'initiate',
            'location' => 'local',
        ];
        $this->logMessage($args, $state_data);

        return $state_data;
    }

    /**
     * Log initiate migration on remote.
     *
     * @param array $state_data
     *
     * @handles wpmdb_respond_remote_initiate
     **/
    public function remoteInitiate($state_data)
    {
        if (is_wp_error($state_data)) {
            return;
        }

        $args = [
            'type'     => 'initiate',
            'location' => 'remote',
        ];
        $this->logMessage($args, $state_data);
    }

    /**
     * Log migration complete on remote.
     *
     * @param array|WP_Error      $state_data
     * @param array|bool|WP_Error $result
     *
     * @handles wpmdb_remote_finalize
     **/
    public function remoteFinalize($state_data, $result)
    {
        if (is_wp_error($state_data) || is_wp_error($result)) {
            return;
        }

        $args = [
            'type'     => 'complete',
            'location' => 'remote',
            'target'   => true,
        ];
        $this->logMessage($args, $state_data);
    }

    /**
     * Log migration complete.
     *
     * @param array|WP_Error      $state_data
     * @param array|bool|WP_Error $result
     *
     * @handles wpmdb_after_finalize_migration
     **/
    public function complete($state_data, $result)
    {
        if (is_wp_error($state_data) || is_wp_error($result)) {
            return;
        }

        $args = [
            'type'     => 'complete',
            'location' => 'local',
        ];
        $this->logMessage($args);
    }

    /**
     * Log cancellation.
     *
     * @handles wpmdb_cancellation
     **/
    public function cancellation()
    {
        $args = [
            'type'     => 'cancel',
            'location' => 'local',
        ];
        $this->logMessage($args);
    }

    /**
     * Log cancellation on remote.
     *
     * @handles wpmdb_respond_to_push_cancellation
     **/
    public function remoteCancellation()
    {
        $args = [
            'type'     => 'cancel',
            'location' => 'remote',
        ];
        $this->logMessage($args);
    }
}
