<?php
/*
Plugin Name: Refugee Connect receipt Sender
Plugin URI:
Description: Basic WordPress Plugin Header Comment
Version:     2017040301
Author:      Timothy White
Author URI:  https://whiteitsolutions.com.au
License:     GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

require_once(dirname(__FILE__) . '/vendor/autoload.php');

if (!class_exists('RefugeeConnect_receipts')) {
    class RefugeeConnect_receipts
    {

        public function init()
        {
            register_activation_hook(__FILE__, [$this, 'pluginprefix_function_to_run']);
            register_deactivation_hook(__FILE__, [$this, 'pluginprefix_function_to_run']);
            add_action('admin_menu', [$this, 'plugin_menu']);
            // Register plugin settings
            add_action('admin_init', array($this, 'register_settings'));
        }

        function pluginprefix_install()
        {
            // trigger our function that registers the custom post type
            //pluginprefix_setup_post_type();

            // clear the permalinks after the post type has been registered
            //flush_rewrite_rules();
        }

        function pluginprefix_deactivation()
        {
            // our post type will be automatically removed, so no need to unregister it

            // clear the permalinks to remove our post type's rules
            //flush_rewrite_rules();
        }

        public function register_settings()
        {
            register_setting('refugeeconnect_receipt_group', 'refugeeconnect_receipt_settings');
            add_settings_section(
                'settingssection1',
                'Intuit App Settings',
                array($this, 'settings_section_callback'),
                'refugeeconnect_receipt_settings'
            );
            // you can define EVERYTHING to create, display, and process each settings field as one line per setting below.  And all settings defined in this function are stored as a single serialized object.
            add_settings_field(
                'intuit_app_token',
                'Intuit App Token',
                array($this, 'settings_field'),
                'refugeeconnect_receipt_settings',
                'settingssection1',
                array(
                    'setting' => 'refugeeconnect_receipt_settings',
                    'field' => 'intuit_app_token',
                    'label' => '',
                    'class' => 'regular-text'
                )
            );
            add_settings_field(
                'intuit_app_oauth_key',
                'Intuit Oauth Consumer Key',
                array($this, 'settings_field'),
                'refugeeconnect_receipt_settings',
                'settingssection1',
                array(
                    'setting' => 'refugeeconnect_receipt_settings',
                    'field' => 'intuit_app_oauth_key',
                    'label' => '',
                    'class' => 'regular-text'
                )
            );
            add_settings_field(
                'intuit_app_oauth_secret',
                'Intuit Oauth Consumer Secret',
                array($this, 'settings_field'),
                'refugeeconnect_receipt_settings',
                'settingssection1',
                array(
                    'setting' => 'refugeeconnect_receipt_settings',
                    'field' => 'intuit_app_oauth_secret',
                    'label' => '',
                    'class' => 'regular-text'
                )
            );
            add_settings_field(
                'intuit_app_redirect_uri',
                'Intuit App Redirect URI',
                array($this, 'settings_field'),
                'refugeeconnect_receipt_settings',
                'settingssection1',
                array(
                    'setting' => 'refugeeconnect_receipt_settings',
                    'field' => 'intuit_app_redirect_uri',
                    'label' => '',
                    'class' => 'regular-text'
                )
            );
        }

        public function settings_section_callback()
        {
            echo ' ';
        }

        public function settings_field($args)
        {
            // This is the default processor that will handle standard text input fields.  Because it accepts a class, it can be styled or even have jQuery things (like a calendar picker) integrated in it.  Pass in a 'default' argument only if you want a non-empty default value.
            $settingname = esc_attr($args['setting']);
            $setting = get_option($settingname);
            $field = esc_attr($args['field']);
            $label = esc_attr($args['label']);
            $class = esc_attr($args['class']);
            $default = ($args['default'] ? esc_attr($args['default']) : '');
            $value = (($setting[$field] && strlen(trim($setting[$field]))) ? $setting[$field] : $default);
            echo '<input type="text" name="' . $settingname . '[' . $field . ']" id="' . $settingname . '[' . $field . ']" class="' . $class . '" value="' . $value . '" /><p class="description">' . $label . '</p>';
        }

        function plugin_menu()
        {
            add_options_page(
                'Receipt Options',
                'Refugee Connect receipts',
                'manage_options',
                'refugee-connect-receipts-admin',
                [$this, 'plugin_options']
            );
            add_options_page(
                'Receipt Options',
                'Refugee Connect receipts Connect Intuit',
                'manage_options',
                'refugee-connect-connect-intunit',
                [$this, 'connect_intuit']
            );
        }

        function connect_intuit()
        {
            //TODO record timestamp of oauth token as it expires in 180 days, and ensure we do a reconnection attempt when required
            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have sufficient permissions to access this page.'));
            }

            $options = get_option('refugeeconnect_receipt_settings', []);
            $server = new Wheniwork\OAuth1\Client\Server\Intuit(
                array(
                    'identifier' => $options['intuit_app_oauth_key'],
                    'secret' => $options['intuit_app_oauth_secret'],
                    'callback_uri' => (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]",
                )
            );

            if (isset($_GET['oauth_token']) && isset($_GET['oauth_verifier'])) {
                // Retrieve the temporary credentials we saved before
                $temporaryCredentials = get_option('refugeeconnect_receipt_temp_cred', '');

                // We will now retrieve token credentials from the server
                $tokenCredentials = $server->getTokenCredentials(
                    $temporaryCredentials,
                    $_GET['oauth_token'],
                    $_GET['oauth_verifier']
                );

                update_option('refugeeconnect_receipt_authed_creds', $tokenCredentials);

                ?>

                <div class="wrap">
                    <h3>Intuit Connection Established</h3>
                </div>
                <?php

            } else {

                // Retrieve temporary credentials
                $temporaryCredentials = $server->getTemporaryCredentials();
                update_option('refugeeconnect_receipt_temp_cred', $temporaryCredentials);

                $url = $server->getAuthorizationUrl($temporaryCredentials);

                ?>
                <div class="wrap">
                    <h2>Refugee Connect Receipt Connect to Intuit</h2>
                    <p>Click <a href="<?= $url ?>">Here</a> to connect to Intuit.</p>
                </div>
                <?php
            }

            $tokenCredentials = get_option('refugeeconnect_receipt_authed_creds', '');
            if ($tokenCredentials) {
                // User is an instance of League\OAuth1\Client\Server\User
                $user = $server->getUserDetails($tokenCredentials);

                // Email is either a string or null (as some providers do not supply this data)
                $email = $server->getUserEmail($tokenCredentials);

                ?>
                <div class="wrap">
                    <h2>Refugee Connect Receipt Connected to Intuit</h2>
                    <?= serialize($user) ?><br/>
                    <?= $email ?><br/>
                </div>
                <?php
            }

        }


        function plugin_options()
        {
            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have sufficient permissions to access this page.'));
            }
            var_dump(get_option('refugeeconnect_receipt_temp_cred', ''));

            ?>
            <div class="wrap">
                <h2>Refugee Connect Receipt Settings</h2>
                <p>You'll need to go to the <a href="https://developer.intuit.com/v2/ui#/app/dashboard">Intuit
                        Developers Console</a> to setup your project and setup the values below.</p>
                <form action="options.php" method="POST">
                    <?php settings_fields('refugeeconnect_receipt_group'); ?>
                    <?php do_settings_sections('refugeeconnect_receipt_settings'); ?>
                    <?php submit_button(); ?>
                </form>
            </div>
            <?php
        }


    }

    $RefugeeConnect_receipts_Plugin = new RefugeeConnect_receipts();
    $RefugeeConnect_receipts_Plugin->init();
}
