<?php

use jtl\Connector\Core\Exception\MissingRequirementException;
use jtl\Connector\Application\Application;
use jtl\Connector\Core\System\Check;
use JtlWooCommerceConnector\Utilities\Config;
use JtlWooCommerceConnector\Utilities\Id;
use JtlWooCommerceConnector\Utilities\SupportedPlugins;
use Symfony\Component\Yaml\Yaml;
use \WC_Admin_Settings as WC_Admin_Settings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @author Jan Weskamp <jan.weskamp@jtl-software.com>
 * @copyright 2010-2013 JTL-Software GmbH
 */
final class JtlConnectorAdmin
{
    /**
     * Password for connector
     */
    const OPTIONS_TOKEN = 'connector_password';
    /**
     * Pull all orders even the completed ones
     */
    const OPTIONS_COMPLETED_ORDERS = 'jtlconnector_completed_orders';
    /**
     * Just pull orders created after a specific date
     */
    const OPTIONS_PULL_ORDERS_SINCE = 'jtlconnector_pull_orders_since';
    /**
     * Use another format for child product names than Variation #22 of Product name
     */
    const OPTIONS_VARIATION_NAME_FORMAT = 'jtlconnector_variation_name_format';
    /**
     * The currently installed connector version.
     */
    
    /**
     * The update to a new connector version failed.
     */
    const OPTIONS_UPDATE_FAILED = 'jtlconnector_update_failed';
    
    const OPTIONS_INSTALLED_VERSION = 'jtlconnector_installed_version';
    const OPTIONS_DEVELOPER_LOGGING = 'developer_logging';
    const OPTIONS_SHOW_VARIATION_SPECIFICS_ON_PRODUCT_PAGE = 'jtlconnector_show_variation_specifics_on_product_page';
    const OPTIONS_SEND_CUSTOM_PROPERTIES = 'jtlconnector_send_custom_properties';
    const OPTIONS_USE_GTIN_FOR_EAN = 'jtlconnector_use_gtin_for_ean';
    const OPTIONS_USE_DELIVERYTIME_CALC = 'jtlconnector_use_deliverytime_calc';
    const OPTIONS_DISABLED_ZERO_DELIVERY_TIME = 'jtlconnector_disabled_zero_delivery_time';
    const OPTIONS_PRAEFIX_DELIVERYTIME = 'jtlconnector_praefix_deliverytime';
    const OPTIONS_SUFFIX_DELIVERYTIME = 'jtlconnector_suffix_deliverytime';
    
    const JTLWCC_CONFIG = [
        //FIRSTPAGE
        self::OPTIONS_SHOW_VARIATION_SPECIFICS_ON_PRODUCT_PAGE => 'bool',
        self::OPTIONS_SEND_CUSTOM_PROPERTIES                   => 'bool',
        self::OPTIONS_VARIATION_NAME_FORMAT                    => 'string',
        self::OPTIONS_USE_GTIN_FOR_EAN                         => 'bool',
        //PAGE
        self::OPTIONS_USE_DELIVERYTIME_CALC                    => 'bool',
        self::OPTIONS_DISABLED_ZERO_DELIVERY_TIME              => 'bool',
        self::OPTIONS_PRAEFIX_DELIVERYTIME                     => 'string',
        self::OPTIONS_SUFFIX_DELIVERYTIME                      => 'string',
        //PAGE
        self::OPTIONS_COMPLETED_ORDERS                         => 'bool',
        self::OPTIONS_PULL_ORDERS_SINCE                        => 'date',
        //Page
        self::OPTIONS_DEVELOPER_LOGGING                        => 'bool',
    ];
    
    const JTLWCC_CONFIG_DEFAULTS = [
        //FIRSTPAGE
        self::OPTIONS_SHOW_VARIATION_SPECIFICS_ON_PRODUCT_PAGE => true,
        self::OPTIONS_SEND_CUSTOM_PROPERTIES                   => true,
        self::OPTIONS_VARIATION_NAME_FORMAT                    => '',
        self::OPTIONS_USE_GTIN_FOR_EAN                         => true,
        //PAGE
        self::OPTIONS_USE_DELIVERYTIME_CALC                    => true,
        self::OPTIONS_DISABLED_ZERO_DELIVERY_TIME              => true,
        self::OPTIONS_PRAEFIX_DELIVERYTIME                     => 'ca. ',
        self::OPTIONS_SUFFIX_DELIVERYTIME                      => ' Werktage',
        //PAGE
        self::OPTIONS_COMPLETED_ORDERS                         => true,
        self::OPTIONS_PULL_ORDERS_SINCE                        => '',
        //Page
        self::OPTIONS_DEVELOPER_LOGGING                        => false,
    ];
    
    
    private static $initiated = false;
    
    // <editor-fold defaultstate="collapsed" desc="Activation">
    public static function plugin_activation()
    {
        global $woocommerce;
        $version = $woocommerce->version;
        if (jtlwcc_woocommerce_deactivated()) {
            jtlwcc_deactivate_plugin();
            add_action('admin_notices', 'jtlwcc_woocommerce_not_activated');
            
        } elseif (version_compare($version,
            trim(Yaml::parseFile(JTLWCC_CONNECTOR_DIR . '/build-config.yaml')['min_wc_version']), '<')) {
            jtlwcc_deactivate_plugin();
            add_action('admin_notices', 'jtlwcc_wrong_woocommerce_version');
        }
        
        try {
            self::run_system_check();
            self::activate_linking();
            self::activate_checksum();
            self::activate_category_tree();
            self::set_linking_table_name_prefix_correctly();
            self::add_manufacturer_linking_tables();
            add_option(self::OPTIONS_TOKEN, self::create_password());
            add_option(self::OPTIONS_COMPLETED_ORDERS, 'yes');
            add_option(self::OPTIONS_PULL_ORDERS_SINCE, '');
            add_option(self::OPTIONS_VARIATION_NAME_FORMAT, '');
            add_option(self::OPTIONS_INSTALLED_VERSION,
                trim(Yaml::parseFile(JTLWCC_CONNECTOR_DIR . '/build-config.yaml')['version']));
        } catch (\jtl\Connector\Core\Exception\MissingRequirementException $exc) {
            if (is_admin() && (!defined('DOING_AJAX') || !DOING_AJAX)) {
                jtlwcc_deactivate_plugin();
                wp_die($exc->getMessage());
            } else {
                return;
            }
        }
    }
    
    private static function run_system_check()
    {
        try {
            if (file_exists(JTLWCC_CONNECTOR_DIR . '/connector.phar')) {
                if (is_writable(sys_get_temp_dir())) {
                    self::run_phar_check();
                } else {
                    add_action('admin_notices', 'directory_no_write_access');
                }
            }
            
            Check::run();
            
        } catch (\Exception $e) {
            wp_die($e->getMessage());
        }
    }
    
    private static function run_phar_check()
    {
        if (!extension_loaded('phar')) {
            add_action('admin_notices', 'phar_extension');
        }
        if (extension_loaded('suhosin')) {
            if (strpos(ini_get('suhosin.executor.include.whitelist'), 'phar') === false) {
                add_action('admin_notices', 'suhosin_whitelist');
            }
        }
    }
    
    private static function activate_linking()
    {
        global $wpdb;
        
        $query = '
            CREATE TABLE IF NOT EXISTS `%s` (
                `endpoint_id` BIGINT(20) unsigned NOT NULL,
                `host_id` INT(10) unsigned NOT NULL,
                PRIMARY KEY (`endpoint_id`, `host_id`),
                INDEX (`host_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci';
        
        $tables = [
            'jtl_connector_link_category',
            'jtl_connector_link_crossselling',
            'jtl_connector_link_order',
            'jtl_connector_link_payment',
            'jtl_connector_link_product',
            'jtl_connector_link_shipping_class',
            'jtl_connector_link_specific',
            'jtl_connector_link_specific_value',
        ];
        
        foreach ($tables as $table) {
            $wpdb->query(sprintf($query, $table));
        }
        
        $wpdb->query('
            CREATE TABLE IF NOT EXISTS `jtl_connector_link_customer` (
                `endpoint_id` VARCHAR(255) NOT NULL,
                `host_id` INT(10) unsigned NOT NULL,
                `is_guest` BIT,
                PRIMARY KEY (`endpoint_id`, `host_id`, `is_guest`),
                INDEX (`host_id`, `is_guest`),
                INDEX (`endpoint_id`, `is_guest`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci'
        );
        
        $wpdb->query('
            CREATE TABLE IF NOT EXISTS `jtl_connector_link_image` (
                `endpoint_id` VARCHAR(255) NOT NULL,
                `host_id` INT(10) NOT NULL,
                `type` INT unsigned NOT NULL,
                PRIMARY KEY (`endpoint_id`, `host_id`, `type`),
                INDEX (`host_id`, `type`),
                INDEX (`endpoint_id`, `type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci'
        );
        
        self::add_constraints_for_multi_linking_tables();
        self::set_linking_table_name_prefix_correctly();
        self::add_manufacturer_linking_tables();
    }
    
    private static function activate_checksum()
    {
        global $wpdb;
        
        $engine = $wpdb->get_var(sprintf("
            SELECT ENGINE
            FROM information_schema.TABLES
            WHERE TABLE_NAME = '{$wpdb->posts}' AND TABLE_SCHEMA = '%s'",
            DB_NAME
        ));
        
        if ($engine === 'InnoDB') {
            $constraint = ", CONSTRAINT `jtl_connector_product_checksum1` FOREIGN KEY (`product_id`) REFERENCES {$wpdb->posts} (`ID`) ON DELETE CASCADE ON UPDATE NO ACTION";
        } else {
            $constraint = '';
        }
        
        $wpdb->query("
            CREATE TABLE IF NOT EXISTS `jtl_connector_product_checksum` (
                `product_id` BIGINT(20) unsigned NOT NULL,
                `type` tinyint unsigned NOT NULL,
                `checksum` varchar(255) NOT NULL,
                PRIMARY KEY (`product_id`) {$constraint}
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci");
    }
    
    private static function activate_category_tree()
    {
        global $wpdb;
        
        $engine = $wpdb->get_var(sprintf("
            SELECT ENGINE
            FROM information_schema.TABLES
            WHERE TABLE_NAME = '{$wpdb->terms}' AND TABLE_SCHEMA = '%s'",
            DB_NAME
        ));
        
        if ($engine === 'InnoDB') {
            $constraint = ", CONSTRAINT `jtl_connector_category_level1` FOREIGN KEY (`category_id`) REFERENCES {$wpdb->terms} (`term_id`) ON DELETE CASCADE ON UPDATE NO ACTION";
        } else {
            $constraint = '';
        }
        
        $wpdb->query("
            CREATE TABLE IF NOT EXISTS `jtl_connector_category_level` (
                `category_id` BIGINT(20) unsigned NOT NULL,
                `level` int(10) unsigned NOT NULL,
                `sort` int(10) unsigned NOT NULL,
                PRIMARY KEY (`category_id`),
                INDEX (`level`) {$constraint}
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci");
    }
    
    private static function create_password()
    {
        if (function_exists('com_create_guid') === true) {
            return trim(com_create_guid(), '{}');
        }
        
        return sprintf('%04X%04X-%04X-%04X-%04X-%04X%04X%04X', mt_rand(0, 65535), mt_rand(0, 65535),
            mt_rand(0, 65535),
            mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535),
            mt_rand(0, 65535));
    }
    
    // </editor-fold>
    
    public static function plugin_deactivation()
    {
        delete_option(self::OPTIONS_TOKEN);
    }
    
    public static function init()
    {
        if (!self::$initiated) {
            self::init_hooks();
        }
    }
    
    public static function init_hooks()
    {
        self::$initiated = true;
        
        add_filter('plugin_row_meta', ['JtlConnectorAdmin', 'jtlconnector_plugin_row_meta'], 10, 2);
        
        add_action('admin_post_settings_save_woo-jtl-connector', ['JtlConnectorAdmin', 'save']);
        
        //Register custom fields
        add_action('woocommerce_admin_field_jtl_date_field',
            ['JtlConnectorAdmin', 'jtl_date_field']
        );
        add_action('woocommerce_admin_field_paragraph',
            ['JtlConnectorAdmin', 'paragraph_field']
        );
        add_action('woocommerce_admin_field_connector_url',
            ['JtlConnectorAdmin', 'connector_url_field']
        );
        add_action('woocommerce_admin_field_connector_password',
            ['JtlConnectorAdmin', 'connector_password_field']
        );
        add_action('woocommerce_admin_field_active_true_false_radio',
            ['JtlConnectorAdmin', 'active_true_false_radio_btn']
        );
        add_action('woocommerce_admin_field_variation_name_select',
            ['JtlConnectorAdmin', 'variation_name_select']
        );
        add_action('woocommerce_admin_field_dev_log_btn',
            ['JtlConnectorAdmin', 'dev_log_btn']
        );
        add_action('woocommerce_admin_field_jtl_text_input',
            ['JtlConnectorAdmin', 'jtl_text_input']
        );
        add_action('woocommerce_admin_field_jtl_checkbox',
            ['JtlConnectorAdmin', 'jtl_checkbox']
        );
        add_action('woocommerce_admin_field_not_compatible_plugins_field',
            ['JtlConnectorAdmin', 'not_compatible_plugins_field']
        );
        add_action('woocommerce_admin_field_compatible_plugins_field',
            ['JtlConnectorAdmin', 'compatible_plugins_field']
        );
        
        //NEW PAGE
        add_action('admin_menu', 'woo_jtl_connector_add_admin_menu');
        add_action('admin_enqueue_scripts', 'woo_jtl_connector_loadCssAndJs');
        function woo_jtl_connector_add_admin_menu()
        {
            add_menu_page(
                __('JTL-Connector', JTLWCC_TEXT_DOMAIN),
                __('JTL-Connector', JTLWCC_TEXT_DOMAIN),
                'manage_woocommerce',
                'woo-jtl-connector',
                null,
                null,
                '55.5'
            );
            
            add_submenu_page(
                'woo-jtl-connector',
                __('JTL-Connector:Information', JTLWCC_TEXT_DOMAIN),
                __('Information', JTLWCC_TEXT_DOMAIN),
                'manage_woocommerce',
                'woo-jtl-connector-information',
                'woo_jtl_connector_information_page'
            );
            
            add_submenu_page(
                'woo-jtl-connector',
                __('JTL-Connector:Advanced', JTLWCC_TEXT_DOMAIN),
                __('Advanced Settings', JTLWCC_TEXT_DOMAIN),
                'manage_woocommerce',
                'woo-jtl-connector-advanced',
                'woo_jtl_connector_advanced_page'
            );
            
            add_submenu_page(
                'woo-jtl-connector',
                __('JTL-Connector:Delivery times', JTLWCC_TEXT_DOMAIN),
                __('Delivery times', JTLWCC_TEXT_DOMAIN),
                'manage_woocommerce',
                'woo-jtl-connector-delivery-time',
                'woo_jtl_connector_delivery_time_page'
            );
            
            add_submenu_page(
                'woo-jtl-connector',
                __('JTL-Connector:Customer orders', JTLWCC_TEXT_DOMAIN),
                __('Customer orders', JTLWCC_TEXT_DOMAIN),
                'manage_woocommerce',
                'woo-jtl-connector-customer-order',
                'woo_jtl_connector_customer_order_page'
            );
            
            add_submenu_page(
                'woo-jtl-connector',
                __('JTL-Connector:Developer Logging', JTLWCC_TEXT_DOMAIN),
                __('Developer Logging', JTLWCC_TEXT_DOMAIN),
                'manage_woocommerce',
                'woo-jtl-connector-developer-logging',
                'woo_jtl_connector_developer_logging_page'
            );
            
            remove_submenu_page('woo-jtl-connector', 'woo-jtl-connector');
        }
        
        function woo_jtl_connector_loadCssAndJs($hook)
        {
            // your-slug => The slug name to refer to this menu used in "add_submenu_page"
            // tools_page => refers to Tools top menu, so it's a Tools' sub-menu page
            if (!preg_match('/^jtl-connector_page_woo-/', $hook)) {
                return;
            }
            
            wp_enqueue_style('bootstrap4', 'https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css');
            wp_enqueue_style('custom-css-jtl', JTLWCC_CONNECTOR_DIR_URL . '/includes/css/custom.css');
            wp_enqueue_script('boot1', 'https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.bundle.min.js',
                ['jquery'], '', true);
            
        }
        
        function woo_jtl_connector_information_page()
        {
            JtlConnectorAdmin::displayPageNew('information_page', __('Connector information', JTLWCC_TEXT_DOMAIN));
        }
        
        function woo_jtl_connector_advanced_page()
        {
            JtlConnectorAdmin::displayPageNew('advanced_page', __('Advanced Settings', JTLWCC_TEXT_DOMAIN), true);
        }
        
        function woo_jtl_connector_delivery_time_page()
        {
            JtlConnectorAdmin::displayPageNew('delivery_time_page', __('Delivery time', JTLWCC_TEXT_DOMAIN), true);
        }
        
        function woo_jtl_connector_customer_order_page()
        {
            JtlConnectorAdmin::displayPageNew('customer_order_page', __('Customer order', JTLWCC_TEXT_DOMAIN), true);
        }
        
        function woo_jtl_connector_developer_logging_page()
        {
            JtlConnectorAdmin::displayPageNew('developer_logging_page', __('Developer Logging', JTLWCC_TEXT_DOMAIN),
                true);
        }
        
        self::update();
    }
    
    public static function jtlconnector_plugin_row_meta($links, $file)
    {
        if (strpos($file, 'woo-jtl-connector.php') !== false) {
            $url = esc_url('http://guide.jtl-software.de/jtl/Kategorie:JTL-Connector:WooCommerce');
            $new_links = [
                '<a target="_blank" href="' . $url . '">' . __('Documentation', JTLWCC_TEXT_DOMAIN) . '</a>',
            ];
            $links = array_merge($links, $new_links);
        }
        
        return $links;
    }
    
    // <editor-fold defaultstate="collapsed" desc="Settings">
    
    public static function displayPageNew($page, $title = 'Connector information', $submit = false)
    {
        $options = null;
        if (is_null($page)) {
            return;
        }
        
        self::validateAndPrepareConfig();
        
        switch ($page) {
            case 'information_page':
                $settings = apply_filters('woocommerce_settings_jtlconnector', self::getInformationFields());
                break;
            case 'advanced_page':
                $settings = apply_filters('woocommerce_settings_jtlconnector', self::getAdvancedFields());
                break;
            case 'delivery_time_page':
                $settings = apply_filters('woocommerce_settings_jtlconnector', self::getDeliveryTimeFields());
                break;
            case 'customer_order_page':
                $settings = apply_filters('woocommerce_settings_jtlconnector', self::getCustomerOrderFields());
                break;
            case'developer_logging_page':
                $settings = apply_filters('woocommerce_settings_jtlconnector', self::getDeveloperLoggingFields());
                break;
            default:
                $settings = null;
                break;
        }
        
        if (is_null($settings)) {
            return;
        }
        
        $options = apply_filters('woocommerce_get_settings_jtlconnector', $settings);
        
        ?>
        <div class="bootstrap-wrapper m-0 bg-light">
            <?php
            self::displayNanvigation($page);
            ?>
            <div class="container-fluid">
                <div class="row justify-content-center pb-4">
                    <form method="post"
                          id="mainform"
                          class="form-horizontal col-10 bg-light"
                          action="<?php echo esc_html(admin_url('admin-post.php')); ?>?action=settings_save_woo-jtl-connector"
                          enctype="multipart/form-data">
                        <div class="form-group row">
                            <h2 class="col-12"><?php print $title ?></h2>
                        </div>
                        <?php
                        print '' . woocommerce_admin_fields($options) . '';
                        if ($submit) {
                            ?>
                            <div class="form-group row">
                                <button type="submit" name="submit" id="submit" class="btn btn-outline-primary ml-3">
                                    Änderungen speichern
                                </button>
                            </div>
                            <?php
                        }
                        ?>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }
    
    private static function displayNanvigation($page)
    {
        ?>
        <div class="container-fluid mb-3 navbar navbar-dark bg-dark">
            <nav class="nav nav-pills nav-fill flex-column flex-sm-row " id="jtlNavbar">
                <a class="navbar-brand" href="https://guide.jtl-software.de/jtl-connector/woocommerce/" target="_blank">
                    <img src=" https://www.jtl-software.de/site/themes/jtlwebsite/assets/dist/images/logos/jtl-logo.svg"
                         width="30" height="30" class="d-inline-block align-top" alt="">
                    Connector
                </a>
                <a class="flex-sm-fill text-sm-center nav-link <?php if (strcmp($page, 'information_page') === 0) {
                    print 'active';
                } ?>"
                   href="admin.php?page=woo-jtl-connector-information"><?php print __('Information',
                        JTLWCC_TEXT_DOMAIN); ?></a>
                <a class="flex-sm-fill text-sm-center nav-link <?php if (strcmp($page, 'advanced_page') === 0) {
                    print 'active';
                } ?>"
                   href="admin.php?page=woo-jtl-connector-advanced"><?php print __('Advanced Settings',
                        JTLWCC_TEXT_DOMAIN); ?></a>
                <a class="flex-sm-fill text-sm-center nav-link <?php if (strcmp($page, 'delivery_time_page') === 0) {
                    print 'active';
                } ?>"
                   href="admin.php?page=woo-jtl-connector-delivery-time"><?php print __('Delivery times',
                        JTLWCC_TEXT_DOMAIN); ?></a>
                <a class="flex-sm-fill text-sm-center nav-link <?php if (strcmp($page, 'customer_order_page') === 0) {
                    print 'active';
                } ?>"
                   href="admin.php?page=woo-jtl-connector-customer-order"><?php print __('Customer orders',
                        JTLWCC_TEXT_DOMAIN); ?></a>
                <a class="flex-sm-fill text-sm-center nav-link <?php if (strcmp($page,
                        'developer_logging_page') === 0) {
                    print 'active';
                } ?>"
                   href="admin.php?page=woo-jtl-connector-developer-logging"><?php print __('Developer Logging',
                        JTLWCC_TEXT_DOMAIN); ?></a>
            </nav>
        </div>
        <?php
    }
    
    public static function settings_link($links = [])
    {
        $settings_link = '<a href="admin.php?page=woo-jtl-connector-information">' . __('Settings',
                JTLWCC_TEXT_DOMAIN) . '</a>';
        
        array_unshift($links, $settings_link);
        
        return $links;
    }
    
    private static function notCompatiblePluginsError()
    {
        //Show error if unsupported plugins are in use
        if (count(SupportedPlugins::getNotSupportedButActive()) > 0) {
            self::jtlwcc_show_wordpress_error(
                sprintf(
                    __('The listed plugins can cause problems when using the connector: %s', JTLWCC_TEXT_DOMAIN),
                    SupportedPlugins::getNotSupportedButActive(true)
                )
            );
        }
    }
    
    private static function getInformationFields()
    {
        $fields = [];
        
        self::notCompatiblePluginsError();
        
        //Add Information field
        $fields[] = [
            'type' => 'title',
            'desc' => __('With JTL-Connector for WooCommerce, you can connect your WooCommerce online shop with the free JTL-Wawi ERP system by JTL-Software. The ERP system as well as the entire JTL product family are perfectly suited to the requirements of e-commerce and mail order businesses. They help you to process more orders in a shorter time and offer a range of exciting functionalities. Basic information and credentials of the installed JTL-Connector. It is needed to configure the JTL-Connector in the jtl customer center and JTL-Wawi.',
                JTLWCC_TEXT_DOMAIN),
        ];
        
        //Add sectionend
        $fields[] = [
            'type' => 'sectionend',
        ];
        
        //Add connector url field
        $fields[] = [
            'title'     => 'Connector URL',
            'type'      => 'connector_url',
            'helpBlock' => __('This URL should be placed in the JTL-Customer-Center and in your JTL-Wawi as "Onlineshop-URL".',
                JTLWCC_TEXT_DOMAIN),
            'id'        => 'connector_url',
            'value'     => get_bloginfo('url') . '/index.php/jtlconnector/',
        ];
        
        //Add connector password field
        $fields[] = [
            'title'     => __('Connector Password', JTLWCC_TEXT_DOMAIN),
            'type'      => 'connector_password',
            'helpBlock' => __('This secret password will be used for identifying that your JTL-Wawi ist allowed to pull/push data.',
                JTLWCC_TEXT_DOMAIN),
            'id'        => 'connector_password',
            'value'     => get_option(JtlConnectorAdmin::OPTIONS_TOKEN),
        ];
        
        //Add connector version field
        $fields[] = [
            'title'     => 'Connector Version',
            'type'      => 'paragraph',
            'helpBlock' => __('This is your current installed connector version.', JTLWCC_TEXT_DOMAIN),
            'desc'      => Config::get('connector_version'),
        ];
        
        //Add sectionend
        $fields[] = [
            'type' => 'sectionend',
        ];
        
        //Add extend plugin informations
        if (count(SupportedPlugins::getSupported()) > 0) {
            $fields[] = [
                'title'   => __('These activated plugins extend the JTL-Connector:', JTLWCC_TEXT_DOMAIN),
                'type'    => 'compatible_plugins_field',
                'plugins' => SupportedPlugins::getSupported(),
            ];
        }
        
        //Add Incompatible plugin informations
        $fields[] = [
            'title'   => __('Incompatible with these plugins:', JTLWCC_TEXT_DOMAIN),
            'type'    => 'not_compatible_plugins_field',
            'plugins' => SupportedPlugins::getNotSupportedButActive(false, true, true),
        ];
        
        //Add sectionend
        $fields[] = [
            'type' => 'sectionend',
        ];
        
        return $fields;
    }
    
    private static function getAdvancedFields()
    {
        $fields = [];
        
        self::notCompatiblePluginsError();
        
        //Add Settings information field
        $fields[] = [
            'type' => 'title',
            'desc' => __('With JTL-Connector for WooCommerce, you can connect your WooCommerce online shop with the free JTL-Wawi ERP system by JTL-Software. These are the advanced settings of the installed JTL-Connector. Here you can configure how some data is handled while push/pull.',
                JTLWCC_TEXT_DOMAIN),
        ];
        
        //Add sectionend
        $fields[] = [
            'type' => 'sectionend',
        ];
        
        //Add variation specific radio field
        $fields[] = [
            'title'     => __('Variation specifics', JTLWCC_TEXT_DOMAIN),
            'type'      => 'active_true_false_radio',
            'desc'      => __('Enable if you want to show your customers the variation as specific (Default : Enabled).',
                JTLWCC_TEXT_DOMAIN),
            'id'        => self::OPTIONS_SHOW_VARIATION_SPECIFICS_ON_PRODUCT_PAGE,
            'value'     => Config::get(self::OPTIONS_SHOW_VARIATION_SPECIFICS_ON_PRODUCT_PAGE),
            'trueText'  => __('Enabled', JTLWCC_TEXT_DOMAIN),
            'falseText' => __('Disabled', JTLWCC_TEXT_DOMAIN),
        ];
        
        //Add custom properties radio field
        $fields[] = [
            'title'     => __('Custom properties', JTLWCC_TEXT_DOMAIN),
            'type'      => 'active_true_false_radio',
            'desc'      => __('Enable if you want to show your customers the custom properties as attribute (Default : Enabled).',
                JTLWCC_TEXT_DOMAIN),
            'id'        => self::OPTIONS_SEND_CUSTOM_PROPERTIES,
            'value'     => Config::get(self::OPTIONS_SEND_CUSTOM_PROPERTIES),
            'trueText'  => __('Enabled', JTLWCC_TEXT_DOMAIN),
            'falseText' => __('Disabled', JTLWCC_TEXT_DOMAIN),
        ];
        
        //Add gtin/ean radio field
        $fields[] = [
            'title'     => __('GTIN / EAN', JTLWCC_TEXT_DOMAIN),
            'type'      => 'active_true_false_radio',
            'desc'      => __('Enable if you want to use the GTIN field for ean. (Default : Enabled / Required plugin: WooCommerce Germanized).',
                JTLWCC_TEXT_DOMAIN),
            'id'        => self::OPTIONS_USE_GTIN_FOR_EAN,
            'value'     => Config::get(self::OPTIONS_USE_GTIN_FOR_EAN),
            'trueText'  => __('Enabled', JTLWCC_TEXT_DOMAIN),
            'falseText' => __('Disabled', JTLWCC_TEXT_DOMAIN),
        ];
        
        //Add variation select field
        $fields[] = [
            'title'     => __('Variation name format', JTLWCC_TEXT_DOMAIN),
            'type'      => 'variation_name_select',
            'id'        => self::OPTIONS_VARIATION_NAME_FORMAT,
            'value'     => Config::get(self::OPTIONS_VARIATION_NAME_FORMAT),
            'options'   => [
                ''                => __('Variation #22 of Product name', JTLWCC_TEXT_DOMAIN),
                'space'           => __('Variation #22 of Product name Color: black, Size: S', JTLWCC_TEXT_DOMAIN),
                'brackets'        => __('Variation #22 of Product name (Color: black, Size: S)',
                    JTLWCC_TEXT_DOMAIN),
                'space_parent'    => __('Product name Color: black, Size: S', JTLWCC_TEXT_DOMAIN),
                'brackets_parent' => __('Product name (Color: black, Size: S)', JTLWCC_TEXT_DOMAIN),
            ],
            'helpBlock' => __('Define how the child product name is formatted.', JTLWCC_TEXT_DOMAIN),
        ];
        
        //Add sectionend
        $fields[] = [
            'type' => 'sectionend',
        ];
        
        
        return $fields;
    }
    
    private static function getCustomerOrderFields()
    {
        $fields = [];
        
        self::notCompatiblePluginsError();
        
        //Add Settings information field
        $fields[] = [
            'type' => 'title',
            'desc' => __('With JTL-Connector for WooCommerce, you can connect your WooCommerce online shop with the free JTL-Wawi ERP system by JTL-Software. Customer order related settings of the installed JTL-Connector. Here you can set some options to modify the import of customer orders.',
                JTLWCC_TEXT_DOMAIN),
        ];
        
        //Add sectionend
        $fields[] = [
            'type' => 'sectionend',
        ];
        
        //Add variation specific radio field
        $fields[] = [
            'title'     => __('Pull completed orders', JTLWCC_TEXT_DOMAIN),
            'type'      => 'active_true_false_radio',
            'desc'      => __('Do not choose when having a large amount of data and low server specifications.',
                JTLWCC_TEXT_DOMAIN),
            'id'        => self::OPTIONS_COMPLETED_ORDERS,
            'value'     => Config::get(self::OPTIONS_COMPLETED_ORDERS),
            'trueText'  => __('Enabled', JTLWCC_TEXT_DOMAIN),
            'falseText' => __('Disabled', JTLWCC_TEXT_DOMAIN),
        ];
        //Add pull order since date field
        $fields[] = [
            'title'     => __('Pull orders since', JTLWCC_TEXT_DOMAIN),
            'type'      => 'jtl_date_field',
            // 'default'  => '2019-03-22',
            'value'     => Config::get(self::OPTIONS_PULL_ORDERS_SINCE),
            'helpBlock' => __('Define a start date for pulling of orders.', JTLWCC_TEXT_DOMAIN),
            'id'        => self::OPTIONS_PULL_ORDERS_SINCE,
        ];
        
        //Add sectionend
        $fields[] = [
            'type' => 'sectionend',
        ];
        
        
        return $fields;
    }
    
    private static function getDeliveryTimeFields()
    {
        $fields = [];
        
        self::notCompatiblePluginsError();
        
        //Add Settings information field
        $fields[] = [
            'type' => 'title',
            'desc' => __('With JTL-Connector for WooCommerce, you can connect your WooCommerce online shop with the free JTL-Wawi ERP system by JTL-Software. Delivery time related settings of the installed JTL-Connector. Here you can set some options to modify the pull/psuh of delivery times.',
                JTLWCC_TEXT_DOMAIN),
        ];
        
        //Add sectionend
        $fields[] = [
            'type' => 'sectionend',
        ];
        
        //Add delivery time calculation radio field
        $fields[] = [
            'title'     => __('DeliveryTime Calculation', JTLWCC_TEXT_DOMAIN),
            'type'      => 'active_true_false_radio',
            'desc'      => __('Enable if you want to use delivery time calculation. (Default : Enabled / Required plugin: WooCommerce Germanized).',
                JTLWCC_TEXT_DOMAIN),
            'id'        => self::OPTIONS_USE_DELIVERYTIME_CALC,
            'value'     => Config::get(self::OPTIONS_USE_DELIVERYTIME_CALC),
            'trueText'  => __('Enabled', JTLWCC_TEXT_DOMAIN),
            'falseText' => __('Disabled', JTLWCC_TEXT_DOMAIN),
        ];
        
        //Add dont use zero values radio field
        $fields[] = [
            'title'     => __('Dont use zero values for delivery time', JTLWCC_TEXT_DOMAIN),
            'type'      => 'active_true_false_radio',
            'desc'      => __('Enable if you dont want to use zero values for delivery time. (Default : Enabled).',
                JTLWCC_TEXT_DOMAIN),
            'id'        => self::OPTIONS_DISABLED_ZERO_DELIVERY_TIME,
            'value'     => Config::get(self::OPTIONS_DISABLED_ZERO_DELIVERY_TIME),
            'trueText'  => __('Enabled', JTLWCC_TEXT_DOMAIN),
            'falseText' => __('Disabled', JTLWCC_TEXT_DOMAIN),
        ];
        
        //Add prefix for delivery time textinput field
        $fields[] = [
            'title'     => __('Prefix for delivery time', JTLWCC_TEXT_DOMAIN),
            'type'      => 'jtl_text_input',
            'id'        => self::OPTIONS_PRAEFIX_DELIVERYTIME,
            'value'     => Config::get(self::OPTIONS_PRAEFIX_DELIVERYTIME),
            'helpBlock' => __("Define the prefix like" . PHP_EOL . "'ca. 4 Days'.", JTLWCC_TEXT_DOMAIN),
        ];
        
        //Add suffix for delivery time textinput field
        $fields[] = [
            'title'     => __('Suffix for delivery time', JTLWCC_TEXT_DOMAIN),
            'type'      => 'jtl_text_input',
            'id'        => self::OPTIONS_SUFFIX_DELIVERYTIME,
            'value'     => Config::get(self::OPTIONS_SUFFIX_DELIVERYTIME),
            'helpBlock' => __("Define the Suffix like" . PHP_EOL . "'ca. 4 work days'.", JTLWCC_TEXT_DOMAIN),
        ];
        
        //Add sectionend
        $fields[] = [
            'type' => 'sectionend',
        ];
        
        return $fields;
    }
    
    private static function getDeveloperLoggingFields()
    {
        $fields = [];
        
        self::notCompatiblePluginsError();
        
        //Add Settings information field
        $fields[] = [
            'type' => 'title',
            'desc' => __('With JTL-Connector for WooCommerce, you can connect your WooCommerce online shop with the free JTL-Wawi ERP system by JTL-Software. Developer logging related settings of the installed JTL-Connector. Here you can enable/disable/reset/download the developer logs of the jtl connector.',
                JTLWCC_TEXT_DOMAIN),
        ];
        
        //Add dev log radio field
        $fields[] = [
            'title'     => __('Dev-Logs', JTLWCC_TEXT_DOMAIN),
            'type'      => 'active_true_false_radio',
            'desc'      => __('Enable JTL-Connector dev-logs for debugging (Default : Disabled).',
                JTLWCC_TEXT_DOMAIN),
            'id'        => self::OPTIONS_DEVELOPER_LOGGING,
            'value'     => Config::get(self::OPTIONS_DEVELOPER_LOGGING),
            'trueText'  => __('Enabled', JTLWCC_TEXT_DOMAIN),
            'falseText' => __('Disabled', JTLWCC_TEXT_DOMAIN),
        ];
        
        //Add dev log buttons
        $fields[] = [
            'type'          => 'dev_log_btn',
            'downloadText'  => __('Download', JTLWCC_TEXT_DOMAIN),
            'clearLogsText' => __('Clear logs', JTLWCC_TEXT_DOMAIN),
        ];
        
        //Add sectionend
        $fields[] = [
            'type' => 'sectionend',
        ];
        
        
        return $fields;
    }
    
    // <editor-fold defaultstate="collapsed" desc="CustomOutputFields">
    public static function jtl_date_field(array $field)
    {
        $option_value = $field['default'];// get_option($field['id'], $field['default']);
        
        ?>

        <div class="form-group row">
            <label for="<?= $field['id'] ?>" class="col-12 col-form-label"><?= $field['title'] ?></label>
            <div class="col-12">
                <input class="form-control" type="date"
                       value="<?= isset($field['value']) && !is_null($field['value']) && $field['value'] !== '' ? $field['value'] : $option_value ?>"
                       id="<?= $field['id'] ?>"
                       name="<?= $field['id'] ?>">
            </div>
            <?php
            if (isset($field['helpBlock']) && $field['helpBlock'] !== '') {
                ?>
                <small id="<?= $field['id'] ?>_helpBlock" class="form-text text-muted col-12">
                    <?= $field['helpBlock'] ?>
                </small>
                <?php
            }
            ?>
        </div>
        <?php
    }
    
    public static function connector_password_field(array $field)
    {
        ?>
        <div class="form-group row">
            <label class="col-12" for="<?= $field['id'] ?>"><?= $field['title'] ?></label>
            <div class="input-group col-12">
                <input type="text"
                       class="form-control"
                       aria-label="Connector Password"
                       aria-describedby="<?= $field['id'] ?>_btn"
                       id="<?= $field['id'] ?>"
                       value="<?= $field['value'] ?>"
                       readonly="readonly">
                <div class="input-group-append">
                    <button class="btn btn-outline-secondary"
                            type="button"
                            title="Copy"
                            id="<?= $field['id'] ?>_btn"
                            onclick="
                                let text = document.getElementById('connector_password').value;
                                let dummy = document.createElement('textarea');
                                document.body.appendChild(dummy);
                                dummy.value = text;
                                dummy.select();
                                document.execCommand('copy');
                                document.body.removeChild(dummy);
                        ">Copy
                    </button>
                </div>
            </div>
            <?php
            if (isset($field['helpBlock']) && $field['helpBlock'] !== '') {
                ?>
                <small id="<?= $field['id'] ?>_helpBlock" class="form-text text-muted col-12">
                    <?= $field['helpBlock'] ?>
                </small>
                <?php
            }
            ?>
        </div>
        <?php
    }
    
    public static function connector_url_field(array $field)
    {
        ?>
        <div class="form-group row">
            <label class="col-12" for="<?= $field['id'] ?>"><?= $field['title'] ?></label>
            <div class="input-group col-12">
                <input type="text"
                       class="form-control"
                       aria-label="Connector URL"
                       aria-describedby="<?= $field['id'] ?>_btn"
                       id="<?= $field['id'] ?>"
                       value="<?= $field['value'] ?>"
                       readonly="readonly">
                <div class="input-group-append">
                    <button class="btn btn-outline-secondary"
                            type="button"
                            title="Copy"
                            id="<?= $field['id'] ?>_btn"
                            onclick="
                                let text = document.getElementById('connector_url').value;
                                let dummy = document.createElement('textarea');
                                document.body.appendChild(dummy);
                                dummy.value = text;
                                dummy.select();
                                document.execCommand('copy');
                                document.body.removeChild(dummy);
                        ">Copy
                    </button>
                </div>
            </div>
            <?php
            if (isset($field['helpBlock']) && $field['helpBlock'] !== '') {
                ?>
                <small id="<?= $field['id'] ?>_helpBlock" class="form-text text-muted col-12">
                    <?= $field['helpBlock'] ?>
                </small>
                <?php
            }
            ?>
        </div>
        <?php
    }
    
    public static function not_compatible_plugins_field(array $field)
    {
        ?>
        <div class="form-group row">
            <h2 class="col-12 mb-4"><?php print($field['title']); ?></h2>
            <ul class="list-group col-12 pl-3">
                <?php
                $change = false;
                if (count($field['plugins']) > 0) {
                    foreach ($field['plugins'] as $key => $value) {
                        ?>
                        <li class="list-group-item <?php $change ? print('list-group-item-light') : print(''); ?>"><?php print $value; ?></li> <?php
                        $change = !$change;
                    }
                }
                ?>
            </ul>
        </div>
        <?php
    }
    
    public static function compatible_plugins_field(array $field)
    {
        ?>
        <div class="form-group row">
            <h2 class="col-12 mb-4"><?php print($field['title']); ?></h2>
            <ul class="list-group col-12 pl-3">
                <?php
                $change = false;
                if (count($field['plugins']) > 0) {
                    foreach ($field['plugins'] as $key => $value) {
                        ?>
                        <li class="list-group-item <?php $change ? print('list-group-item-light') : print(''); ?>">
                            <?php print isset($value['Name']) && $value['Name'] !== '' ? $value['Name'] : $value['Name']; ?>
                            -
                            <?php print isset($value['Version']) && $value['Version'] !== '' ? $value['Version'] : $value['Version']; ?>
                            (<a target="_blank"
                                href="<?php print isset($value['AuthorURI']) && $value['AuthorURI'] !== '' ? $value['AuthorURI'] : '#'; ?>">
                                <?php print isset($value['Author']) && $value['Author'] !== '' ? $value['Author'] : $value['Author']; ?>
                            </a>)
                        </li>
                        <?php
                        $change = !$change;
                    }
                }
                ?>
            </ul>
        </div>
        <?php
    }
    
    public static function paragraph_field(array $field)
    {
        ?>
        <div class="form-group row">
            <label for="statictext_<?= wc_sanitize_taxonomy_name($field['title']) ?>"
                   class="col-12 col-form-label"><?= $field['title'] ?></label>
            <div class="col-12">
                <input type="text" readonly class="form-control-plaintext"
                       id="statictext_<?= wc_sanitize_taxonomy_name($field['title']) ?>" value="<?= $field['desc'] ?>">
                <?php
                if (isset($field['helpBlock']) && $field['helpBlock'] !== '') {
                    ?>
                    <small id="<?= $field['id'] ?>_helpBlock" class="form-text text-muted">
                        <?= $field['helpBlock'] ?>
                    </small>
                    <?php
                }
                ?>
            </div>
        </div>
        <?php
    }
    
    public static function active_true_false_radio_btn(array $field)
    {
        ?>
        <div class="form-group row">
            <label class="col-12" for="true_false_radio_<?= $field['id'] ?>"><?= $field['title'] ?></label>
            <div class="true_false_radio col-12 " name="true_false_radio_<?= $field['id'] ?>">
                <div class="custom-control custom-radio">
                    <input type="radio" id="<?= $field['id'] ?>_1" name="<?= $field['id'] ?>" value="true"
                           class="custom-control-input "
                        <?php if ($field['value']) {
                            print 'checked';
                        } ?>
                    >
                    <label class="custom-control-label <?php if ($field['value']) {
                        print 'active';
                    } ?>" for="<?= $field['id'] ?>_1"><?= $field['trueText'] ?></label>
                </div>
                <div class="custom-control custom-radio">
                    <input type="radio" id="<?= $field['id'] ?>_2" name="<?= $field['id'] ?>" value="false"
                           class="custom-control-input "
                        <?php if (!$field['value']) {
                            print 'checked="checked"';
                        } ?>
                    >
                    <label class="custom-control-label  <?php if (!$field['value']) {
                        print 'active';
                    } ?>" for="<?= $field['id'] ?>_2"><?= $field['falseText'] ?></label>
                </div>
            </div>
            <?php
            if (isset($field['desc']) && $field['desc'] !== '') {
                ?>
                <small id="<?= $field['id'] ?>_desc" class="form-text text-muted col-12">
                    <?= $field['desc'] ?>
                </small>
                <?php
            }
            ?>
        </div>
        <?php
    }
    
    public static function variation_name_select(array $field)
    {
        ?>
        <div class="form-group row">
            <label class="col-12" for="<?= $field['id'] ?>"><?= $field['title'] ?></label>
            <select class="form-control custom-select col-12 ml-3" name="<?= $field['id'] ?>">
                <?php
                if (isset($field['options']) && is_array($field['options']) && count($field['options']) > 0) {
                    foreach ($field['options'] as $key => $ovalue) {
                        ?>
                        <option value="<?php print $key; ?>" <?php if ($key === $field['value']) {
                            print 'selected';
                        } ?>><?php print $ovalue; ?></option> <?php
                    }
                }
                ?>
            </select>
            <?php
            if (isset($field['helpBlock']) && $field['helpBlock'] !== '') {
                ?>
                <small id="<?= $field['id'] ?>_helpBlock" class="form-text text-muted col-12">
                    <?= $field['helpBlock'] ?>
                </small>
                <?php
            }
            ?>
        </div>
        <?php
    }
    
    public static function dev_log_btn(array $field)
    {
        ?>
        <div class="form-group row">
            <div class="btn-group btn-group-lg col-12" role="group"
            ">
            <button type="button" id="downloadLogBtn"
                    class="btn btn-outline-success"><?= $field['downloadText'] ?></button>
            <button type="button" id="clearLogBtn"
                    class="btn btn-outline-danger"><?= $field['clearLogsText'] ?></button>
        </div>
        <?php
    }
    
    public static function jtl_text_input(array $field)
    {
        ?>
        <div class="form-group row">
            <label class="col-12" for="<?= $field['id'] ?>"><?= $field['title'] ?></label>
            <input
                    type="text"
                    class="form-control col-12 ml-3"
                    id="<?= $field['id'] ?>"
                    name="<?= $field['id'] ?>"
                    value="<?= $field['value'] ?>"
            >
            <?php
            if (isset($field['helpBlock']) && $field['helpBlock'] !== '') {
                ?>
                <small id="<?= $field['id'] ?>_helpBlock" class="form-text text-muted col-12">
                    <?= $field['helpBlock'] ?>
                </small>
                <?php
            }
            ?>
        </div>
        <?php
    }
    
    public static function jtl_checkbox(array $field)
    {
        ?>
        <div class="form-group row">
            <label class="col-12" for="<?= $field['id'] ?>"><?= $field['title'] ?></label>

            <input type="checkbox" class="form-control col-12" id="<?= $field['id'] ?>" name="<?= $field['id'] ?>"
                <?php if ($field['value']) {
                    print 'checked';
                } ?>>

            <textarea type="text" class="form-control" aria-label="Text input with checkbox"
                      readonly><?= $field['desc'] ?> </textarea>

        </div>
        <?php
    }
    
    // </editor-fold>
    
    /**
     * Save Settings
     */
    public static function save()
    {
        $settings = $_REQUEST;
        
        foreach ($settings as $key => $item) {
            $cast = self::JTLWCC_CONFIG[$key];
            
            switch ($cast) {
                case 'bool':
                    if (strcmp($item, 'on') === 0 || strcmp($item, 'true') === 0 || strcmp($item,
                            '1') === 0 || $item === true) {
                        $value = true;
                    } else {
                        $value = false;
                    }
                    break;
                case 'int':
                    $value = (int)$item;
                    break;
                case 'float':
                    $value = (float)$item;
                    break;
                default:
                    $value = trim($item);
                    break;
            }
            
            if (array_key_exists($key, self::JTLWCC_CONFIG)) {
                
                Config::set($key, $value);
                $option = get_option($key, null);
                
                if ($option !== $value || is_null($option)) {
                    update_option($key, $value);
                }
                
            }
            
        }
        $request = $_SERVER["HTTP_REFERER"];
        
        wp_redirect($request, 301);
        //WC_Admin_Settings::save_fields($settings);
    }
    
    /**
     * Validate and prepare config.json
     */
    private static function validateAndPrepareConfig()
    {
        $configFileValues = Config::getData();
        
        if (get_option(JtlConnectorAdmin::OPTIONS_TOKEN, false) === false) {
            add_option(self::OPTIONS_TOKEN, self::create_password());
        }
        
        if (!Config::has(JtlConnectorAdmin::OPTIONS_TOKEN)
            || Config::has(JtlConnectorAdmin::OPTIONS_TOKEN)
            && $configFileValues->connector_password !== get_option(JtlConnectorAdmin::OPTIONS_TOKEN)
        ) {
            
            Config::set(
                JtlConnectorAdmin::OPTIONS_TOKEN,
                get_option(JtlConnectorAdmin::OPTIONS_TOKEN)
            );
        }
        
        $version = trim(Yaml::parseFile(JTLWCC_CONNECTOR_DIR . '/build-config.yaml')['version']);
        
        if (!Config::has('connector_version') || Config::has('connector_version') && version_compare(
                $configFileValues->connector_version,
                $version,
                '!='
            )
        ) {
            Config::set(
                'connector_version',
                $version
            );
        }
        
        foreach (self::JTLWCC_CONFIG as $key => $value) {
            $option = get_option($key, null);
            
            if (Config::has($key) && is_null($option)) {
                add_option($key, $configFileValues->$key);
            }
            
            if (!Config::has($key) && !is_null($option) || Config::has($key) && !is_null($option)) {
                $cast = self::JTLWCC_CONFIG[$key];
                
                switch ($cast) {
                    case 'bool':
                        if (strcmp($option, 'on') === 0 || strcmp($option, 'true') === 0 || strcmp($option,
                                '1') === 0 || $option === true) {
                            $value = true;
                            update_option($key, true);
                        } else {
                            $value = false;
                            update_option($key, false);
                        }
                        break;
                    case 'int':
                        $value = (int)$option;
                        break;
                    case 'float':
                        $value = (float)$option;
                        break;
                    default:
                        $value = trim($option);
                        break;
                }
                
                Config::set($key, $value);
            }
            
            if (!Config::has($key) && is_null($option)) {
                add_option($key, self::JTLWCC_CONFIG_DEFAULTS[$key]);
                Config::set($key, self::JTLWCC_CONFIG_DEFAULTS[$key]);
            }
        }
    }
    // </editor-fold>
    
    // <editor-fold defaultstate="collapsed" desc="Update">
    private static function update()
    {
        $installed_version = \get_option(self::OPTIONS_INSTALLED_VERSION, '');
        $installed_version = version_compare($installed_version, '1.3.0', '<') ? '1.0' : $installed_version;
        
        switch ($installed_version) {
            case '1.0':
                self::update_to_multi_linking();
            case '1.3.0':
            case '1.3.1':
                self::update_multi_linking_endpoint_types();
            case '1.3.2':
            case '1.3.3':
            case '1.3.4':
            case '1.3.5':
            case '1.4.0':
            case '1.4.1':
            case '1.4.2':
            case '1.4.3':
            case '1.4.4':
            case '1.4.5':
            case '1.4.6':
            case '1.4.7':
            case '1.4.8':
            case '1.4.9':
            case '1.4.10':
            case '1.4.11':
            case '1.4.12':
            case '1.5.0':
                self::add_specifc_linking_tables();
            case '1.5.1':
            case '1.5.2':
            case '1.5.3':
            case '1.5.4':
            case '1.5.5':
            case '1.5.6':
            case '1.5.7':
            case '1.6.0':
                self::set_linking_table_name_prefix_correctly();
            case '1.6.1':
            case '1.6.2':
            case '1.6.3':
            case '1.6.4':
            case '1.7.0':
            case '1.7.1':
                self::add_manufacturer_linking_tables();
            case '1.7.2':
        }
        
        \update_option(self::OPTIONS_INSTALLED_VERSION,
            trim(Yaml::parseFile(JTLWCC_CONNECTOR_DIR . '/build-config.yaml')['version']));
    }
    // </editor-fold>
    
    // <editor-fold defaultstate="collapsed" desc="Update 1.3.0">
    private static function update_to_multi_linking()
    {
        global $wpdb;
        
        $query =
            'CREATE TABLE IF NOT EXISTS `%s` (
                `endpoint_id` varchar(255) NOT NULL,
                `host_id` INT(10) NOT NULL,
                PRIMARY KEY (`endpoint_id`, `host_id`),
                INDEX (`host_id`),
                INDEX (`endpoint_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci';
        
        $result = true;
        $wpdb->query('START TRANSACTION');
        
        $result = $result && $wpdb->query(sprintf($query, 'jtl_connector_link_category'));
        $result = $result && $wpdb->query(sprintf($query, 'jtl_connector_link_customer'));
        $result = $result && $wpdb->query(sprintf($query, 'jtl_connector_link_product'));
        $result = $result && $wpdb->query(sprintf($query, 'jtl_connector_link_image'));
        $result = $result && $wpdb->query(sprintf($query, 'jtl_connector_link_order'));
        $result = $result && $wpdb->query(sprintf($query, 'jtl_connector_link_payment'));
        $result = $result && $wpdb->query(sprintf($query, 'jtl_connector_link_crossselling'));
        
        $types = $wpdb->get_results('SELECT type FROM `jtl_connector_link` GROUP BY type');
        
        foreach ($types as $type) {
            $type = (int)$type->type;
            $tableName = self::get_table_name($type);
            $result = $result && $wpdb->query("
                INSERT INTO `{$tableName}` (`host_id`, `endpoint_id`)
                SELECT `host_id`, `endpoint_id` FROM `jtl_connector_link` WHERE `type` = {$type}
            ");
        }
        
        if ($result) {
            $wpdb->query('DROP TABLE IF EXISTS `jtl_connector_link`');
            $wpdb->query('COMMIT');
        } else {
            $wpdb->query('ROLLBACK');
            update_option(self::OPTIONS_UPDATE_FAILED, 'yes');
            add_action('admin_notices', 'update_failed');
        }
    }
    
    private static function get_table_name($type)
    {
        switch ($type) {
            case \jtl\Connector\Linker\IdentityLinker::TYPE_CATEGORY:
                return 'jtl_connector_link_category';
            case \jtl\Connector\Linker\IdentityLinker::TYPE_CUSTOMER:
                return 'jtl_connector_link_customer';
            case \jtl\Connector\Linker\IdentityLinker::TYPE_PRODUCT:
                return 'jtl_connector_link_product';
            case \jtl\Connector\Linker\IdentityLinker::TYPE_IMAGE:
                return 'jtl_connector_link_image';
            case \jtl\Connector\Linker\IdentityLinker::TYPE_CUSTOMER_ORDER:
                return 'jtl_connector_link_order';
            case \jtl\Connector\Linker\IdentityLinker::TYPE_PAYMENT:
                return 'jtl_connector_link_payment';
            case \jtl\Connector\Linker\IdentityLinker::TYPE_CROSSSELLING:
                return 'jtl_connector_link_crossselling';
        }
        
        return null;
    }
    // </editor-fold>
    
    // <editor-fold defaultstate="collapsed" desc="Update 1.3.2">
    private static function update_multi_linking_endpoint_types()
    {
        global $wpdb;
        
        // Modify varchar endpoint_id to integer
        $modifyEndpointType = 'ALTER TABLE `%s` MODIFY `endpoint_id` BIGINT(20) unsigned';
        $wpdb->query(sprintf($modifyEndpointType, 'jtl_connector_link_order'));
        $wpdb->query(sprintf($modifyEndpointType, 'jtl_connector_link_payment'));
        $wpdb->query(sprintf($modifyEndpointType, 'jtl_connector_link_product'));
        $wpdb->query(sprintf($modifyEndpointType, 'jtl_connector_link_crossselling'));
        $wpdb->query(sprintf($modifyEndpointType, 'jtl_connector_link_category'));
        
        // Add is_guest column for customers instead of using a prefix
        $wpdb->query('ALTER TABLE `jtl_connector_link_customer` ADD COLUMN `is_guest` BIT');
        $wpdb->query(sprintf('
            UPDATE `jtl_connector_link_customer` 
            SET `is_guest` = 1
            WHERE `endpoint_id` LIKE "%s_%%"',
            Id::GUEST_PREFIX
        ));
        $wpdb->query(sprintf('
            UPDATE `jtl_connector_link_customer` 
            SET `is_guest` = 0
            WHERE `endpoint_id` NOT LIKE "%s_%%"',
            Id::GUEST_PREFIX
        ));
        
        // Add type column for images instead of using a prefix
        $wpdb->query('ALTER TABLE `jtl_connector_link_image` ADD COLUMN `type` INT(4) unsigned');
        $updateImageLinkingTable = '
            UPDATE `jtl_connector_link_image` 
            SET `type` = %d, `endpoint_id` = SUBSTRING(`endpoint_id`, 3)
            WHERE `endpoint_id` LIKE "%s_%%"';
        $wpdb->query(sprintf($updateImageLinkingTable,
            \jtl\Connector\Linker\IdentityLinker::TYPE_CATEGORY,
            Id::CATEGORY_PREFIX
        ));
        $wpdb->query(sprintf($updateImageLinkingTable,
            \jtl\Connector\Linker\IdentityLinker::TYPE_PRODUCT,
            Id::PRODUCT_PREFIX
        ));
        
        self::add_constraints_for_multi_linking_tables();
    }
    
    private static function add_constraints_for_multi_linking_tables()
    {
        global $wpdb;
        
        $engine = $wpdb->get_var(sprintf("
            SELECT ENGINE
            FROM information_schema.TABLES
            WHERE TABLE_NAME = '{$wpdb->posts}' AND TABLE_SCHEMA = '%s'",
            DB_NAME
        ));
        
        if ($engine === 'InnoDB') {
            $wpdb->query("
                ALTER TABLE `jtl_connector_link_product`
                ADD CONSTRAINT `jtl_connector_link_product_1` FOREIGN KEY (`endpoint_id`) REFERENCES `{$wpdb->posts}` (`ID`) ON DELETE CASCADE ON UPDATE NO ACTION"
            );
            $wpdb->query("
                ALTER TABLE `jtl_connector_link_order`
                ADD CONSTRAINT `jtl_connector_link_order_1` FOREIGN KEY (`endpoint_id`) REFERENCES `{$wpdb->posts}` (`ID`) ON DELETE CASCADE ON UPDATE NO ACTION"
            );
            $wpdb->query("
                ALTER TABLE `jtl_connector_link_payment`
                ADD CONSTRAINT `jtl_connector_link_payment_1` FOREIGN KEY (`endpoint_id`) REFERENCES `{$wpdb->posts}` (`ID`) ON DELETE CASCADE ON UPDATE NO ACTION"
            );
            $wpdb->query("
                ALTER TABLE `jtl_connector_link_crossselling`
                ADD CONSTRAINT `jtl_connector_link_crossselling_1` FOREIGN KEY (`endpoint_id`) REFERENCES `{$wpdb->posts}` (`ID`) ON DELETE CASCADE ON UPDATE NO ACTION"
            );
        }
        $engine = $wpdb->get_var(sprintf("
            SELECT ENGINE
            FROM information_schema.TABLES
            WHERE TABLE_NAME = '{$wpdb->terms}' AND TABLE_SCHEMA = '%s'",
            DB_NAME
        ));
        
        if ($engine === 'InnoDB') {
            $wpdb->query("
                ALTER TABLE `jtl_connector_link_category`
                ADD CONSTRAINT `jtl_connector_link_category_1` FOREIGN KEY (`endpoint_id`) REFERENCES `{$wpdb->terms}` (`term_id`) ON DELETE CASCADE ON UPDATE NO ACTION"
            );
            
            $table = $wpdb->prefix . 'woocommerce_attribute_taxonomies';
            $wpdb->query("
                ALTER TABLE `jtl_connector_link_specific`
                ADD CONSTRAINT `jtl_connector_link_specific_1` FOREIGN KEY (`endpoint_id`) REFERENCES `{$table}` (`attribute_id`) ON DELETE CASCADE ON UPDATE NO ACTION"
            );
        }
    }
    
    // </editor-fold>
    
    // <editor-fold defaultstate="collapsed" desc="Update 1.5.0">
    private static function add_specifc_linking_tables()
    {
        global $wpdb;
        
        $query = '
            CREATE TABLE IF NOT EXISTS `%s` (
                `endpoint_id` BIGINT(20) unsigned NOT NULL,
                `host_id` INT(10) unsigned NOT NULL,
                PRIMARY KEY (`endpoint_id`, `host_id`),
                INDEX (`host_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci';
        
        $wpdb->query(sprintf($query, 'jtl_connector_link_specific'));
        $wpdb->query(sprintf($query, 'jtl_connector_link_specific_value'));
        
        $engine = $wpdb->get_var(sprintf("
            SELECT ENGINE
            FROM information_schema.TABLES
            WHERE TABLE_NAME = '{$wpdb->posts}' AND TABLE_SCHEMA = '%s'",
            DB_NAME
        ));
        
        if ($engine === 'InnoDB') {
            $wpdb->query("
                ALTER TABLE `jtl_connector_link_category`
                ADD CONSTRAINT `jtl_connector_link_category_1` FOREIGN KEY (`endpoint_id`) REFERENCES `{$wpdb->terms}` (`term_id`) ON DELETE CASCADE ON UPDATE NO ACTION"
            );
            
            $table = $wpdb->prefix . 'woocommerce_attribute_taxonomies';
            $wpdb->query("
                ALTER TABLE `jtl_connector_link_specific`
                ADD CONSTRAINT `jtl_connector_link_specific_1` FOREIGN KEY (`endpoint_id`) REFERENCES `{$table}` (`attribute_id`) ON DELETE CASCADE ON UPDATE NO ACTION"
            );
        }
    }
    // </editor-fold>
    
    // <editor-fold defaultstate="collapsed" desc="Update 1.6.0">
    private static function set_linking_table_name_prefix_correctly()
    {
        global $wpdb;
        
        $query = 'RENAME TABLE %s TO %s%s;';
        
        $tables = [
            'jtl_connector_category_level',
            'jtl_connector_link_category',
            'jtl_connector_link_crossselling',
            'jtl_connector_link_customer',
            'jtl_connector_link_image',
            'jtl_connector_link_order',
            'jtl_connector_link_payment',
            'jtl_connector_link_product',
            'jtl_connector_link_shipping_class',
            'jtl_connector_link_specific',
            'jtl_connector_link_specific_value',
            'jtl_connector_product_checksum',
        ];
        foreach ($tables as $table) {
            $sql = sprintf($query, $table, $wpdb->prefix, $table);
            $wpdb->query($sql);
        }
        
    }
    // </editor-fold>
    
    // <editor-fold defaultstate="collapsed" desc="Update 1.7.1">
    private static function add_manufacturer_linking_tables()
    {
        global $wpdb;
        
        $query = '
            CREATE TABLE IF NOT EXISTS `%s` (
                `endpoint_id` BIGINT(20) unsigned NOT NULL,
                `host_id` INT(10) unsigned NOT NULL,
                PRIMARY KEY (`endpoint_id`, `host_id`),
                INDEX (`host_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci';
        
        $wpdb->query(sprintf($query, $wpdb->prefix . 'jtl_connector_link_manufacturer'));
        
        $engine = $wpdb->get_var(sprintf("
            SELECT ENGINE
            FROM information_schema.TABLES
            WHERE TABLE_NAME = '{$wpdb->posts}' AND TABLE_SCHEMA = '%s'",
            DB_NAME
        ));
        
        if ($engine === 'InnoDB') {
            $wpdb->query("
              ALTER TABLE `{$wpdb->prefix}jtl_connector_link_manufacturer`
                ADD CONSTRAINT `jtl_connector_link_manufacturer_1` FOREIGN KEY (`endpoint_id`) REFERENCES `{$wpdb->terms}` (`term_id`) ON DELETE CASCADE ON UPDATE NO ACTION"
            );
        }
    }
    // </editor-fold>
    
    // <editor-fold defaultstate="collapsed" desc="Error messages">
    function update_failed()
    {
        self::jtlwcc_show_wordpress_error(__('The linking table migration was not successful. Please use the forum for help.',
            JTLWCC_TEXT_DOMAIN));
    }
    
    function directory_no_write_access()
    {
        self::jtlwcc_show_wordpress_error(sprintf(__('Directory %s has no write access.', sys_get_temp_dir()),
            JTLWCC_TEXT_DOMAIN));
    }
    
    function phar_extension()
    {
        self::jtlwcc_show_wordpress_error(__('PHP extension "phar" could not be found.', JTLWCC_TEXT_DOMAIN));
    }
    
    function suhosin_whitelist()
    {
        self::jtlwcc_show_wordpress_error(__('PHP extension "phar" is not on the suhosin whitelist.',
            JTLWCC_TEXT_DOMAIN));
    }
    
    public static function jtlwcc_show_wordpress_error($message)
    {
        echo '<div class="error"><p><b>JTL-Connector:</b>&nbsp;' . $message . '</p></div>';
    }
    // </editor-fold>
}