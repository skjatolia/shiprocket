<?php
/*
  Plugin Name: Shiprocket
  Description: Seamlessly integrate with Shiprocket which will help you ship across 26000 pincodes and and at the cheapest of rates. Let you customer choose the courier based on their flexibility.
  Version: 1.0.7
  Author: Shiprocket
  Author URI: https://shiprocket.in
  Copyright: Shiprocket
  Text Domain: shiprocket-woocommerce-shipping-calculator
  Requires at least: 3.0.0
  Tested up to: 6.0
 */


if (!defined('ABSPATH')) {
    exit;
}
ini_set( 'display_errors', 0 );

/*
 * Common Classes.
 */
if (!class_exists("Shiprocket_Shipping_Rates_Common")) {
    require_once 'class-shiprocket-shipping-rates-common.php';
}


register_activation_hook(__FILE__, function () {
    $woocommerce_status = Shiprocket_Shipping_Rates_Common::woocommerce_active_check(); // True if woocommerce is active.
    if ($woocommerce_status === false) {
        deactivate_plugins(basename(__FILE__));
        wp_die(__("Oops! You tried installing the plugin without activating woocommerce. Please install and activate woocommerce and then try again .", "shiprocket-woocommerce-shipping-calculator"), "", array('back_link' => 1));
    }
});

register_uninstall_hook(__FILE__, 'shiprocket_woocommerce_uninstall');

/**
 * Delete all settings data when uninstalled
 *
 * @return null
 */
function shiprocket_woocommerce_uninstall() {
    delete_option('woocommerce_shiprocket_woocommerce_shipping_settings');
}

;

/**
 * Shiprocket shipping calculator root directory path.
 */
if (!defined('SHIPROCKET_WC_RATE_PLUGIN_ROOT_DIR')) {
    define('SHIPROCKET_WC_RATE_PLUGIN_ROOT_DIR', __DIR__);
}

/**
 * Shiprocket Shipping Calculator root file.
 */
if (!defined('SHIPROCKET_WC_RATE_PLUGIN_ROOT_FILE')) {
    define('SHIPROCKET_WC_RATE_PLUGIN_ROOT_FILE', __FILE__);
}

/**
 * Shiprocket rates api.
 */
if (!defined("SHIPROCKET_WC_RATE_URL")) {
    define("SHIPROCKET_WC_RATE_URL", "https://apiv2.shiprocket.in/v1/external/woocommerce/courier/serviceability");
}

/**
 * Shiprocket rates api.
 */
if (!defined("SHIPROCKET_WC_OPEN_RATE_URL")) {
    define("SHIPROCKET_WC_OPEN_RATE_URL", "https://apiv2.shiprocket.in/v1/open/courier/serviceability");
}

if (!defined("SHIPROCKET_ACCESS_TOKEN")) {
    define("SHIPROCKET_ACCESS_TOKEN", "32e1024fc4e127f4d81a98c8a51cd1f4330b590f490de76380dd872426d564cc");
}

/**
 * Shiprocket account register api.
 */
if (!defined("SHIPROCKET_WC_ACCOUNT_REGISTER_ENDPOINT")) {
    define("SHIPROCKET_WC_ACCOUNT_REGISTER_ENDPOINT", "https://apiv2.shiprocket.in/v1/external/woocommerce/auth/register");
}

/**
 * Shiprocket account register api.
 */
if (!defined("SHIPROCKET_BULK_ACTION_URL")) {
    define("SHIPROCKET_BULK_ACTION_URL", "https://app.shiprocket.in/orders/processing?channel=wc&");
}

/**
 * WooCommerce Shipping Calculator.
 */
if (!class_exists("Shiprocket_Woocommerce_Shipping")) {

    /**
     * Shipping Calculator Class.
     */
    class Shiprocket_Woocommerce_Shipping {

        /**
         * Constructor
         */
        public function __construct() {
            // Handle links on plugin page
            add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'shiprocket_plugin_action_links'));
            // Initialize the shipping method
            add_action('woocommerce_shipping_init', array($this, 'shiprocket_woocommerce_shipping_init'));
            // Register the shipping method
            add_filter('woocommerce_shipping_methods', array($this, 'shiprocket_woocommerce_shipping_methods'));

            // Rebuild the cart of order update on change of chosen payment method
            add_action('woocommerce_checkout_update_order_review', function($posted_data) {
                // Parsing posted data on checkout
                $post = array();
                $vars = explode('&', $posted_data);
                foreach ($vars as $k => $value) {
                    $v = explode('=', urldecode($value));
                    $post[$v[0]] = $v[1];
                }
                
                WC()->session->set('chosen_payment_method', $post['payment_method']);

                foreach (WC()->cart->get_shipping_packages() as $package_key => $package) {
                    WC()->session->set('shipping_for_package_' . $package_key, false);
                }

                WC()->cart->calculate_shipping();
                WC()->cart->calculate_totals();

                return;
            });

            add_filter( 'woocommerce_package_rates', 'filter_wocommmerce_shipping_methods', 10, 2 );
            function filter_wocommmerce_shipping_methods( $rates, $package ) {
                $other_rate_present = 0;
                $other_rates = [];
                $shiprocket_method_present = 0;
                foreach($rates as $rate_key => $rate) {
                    if(preg_match('/^shiprocket_woocommerce_shipping/',$rate_key) || preg_match('/^shiprocket_woocommerce_shipping/',$rate->method_id)) {
                        if(!$shiprocket_method_present) $shiprocket_method_present = 1;
                    }else {
                        if(!$other_rate_present)    $other_rate_present = 1;
                        array_push($other_rates, $rate_key);
                    }
                }
                if($shiprocket_method_present && $other_rate_present) {
                    foreach($other_rates as $rate_key) {
                        unset($rates[$rate_key]);
                        continue;
                    } 
                }
                return $rates;
            }

        }

        /**
         * Plugin configuration.
         *
         * @return array
         */
        public static function shiprocket_plugin_configuration() {
            return array(
                'id' => 'shiprocket_woocommerce_shipping',
                'method_title' => __('Shiprocket App Configuration', 'shiprocket-woocommerce-shipping-calculator'),
                'method_description' => __("Get Shiprocket Courier rates for each order based on your shipping and customer pin code. Using this app you can display shiprocket’s courier serviceability and Estimated Date of Delivery(EDD) on your Product and Checkout page.By enabling this Shiprocket will update your Products and Checkout Page. \n*Please make sure all your products Weight (in Kg) and Dimensions (in cm) are updated on WooCommerce panel. The plugin wont work if Weight and Dimensions are not available.", 'shiprocket-woocommerce-shipping-calculator') . '<br/><br/>' . __('This plugin comes with a FREE Shiprocket.in account.')
            );
        }

        /**
         * Plugin action links on Plugin page.
         *
         * @param array $links available links
         *
         * @return array
         */
        public function shiprocket_plugin_action_links($links) {
            $plugin_links = array(
                '<a href="' . admin_url('admin.php?page=wc-settings&tab=shipping&section=shiprocket_woocommerce_shipping') . '">' . __('Settings', 'shiprocket-woocommerce-shipping-calculator') . '</a>',
                '<a href="https://support.shiprocket.in/solution/articles/43000526636-shiprocket-wordpress-app-help-document">' . __('Documentation', 'shiprocket-woocommerce-shipping-calculator') . '</a>',
                '<a href="https://app.shiprocket.in/register">' . __('Sign Up', 'shiprocket-woocommerce-shipping-calculator') . '</a>'
            );
            return array_merge($plugin_links, $links);
        }

        /**
         * Shipping Initialization.
         *
         * @return null
         */
        public function shiprocket_woocommerce_shipping_init() {
            if (!class_exists("Shiprocket_Woocommerce_Shipping_Method")) {
                require_once 'includes/class-shiprocket-woocommerce-shipping-method.php';
            }

            new Shiprocket_Woocommerce_Shipping_Method();
        }

        /**
         * Register Shipping Method to woocommerce.
         *
         * @param array $methods Available methods
         *
         * @return array
         */
        public function Shiprocket_Woocommerce_Shipping_Methods($methods) {
            $methods['shiprocket_woocommerce_shipping'] = 'Shiprocket_Woocommerce_Shipping_Method';
            return $methods;
        }

    }

    new shiprocket_woocommerce_shipping();
}

if (!class_exists('Shiprocket_Woocommerce_Api')) {
    require_once 'includes/api/class-shiprocket-woocommerce-api.php';
}
new Shiprocket_Woocommerce_Api();


add_filter('bulk_actions-edit-shop_order', 'Register_Sr_Bulk_actions');

/**
 * Settings link for Register Page
 *
 * @param array $bulk_actions available bulk actions
 *
 * @return array
 */
function Register_Sr_Bulk_actions($bulk_actions) {
    $bulk_actions['ship_with_shiprocket'] = __('Ship With Shiprocket', 'ship_with_shiprocket');
    return $bulk_actions;
}

add_action('admin_action_ship_with_shiprocket', 'Sr_Bulk_Process_Custom_action');

/**
 * Custom action for bulk actions in all orders page
 *
 * @return null
 */
function Sr_Bulk_Process_Custom_action() {

    // if an array with order IDs is not presented, exit the function
    if (!isset($_REQUEST['post']) && !is_array($_REQUEST['post'])) {
        return;
    }

    $redirect_url = SHIPROCKET_BULK_ACTION_URL . "shop=" . get_site_url();
    foreach ($_REQUEST['post'] as $order_id) {
        $redirect_url .= "&ids[]={$order_id}";
    }

    wp_redirect($redirect_url);
    exit;
}

add_action('woocommerce_single_product_summary', 'shiprocket_show_check_pincode', 20);

/**
 * Show an option to check serviceability to a pincode
 *
 * @return null
 */
function Shiprocket_Show_Check_pincode() {

    global $product;

    $settings = get_option('woocommerce_shiprocket_woocommerce_shipping_settings');

    if (!isset($settings['integration_id'])) {
        /* Notice For Seller Developer */
        // wc_add_notice( __("Integration Id is missing. Please contact the administrator"), "notice" );
        return true;
    }
    ?>
    <div>
        <input type="text" id="shiprocket_pincode_check" name="shiprocket_pincode_check" value="" placeholder="Enter Pincode">

        <button id="check_pincode" onClick="checkPincode_Shiprocket_Manual()"> Check Pincode </button>
    </div>
    <div id="pincode_response"></div>
    <script>
        function checkPincode_Shiprocket_Manual() {
            var pincode = document.getElementById("shiprocket_pincode_check").value;
            if (pincode == '') {
                jQuery('#pincode_response').text("This pincode field is required!")
            } else {
                var url = "<?php echo SHIPROCKET_WC_RATE_URL; ?>";

                url += "?weight=" + "<?php echo $product->get_weight(); ?>" + "&cod=1&delivery_postcode=" + pincode;

                url += "&store_url=" + "<?php echo get_site_url(); ?>";

                url += "&merchant_id=" + "<?php echo $settings['integration_id']; ?>";

                url += "&unit=" + "<?php echo get_option('woocommerce_weight_unit'); ?>";

                var token = 'ACCESS_TOKEN:' + '<?php echo SHIPROCKET_ACCESS_TOKEN; ?>';

                jQuery.ajax({
                    url: url,
                    headers: {'authorization': token},
                    success: function (response) {
                        if (response.status == 200) {
                            var recommeded_courier_id = response.data.recommended_courier_company_id;
                            var available_couriers = response.data.available_courier_companies;
                            var recommeded_courier = available_couriers.filter(c => c.courier_company_id == recommeded_courier_id);
                            if (recommeded_courier_id !== null && recommeded_courier_id !== '' && recommeded_courier_id !== undefined) {
                                var recommeded_courier = available_couriers.filter(c => c.courier_company_id == recommeded_courier_id);
                                var etd = recommeded_courier[0].etd;
                            } else {
                                var etd = available_couriers[0].etd;
                            }
                            var msg = `<span>You'll get your product by <strong>` + etd + `</strong> !</span>`;

                            jQuery('#pincode_response').html(msg);
                        } else {
                            jQuery('#pincode_response').text("This pincode is not serviceable!")
                        }
                    },
                    error: function (error) {
                        jQuery('#pincode_response').text("This pincode is not serviceable!")
                    }
                });
            }
        }

    </script>
    <?php
}