<?php

use jtl\Connector\Core\Exception\MissingRequirementException;
use jtl\Connector\Application\Application;
use jtl\Connector\Core\System\Check;
use JtlWooCommerceConnector\Utilities\Config;
use JtlWooCommerceConnector\Utilities\Id;
use JtlWooCommerceConnector\Utilities\SupportedPlugins;
use Symfony\Component\Yaml\Yaml;
use \WC_Admin_Settings as WC_Admin_Settings;

if ( ! defined('ABSPATH')) {
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
    const OPTIONS_TOKEN = 'jtlconnector_password';
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
    const OPTIONS_INSTALLED_VERSION = 'jtlconnector_installed_version';
    /**
     * The update to a new connector version failed.
     */
    const OPTIONS_UPDATE_FAILED = 'jtlconnector_update_failed';
    
    const OPTIONS_DEVELOPER_LOGGING = 'developer_logging';
    
    const OPTIONS_SHOW_VARIATION_SPECIFICS_ON_PRODUCT_PAGE = 'show_variation_specifics_on_product_page';
    
    const OPTIONS_SEND_CUSTOM_PROPERTIES = 'send_custom_properties';
    
    const OPTIONS_USE_GTIN_FOR_EAN = 'use_gtin_for_ean';
    
    const OPTIONS_USE_DELIVERYTIME_CALC = 'use_deliverytime_calc';
    const OPTIONS_DISABLED_ZERO_DELIVERY_TIME = 'disabled_zero_delivery_time';
    const OPTIONS_PRAEFIX_DELIVERYTIME = 'praefix_deliverytime';
    const OPTIONS_SUFFIX_DELIVERYTIME = 'suffix_deliverytime';
    
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
            add_option(self::OPTIONS_TOKEN, self::create_password());
            add_option(self::OPTIONS_COMPLETED_ORDERS, 'yes');
            add_option(self::OPTIONS_PULL_ORDERS_SINCE, '');
            add_option(self::OPTIONS_VARIATION_NAME_FORMAT, '');
            add_option(self::OPTIONS_INSTALLED_VERSION,
                trim(Yaml::parseFile(JTLWCC_CONNECTOR_DIR . '/build-config.yaml')['version']));
        } catch (\jtl\Connector\Core\Exception\MissingRequirementException $exc) {
            if (is_admin() && ( ! defined('DOING_AJAX') || ! DOING_AJAX)) {
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
        if ( ! extension_loaded('phar')) {
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
        if ( ! self::$initiated) {
            self::init_hooks();
        }
    }
    
    public static function init_hooks()
    {
        self::$initiated = true;
        
        add_filter('plugin_row_meta', ['JtlConnectorAdmin', 'jtlconnector_plugin_row_meta'], 10, 2);
        
        add_action('woocommerce_settings_tabs_array', ['JtlConnectorAdmin', 'add_settings_tab'], 50);
        add_action('woocommerce_settings_tabs_woo-jtl-connector', ['JtlConnectorAdmin', 'display_page'], 1);
        add_action('woocommerce_settings_save_woo-jtl-connector', ['JtlConnectorAdmin', 'save']);
        
        add_action('woocommerce_admin_field_date', ['JtlConnectorAdmin', 'date_field']);
        add_action('woocommerce_admin_field_paragraph', ['JtlConnectorAdmin', 'paragraph_field']);
        add_action('woocommerce_admin_field_connector_url', ['JtlConnectorAdmin', 'connector_url_field']);
        add_action('woocommerce_admin_field_connector_password', ['JtlConnectorAdmin', 'connector_password_field']);
        add_action('woocommerce_admin_field_active_true_false_radio',
            ['JtlConnectorAdmin', 'active_true_false_radio_btn']);
        add_action('woocommerce_admin_field_dev_log_btn', ['JtlConnectorAdmin', 'dev_log_btn']);
        add_action('woocommerce_admin_field_jtl_text_input', ['JtlConnectorAdmin', 'jtl_text_input']);
        
        self::update();
    }
    
    public static function jtlconnector_plugin_row_meta($links, $file)
    {
        if (strpos($file, 'woo-jtl-connector.php') !== false) {
            $url       = esc_url('http://guide.jtl-software.de/jtl/Kategorie:JTL-Connector:WooCommerce');
            $new_links = [
                '<a target="_blank" href="' . $url . '">' . __('Documentation', JTLWCC_TEXT_DOMAIN) . '</a>',
            ];
            $links     = array_merge($links, $new_links);
        }
        
        return $links;
    }
    
    // <editor-fold defaultstate="collapsed" desc="Settings">
    public static function add_settings_tab($tabs)
    {
        $tabs[JTLWCC_TEXT_DOMAIN] = 'JTL-Connector';
        
        return $tabs;
    }
    
    public static function settings_link($links = [])
    {
        $settings_link = '<a href="admin.php?page=wc-settings&tab=woo-jtl-connector">' . __('Settings',
                JTLWCC_TEXT_DOMAIN) . '</a>';
        
        array_unshift($links, $settings_link);
        
        return $links;
    }
    
    public static function display_page()
    {
        return '<div class="wrap woocommerce">' . woocommerce_admin_fields(JtlConnectorAdmin::get_settings()) . '</div>';
    }
    
    public static function get_settings()
    {
        self::validateAndPrepareConfig();
        
        $settings = apply_filters('woocommerce_settings_jtlconnector', self::getConfigFields());
        
        return apply_filters('woocommerce_get_settings_jtlconnector', $settings);
    }
    
    // <editor-fold defaultstate="collapsed" desc="CustomOutputFields">
    
    /**
     * @return array
     */
    
    private static function getConfigFields()
    {
        $fields = [];
        
        //Add Information field
        $fields[] = [
            'title' => __('Information', JTLWCC_TEXT_DOMAIN),
            'type'  => 'title',
            'desc'  => __('Basic information and credentials of the installed JTL-Connectors. It is needed to configure the JTL-Connector in the jtl customer center and JTL-Wawi.',
                JTLWCC_TEXT_DOMAIN),
        ];
        
        //Add connector url field
        $fields[] = [
            'title' => 'Connector URL',
            'type'  => 'connector_url',
            'id'    => 'connector_url',
            'value' => get_bloginfo('url') . '/index.php/jtlconnector/',
        ];
        
        //Add connector password field
        $fields[] = [
            'title' => __('Connector Password', JTLWCC_TEXT_DOMAIN),
            'type'  => 'connector_password',
            'id'    => 'connector_password',
            'value' => get_option(JtlConnectorAdmin::OPTIONS_TOKEN),
        ];
        
        //Add connector version field
        $fields[] = [
            'title' => 'Connector Version',
            'type'  => 'paragraph',
            'desc'  => Config::get('connector_version'),
        ];
        
        //Add sectionend
        $fields[] = [
            'type' => 'sectionend',
        ];
        
        //Add Incompatible plugin informations
        $fields[] = [
            'title' => __('Incompatible with these plugins:', JTLWCC_TEXT_DOMAIN),
            'type'  => 'title',
            'desc'  => SupportedPlugins::getNotSupportedButActive(true, true),
        ];
        
        //Show error if unsupported plugins are in use
        if (count(SupportedPlugins::getNotSupportedButActive()) > 0) {
            self::jtlwcc_show_wordpress_error(
                sprintf(
                    __('The listed plugins can cause problems when using the connector: %s', JTLWCC_TEXT_DOMAIN),
                    SupportedPlugins::getNotSupportedButActive(true)
                )
            );
        }
        
        //Add extend plugin informations
        if (count(SupportedPlugins::getSupported()) > 0) {
            
            $fields[] = [
                'title' => __('These activated plugins extend the JTL-Connector:', JTLWCC_TEXT_DOMAIN),
                'type'  => 'title',
                'desc'  => SupportedPlugins::getSupported(true),
            ];
        }
        
        //Add sectionend
        $fields[] = [
            'type' => 'sectionend',
        ];
        
        //Add Settings information field
        $fields[] = [
            'title' => __('Settings', JTLWCC_TEXT_DOMAIN),
            'type'  => 'title',
            'desc'  => __('Settings for the usage of the connector. By default the completed orders are pulled with no time limit.',
                JTLWCC_TEXT_DOMAIN),
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
            'title'    => __('Prefix for delivery time', JTLWCC_TEXT_DOMAIN),
            'type'     => 'jtl_text_input',
            'id'       => self::OPTIONS_PRAEFIX_DELIVERYTIME,
            'value'    => Config::get(self::OPTIONS_PRAEFIX_DELIVERYTIME),
            'desc_tip' => __("Define the prefix like" . PHP_EOL . "'ca. 4 Days'.", JTLWCC_TEXT_DOMAIN),
        ];
        
        //Add suffix for delivery time textinput field
        $fields[] = [
            'title'    => __('Suffix for delivery time', JTLWCC_TEXT_DOMAIN),
            'type'     => 'jtl_text_input',
            'id'       => self::OPTIONS_SUFFIX_DELIVERYTIME,
            'value'    => Config::get(self::OPTIONS_SUFFIX_DELIVERYTIME),
            'desc_tip' => __("Define the Suffix like" . PHP_EOL . "'ca. 4 work days'.", JTLWCC_TEXT_DOMAIN),
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
        
        //Add pull completed order checkbox field
        $fields[] = [
            'title' => __('Pull completed orders', JTLWCC_TEXT_DOMAIN),
            'type'  => 'checkbox',
            'desc'  => __('Do not choose when having a large amount of data and low server specifications.',
                JTLWCC_TEXT_DOMAIN),
            'id'    => self::OPTIONS_COMPLETED_ORDERS,
        ];
        
        //Add pull order since date field
        $fields[] = [
            'title'    => __('Pull orders since', JTLWCC_TEXT_DOMAIN),
            'type'     => 'date',
            'desc_tip' => __('Define a start date for pulling of orders.', JTLWCC_TEXT_DOMAIN),
            'id'       => self::OPTIONS_PULL_ORDERS_SINCE,
        ];
        
        //Add variation select field
        $fields[] = [
            'title'    => __('Variation name format', JTLWCC_TEXT_DOMAIN),
            'type'     => 'select',
            'class'    => 'wc-enhanced-select',
            'id'       => self::OPTIONS_VARIATION_NAME_FORMAT,
            'options'  => [
                ''                => __('Variation #22 of Product name', JTLWCC_TEXT_DOMAIN),
                'space'           => __('Variation #22 of Product name Color: black, Size: S', JTLWCC_TEXT_DOMAIN),
                'brackets'        => __('Variation #22 of Product name (Color: black, Size: S)',
                    JTLWCC_TEXT_DOMAIN),
                'space_parent'    => __('Product name Color: black, Size: S', JTLWCC_TEXT_DOMAIN),
                'brackets_parent' => __('Product name (Color: black, Size: S)', JTLWCC_TEXT_DOMAIN),
            ],
            'desc_tip' => __('Define how the child product name is formatted.', JTLWCC_TEXT_DOMAIN),
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
    
    /**
     * @param array $field
     */
    public static function date_field(array $field)
    {
        $option_value = get_option($field['id'], $field['default']);
        
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?= $field['id'] ?>"><?= $field['title'] ?></label>
                <span class="woocommerce-help-tip" data-tip="<?= $field['desc_tip'] ?>"></span>
            </th>
            <td class="forminp forminp-select">
                <input id="<?= $field['id'] ?>" name="<?= $field['id'] ?>" value="<?= $option_value ?>"
                       style="width:400px;margin:0;padding:6px;box-sizing:border-box" type="date">
                <span class="description"><?= $field['desc'] ?></span>
            </td>
        </tr>
        <?php
    }
    
    /**
     * Output a field with non editable content. With an copy btn
     *
     * @param array $field
     */
    public static function connector_password_field(array $field)
    {
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?= $field['id'] ?>"><?= $field['title'] ?></label>
            </th>
            <td class="connector-password">

                <div class="input-group">
                    <input class="form-control" type="text" id="<?= $field['id'] ?>" value="<?= $field['value'] ?>"
                           readonly="readonly">
                    <span class="input-group-btn">
                        <button type="button"
                                class="clip-btn btn btn-default button"
                                title="Copy"
                                onclick="
                                var text = document.getElementById('connector_password').value;
                                var dummy = document.createElement('textarea');
                                document.body.appendChild(dummy);
                                dummy.value = text;
                                dummy.select();
                                document.execCommand('copy');
                                document.body.removeChild(dummy);
                        ">Copy
                        </button>
                        </span>
                </div>
            </td>
        </tr>
        <?php
    }
    
    /**
     * Output a field with non editable content. With an copy btn
     *
     * @param array $field
     */
    public static function connector_url_field(array $field)
    {
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?= $field['id'] ?>"><?= $field['title'] ?></label>
            </th>
            <td class="connector-password">

                <div class="input-group">
                    <input class="form-control" type="text" id="<?= $field['id'] ?>" value="<?= $field['value'] ?>"
                           readonly="readonly">
                    <span class="input-group-btn">
                        <button type="button"
                                class="clip-btn btn btn-default button"
                                title="Copy"
                                onclick="
                                var text = document.getElementById('connector_url').value;
                                var dummy = document.createElement('textarea');
                                document.body.appendChild(dummy);
                                dummy.value = text;
                                dummy.select();
                                document.execCommand('copy');
                                document.body.removeChild(dummy);
                        ">Copy
                        </button>
                        </span>
                </div>
            </td>
        </tr>
        <?php
    }
    
    /**
     * Output a paragraph with non editable content.
     *
     * @param array $field The field information.
     */
    public static function paragraph_field(array $field)
    {
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label><?= $field['title'] ?></label>
            </th>
            <td>
                <p style="margin-top:0"><?= $field['desc'] ?></p>
            </td>
        </tr>
        <?php
    }
    
    /**
     * Output a radio btn for true false content.
     *
     * @param array $field
     */
    public static function active_true_false_radio_btn(array $field)
    {
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?= $field['id'] ?>"><?= $field['title'] ?></label>
            </th>
            <td>
                <p style="margin-top:0"><?= $field['desc'] ?></p>
            </td>
        </tr>
        <tr valign="top">
            <th scope="row" class="titledesc">

            </th>
            <td class="true_false_radio">
                <input type="radio" name="<?= $field['id'] ?>" value="true" <?php checked(true, $field['value'],
                    true); ?>><?= $field['trueText'] ?>
                <input type="radio" name="<?= $field['id'] ?>" value="false" <?php checked(false, $field['value'],
                    true); ?>><?= $field['falseText'] ?>
            </td>

        </tr>
        
        <?php
    }
    
    
    /**
     * Output Developer Log Buttons
     *
     * @param array $field
     */
    public static function dev_log_btn(array $field)
    {
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">

            </th>
            <td>
                <div class="btn-group" style="margin-top: 0px;">
                    <button type="button" id="downloadLogBtn"
                            class="btn btn-primary button"><?= $field['downloadText'] ?></button>
                    <button type="button" id="clearLogBtn"
                            class="btn btn-primary button"><?= $field['clearLogsText'] ?></button>
                </div>
            </td>
        </tr>
        <?php
    }
    
    /**
     * @param array $field
     */
    public static function jtl_text_input(array $field)
    {
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?= $field['id'] ?>"><?= $field['title'] ?></label>
                <span class="woocommerce-help-tip" data-tip="<?= $field['desc_tip'] ?>"></span>
            </th>
            <td>
                <input class="form-control"
                       type="text"
                       name="<?= $field['id'] ?>"
                       id="<?= $field['id'] ?>"
                       value="<?= $field['value'] ?>"
                >
            </td>
        </tr>
        <?php
    }
    // </editor-fold>
    
    /**
     * Save Settings
     */
    public static function save()
    {
        $settings     = self::get_settings();
        $configValues = [
            self::OPTIONS_DEVELOPER_LOGGING                        => 'bool',
            self::OPTIONS_SHOW_VARIATION_SPECIFICS_ON_PRODUCT_PAGE => 'bool',
            self::OPTIONS_SEND_CUSTOM_PROPERTIES                   => 'bool',
            self::OPTIONS_USE_GTIN_FOR_EAN                         => 'bool',
            self::OPTIONS_USE_DELIVERYTIME_CALC                    => 'bool',
            self::OPTIONS_DISABLED_ZERO_DELIVERY_TIME              => 'bool',
            self::OPTIONS_PRAEFIX_DELIVERYTIME                     => 'string',
            self::OPTIONS_SUFFIX_DELIVERYTIME                      => 'string',
        ];
        
        foreach ($configValues as $configValue => $type) {
            foreach ($settings as $key => $setting) {
                if (isset($setting['id']) && $setting['id'] === $configValue) {
                    unset($settings[$key]);
                }
            }
        }
        foreach ($_POST as $key => $item) {
            if (array_key_exists($key, $configValues)) {
                $cast = $configValues[$key];
                
                switch ($cast) {
                    case 'bool':
                        $value = 'true' === $item;
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
                
                Config::set($key, $value);
                unset($_POST[$key]);
            }
        }
        
        WC_Admin_Settings::save_fields($settings);
    }
    
    /**
     * Validate and prepare config.json
     */
    private static function validateAndPrepareConfig()
    {
        //UPADTE config.json with Plugin options
        if ( ! Config::has('connector_password')
             || Config::has('connector_password')
                && Config::get('connector_password') !== get_option(JtlConnectorAdmin::OPTIONS_TOKEN)
        ) {
            Config::set(
                'connector_password',
                get_option(JtlConnectorAdmin::OPTIONS_TOKEN)
            );
        }
        
        if ( ! Config::has('connector_version') || Config::has('connector_version') && version_compare(
                Config::get('connector_version'),
                trim(Yaml::parseFile(JTLWCC_CONNECTOR_DIR . '/build-config.yaml')['version']),
                '!='
            )
        ) {
            Config::set(
                'connector_version',
                Yaml::parseFile(JTLWCC_CONNECTOR_DIR . '/build-config.yaml')['version']
            );
        }
        
        if ( ! Config::has(self::OPTIONS_DEVELOPER_LOGGING)) {
            Config::set(
                self::OPTIONS_DEVELOPER_LOGGING,
                false
            );
        }
        
        if ( ! Config::has(self::OPTIONS_USE_GTIN_FOR_EAN)) {
            Config::set(
                self::OPTIONS_USE_GTIN_FOR_EAN,
                true
            );
        }
        
        if ( ! Config::has(self::OPTIONS_USE_DELIVERYTIME_CALC)) {
            Config::set(
                self::OPTIONS_USE_DELIVERYTIME_CALC,
                true
            );
        }
        
        if ( ! Config::has(self::OPTIONS_DISABLED_ZERO_DELIVERY_TIME)) {
            Config::set(
                self::OPTIONS_DISABLED_ZERO_DELIVERY_TIME,
                true
            );
        }
        
        if ( ! Config::has(self::OPTIONS_PRAEFIX_DELIVERYTIME)) {
            Config::set(
                self::OPTIONS_PRAEFIX_DELIVERYTIME,
                'ca.'
            );
        }
        
        if ( ! Config::has(self::OPTIONS_SUFFIX_DELIVERYTIME)) {
            Config::set(
                self::OPTIONS_SUFFIX_DELIVERYTIME,
                'Werktage'
            );
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
            case '1.6.3.1':
            case '1.6.3.2':
            case '1.6.3.3':
            case '1.6.4':
            case '1.7.0':
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
            $type      = (int)$type->type;
            $tableName = self::get_table_name($type);
            $result    = $result && $wpdb->query("
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