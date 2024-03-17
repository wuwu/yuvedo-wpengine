<?php

namespace DeliciousBrains\WPMDB\Common\Transfers\Files;

use DeliciousBrains\WPMDB\Common\Debug;
use DeliciousBrains\WPMDB\Common\Error\ErrorLog;
use DeliciousBrains\WPMDB\Common\Filesystem\Filesystem;
use DeliciousBrains\WPMDB\Common\Http\Helper;
use DeliciousBrains\WPMDB\Common\Http\Http;
use DeliciousBrains\WPMDB\Common\Http\RemotePost;
use DeliciousBrains\WPMDB\Common\Migration\MigrationHelper;
use DeliciousBrains\WPMDB\Common\MigrationPersistence\Persistence;
use DeliciousBrains\WPMDB\Common\MigrationState\MigrationStateManager;
use DeliciousBrains\WPMDB\Common\MigrationState\StateFactory;
use DeliciousBrains\WPMDB\Common\Queue\Manager;
use DeliciousBrains\WPMDB\Common\Settings\Settings;
use DeliciousBrains\WPMDB\Common\Util\ZipAndEncode;
use DeliciousBrains\WPMDB\Common\Util\Util as Common_Util;
use DI\DependencyException;
use DI\NotFoundException;
use DirectoryIterator;
use Exception;
use Requests;
use Requests_Response;
use WP_Error;
use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class Util
{

    public $filesystem;
    /**
     * @var Http
     */
    private $http;
    /**
     * @var ErrorLog
     */
    private $error_log;
    /**
     * @var Helper
     */
    private $http_helper;
    /**
     * @var RemotePost
     */
    private $remote_post;
    /**
     * @var Settings
     */
    private $settings;
    /**
     * @var MigrationStateManager
     */
    private $migration_state_manager;
    /**
     * @var Common_Util
     */
    private $util;

    const TMP_FOLDER_PREFIX = 'wpmdb-tmp';

    public function __construct(
        Filesystem $filesystem,
        Http $http,
        ErrorLog $error_log,
        Helper $http_helper,
        RemotePost $remote_post,
        Settings $settings,
        MigrationStateManager $migration_state_manager,
        Common_Util $util
    ) {
        $this->filesystem              = $filesystem;
        $this->http                    = $http;
        $this->error_log               = $error_log;
        $this->http_helper             = $http_helper;
        $this->remote_post             = $remote_post;
        $this->settings                = $settings->get_settings();
        $this->migration_state_manager = $migration_state_manager;
        $this->util                    = $util;

        add_filter( 'wpmdb_theoretical_transfer_bottleneck', function ( $bottleneck ) {
            return $this->get_transfer_bottleneck();
        } );
    }

    /**
     * Get a list of files to be migrated from the remote.
     *
     * @param array  $directories
     * @param string $action
     * @param array  $excludes
     * @param string $date
     * @param string $timezone
     *
     * @return array|WP_Error
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function get_remote_files(array $directories, $action, $excludes, $date = null, $timezone = null)
    {
        // POST to remote to get list of files
        $state_data = $this->migration_state_manager->set_post_data();

        if (is_wp_error($state_data)) {
            return $state_data;
        }

        $data                       = array();
        $data['action']             = $action;
        $data['intent']             = $state_data['intent'];
        $data['migration_state_id'] = $state_data['migration_state_id'];
        $data['folders']            = json_encode($directories);
        $data['excludes']           = json_encode($excludes);
        $data['stage']              = $state_data['stage'];
        $data['sig']                = $this->http_helper->create_signature($data, $state_data['key']);

        if ( ! is_null($date)) {
            $data['date'] = $date;
        }

        if ( ! is_null($timezone)) {
            $data['timezone'] = $timezone;
        }

        $ajax_url = trailingslashit($state_data['url']) . 'wp-admin/admin-ajax.php';
        $response = $this->remote_post->post($ajax_url, $data, __FUNCTION__);
        $response = $this->remote_post->verify_remote_post_response($response);

        if (is_wp_error($response)) {
            return $response;
        }

        $response['data'] = json_decode(ZipAndEncode::decode($response['data']), true);

        if (isset($response['wpmdb_error'])) {
            return $response;
        }

        if ( ! $response['success']) {
            return new WP_Error('wpmdbtransfers_invalid_file_list', $response['data']);
        }

        return $response['data'];
    }

    /**
     * Save queue status to the remote site.
     *
     * @param array  $queue_status
     * @param string $action
     *
     * @return Requests_Response|WP_Error
     * @throws Exception
     */
    public function save_queue_status_to_remote(array $queue_status, $action)
    {
        $state_data = $this->migration_state_manager->set_post_data();

        if (is_wp_error($state_data)) {
            return $state_data;
        }

        $data                    = array();
        $data['action']          = $action;
        $data['intent']          = $state_data['intent'];
        $data['stage']           = $state_data['stage'];
        $data['remote_state_id'] = MigrationHelper::get_current_migration_id();
        $data['sig']             = $this->http_helper->create_signature($data, $state_data['key']);

        $data['queue_status'] = base64_encode(gzencode(json_encode($queue_status)));

        $ajax_url = trailingslashit($state_data['url']) . 'wp-admin/admin-ajax.php';

        return $this->remote_post_and_verify($ajax_url, $data);
    }

    /**
     * Fire POST at remote and check for the 'wpmdb_error' key in response
     *
     * @param string $ajax_url
     * @param array  $data
     *
     * @return Requests_Response|WP_Error
     * @throws Exception
     */
    public function remote_post_and_verify($ajax_url, $data, $headers = array())
    {
        $requests_options = $this->get_requests_options();

        try {
            $response = Requests::post($ajax_url, $headers, $data, $requests_options);
        } catch (Exception $e) {
            return new WP_Error('wpmdb_error', $e->getMessage());
        }

        $response_body = json_decode($response->body, true);

        if (isset($response_body['wpmdb_error'])) {
            throw new Exception($response_body['body']);
        }

        return $response;
    }

    /**
     * Log and generate WP_Error with transfer related error code.
     *
     * @param string $msg
     * @param array  $data
     *
     * @return WP_Error
     */
    public function log_and_return_error($msg, $data = array())
    {
        $this->error_log->log_error($msg, $data);

        return new WP_Error('wpmdb_transfer_error', $msg);
    }

    /**
     *
     * Handles individual file transfer errors
     *
     * @param string $message
     *
     * @return array
     */
    public function fire_transfer_errors($message)
    {
        error_log($message);
        $this->error_log->log_error($message);

        return [
            'error'   => true,
            'message' => $message,
        ];
    }

    /**
     * @return array
     */
    public function get_requests_options()
    {
        // Listen to SSL verify setting
        $wpmdb_settings   = $this->settings;
        $sslverify        = 1 === $wpmdb_settings['verify_ssl'];
        $requests_options = [];

        // Make Requests cURL transport wait 45s for timeouts
        $hooks = new \Requests_Hooks();
        $hooks->register(
            'curl.before_send',
            function ($handle) {
                $remote_cookie = Persistence::getRemoteWPECookie();
                if (false !== $remote_cookie) {
                    curl_setopt($handle, CURLOPT_COOKIE, 'wpe-auth=' . $remote_cookie);
                }
                curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 45);
                curl_setopt($handle, CURLOPT_TIMEOUT, 45);
                curl_setopt($handle, CURLOPT_ENCODING, 'gzip,deflate');
            }
        );

        $requests_options['hooks']     = $hooks;
        $requests_options['useragent'] = $this->util->get_requests_user_agent();
        if ( ! $sslverify) {
            $requests_options['verify'] = false;
        }

        return $requests_options;
    }

    /**
     * Check's that files migrated match the .manifest file. Always fires at the migration destination
     *
     * @param array  $files
     * @param string $stage
     *
     * @throws Exception
     */
    public function check_manifest($files, $stage)
    {
        $failures = [];

        foreach ($files as $file) {
            $file_path = Common_Util::get_stage_base_dir($stage);
            if (!file_exists($file_path)) {
                $failures[] = $file_path;
            }
        }

        if (!empty($failures)) {
            throw new Exception(sprintf(__('The following files failed to transfer: <br> %s', 'wp-migrate-db'), implode('<br>', $failures)));
        }
    }

    /**
     * Merges a stored queue status (if exists) with the provided one.
     *
     * @param array $queue_status
     * @param string $stage
     * @param string $migration_state_id
     * @return array
     */
    public function concat_existing_remote_items($queue_status, $stage, $migration_state_id)
    {
        $stored_queue = $this->get_queue_status($stage, $migration_state_id);
        if (false !== $stored_queue) {
            $queue_status['total'] += $stored_queue['total'];
            $queue_status['size'] += $stored_queue['size'];
            $queue_status['manifest'] = array_merge($stored_queue['manifest'], $queue_status['manifest']);

            $this->remove_tmp_folder($stage);
        }

        return $queue_status;
    }

    /**
     * Saves queue data to the manifest file
     *
     * @param array $data
     * @param       $stage
     * @param string $migration_state_id
     * @param bool $full_site_export
     *
     * @return bool|int
     * @throws Exception
     */
    public function save_queue_status(array $data, $stage, $migration_state_id, $full_site_export = false)
    {
        $tmp_path = $this->get_queue_tmp_path($stage, $full_site_export);

        //Remove any existing temporary folders
        $this->cleanup_existing_temp_folders($stage);

        if (!$this->filesystem->mkdir($tmp_path)) {
            throw new Exception(sprintf(__('Unable to create folder for file transfers: %s'), $tmp_path));
        }

        //Auto-generate .htaccess file to prevent direct file access on apache
        if ($stage !== 'media_files') {
            self::prevent_direct_access_with_htaccess($tmp_path);
        }

        $filename = $this->get_queue_manifest_file_name($migration_state_id);
        $manifest = @file_put_contents($tmp_path . DIRECTORY_SEPARATOR . $filename, json_encode($data));

        if (!$manifest) {
            throw new Exception(sprintf(__('Unable to create the transfer manifest file. Verify the web server can write to this file/folder: `%s`'), $tmp_path . DIRECTORY_SEPARATOR . '.manifest'));
        }

        return $manifest;
    }

    /**
     * Get stored queue manifest array.
     *
     * @param string $stage
     * @param string $migration_state_id
     * @param bool $full_site_export
     *
     * @return array|false
     */
    public function get_queue_status($stage, $migration_state_id, $full_site_export = false)
    {
        $filename  = $this->get_queue_manifest_file_name($migration_state_id);
        $tmp_path  = $this->get_queue_tmp_path($stage, $full_site_export);
        $file_path = $tmp_path . DIRECTORY_SEPARATOR . $filename;
        $manifest  = is_file($file_path) ? @file_get_contents($file_path) : false;

        if (false !== $manifest) {
            return json_decode($manifest, true);
        }

        return false;
    }

    /**
     * Get queue tmp path.
     *
     * @param string $stage
     * @param bool $full_site_export
     * @return string
     */
    private function get_queue_tmp_path($stage, $full_site_export = false)
    {
        //@todo avoid passing full_site_export down to here.
        if ($full_site_export === true || $stage === 'media_files') {
            return self::get_wp_uploads_dir();
        }
        return self::get_temp_dir($stage);

    }

    /**
     * Get manifest file name.
     *
     * @param $migration_state_id
     * @return string
     */
    public static function get_queue_manifest_file_name($migration_state_id)
    {
        return '.' . $migration_state_id . '-manifest';
    }

    public function cleanup_media_migration()
    {
        $uploads = self::get_wp_uploads_dir();
        $this->remove_manifests($uploads);

        return true;
    }

    /**
     * Will look for a tmp folder to remove based on the $stage param (themes, plugins)
     *
     * @param $stage
     *
     * @return bool
     */
    public function remove_tmp_folder($stage)
    {
        $fs = $this->filesystem;

        if ($stage === 'media_files') {
            return $this->cleanup_media_migration();
        }

        $tmp_folder = self::get_temp_dir($stage);
        if ($fs->file_exists($tmp_folder)) {
            if ($fs->is_dir($tmp_folder)) {
                return $fs->rmdir($tmp_folder, true);
            }
        }

        return true;
    }

    /**
     *
     * Verify a file is the correct size.
     *
     * Will throw an Exception if the given file does not exist.
     *
     * @param string $filepath
     * @param int    $expected_size
     *
     * @return bool
     * @throws Exception
     */
    public function verify_file($filepath, $expected_size)
    {
        if ( ! file_exists($filepath)) {
            throw new Exception(sprintf(__('File does not exist: %s', 'wp-migrate-db'), $filepath));
        }

        $filesystem_size = filesize($filepath);
        if ($filesystem_size !== (int)$expected_size) {
            return false;
        }

        return true;
    }

    /**
     * Enqueue files to be transferred.
     *
     * @param array   $files
     * @param Manager $queue_manager
     * @param string  $stage
     *
     * @return bool|WP_Error
     */
    public function enqueue_files($files, $queue_manager, $stage)
    {
        foreach ($files as $file) {
            $enqueued = $queue_manager->enqueue_file($file, $stage);

            if (is_wp_error($enqueued)) {
                return new WP_Error(
                    'enqueue-file-error',
                    sprintf(
                        __('Could not add file "%1$s" to queue.<br>Database Error: %2$s', 'wp-migrate-db'),
                        $file['absolute_path'],
                        $enqueued->get_error_message()
                    )
                );
            }
        }

        return true;
    }

    /**
     * Determine folder transferred numbers for client.
     *
     * @param array $data
     * @param int   $bytes_transferred
     * @param array $state_data
     *
     * @return array
     */
    public function process_queue_data($data, $state_data, $bytes_transferred = 0)
    {
        $result_set = [];

        if (empty($data)) {
            return array($result_set, 0);
        }


        $stage = $state_data['stage'];
        // Could be empty - stores progress of folder migrations between requests. Generally, the size of batch is 100 files and each file could be from a separate folder
        $folder_transfer_status = get_site_option(constant("WPMDB_FOLDER_TRANSFER_" . strtoupper($stage) . "_OPTION") . $state_data['migration_state_id']);

        if (empty($folder_transfer_status)) {
            $folder_transfer_status = [];
        }

        $total_transferred      = 0;
        $batch_size             = 0;

        foreach ($data as $key => $record) {
            $is_chunked = isset($record['chunked']) && $record['chunked'];
            $dirname    = $record['folder_name'];
            $keys       = array_keys($result_set);

            // This method is called in WPMDBPro_Theme_Plugin_Files_Local::ajax_initiate_file_migration()
            // $bytes_transferred = 0 and we don't need to iterate over _all_ the files
            if (0 === $bytes_transferred && \in_array($dirname, $keys)) {
                continue;
            }

            if (0 !== $bytes_transferred) {
                if (!isset($folder_transfer_status[$dirname])) {
                    $batch_size = 0;

                    $folder_transfer_status[$dirname] = [
                        'folder_transferred'         => 0,
                        'folder_percent_transferred' => 0,
                    ];
                }

                $item_size = $record['size'];

                if ($is_chunked) {
                    $item_size = $record['chunk_size'];
                }

                $folder_transfer_status[$dirname]['folder_transferred'] += $item_size;

                if (!$is_chunked) {
                    $batch_size += $item_size;
                } else {
                    $batch_size = $item_size;
                }

                $transferred_percentage = $record['folder_size'] > 0 ? $folder_transfer_status[$dirname]['folder_transferred'] / $record['folder_size'] : 0;
                $folder_transfer_status[$dirname]['folder_percent_transferred'] = $transferred_percentage;
            }

            $result_set[$dirname] = [
                'nice_name'                  => $record['nice_name'],
                'relative_path'              => DIRECTORY_SEPARATOR . $dirname,
                'absolute_path'              => $record['folder_abs_path'],
                'item_size'                  => $record['size'],
                'size'                       => $record['folder_size'],
                'batch_size'                 => $batch_size,
                'folder_transferred'         => isset($folder_transfer_status[$dirname]['folder_transferred']) ? $folder_transfer_status[$dirname]['folder_transferred'] : 0,
                'folder_percent_transferred' => isset($folder_transfer_status[$dirname]['folder_percent_transferred']) ? $folder_transfer_status[$dirname]['folder_percent_transferred'] : 0,
                'total_transferred'          => $bytes_transferred,
            ];
        }

        //Updates folder status transient
        $this->update_folder_status($state_data, $result_set, $bytes_transferred);

        // Maybe compute folder percent transferred here?
        return $result_set;
    }

    /**
     * @param array $state_data
     * @param array $result_set
     * @param int   $bytes_transferred
     *
     * @return bool
     */
    public function update_folder_status($state_data, $result_set, $bytes_transferred)
    {
        if (0 === $bytes_transferred) {
            return false;
        }

        $folders_in_progress = [];

        foreach ($result_set as $key => $folder) {
            if ($folder['folder_transferred'] < $folder['size']) {
                $folders_in_progress[$key] = $folder;
            }
        }

        $stage = $state_data['stage'];
        if (empty($folders_in_progress) && 0 !== $bytes_transferred) {
            delete_site_option(constant("WPMDB_FOLDER_TRANSFER_" . strtoupper($stage) . "_OPTION") . $state_data['migration_state_id']);
        } else {
            update_site_option(constant("WPMDB_FOLDER_TRANSFER_" . strtoupper($stage) . "_OPTION") . $state_data['migration_state_id'], $folders_in_progress);
        }

        return true;
    }

    /**
     * Clean up any temporary file chunks.
     *
     * @param string $suffix
     *
     * @return void
     */
    public function cleanup_temp_chunks($suffix = 'tmpchunk')
    {
        $dir      = Chunker::get_chunk_base();
        $iterator = new DirectoryIterator($dir);

        foreach ($iterator as $fileInfo) {
            if ( ! $fileInfo->isDot()) {
                $name = $fileInfo->getFilename();

                if (preg_match("/(([a-z0-9]+-){5})$suffix/", $name) && $fileInfo->isFile()) {
                    $this->filesystem->unlink($dir . $name);
                }
            }
        }
    }

    /**
     * Extracts the theme folder name from a given path.
     *
     * @param  string $local_path
     * @param  string $temp_path
     * @param  array $manifest
     *
     * @return false|string
     */
    public function get_theme_folder_name($local_path, $temp_path, $manifest)
    {
        Debug::log(__FUNCTION__ . ': local_path "' . $local_path . '", temp_path  “' . $temp_path . '”.');

        $last = basename(str_replace('\\', '/', $local_path));

        if ($this->filesystem->file_exists($temp_path . $last)) {
            Debug::log(__FUNCTION__ . ': Returning "' . $last . '".');
            return $last;
        }

        foreach ($manifest as $key => $item) {
            $manifest_item = explode(DIRECTORY_SEPARATOR, $item);
            unset($manifest_item[count($manifest_item) - 1]);
            $imploded = implode(DIRECTORY_SEPARATOR, $manifest_item);

            if (stripos($item, 'style.css') !== false && stripos($local_path, $imploded) !== false) {
                $pieces = explode(DIRECTORY_SEPARATOR, $item);
                if (empty($pieces)) {
                    Debug::log(__FUNCTION__ . ': Failed to explode item:');
                    Debug::log($item);
                    return false;
                }

                $glued_pieces = isset($pieces[1]) ? $pieces[1] : '';

                // If the last piece is the style.css, discard it and glue the paths together.
                if (end($pieces) === 'style.css') {
                    reset($pieces);
                    array_pop($pieces);
                    $glued_pieces = implode(DIRECTORY_SEPARATOR, $pieces);
                }
                if ($this->filesystem->file_exists($temp_path . $glued_pieces)) {
                    Debug::log(__FUNCTION__ . ': Returning glued pieces "' . $glued_pieces . '".');
                    return $glued_pieces;
                }
            }
        }

        Debug::log(__FUNCTION__ . ': Failed to get name from manifest:');
        Debug::log($manifest);

        return false;
    }

    /**
     * Load the manifest file.
     *
     * @param string $stage
     * @param string $migration_id
     *
     * @return mixed|WP_Error
     */
    public function load_manifest($stage, $migration_id)
    {
        $filename      = static::get_queue_manifest_file_name($migration_id);
        $manifest_path = self::get_temp_dir($stage) . $filename;
        $contents      = file_get_contents($manifest_path);

        if ( ! $contents) {
            return new WP_Error('wpmdb_load_manifest_failed', __("Failed to load manifest file."));
        }

        $queue_info = json_decode($contents, true);

        if ( ! $queue_info) {
            return new WP_Error('wpmdb_parse_manifest_failed', __("Failed to parse manifest file."));
        }

        return $queue_info;
    }

    /**
     * Process data
     *
     * @param array $data
     *
     * @return array
     */
    public function process_file_data($data)
    {
        $result_set = [];

        if (!empty($data)) {
            foreach ($data as $size => $record) {
                $display_path                  = $record->file['subpath'];
                $record->file['relative_path'] = $display_path;

                $result_set[] = $record->file;
            }
        }

        return $result_set;
    }

    public static function get_wp_uploads_dir()
    {
        $upload_info = wp_get_upload_dir();

        return $upload_info['basedir'];
    }

    /**
     * @param $directory
     */
    public function remove_manifests($directory)
    {
        $iterator = new DirectoryIterator($directory);

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isDot()) {
                $name = $fileInfo->getFilename();

                if (preg_match("/(([a-z0-9]+-){5})manifest/", $name) && $fileInfo->isFile()) {
                    $this->filesystem->unlink($directory . DIRECTORY_SEPARATOR . $name);
                }
            }
        }
    }

    /**
     *
     * @return int
     */
    public function get_transfer_bottleneck()
    {
        $bottleneck = $this->util->get_max_upload_size();

        // Subtract 250 KB from min for overhead
        $bottleneck -= 250000;

        return $bottleneck;
    }

    /**
     * Enables the bottleneck-ed recursive file scanner.
     */
    public static function enable_scandir_bottleneck() {
        add_filter('wpmdb_bottleneck_dir_scan', function ($bottleneck) {
            return true;
        });
    }

    /**
     * @param string $base
     *
     * @return array
     */
    public function is_tmp_folder_writable( $base = 'theme_files' ) {
        $options_to_dirs = [
            'theme_files'    => 'themes',
            'plugin_files'   => 'plugins',
            'muplugin_files' => 'muplugins',
            'other_files'    => 'others'
        ];
        $tmp          = self::get_temp_dir($options_to_dirs[$base]);
        $test_file    = $tmp . '/test.php';
        $renamed_file = $tmp . '/test-2.php';

        $return = [
            'status' => true,
        ];

        if ( ! $this->filesystem->mkdir( $tmp ) ) {
            $message = sprintf( __( 'File transfer error - Unable to create a temporary folder. (%s)', 'wp-migrate-db' ), $tmp );
            $this->error_log->log_error( $message );

            return [
                'status'  => false,
                'message' => $message,
            ];
        }

        if ( method_exists('WpeCommon', 'get_wpe_auth_cookie_value') ) {
            return $return;
        }

        if ( ! $this->filesystem->touch( $test_file ) ) {
            $message = sprintf( __( 'File transfer error - Unable to create a PHP file on the server. (%s)', 'wp-migrate-db' ), $test_file );
            $this->error_log->log_error( $message );

            return [
                'status'  => false,
                'message' => $message,
            ];
        }

        if ( ! file_put_contents( $test_file, 'test' ) ) {
            $message = sprintf( __( 'File transfer error - Unable to update file contents using using PHP\'s file_put_contents() function. (%s)', 'wp-migrate-db' ), $test_file );
            $this->error_log->log_error( $message );

            return [
                'status'  => false,
                'message' => $message,
            ];
        }

        if ( ! rename( $test_file, $renamed_file ) ) {
            $message = sprintf( __( 'File transfer error - Unable to move file to the correct location using PHP\'s rename() function. (%s)', 'wp-migrate-db' ), $renamed_file );
            $this->error_log->log_error( $message );

            return [
                'status'  => false,
                'message' => $message,
            ];
        }

        //Clean up
        if ( ! $this->remove_tmp_folder( $options_to_dirs[$base] ) ) {
            $message = sprintf( __( 'File transfer error - Unable to delete file using PHP\'s unlink() function. (%s)', 'wp-migrate-db' ), $renamed_file );
            $this->error_log->log_error( $message );

            return [
                'status'  => false,
                'message' => $message,
            ];
        }

        return $return;
    }


    /**
     * Where to store files as they're being transferred
     *
     * @param  string $stage
     * @return bool|mixed|void
     */
    public static function get_temp_dir($stage)
    {
        $tmp_dir = self::TMP_FOLDER_PREFIX;

        $is_source = Common_Util::is_source();
        if ($is_source) {
            $tmp_dir .= '-' . $stage;
        }

        $migration_id = StateFactory::create('current_migration')->load_state(null)->get('migration_id');
        if (!empty($migration_id)) {
            $tmp_dir .= sprintf('-%s', md5($migration_id));
        }

        $temp_base = $is_source ? Filesystem::get_upload_info() : Common_Util::get_stage_base_dir($stage);
        $temp_dir  =  $temp_base . DIRECTORY_SEPARATOR . $tmp_dir . DIRECTORY_SEPARATOR;

        return apply_filters('wpmdb_transfers_temp_dir', $temp_dir);
    }

    /**
     * Removes stage temp folders.
     *
     * @param  string $stage
     * @return void
     */
    public function cleanup_existing_temp_folders($stage)
    {
        $stage_base_dir = Common_Util::get_stage_base_dir($stage);

        foreach (glob($stage_base_dir . DIRECTORY_SEPARATOR . self::TMP_FOLDER_PREFIX . '*') as $temp_folder) {
            $this->filesystem->rmdir($temp_folder, true);
        }
    }

    /**
     * Sanitizes a provided file path.
     * If the filename includes a path, it will get split and only the last part will be sanitized.
     *
     * @param string $file_path
     *
     * @return string
     */
    public static function sanitize_file_path($file_path) {
        //split path
        $split = explode(DIRECTORY_SEPARATOR, $file_path);

        //sanitize last part
        $file_path = array_pop($split);
        $split[]   = sanitize_file_name($file_path);
        return implode(DIRECTORY_SEPARATOR, $split);
    }

    /**
     * Exclude plugins from the plugins list.
     *
     * @param array $plugins
     * @param bool  $exclude_mdb When set to true, WP Migrate lite/pro will be excluded
     *
     * @return array
     */
    public static function filter_excluded_plugins($plugins, $exclude_mdb = true)
    {
        $excluded_plugins = true === $exclude_mdb ? ['wp-migrate-db'] : [];
        $excluded_plugins = apply_filters('wpmdb_excluded_plugins', $excluded_plugins);

        $filtered_plugins = [];

        foreach ($plugins as $key => $plugin) {
            if (Common_Util::array_search_string_begin_with($key, $excluded_plugins)) {
                continue;
            }
            $filtered_plugins[$key] = $plugin;
        }

        return $filtered_plugins;
    }

    /**
     * Generates a .htaccess file in the given path with a deny all rule.
     *
     * @param string $file_path
     *
     * @return bool
     * @throws Exception
     */
    public static function prevent_direct_access_with_htaccess($file_path)
    {
        if (!is_dir($file_path)) {
            return false;
        }

        //Create .htaccess file
        $htaccess_file = fopen($file_path . DIRECTORY_SEPARATOR . '.htaccess', 'w+');

        if (empty($htaccess_file)) {
            error_log(sprintf("WPMDB: Unable to open .htaccess file for writing in %s", $file_path));
            return false;
        }

        if (false === fwrite($htaccess_file, "Satisfy all\nOrder deny,allow\nDeny from all")) {
            error_log(sprintf("WPMDB: Unable to write to .htaccess file in %s", $file_path));
            return false;
        }

        fclose($htaccess_file);

        return true;
    }

    /**
     * Checks if a directory is empty
     *
     * @param string $dir
     * @param array  $excludes
     *
     * @return bool
     */
    public static function is_empty_dir($dir, $excludes = [])
    {
        if ( ! is_dir($dir)) {
            return false;
        }

        //Always exclude .DS_Store
        $excludes[] = '.DS_Store';

        //Always exclude WPMDB tmp directories
        $excludes[]   = self::TMP_FOLDER_PREFIX . '*';
        $path         = realpath($dir);
        $dir_iterator = new RecursiveDirectoryIterator($path);

        //Ignore [., ..]
        $dir_iterator->setFlags(FilesystemIterator::SKIP_DOTS);

        $files = new RecursiveIteratorIterator(
        // Filter iterator results
            new RecursiveCallbackFilterIterator($dir_iterator, function ($file, $key, $iterator) use ($excludes) {
                // If folder, allow recursion to look for files
                if ($file->isReadable() && $iterator->hasChildren()) {
                    return true;
                }

                // Make sure files are not excluded files
                return $file->isReadable() && $file->isFile() && ! Excludes::shouldExcludeFile($file->getPathname(), $excludes);
            })
        );

        return iterator_count($files) === 0;
    }

    /**
     * Creates a temporary file resource handle.
     *
     * @return resource|null
     */
    public static function tmpfile()
    {
        if (function_exists('tmpfile')) {
            $tmp_file = tmpfile();
        }

        return apply_filters('wpmdb_transfers_stream_handle', $tmp_file);
    }
}
