<?php

namespace DeliciousBrains\WPMDB\Pro\Migration\Connection;

use DeliciousBrains\WPMDB\Common\Debug;
use DeliciousBrains\WPMDB\Common\Http\Helper;
use DeliciousBrains\WPMDB\Common\Http\Http;
use DeliciousBrains\WPMDB\Common\Http\RemotePost;
use DeliciousBrains\WPMDB\Common\Http\WPMDBRestAPIServer;
use DeliciousBrains\WPMDB\Common\MigrationPersistence\Persistence;
use DeliciousBrains\WPMDB\Common\Properties\DynamicProperties;
use DeliciousBrains\WPMDB\Common\Properties\Properties;
use DeliciousBrains\WPMDB\Common\Util\Util;
use WP_Error;

class Local
{

    /**
     * @var Http
     */
    private $http;
    /**
     * @var Helper
     */
    private $http_helper;
    /**
     * @var Properties
     */
    private $props;
    /**
     * @var License
     */
    private $license;
    /**
     * @var RemotePost
     */
    private $remote_post;
    /**
     * @var Util
     */
    private $util;
    /**
     * @var DynamicProperties
     */
    private $dynamic_props;
    /**
     * @var WPMDBRestAPIServer
     */
    private $rest_API_server;

    public function __construct(
        Http $http,
        Helper $http_helper,
        Properties $props,
        RemotePost $remote_post,
        Util $util,
        WPMDBRestAPIServer $rest_API_server,
        $license
    ) {
        $this->http            = $http;
        $this->http_helper     = $http_helper;
        $this->props           = $props;
        $this->remote_post     = $remote_post;
        $this->util            = $util;
        $this->dynamic_props   = DynamicProperties::getInstance();
        $this->rest_API_server = $rest_API_server;
        $this->license         = $license;
    }

    public function register()
    {
        // @TODO probably need to force flush rewrite rules
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    public function register_rest_routes()
    {
        $this->rest_API_server->registerRestRoute('/verify-connection', [
            'methods'  => 'POST',
            'callback' => [$this, 'ajax_verify_connection_to_remote_site'],
        ]);
    }

    /**
     * WP REST API endpoint call to `/verify-connection`
     * Verifies that the local site has a valid licence.
     *
     * @return void
     */
    public function ajax_verify_connection_to_remote_site()
    {
        $_POST = $this->http_helper->convert_json_body_to_post();

        Persistence::cleanupStateOptions(); // Wipe old migration options

        $this->http->end_ajax($this->verify_connection_to_remote_site($_POST));
    }

    /**
     * Verifies that the local site has a valid licence.
     *
     * Sends a request to the remote site to collect additional information required to complete the migration.
     *
     * @param array|false $state_data
     *
     * @return array|WP_Error
     */
    public function verify_connection_to_remote_site($state_data = false)
    {
        $key_rules = apply_filters(
            'wpmdb_verify_connection_key_rules',
            array(
                'action'                      => 'key',
                'url'                         => 'url',
                'key'                         => 'string',
                'intent'                      => 'key',
                'nonce'                       => 'key',
                'convert_post_type_selection' => 'numeric',
                'profile'                     => 'numeric',
            ),
            __FUNCTION__
        );

        $state_data = Persistence::setPostData($key_rules, __METHOD__, WPMDB_MIGRATION_STATE_OPTION, $state_data);

        if (is_wp_error($state_data)) {
            return $state_data;
        }

        if ( ! $this->license->is_valid_licence()) {
            $message = __('Please activate your license before attempting a pull or push migration.', 'wp-migrate-db');

            return new WP_Error('invalid-license', $message);
        }

        do_action('wpmdb_before_verify_connection_to_remote_site', $state_data);

        $data = array(
            'action'  => 'wpmdb_verify_connection_to_remote_site',
            'intent'  => $state_data['intent'],
            'referer' => $this->util->get_short_home_address_from_url(Util::home_url()),
            'version' => $this->props->plugin_version,
        );

        $data = apply_filters('wpmdb_verify_connection_to_remote_site_args', $data, $state_data);

        $data['sig'] = $this->http_helper->create_signature($data, $state_data['key']);
        $ajax_url    = $this->util->ajax_url();

        list($response, $url_used) = $this->try_verify_connection_to_remote_site(
            $ajax_url,
            $data,
            __FUNCTION__
        );

        if (is_wp_error($response)) {
            return $response;
        }

        /**
         * Filter the response to the verify connection to remote site call.
         *
         * @param array $response from the remote
         * @param array $data     that was sent
         *
         * @returns array|WP_Error
         */
        $response = apply_filters('wpmdb_handle_verify_connection_to_remote_site_response', $response, $data);

        if (is_wp_error($response)) {
            return $response;
        }

        if (isset($response['site_details']['wpe_cookie'])) {
            Persistence::storeRemoteWPECookie($response['site_details']['wpe_cookie']);
        }

        $url_bits = Util::parse_url($this->dynamic_props->attempting_to_connect_to);

        $data['scheme'] = $url_bits['scheme'];
        $data           += $response;

        // Store response in DB
        Persistence::storeRemoteResponse($data);

        // If an alternate URL was used, make sure caller knows.
        // This is intentionally added after the remote's response has been saved.
        if ($url_used !== $ajax_url) {
            $data['url_used'] = untrailingslashit(substr_replace($url_used, '', -strlen('/wp-admin/admin-ajax.php')));
        }

        return $data;
    }

    /**
     * Try to connect to remote site to verify, maybe retry if connection not successful.
     *
     * @param string $ajax_url
     * @param array  $data
     * @param string $scope
     * @param bool   $is_retry
     *
     * @return array Containing response and URL
     */
    private function try_verify_connection_to_remote_site($ajax_url, $data, $scope, $is_retry = false)
    {
        $timeout         = apply_filters('wpmdb_prepare_remote_connection_timeout', 30);
        $remote_response = $this->remote_post->post($ajax_url, $data, $scope, compact('timeout'), true);

        // WP_Error is thrown manually by remote_post() to tell us something went wrong
        if (is_wp_error($remote_response)) {
            return [$remote_response, $ajax_url];
        }

        $response = false;
        if (Util::is_json($remote_response)) {
            $response = json_decode($remote_response, true);
        }

        if ( ! $response && ! $is_retry) {
            $alt_url = apply_filters('wpmdb_get_alternate_connection_url', false, $ajax_url);

            if ( ! empty($alt_url) && $alt_url !== $ajax_url) {
                Debug::log(__FUNCTION__ . ': Trying alternative URL "' . $alt_url . '".');

                return $this->try_verify_connection_to_remote_site($alt_url, $data, $scope, true);
            }
        }

        if ( ! $response) {
            return [
                new WP_Error(
                    'json-decode-failure',
                    __(
                        'Failed attempting to decode the response from the remote server. Please contact support.',
                        'wp-migrate-db'
                    ),
                    $remote_response
                ),
                $ajax_url,
            ];
        }

        return [$response, $ajax_url];
    }
}
