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
        private $sandbox;
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

            add_action('admin_notices', [$this, 'admin_notices']);

            add_action('admin_post_send_receipt_email', [$this, 'send_receipt_email_hook']);
            add_action('admin_post_mark_receipt_manual', [$this, 'mark_receipt_manual_hook']);
            add_action('admin_post_send_all_unsent_email', [$this, 'send_all_unsent_email_hook']);
            add_action('admin_post_sync_intuit', [$this, 'sync_external_hook']);

            // Setup template for Requests
            $template = \Httpful\Request::init()
                ->addHeaders(['Accept' => 'application/xml; q=0.5, text/xml; q=0.5, application/json']);
            \Httpful\Request::ini($template);
            \Httpful\Httpful::register('text/xml', new \Httpful\Handlers\XmlHandler());

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
            $this->check_intuit_refresh();
            $this->setupOAuth();
            $this->syncSalesReceipts();
            $this->syncCustomers();
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

        /**
         * Gets the option from our shared refugeeconnect_receipt_settings option
         * @param $option string Array index of the option we want
         * @return mixed
         */
        private function get_rc_option($option)
        {
            $options = get_option('refugeeconnect_receipt_settings', []);
            if (!empty($options[$option])) {
                return $options[$option];
            }
            return null;
        }

        public function setupOAuth()
        {
            $this->intuitOauthServer = new Wheniwork\OAuth1\Client\Server\Intuit(
                [
                    'identifier' => $this->get_rc_option('intuit_app_oauth_key'),
                    'secret' => $this->get_rc_option('intuit_app_oauth_secret'),
                    'callback_uri' => (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]",
                ]
            );

            $this->sandbox = $this->get_rc_option('intuit_app_sandbox') == "on" ? true : false;

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
                    'class' => 'regular-text',
                    'default' => 'ExternalReceipt'
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

            add_settings_section(
                'settingssection2',
                'Email Receipt Settings',
                [$this, 'settings_section_callback'],
                'refugeeconnect_receipt_settings'
            );

            add_settings_field(
                'email_from_address',
                'From Email Address',
                [$this, 'settings_field'],
                'refugeeconnect_receipt_settings',
                'settingssection2',
                [
                    'setting' => 'refugeeconnect_receipt_settings',
                    'field' => 'email_from_address',
                    'label' => '',
                    'class' => 'regular-text'
                ]
            );

            add_settings_field(
                'email_from_name',
                'From Email Name',
                [$this, 'settings_field'],
                'refugeeconnect_receipt_settings',
                'settingssection2',
                [
                    'setting' => 'refugeeconnect_receipt_settings',
                    'field' => 'email_from_name',
                    'label' => '',
                    'class' => 'regular-text'
                ]
            );

            add_settings_field(
                'email_replyto_address',
                'Reply To Email Address',
                [$this, 'settings_field'],
                'refugeeconnect_receipt_settings',
                'settingssection2',
                [
                    'setting' => 'refugeeconnect_receipt_settings',
                    'field' => 'email_replyto_address',
                    'label' => '',
                    'class' => 'regular-text'
                ]
            );

            add_settings_field(
                'email_replyto_name',
                'Reply To Name',
                [$this, 'settings_field'],
                'refugeeconnect_receipt_settings',
                'settingssection2',
                [
                    'setting' => 'refugeeconnect_receipt_settings',
                    'field' => 'email_replyto_name',
                    'label' => '',
                    'class' => 'regular-text'
                ]
            );

            add_settings_field(
                'email_bcc',
                'BCC Email Address',
                [$this, 'settings_field'],
                'refugeeconnect_receipt_settings',
                'settingssection2',
                [
                    'setting' => 'refugeeconnect_receipt_settings',
                    'field' => 'email_bcc',
                    'label' => '',
                    'class' => 'regular-text'
                ]
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
            add_menu_page(
                'Donation Receipts',
                'Donation Receipts',
                'manage_options',
                'refugee-connect-receipts',
                [$this, 'receipt_page'],
                'dashicons-portfolio',
                59 );

            add_submenu_page(
                'refugee-connect-receipts',
                'Refugee Connect Customers',
                'Customers',
                'manage_options',
                'refugee-connect-customers',
                [$this, 'customers_page']
            );

            add_submenu_page(
                'refugee-connect-receipts',
                'Settings',
                'Settings',
                'manage_options',
                'refugee-connect-receipts-admin',
                [$this, 'plugin_options']
            );
            add_submenu_page(
                'refugee-connect-receipts',
                'Intuit/Quickbooks Connection',
                'Connect to Quickbooks',
                'manage_options',
                'refugee-connect-connect-intunit',
                [$this, 'connect_intuit']
            );
        }

        function check_intuit_refresh()
        {
            // If our token is more than 151 days old, we can try to renew it
            if(get_option('refugeeconnect_receipt_authed_timestamp') < time() - 3600*24*151) {
                return false;
            }

            $uri = 'https://appcenter.intuit.com/api/v1/connection/reconnect';
            $response = \Httpful\Request::get($uri)
                ->addHeaders($this->getAuthHeader('GET', $uri))
                ->expectsXml()
                ->send();
            $tokenCredentials = $this->createTokenCredentials($response->body);

            if($tokenCredentials) {
                update_option('refugeeconnect_receipt_authed_creds', $tokenCredentials);
                update_option('refugeeconnect_receipt_authed_timestamp', time());
                $this->addNotice("Successfully refreshed Intuit Oauth token", 'success');
            }
        }

        // Based on https://github.com/thephpleague/oauth1-client/blob/master/src/Client/Server/Server.php#L474
        protected function createTokenCredentials($data)
        {
            if ($data->ErrorMessage) {
                $this->addNotice("Error [{$data->ErrorCode}] in retrieving token credentials for refresh: {$data->ErrorMessage}", 'error');
                return false;
            }

            $tokenCredentials = new \League\OAuth1\Client\Credentials\TokenCredentials();
            $tokenCredentials->setIdentifier($data->OAuthToken);
            $tokenCredentials->setSecret($data->OAuthTokenSecret);

            return $tokenCredentials;
        }

        function connect_intuit()
        {
            //TODO record timestamp of oauth token as it expires in 180 days, and ensure we do a reconnection attempt when required
            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have sufficient permissions to access this page.'));
            }

            if (isset($_GET['refresh'])) {
                $this->check_intuit_refresh();
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
                    update_option('refugeeconnect_receipt_authed_timestamp', time());

                    ?>
                    <div class="notice notice-success is-dismissible">
                        <p>Intuit Connection Established</p>
                    </div>
                    <?php

                } catch (Exception $e) {
                    $this->error("Failed to connect to Intuit<br/>
                        {$e->getCode()}: {$e->getMessage()}");
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
                    <?= get_option('refugeeconnect_receipt_authed_timestamp', 'No Timestamp') ?>
                </div>
                <?php
            }

        }


        function plugin_options()
        {
            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have sufficient permissions to access this page.'));
            }

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

        private function getReceipt($id)
        {
            $sql = $this->wpdb->prepare(
                "
                    SELECT receipt.id AS id, CustomerName, PrimaryEmailAddress, receipt.Object as Object
                    FROM {$this->receipt_table_name} AS receipt
                    INNER JOIN {$this->customer_table_name} ON receipt.CustomerID={$this->customer_table_name}.id
                    WHERE receipt.id = %d
                    ",
                $id
            );
            $receipt = $this->wpdb->get_row($sql);
            if (!$receipt) {
                wp_die("Invalid Receipt Number");
            }
            return $receipt;
        }

        function download_pdf()
        {

            // Generate PDF receipt
            if (!empty($_GET['pdf_receipt']) && $_GET['page'] == 'refugee-connect-receipts') {
                if (!current_user_can('manage_options')) {
                    wp_die(__('You do not have sufficient permissions to access this page.'));
                }
                check_admin_referer('download-receipt_' . $_GET['pdf_receipt']);

                $receipt = $this->getReceipt($_GET['pdf_receipt']);
                $receipt_ob = unserialize($receipt->Object);
                $customer_email = $receipt->PrimaryEmailAddress;
                $receipt_html = new RefugeeConnect_receipt_template($receipt_ob, $customer_email);

                if (!empty($_GET['preview'])) {
                    echo $receipt_html->get_html();
                } else {

                    $mpdf = new mPDF();
                    $mpdf->WriteHTML($receipt_html->get_html());
                    $mpdf->Output('Donation_Receipt_' . $receipt_ob->DocNumber . '.pdf', 'D');
                }
                die();
            }

        }

        function send_all_unsent_email_hook()
        {
            check_admin_referer('send-receipt-email-all');
            $sql = $this->wpdb->prepare(
                "
                    SELECT receipt.id AS id, CustomerName, PrimaryEmailAddress, receipt.Object as Object
                    FROM {$this->receipt_table_name} AS receipt
                    INNER JOIN {$this->customer_table_name} ON receipt.CustomerID={$this->customer_table_name}.id
                    WHERE ExternalReceipt = 'Not Sent'
                    "
            );
            $results = $this->wpdb->get_results($sql);
            if(sizeof($results) == 0) {
                $this->addNotice("All Receipts that can be sent are already marked as sent", 'warning');
            }
            foreach($results as $receipt) {
                $this->sendReceiptEmail($receipt);
            }
            wp_redirect(admin_url("admin.php?page=refugee-connect-receipts"));
            exit();
        }

        function send_receipt_email_hook()
        {
            check_admin_referer('send-receipt-email_' . $_GET['send_email']);
            $this->sendReceiptEmail($this->getReceipt($_GET['send_email']));
            wp_redirect(admin_url("admin.php?page=refugee-connect-receipts"));
            exit();
        }

        function mark_receipt_manual_hook()
        {
            check_admin_referer('send-receipt-manual_' . $_GET['mark_sent']);
            $this->markReceiptManuallySent($_GET['mark_sent']);
            wp_redirect(admin_url("admin.php?page=refugee-connect-receipts"));
            exit();
        }

        function sync_external_hook()
        {
            check_admin_referer('sync-receipts');
            $this->syncSalesReceipts();
            $this->syncCustomers();
            wp_redirect(admin_url("admin.php?page=refugee-connect-receipts"));
            exit();
        }

        function receipt_page()
        {
            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have sufficient permissions to access this page.'));
            }

            $current_url = admin_url("admin.php?page=" . $_GET["page"]);

            $receipts = $this->wpdb->get_results(
                "
                    SELECT receipt.id AS id, SyncToken, CustomerID, TxnDate, ExternalReceipt, receipt.Object as Object, PrimaryEmailAddress, {$this->customer_table_name}.Object as CustomerObject
                    FROM {$this->receipt_table_name} AS receipt
                    INNER JOIN {$this->customer_table_name} ON receipt.CustomerID={$this->customer_table_name}.id
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
                    if (!$receipt->PrimaryEmailAddress) {
                        $customer_email = '';
                        $send_receipt = '<a href="' . wp_nonce_url(admin_url('admin-post.php') . '?action=mark_receipt_manual&mark_sent=' . $receipt->id, 'send-receipt-manual_' . $receipt->id) . '">Mark as manually sent</a>';
                    } else {
                        $email_address = esc_attr__($receipt->PrimaryEmailAddress, 'wp_admin_style');;
                        $customer_email = "<a title='{$email_address}'><span  style='font-size: smaller' class='dashicons dashicons-email'></span></a>";
                        $send_receipt = '<a href="' . wp_nonce_url(admin_url('admin-post.php') . '?action=send_receipt_email&send_email=' . $receipt->id, 'send-receipt-email_' . $receipt->id) . '">Send Email Receipt</a>';
                    }
                    ?>
                    <tr valign="top">
                        <td scope="row"><label for="tablecell" title="<?= $receipt_ob->Id ?>"><?php esc_attr_e(
                                    $receipt_ob->DocNumber,
                                    'wp_admin_style'
                                ); ?></label></td>
                        <td><?php esc_attr_e($receipt_ob->TxnDate, 'wp_admin_style'); ?></td>
                        <td><?php esc_attr_e($receipt_ob->CustomerRef->name, 'wp_admin_style'); ?> <?= $customer_email ?></td>
                        <td><?php
                            $lines = [];
                            foreach ($receipt_ob->Line as $line) {
                                if ($line->Id) {
                                    $lines[] = "{$line->LineNum}: {$line->Description} <span style='float:right'>\${$line->Amount}</span>";
                                }
                            }
                            echo implode("<br/>", $lines);

                            ?></td>
                        <td><?= $this->externalStatusFancy($receipt->ExternalReceipt) ?></td>
                        <td>
                            <a href="<?= wp_nonce_url($current_url . '&pdf_receipt=' . $receipt->id, 'download-receipt_' . $receipt->id) ?>">Download
                                PDF</a> | <a href="<?= wp_nonce_url($current_url . '&preview=1&pdf_receipt=' . $receipt->id, 'download-receipt_' . $receipt->id) ?>">Preview</a>
                        </td>
                        <td><?= $send_receipt ?></td>
                    </tr>
                    <?php
                }

                ?>
                </tbody>
            </table>

            <a href="<?= wp_nonce_url(admin_url('admin-post.php') . '?action=send_all_unsent_email', 'send-receipt-email-all') ?>">Send all pending receipts</a> |

            <a href="<?= wp_nonce_url(admin_url('admin-post.php') . '?action=sync_intuit', 'sync-receipts') ?>">Force sync from
                Quickbooks</a>

            <?php
        }

        function customers_page()
        {
            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have sufficient permissions to access this page.'));
            }

            $customers = $this->wpdb->get_results(
                "
                    SELECT id, CustomerName, PrimaryEmailAddress
                    FROM {$this->customer_table_name}
                    "
            );

            ?>
            <h1><strong>Customers</strong></h1>
            <table class="widefat">
                <thead>
                <tr>
                    <th class="row-title"><?php esc_attr_e('Customer ID', 'wp_admin_style'); ?></th>
                    <th><?php esc_attr_e('Name', 'wp_admin_style'); ?></th>
                    <th><?php esc_attr_e('Primary Email', 'wp_admin_style'); ?></th>
                </tr>
                </thead>
                <tbody>

                <?php

                foreach ($customers as $customer) {
                    ?>
                    <tr valign="top">
                        <td scope="row"><label for="tablecell"><?php esc_attr_e(
                                    $customer->id,
                                    'wp_admin_style'
                                ); ?></label></td>
                        <td><?php esc_attr_e($customer->CustomerName, 'wp_admin_style'); ?></td>
                        <td><?php esc_attr_e($customer->PrimaryEmailAddress, 'wp_admin_style'); ?></td>
                    </tr>
                    <?php
                }

                ?>
                </tbody>
            </table>
            <?php
        }

        private function externalStatusFancy($status)
        {
            if ($this->startsWith($status, 'EMAIL ')) {
                $unixtimestamp = substr($status, 6);
                $unixtimestamp += 3600 * 10; // Hard code UTC offset for Brisbane for now, less code
                $timestamp = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $unixtimestamp, false);
                return "Sent Email $timestamp";
            }
            if ($this->startsWith($status, 'MANUAL ')) {
                $unixtimestamp = substr($status, 7);
                $unixtimestamp += 3600 * 10; // Hard code UTC offset for Brisbane for now, less code
                $timestamp = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $unixtimestamp, false);
                return "Sent Manual $timestamp";
            }
            return $status;
        }

        // https://stackoverflow.com/a/10473026
        private function startsWith($haystack, $needle)
        {
            // search backwards starting from haystack length characters from the end
            return $needle === "" || strrpos($haystack, $needle, -strlen($haystack)) !== false;
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
            return "Not Sent";
        }

        private function updateCustomField($receipt, $status)
        {
            foreach ($receipt->CustomField as $custom_field) {
                if ($custom_field->Name == get_option('intuit_app_custom_field')) {
                    $custom_field->StringValue = $status;
                }
            }
        }

        private function checkResponseError($response) {
            if($response->body->Fault) {
                $this->addNotice("Unexpected response from Intuit. {$response->body->Fault->Error->Message}", 'error');
                wp_redirect(admin_url("admin.php?page=refugee-connect-receipts"));
                exit();
            }
            // We always expect JSON back, if we've gotten XML something is wrong
            if($response->content_type != 'application/json'){
                $raw_body = htmlentities($response->raw_body);
                $this->addNotice("Unexpected response from Intuit. Expecting JSON, got {$response->content_type}<pre>{$raw_body}</pre>", 'error');
                wp_redirect(admin_url("admin.php?page=refugee-connect-receipts"));
                exit();
            }
        }

        function syncSalesReceipts()
        {
            $uri = $this->baseUrl . 'query?query=SELECT%20%2A%20from%20salesreceipt&minorversion=4';
            $response = \Httpful\Request::get($uri)
                ->addHeaders($this->getAuthHeader('GET', $uri))
                ->send();

            // TODO pagination to ensure we get all receipts
            $count = 0;
            $this->checkResponseError($response);
            foreach ($response->body->QueryResponse->SalesReceipt as $receipt) {
                $this->updateReceiptRow($receipt);
                $count++;
            }

            if (!defined('DOING_CRON')) {
                // Don't output notice if we are running under Cron
                $this->addNotice("Syncronised $count receipts with Intuit", 'success');
            }
        }

        private function updateReceiptRow($receipt)
        {
            return $this->wpdb->replace(
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
        }

        function syncCustomers()
        {
            $uri = $this->baseUrl . 'query?query=SELECT%20%2A%20from%20customer&minorversion=4';
            $response = \Httpful\Request::get($uri)
                ->addHeaders($this->getAuthHeader('GET', $uri))
                ->send();

            // TODO pagination to ensure we get all customers
            $count = 0;
            $this->checkResponseError($response);
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
                $this->addNotice("Syncronised $count customers with Intuit", 'success');
            }
        }

        /**
         * Updates QuickBooks ExternalReceipt status field
         * @param $receipt object
         * @param $status string Status to update in QuickBooks
         * @return bool
         */
        private function updateExternalStatus($receipt, $status)
        {
            $receipt_ob = unserialize($receipt->Object);
            $this->updateCustomField($receipt_ob, $status);

            $update_ob = [
                "sparse" => true,
                "Id" => $receipt_ob->Id,
                "SyncToken" => $receipt_ob->SyncToken,
                "CustomField" => $receipt_ob->CustomField
            ];

            $uri = $this->baseUrl . 'salesreceipt';
            $response = \Httpful\Request::post($uri)
                ->addHeaders($this->getAuthHeader('POST', $uri))
                ->sendsJson()
                ->body(json_encode($update_ob))
                ->send();

            if ($response->body->SalesReceipt) {
                $this->updateReceiptRow($response->body->SalesReceipt);
                $this->addNotice("Synced status of Receipt #{$receipt_ob->DocNumber} to Quickbooks", 'success');
                return true;
            } elseif ($response->body->Fault->Error) {
                foreach ($response->body->Fault->Error as $error) {
                    $this->addNotice(
                        "Unable to update QuickBooks Receipt #{$receipt_ob->DocNumber} - 
                             {$error->Detail}<br/>
                             Try forcing sync of receipt's before retrying", 'error');
                }
            }
            return false;
        }

        public function markReceiptManuallySent($receiptID)
        {
            $receipt = $this->getReceipt($receiptID);

            $receipt_ob = unserialize($receipt->Object);
            if (!$this->updateExternalStatus($receipt, "MANUAL " . time())) {
                return $this->addNotice(
                    "Unable to mark receipt #{$receipt_ob->DocNumber} as sent to {$receipt->CustomerName} due to issue
                     updating Quickbooks Status", 'error');
            }

            $this->addNotice("Marked Receipt #{$receipt_ob->DocNumber} to {$receipt->CustomerName} as sent", 'success');
        }


        /**
         * @param $receipt Object
         */
        public function sendReceiptEmail($receipt)
        {
            $receipt_ob = unserialize($receipt->Object);
            $customer_email = $receipt->PrimaryEmailAddress;

            if (!$this->get_rc_option('email_from_address')) {
                return $this->addNotice(
                    "Unable to send receipt #{$receipt_ob->DocNumber} to {$receipt->CustomerName} due to missing From 
                        Address in <a href='" . admin_url("admin.php?page=refugee-connect-receipts-admin")
                    . "'>Settings</a>", 'error');
            }

            if (!$customer_email) {
                return $this->addNotice(
                    "Unable to send receipt #{$receipt_ob->DocNumber} to {$receipt->CustomerName} due to missing customer
                         email address", 'error');
            }

            if (!$this->updateExternalStatus($receipt, "EMAIL " . time())) {
                return $this->addNotice(
                    "Unable to send receipt #{$receipt_ob->DocNumber} to {$receipt->CustomerName} due to issue updating 
                        Quickbooks Status", 'error');
            }


            $receipt_html = new RefugeeConnect_receipt_template($receipt_ob, $customer_email);

            $attachment = '/tmp/Donation_Receipt_' . $receipt_ob->DocNumber . '.pdf';

            $mpdf = new mPDF();
            $mpdf->WriteHTML($receipt_html->get_html());
            $mpdf->Output($attachment, 'F');

            add_filter('wp_mail_content_type', [$this, 'wpdocs_set_html_mail_content_type']);

            $to = $customer_email;
            $subject = 'Refugee Connect Donation Receipt #' . $receipt_ob->DocNumber;
            $body = $receipt_html->get_html();

            $headers[] = "From: {$this->get_rc_option('email_from_name')} <{$this->get_rc_option('email_from_address')}>";
            if ($this->get_rc_option('email_replyto_address')) {
                $headers[] = "Reply-To: {$this->get_rc_option('email_replyto_name')} <{$this->get_rc_option('email_replyto_address')}>";
            }
            if ($this->get_rc_option('email_bcc')) {
                $headers[] = "Bcc: <{$this->get_rc_option('email_bcc')}>";
            }

            wp_mail($to, $subject, $body, $headers, $attachment);

            // Reset content-type to avoid conflicts -- https://core.trac.wordpress.org/ticket/23578
            remove_filter('wp_mail_content_type', [$this, 'wpdocs_set_html_mail_content_type']);

            $this->addNotice("Sent Receipt #{$receipt_ob->DocNumber} to $customer_email", 'success');
        }

        public function wpdocs_set_html_mail_content_type()
        {
            return 'text/html';
        }

        public function admin_notices()
        {
            /* Check transient, if available display notice */
            $notices = get_transient('rc-receipt-admin-notices');
            if ($notices) {
                foreach ($notices as $notice) {
                    ?>
                    <div class="notice notice-<?= $notice->type ?> is-dismissible">
                        <p><?= $notice->message ?></p>
                    </div>
                    <?php
                }
                /* Delete transient, only display this notice once. */
                delete_transient('rc-receipt-admin-notices');
            }
        }

        private function addNotice($message, $type)
        {
            $notice = (object)['message' => $message, 'type' => $type];
            $notices = get_transient('rc-receipt-admin-notices');
            if (!is_array($notices)) {
                $notices = [];
            }
            $notices[] = $notice;
            set_transient('rc-receipt-admin-notices', $notices);
        }

        private function error($message)
        {
            ?>
            <div class="notice notice-error is-dismissible">
                <p><?= $message ?></p>
            </div>
            <?php
            return false;
        }


    }

    $RefugeeConnect_receipts_Plugin = new RefugeeConnect_receipts();
    $RefugeeConnect_receipts_Plugin->init();
}
