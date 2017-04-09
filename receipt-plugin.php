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
require_once(dirname(__FILE__) . '/receipt-template.php');

if (!class_exists('RefugeeConnect_receipts')) {
    class RefugeeConnect_receipts
    {
        /**
         * @var \Wheniwork\OAuth1\Client\Server\Intuit
         */
        private $intuitOauthServer;
        private $baseUrl;
        private $db_version = 0.2;
        private $receipt_table_name;
        private $customer_table_name;
        /**
         * @var wpdb
         */
        private $wpdb;

        public function init()
        {
            global $wpdb;
            register_activation_hook(__FILE__, [$this, 'pluginprefix_function_to_run']);
            register_deactivation_hook(__FILE__, [$this, 'deactivation']);
            add_action('admin_menu', [$this, 'plugin_menu']);
            // Register plugin settings
            add_action('admin_init', [$this, 'register_settings']);
            add_action('admin_init', [$this, 'setupOAuth']);

            add_action('plugins_loaded', [$this, 'database_setup']);
            add_action('plugins_loaded', [$this, 'plugin_setup']);

            add_action('refugeeconnect_receipt_cron_hook', [$this, 'cron_exec']);

            add_action('admin_init', [$this, 'download_pdf']);

            // Setup template for Requests
            $template = \Httpful\Request::init()
                ->expectsJson();
            \Httpful\Request::ini($template);

            $this->wpdb = $wpdb;
        }

        public function plugin_setup()
        {
            if (!wp_next_scheduled('refugeeconnect_receipt_cron_hook')) {
                wp_schedule_event(time(), 'hourly', 'refugeeconnect_receipt_cron_hook');
            }
        }

        public function cron_exec()
        {
            $this->syncSalesReceipts();
        }

        public function database_setup()
        {
            global $wpdb;

            $installed_ver = get_option("refugeeconnectreceiptplugin_db_version");

            $this->receipt_table_name = $wpdb->prefix . "intuit_receipts";
            $this->customer_table_name = $wpdb->prefix . "intuit_customers";


            if ($installed_ver != $this->db_version) {
                require_once(ABSPATH . 'wp-admin/includes/upgrade.php');


                $charset_collate = $wpdb->get_charset_collate();

                $sql = [
                    "CREATE TABLE {$this->receipt_table_name} (
                  id mediumint(9) NOT NULL,
                  SyncToken int(9) NOT NULL,
                  CustomerID int(9) NOT NULL,
                  TxnDate date NOT NULL,
                  ExternalReceipt text NOT NULL,
                  Object text NOT NULL,
                  PRIMARY KEY  (id)
                  ) $charset_collate;",
                    "CREATE TABLE {$this->customer_table_name} (
                  id mediumint(9) NOT NULL,
                  CustomerName text NOT NULL,
                  PrimaryEmailAddress text,
                  Object text NOT NULL,
                  PRIMARY KEY  (id)
                  ) $charset_collate;
                  
                  "
                ];

                dbDelta($sql);

                update_option("refugeeconnectreceiptplugin_db_version", $this->db_version);
            }
        }

        public function setupOAuth()
        {
            $options = get_option('refugeeconnect_receipt_settings', []);
            $this->intuitOauthServer = new Wheniwork\OAuth1\Client\Server\Intuit(
                [
                    'identifier' => $options['intuit_app_oauth_key'],
                    'secret' => $options['intuit_app_oauth_secret'],
                    'callback_uri' => (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]",
                ]
            );

            $this->sandbox = $options['intuit_app_sandbox'] = "on" ? true : false;

            $this->baseUrl = 'https://quickbooks.api.intuit.com';
            if ($this->sandbox) {
                $this->baseUrl = 'https://sandbox-quickbooks.api.intuit.com';
            }
            $realmId = get_option('refugeeconnect_receipt_authed_realm');
            $this->baseUrl .= "/v3/company/$realmId/";
        }

        private function getAuthHeader($method, $uri)
        {
            $tokenCredentials = get_option('refugeeconnect_receipt_authed_creds', '');
            if ($tokenCredentials) {

                return $this->intuitOauthServer->getHeaders($tokenCredentials, $method, $uri);
            }
            return [];
        }

        function activation()
        {
            $this->database_setup();
            $this->plugin_setup();
        }

        function deactivation()
        {
            $timestamp = wp_next_scheduled('refugeeconnect_receipt_cron_hook');
            wp_unschedule_event($timestamp, 'refugeeconnect_receipt_cron_hook');
        }

        public function register_settings()
        {
            register_setting('refugeeconnect_receipt_group', 'refugeeconnect_receipt_settings');
            add_settings_section(
                'settingssection1',
                'Intuit App Settings',
                [$this, 'settings_section_callback'],
                'refugeeconnect_receipt_settings'
            );
            // you can define EVERYTHING to create, display, and process each settings field as one line per setting below.  And all settings defined in this function are stored as a single serialized object.
            add_settings_field(
                'intuit_app_token',
                'Intuit App Token',
                [$this, 'settings_field'],
                'refugeeconnect_receipt_settings',
                'settingssection1',
                [
                    'setting' => 'refugeeconnect_receipt_settings',
                    'field' => 'intuit_app_token',
                    'label' => '',
                    'class' => 'regular-text'
                ]
            );
            add_settings_field(
                'intuit_app_oauth_key',
                'Intuit Oauth Consumer Key',
                [$this, 'settings_field'],
                'refugeeconnect_receipt_settings',
                'settingssection1',
                [
                    'setting' => 'refugeeconnect_receipt_settings',
                    'field' => 'intuit_app_oauth_key',
                    'label' => '',
                    'class' => 'regular-text'
                ]
            );
            add_settings_field(
                'intuit_app_oauth_secret',
                'Intuit Oauth Consumer Secret',
                [$this, 'settings_field'],
                'refugeeconnect_receipt_settings',
                'settingssection1',
                [
                    'setting' => 'refugeeconnect_receipt_settings',
                    'field' => 'intuit_app_oauth_secret',
                    'label' => '',
                    'class' => 'regular-text'
                ]
            );
            add_settings_field(
                'intuit_app_custom_field',
                'Intuit App Custom Field for External Status',
                [$this, 'settings_field'],
                'refugeeconnect_receipt_settings',
                'settingssection1',
                [
                    'setting' => 'refugeeconnect_receipt_settings',
                    'field' => 'intuit_app_custom_field',
                    'label' => '',
                    'class' => 'regular-text'
                ]
            );

            add_settings_field(
                'intuit_app_sandbox',
                'Intuit App Sandbox?',
                [$this, 'settings_field_checkbox'],
                'refugeeconnect_receipt_settings',
                'settingssection1',
                [
                    'setting' => 'refugeeconnect_receipt_settings',
                    'field' => 'intuit_app_sandbox',
                    'label' => '',
                    'class' => 'regular-text'
                ]
            );

            if (get_option('intuit_app_custom_field') === false) // Nothing yet saved
            {
                update_option('intuit_app_custom_field', 'ExternalReceipt');
            }
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

        public function settings_field_checkbox($args)
        {
            // This is the default processor that will handle standard text input fields.  Because it accepts a class, it can be styled or even have jQuery things (like a calendar picker) integrated in it.  Pass in a 'default' argument only if you want a non-empty default value.
            $settingname = esc_attr($args['setting']);
            $setting = get_option($settingname);
            $field = esc_attr($args['field']);
            $label = esc_attr($args['label']);
            $class = esc_attr($args['class']);
            $value = isset($setting[$field]) ? 'checked="checked"' : '';
            echo '<input type="checkbox" name="' . $settingname . '[' . $field . ']" id="' . $settingname . '[' . $field . ']" class="' . $class . '" ' . $value . ' /><p class="description">' . $label . '</p>';
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

            add_options_page(
                'Receipt Options',
                'Refugee Connect Receipts',
                'manage_options',
                'refugee-connect-receipts',
                [$this, 'receipt_page']
            );
        }

        function connect_intuit()
        {
            //TODO record timestamp of oauth token as it expires in 180 days, and ensure we do a reconnection attempt when required
            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have sufficient permissions to access this page.'));
            }

            if (isset($_GET['oauth_token']) && isset($_GET['oauth_verifier'])) {
                // Retrieve the temporary credentials we saved before
                $temporaryCredentials = get_option('refugeeconnect_receipt_temp_cred', '');

                try {
                    // We will now retrieve token credentials from the server
                    $tokenCredentials = $this->intuitOauthServer->getTokenCredentials(
                        $temporaryCredentials,
                        $_GET['oauth_token'],
                        $_GET['oauth_verifier']
                    );

                    update_option('refugeeconnect_receipt_authed_creds', $tokenCredentials);
                    update_option(
                        'refugeeconnect_receipt_authed_realm',
                        filter_var($_GET['realmId'], FILTER_SANITIZE_NUMBER_INT)
                    );

                    ?>
                    <div class="notice notice-success is-dismissible">
                        <p>Intuit Connection Established</p>
                    </div>
                    <?php

                } catch (Exception $e) {
                    ?>
                    <div class="notice notice-error is-dismissible">
                        <p>Failed to connect to Intuit</p>
                        <p><?= $e->getCode() ?>: <?= $e->getMessage() ?></p>
                    </div>
                    <?php

                }


            } else {

                // Retrieve temporary credentials
                $temporaryCredentials = $this->intuitOauthServer->getTemporaryCredentials();
                update_option('refugeeconnect_receipt_temp_cred', $temporaryCredentials);

                $url = $this->intuitOauthServer->getAuthorizationUrl($temporaryCredentials);

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
                $user = $this->intuitOauthServer->getUserDetails($tokenCredentials);

                // Email is either a string or null (as some providers do not supply this data)
                $email = $this->intuitOauthServer->getUserEmail($tokenCredentials);

                ?>
                <div class="wrap">
                    <h2>Refugee Connect Receipt Connected to Intuit</h2>
                    <?= serialize($tokenCredentials) ?><br/>
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
            $this->syncSalesReceipts();
            $this->syncCustomers();
        }

        function download_pdf()
        {

            // Generate PDF receipt
            if ($_GET['pdf_receipt'] && $_GET['page'] == 'refugee-connect-receipts') {
                if (!current_user_can('manage_options')) {
                    wp_die(__('You do not have sufficient permissions to access this page.'));
                }
                $sql = $this->wpdb->prepare(
                    "
                    SELECT id, Object
                    FROM {$this->receipt_table_name}
                    WHERE id = %d
                    ",
                    $_GET['pdf_receipt']
                );
                $receipt = $this->wpdb->get_row($sql);
                if (!$receipt) {
                    wp_die("Invalid Receipt Number");
                }
                $receipt_ob = unserialize($receipt->Object);
                $receipt_html = new RefugeeConnect_receipt_template($receipt_ob);

                $mpdf = new mPDF();
                $mpdf->WriteHTML($receipt_html->get_html());
                $mpdf->Output('Donation_Receipt_' . $receipt_ob->Id . '.pdf', 'D');
                die();
            }

        }

        function receipt_page()
        {
            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have sufficient permissions to access this page.'));
            }

            $current_url = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";

            $receipts = $this->wpdb->get_results(
                "
                    SELECT id, SyncToken, CustomerID, TxnDate, ExternalReceipt, Object
                    FROM {$this->receipt_table_name}
                    "
            );

            ?>
            <h1><strong>Receipts</strong></h1>
            <table class="widefat">
                <thead>
                <tr>
                    <th class="row-title"><?php esc_attr_e('Receipt ID', 'wp_admin_style'); ?></th>
                    <th><?php esc_attr_e('Date', 'wp_admin_style'); ?></th>
                    <th><?php esc_attr_e('Donor', 'wp_admin_style'); ?></th>
                    <th><?php esc_attr_e('Line Items', 'wp_admin_style'); ?></th>
                    <th><?php esc_attr_e('Status', 'wp_admin_style'); ?></th>
                    <th></th>
                </tr>
                </thead>
                <tbody>

                <?php

                foreach ($receipts as $receipt) {
                    $receipt_ob = unserialize($receipt->Object);
                    ?>
                    <tr valign="top">
                        <td scope="row"><label for="tablecell"><?php esc_attr_e(
                                    $receipt->id,
                                    'wp_admin_style'
                                ); ?></label></td>
                        <td><?php esc_attr_e($receipt_ob->TxnDate, 'wp_admin_style'); ?></td>
                        <td><?php esc_attr_e($receipt_ob->CustomerRef->name, 'wp_admin_style'); ?></td>
                        <td><?php
                            $lines = [];
                            foreach ($receipt_ob->Line as $line) {
                                if ($line->Id) {
                                    $lines[] = "{$line->LineNum}: {$line->Description} <span style='float:right'>\${$line->Amount}</span>";
                                }
                            }
                            echo implode("<br/>", $lines);

                            ?></td>
                        <td><?= $receipt->ExternalReceipt ?></td>
                        <td><a href="<?= $current_url . '&pdf_receipt=' . $receipt->id ?>">Download PDF</a></td>
                    </tr>
                    <?php
                }

                ?>
                </tbody>
            </table>
            <?php
        }

        private function externalReceiptStatus($receipt)
        {
            foreach ($receipt->CustomField as $custom_field) {
                if ($custom_field->Name == get_option('intuit_app_custom_field')) {
                    if ($custom_field->StringValue) { // Check we aren't a Null
                        return $custom_field->StringValue;
                    }
                }
            }
            return "NotSet";
        }

        function syncSalesReceipts()
        {
            $uri = $this->baseUrl . 'query?query=SELECT%20%2A%20from%20salesreceipt&minorversion=4';
            $response = \Httpful\Request::get($uri)
                ->addHeaders($this->getAuthHeader('GET', $uri))
                ->send();

            // TODO pagination to ensure we get all receipts
            $count = 0;
            foreach ($response->body->QueryResponse->SalesReceipt as $receipt) {
                $this->wpdb->replace(
                    $this->receipt_table_name,
                    [
                        'id' => (int)$receipt->Id,
                        'SyncToken' => $receipt->SyncToken,
                        'CustomerID' => $receipt->CustomerRef->value,
                        'TxnDate' => $receipt->TxnDate,
                        'ExternalReceipt' => $this->externalReceiptStatus($receipt),
                        'Object' => serialize($receipt)
                    ]
                );
                $count++;
            }

            if (!defined('DOING_CRON')) {
                // Don't output notice if we are running under Cron
                ?>
                <div class="notice notice-success is-dismissible">
                    <p>Syncronised <?= $count ?> receipts</p>
                </div>
                <?php
            }
        }

        function syncCustomers()
        {
            $uri = $this->baseUrl . 'query?query=SELECT%20%2A%20from%20customer&minorversion=4';
            $response = \Httpful\Request::get($uri)
                ->addHeaders($this->getAuthHeader('GET', $uri))
                ->send();

            // TODO pagination to ensure we get all customers
            $count = 0;
            dump($response->body->QueryResponse);
            foreach ($response->body->QueryResponse->Customer as $customer) {
                $this->wpdb->replace(
                    $this->customer_table_name,
                    [
                        'id' => (int)$customer->Id,
                        'CustomerName' => $customer->DisplayName,
                        'PrimaryEmailAddress' => $customer->PrimaryEmailAddr->Address,
                        'Object' => serialize($customer)
                    ]
                );
                $count++;
            }

            if (!defined('DOING_CRON')) {
                // Don't output notice if we are running under Cron
                ?>
                <div class="notice notice-success is-dismissible">
                    <p>Syncronised <?= $count ?> customers</p>
                </div>
                <?php
            }
        }


    }

    $RefugeeConnect_receipts_Plugin = new RefugeeConnect_receipts();
    $RefugeeConnect_receipts_Plugin->init();
}
