<?php

namespace DeliciousBrains\WPMDB\Pro\Migration\Tables;

use DeliciousBrains\WPMDB\Common\BackupExport;
use DeliciousBrains\WPMDB\Common\FormData\FormData;
use DeliciousBrains\WPMDB\Common\Http\Helper;
use DeliciousBrains\WPMDB\Common\Http\Http;
use DeliciousBrains\WPMDB\Common\Http\Scramble;
use DeliciousBrains\WPMDB\Common\Migration\MigrationHelper;
use DeliciousBrains\WPMDB\Common\Migration\MigrationManager;
use DeliciousBrains\WPMDB\Common\MigrationPersistence\Persistence;
use DeliciousBrains\WPMDB\Common\Properties\DynamicProperties;
use DeliciousBrains\WPMDB\Common\Properties\Properties;
use DeliciousBrains\WPMDB\Common\Settings\Settings;
use DeliciousBrains\WPMDB\Common\Sql\Table;
use DeliciousBrains\WPMDB\Common\Sql\TableHelper;
use Exception;
use WP_Error;

class Remote
{

    /**
     * @var Scramble
     */
    private $scrambler;
    /**
     * @var Settings
     */
    private $settings;
    /**
     * @var Http
     */
    private $http;
    /**
     * @var Helper
     */
    private $http_helper;
    /**
     * @var TableHelper
     */
    private $table_helper;
    /**
     * @var Properties
     */
    private $props;
    /**
     * @var FormData
     */
    private $form_data;
    /**
     * @var MigrationManager
     */
    private $migration_manager;
    /**
     * @var Table
     */
    private $table;
    /**
     * @var BackupExport
     */
    private $backup_export;
    /**
     * @var DynamicProperties
     */
    private $dynamic_props;

    public function __construct(
        Scramble $scrambler,
        Settings $settings,
        Http $http,
        Helper $http_helper,
        TableHelper $table_helper,
        Properties $props,
        FormData $form_data,
        MigrationManager $migration_manager,
        Table $table,
        BackupExport $backup_export
    ) {
        $this->scrambler         = $scrambler;
        $this->settings          = $settings->get_settings();
        $this->http              = $http;
        $this->http_helper       = $http_helper;
        $this->table_helper      = $table_helper;
        $this->props             = $props;
        $this->form_data         = $form_data;
        $this->migration_manager = $migration_manager;
        $this->table             = $table;
        $this->backup_export     = $backup_export;
        $this->dynamic_props     = DynamicProperties::getInstance();
    }

    public function register()
    {
        add_action('wp_ajax_nopriv_wpmdb_process_pull_request', array($this, 'respond_to_process_pull_request'));
        add_action('wp_ajax_nopriv_wpmdb_process_chunk', array($this, 'respond_to_process_chunk'));
        add_action('wp_ajax_nopriv_wpmdb_backup_remote_table', array($this, 'respond_to_backup_remote_table'));
        add_action(
            'wp_ajax_nopriv_wpmdb_process_push_migration_cancellation',
            array($this, 'respond_to_process_push_migration_cancellation')
        );
    }

    /**
     * Exports table data from remote site during a Pull migration.
     *
     * @return void
     */
    function respond_to_process_pull_request()
    {
        MigrationHelper::set_is_remote();
        add_filter('wpmdb_before_response', array($this->scrambler, 'scramble'));

        $key_rules = array(
            'action'              => 'key',
            'migration_id'        => 'key',
            'remote_state_id'     => 'key', // TODO: Remove as redundant?
            'intent'              => 'key',
            'url'                 => 'url',
            'table'               => 'string',
            'form_data'           => 'string',
            'stage'               => 'key',
            'bottleneck'          => 'positive_int',
            'current_row'         => 'int',
            'last_table'          => 'positive_int',
            'gzip'                => 'positive_int',
            'primary_keys'        => 'string',
            'site_url'            => 'url',
            'site_details'        => 'string',
            'find_replace_pairs'  => 'string',
            'pull_limit'          => 'positive_int',
            'db_version'          => 'string',
            'path_current_site'   => 'string',
            'domain_current_site' => 'text',
            'prefix'              => 'string',
            'sig'                 => 'string',
            'source_prefix'       => 'string',
            'destination_prefix'  => 'string',
        );

        $state_data = Persistence::setRemotePostData($key_rules, __METHOD__);

        if (is_wp_error($state_data)) {
            $this->http->end_ajax($state_data);

            return;
        }

        $state_data['find_replace_pairs'] = json_decode(base64_decode($state_data['find_replace_pairs']), true);
        $state_data['form_data']          = base64_decode($state_data['form_data']);
        $state_data['site_details']       = json_decode(base64_decode($state_data['site_details']), true);
        $state_data['primary_keys']       = base64_decode($state_data['primary_keys']);
        $state_data['source_prefix']      = base64_decode($state_data['source_prefix']);
        $state_data['destination_prefix'] = base64_decode($state_data['destination_prefix']);

        $this->form_data->parse_and_save_migration_form_data($state_data['form_data']);

        // Save decoded state_data
        Persistence::saveStateData($state_data, WPMDB_REMOTE_MIGRATION_STATE_OPTION);

        $filtered_post = $this->http_helper->filter_post_elements(
            $state_data,
            array(
                'action',
                'migration_id',
                'remote_state_id', // TODO: Remove as redundant?
                'intent',
                'url',
                'table',
                'form_data',
                'stage',
                'bottleneck',
                'current_row',
                'last_table',
                'gzip',
                'primary_keys',
                'site_url',
                'find_replace_pairs',
                'pull_limit',
                'db_version',
                'path_current_site',
                'domain_current_site',
                'prefix',
                'source_prefix',
                'destination_prefix',
            )
        );

        $sig_data = $filtered_post;
        // find_replace_pairs and form_data weren't used to create the migration signature
        unset ($sig_data['find_replace_pairs'], $sig_data['form_data'], $sig_data['source_prefix'], $sig_data['destination_prefix']);

        if ( ! $this->http_helper->verify_signature($sig_data, $this->settings['key'])) {
            $error_msg = $this->props->invalid_content_verification_error . ' (#124)';

            $this->http->end_ajax(
                new WP_Error(
                    'wpmdb-invalid-content-verification',
                    $error_msg
                )
            );

            return;
        }

        MigrationHelper::set_current_migration_id($filtered_post['migration_id']);

        if (empty($this->settings['allow_pull'])) {
            $message = __(
                'The connection succeeded but the remote site is configured to reject pull connections. You can change this in the "settings" tab on the remote site. (#141)',
                'wp-migrate-db'
            );

            $this->http->end_ajax(new WP_Error(
                'wpmdb-allow-pull-disabled',
                $message
            ));

            return;
        }

        if ( ! empty($filtered_post['db_version'])) {
            $this->dynamic_props->target_db_version = $filtered_post['db_version'];
            add_filter('wpmdb_create_table_query', array($this->table_helper, 'mysql_compat_filter'), 10, 5);
        }

        $this->dynamic_props->find_replace_pairs = $filtered_post['find_replace_pairs'];

        // @TODO move to better place
        $this->dynamic_props->maximum_chunk_size = $state_data['pull_limit'];

        $result = $this->table->process_table($state_data['table'], null, $state_data);

        if (is_wp_error($result)) {
            $this->http->end_ajax($result);

            return;
        }

        $this->http->end_ajax($result, '', true);
    }

    /**
     * Handler for the ajax request to process a chunk of data (e.g. SQL inserts).
     *
     * @return void
     */
    public function respond_to_process_chunk()
    {
        MigrationHelper::set_is_remote();
        add_filter('wpmdb_before_response', array($this->scrambler, 'scramble'));

        $key_rules = array(
            'action'        => 'key',
            'migration_id'  => 'string',
            'table'         => 'string',
            'chunk_gzipped' => 'positive_int',
            'sig'           => 'string',
        );

        try {
            $state_data = Persistence::setPostData($key_rules, __METHOD__);
        } catch (Exception $e) {
            $this->http->end_ajax(new WP_Error($e->getCode(), $e->getMessage()));

            return;
        }

        $filtered_post = $this->http_helper->filter_post_elements($state_data, array(
                'action',
                'migration_id',
                'remote_state_id', // TODO: Remove as redundant?
                'table',
                'chunk_gzipped',
            )
        );

        $gzip = (isset($state_data['chunk_gzipped']) && $state_data['chunk_gzipped']);

        $tmp_file_name = 'chunk.txt';

        if ($gzip) {
            $tmp_file_name .= '.gz';
        }

        $tmp_file_path = wp_tempnam($tmp_file_name);

        if (
            ! isset($_FILES['chunk']['tmp_name']) ||
            ! move_uploaded_file($_FILES['chunk']['tmp_name'], $tmp_file_path)
        ) {
            $this->http->end_ajax(new WP_Error(
                'wpmdb_could_not_upload_sql',
                __('Could not upload the SQL to the server. (#135)', 'wp-migrate-db')
            ));

            return;
        }

        if (false === ($chunk = file_get_contents($tmp_file_path))) {
            $this->http->end_ajax(new WP_Error(
                'wpmdb_could_not_read_sql',
                __('Could not read the SQL file we uploaded to the server. (#136)', 'wp-migrate-db')
            ));

            return;
        }

        // TODO: Use WP_Filesystem API.
        @unlink($tmp_file_path);

        $filtered_post['chunk'] = $chunk;

        if ( ! $this->http_helper->verify_signature($filtered_post, $this->settings['key'])) {
            $error_msg = $this->props->invalid_content_verification_error . ' (#130)';
            $this->http->end_ajax(new WP_Error('wpmdb_invalid_content_verification_error', $error_msg));

            return;
        }

        MigrationHelper::set_current_migration_id($filtered_post['migration_id']);

        if (empty($this->settings['allow_push'])) {
            $this->http->end_ajax(new WP_Error(
                'wpmdb_reject_push',
                __(
                    'The connection succeeded but the remote site is configured to reject push connections. You can change this in the "settings" tab on the remote site. (#139)',
                    'wp-migrate-db'
                )
            ));

            return;
        }

        if ($gzip) {
            $filtered_post['chunk'] = gzuncompress($filtered_post['chunk']);
        }

        $process_chunk_result = $this->table->process_chunk($filtered_post['chunk']);

        $this->http->end_ajax($process_chunk_result);
    }

    /**
     * The remote's handler for requests to back up a table.
     *
     * @return void
     */
    public function respond_to_backup_remote_table()
    {
        MigrationHelper::set_is_remote();
        add_filter('wpmdb_before_response', array($this->scrambler, 'scramble'));

        $key_rules = array(
            'action'              => 'key',
            'intent'              => 'key',
            'migration_id'        => 'string',
            'url'                 => 'url',
            'table'               => 'string',
            'form_data'           => 'string',
            'stage'               => 'key',
            'prefix'              => 'string',
            'current_row'         => 'string',
            'last_table'          => 'string',
            'gzip'                => 'string',
            'primary_keys'        => 'string',
            'path_current_site'   => 'string',
            'domain_current_site' => 'text',
            'sig'                 => 'string',
        );

        $state_data = Persistence::setRemotePostData($key_rules, __METHOD__);

        $filtered_post = $this->http_helper->filter_post_elements(
            $state_data,
            array_keys($key_rules)
        );

        $filtered_post['primary_keys'] = base64_decode($filtered_post['primary_keys']);

        if ( ! $this->http_helper->verify_signature($filtered_post, $this->settings['key'])) {
            $error_msg = $this->props->invalid_content_verification_error . ' (#137)';

            $this->http->end_ajax(new WP_Error('wpmdb-respond-to-remote-backup-error', $error_msg));

            return;
        }

        MigrationHelper::set_current_migration_id($state_data['migration_id']);

        $this->http->end_ajax(
            $this->migration_manager->handle_table_backup(WPMDB_REMOTE_MIGRATION_STATE_OPTION)
        );
    }

    /**
     * Handler for a request to the remote to cancel a migration.
     *
     * @return void
     */
    function respond_to_process_push_migration_cancellation()
    {
        MigrationHelper::set_is_remote();
        add_filter('wpmdb_before_response', array($this->scrambler, 'scramble'));

        $key_rules = array(
            'action'          => 'key',
            'remote_state_id' => 'key', // TODO: Remove as redundant?
            'intent'          => 'key',
            'migration_id'    => 'key',
            'url'             => 'url',
            'form_data'       => 'string',
            'temp_prefix'     => 'string',
            'stage'           => 'key',
            'sig'             => 'string',
        );

        $state_data = Persistence::setRemotePostData($key_rules, __METHOD__);

        $filtered_post = $this->http_helper->filter_post_elements(
            $state_data,
            array(
                'action',
                'intent',
                'migration_id',
                'url',
                'form_data',
                'temp_prefix',
                'stage',
            )
        );

        if ( ! $this->http_helper->verify_signature($filtered_post, $this->settings['key'])) {
            $this->http->end_ajax(new WP_Error(
                'wpmdb_invalid_content_verification_error',
                $this->props->invalid_content_verification_error
            ));

            return;
        }

        MigrationHelper::set_current_migration_id($filtered_post['migration_id']);

        // ***+=== @TODO - revisit usage of parse_migration_form_data
        $this->form_data = $this->form_data->parse_and_save_migration_form_data(base64_decode($filtered_post['form_data']));

        if ($filtered_post['stage'] == 'backup' && ! empty($state_data['dumpfile_created'])) {
            $this->backup_export->delete_export_file($state_data['dump_filename'], true);
        } else {
            $this->table->delete_temporary_tables($filtered_post['temp_prefix']);
        }

        do_action('wpmdb_respond_to_push_cancellation');

        $this->http->end_ajax(true);
    }
}
