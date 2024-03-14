<?php

namespace DeliciousBrains\WPMDB\Pro\Transfers\Files\Transport;

use DeliciousBrains\WPMDB\Common\Exceptions\UnknownStateProperty;
use DeliciousBrains\WPMDB\Common\MigrationPersistence\Persistence;
use DeliciousBrains\WPMDB\Common\MigrationState\Migrations\CurrentMigrationState;
use DeliciousBrains\WPMDB\Common\MigrationState\StateFactory;
use WP_Error;

class TransportManager
{
    const TRANSPORT_ERROR_COUNT_PROPERTY = 'file_transport_error_count';
    const TRANSPORT_METHOD_PROPERTY = 'file_transport_method';
    const IS_USING_FALLBACK_METHOD_PROPERTY = 'file_transport_is_using_fallback_method';
    const FALLBACK_RETRY_COUNT = 2;

    /**
     * @var TransportInterface
     */
    private $default_transport;

    /**
     * @var TransportInterface
     */
    private $fallback_transport;

    /**
     * A list of errors that are used to trigger the transport method fallback routine.
     *
     * @var string[]
     */
    private $fallback_errors = [
        'curl_error_28' => 'cURL error 28',
    ];

    /**
     * Transports a file to destination using one of the set transport methods.
     *
     * @param resource    $payload_file
     * @param array       $request_data
     * @param string|null $url
     * @param string|null $stage
     *
     * @return FileTransportResponse|WP_Error
     */
    public function transport($payload_file, $request_data = [], $url = null, $stage = null)
    {
        $method = $this->get_transport_method();

        if (is_wp_error($method)) {
            return $method;
        }

        $response = $method->transport($payload_file, $request_data, $url);

        if (is_wp_error($response)) {
            $this->handle_error($response, $stage);
        } else {
            $this->reset_transport_error_count();
        }

        return $response;
    }

    /**
     * Handles the errors returned from file transport attempts
     * and updates the current_migration state with the error count to be used
     * to determine the transport method in subsequent requests.
     *
     * @param WP_Error $error
     * @param string   $stage
     *
     * @return void
     */
    private function handle_error($error, $stage)
    {
        $error_message   = $error->get_error_message();
        $fallback_errors = array_filter($this->fallback_errors, function ($item) use ($error_message) {
            return strpos(trim(strtolower($error_message)), trim(strtolower($item))) !== false;
        });

        $current_migration = StateFactory::create('current_migration')->load_state(null);

        if (is_a($current_migration, CurrentMigrationState::class)) {
            if (count($fallback_errors) > 0) {
                //Record the found errors in the migration stats.
                $this->update_file_transport_stats($stage, $fallback_errors);
                try {
                    $count = (int)$current_migration->get(self::TRANSPORT_ERROR_COUNT_PROPERTY);
                    $current_migration->set(self::TRANSPORT_ERROR_COUNT_PROPERTY, $count + 1, false);
                } catch (UnknownStateProperty $exception) {
                    $current_migration->set(self::TRANSPORT_ERROR_COUNT_PROPERTY, 1, false);
                }
            } else {
                //Reset the error count if no errors are found.
                $current_migration->set(self::TRANSPORT_ERROR_COUNT_PROPERTY, 0, false);
            }
            $current_migration->update_state();
        }
    }

    /**
     * Resets the transport error count in the current_migration state.
     *
     * @return void
     */
    private function reset_transport_error_count()
    {
        $current_migration = StateFactory::create('current_migration')->load_state(null);

        if (is_a($current_migration, CurrentMigrationState::class)) {
            $current_migration->set(self::TRANSPORT_ERROR_COUNT_PROPERTY, 0, false);
            $current_migration->update_state();
        }
    }

    /**
     * Returns the transport method that should be used for transport.
     *
     * @return TransportInterface|WP_Error
     */
    public function get_transport_method()
    {
        if ($this->should_use_fallback_method()) {
            $method = $this->fallback_transport;
        } else {
            $method = $this->default_transport;
        }

        $current_migration = StateFactory::create('current_migration')->load_state(null);

        if (is_a($current_migration, CurrentMigrationState::class)) {
            $current_migration->set(self::TRANSPORT_METHOD_PROPERTY, $method->get_method_name(), false);
            $current_migration->update_state();
        }

        if (empty($method)) {
            return new WP_Error(
                'wpmdb_transport_manager',
                __('Unable to determine transport method, method is empty.', 'wp-migrate-db')
            );
        }

        return $method;
    }

    /**
     * Determines if the fallback method should be used.
     *
     * @return bool
     */
    public function should_use_fallback_method()
    {
        if (empty($this->fallback_transport)) {
            return false;
        }

        $current_migration = StateFactory::create('current_migration')->load_state(null);

        if (is_a($current_migration, CurrentMigrationState::class)) {
            try {
                $count                    = (int)$current_migration->get(self::TRANSPORT_ERROR_COUNT_PROPERTY);
                $is_using_fallback_method = (bool)$current_migration->get(self::IS_USING_FALLBACK_METHOD_PROPERTY, false);

                //If the fallback method is already being used, return.
                if (true === $is_using_fallback_method) {
                    return true;
                }

                if ($count >= self::FALLBACK_RETRY_COUNT) {
                    //Set the flag to indicate that the fallback method is being used.
                    $current_migration->set(self::IS_USING_FALLBACK_METHOD_PROPERTY, true, false);
                    $current_migration->update_state();
                    return true;
                }
                return false;
            } catch (UnknownStateProperty $exception) {
                return false;
            }
        }

        return false;
    }

    /**
     * @param string $method Transport method class name.
     *
     * @return void
     */
    public function set_default_method($method)
    {
        $this->default_transport = TransportFactory::create($method);
    }

    /**
     * @param string $method Transport method class name.
     *
     * @return void
     */
    public function set_fallback_method($method)
    {
        $this->fallback_transport = TransportFactory::create($method);
    }

    /**
     * Updates the migration stats with the errors that occurred during file transport.
     *
     * @param string $stage  The stage of the migration.
     * @param array  $errors The errors that occurred during file transport.
     *
     * @return bool
     */
    public function update_file_transport_stats($stage, $errors)
    {
        if ( ! is_array($errors)) {
            return false;
        }

        foreach ($errors as $key => $error) {
            $stat_error = Persistence::getMigrationErrorFromStats($stage, $key);

            if (empty($stat_error['error_count']) || !is_numeric($stat_error['error_count'])) {
                $stat_error['error_count'] = 1;
            } else {
                $stat_error['error_count'] += 1;
            }

            if (empty($stat_error['error_timestamps']) || ! is_array($stat_error['error_timestamps'])) {
                $stat_error['error_timestamps'] = [];
            }

            $stat_error['error_timestamps'][] = time();

            Persistence::addMigrationErrorToStats($stage, $key, $stat_error);
        }

        return true;
    }
}
