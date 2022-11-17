<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * File Constants
 */
define('SHIPROCKET_DOMAIN', 'shiprocket-woocommerce-shipping-calculator');
define('SHIPROCKET_URL', plugin_dir_url(__FILE__));  // Plugin URL
define('SHIPROCKET_DESC_SHRT', 'Get Shiprocket Courier rates for each order based on your shipping and customer pin code. Using this app you can display shiprocket’s courier serviceability and Estimated Date of Delivery(EDD) on your Product and Checkout page.By enabling this Shiprocket will update your Products and Checkout Page.');


/**
 * Shipping Method Class. Responsible for handling rates.
 */
if (!class_exists("Shiprocket_Woocommerce_Shipping_Method")) {

    class Shiprocket_Woocommerce_Shipping_Method extends WC_Shipping_Method {

        /**
         * Weight Unit.
         */
        public static $weight_unit;

        /**
         * Dimension Unit.
         */
        public static $dimension_unit;

        /**
         * Currency code.
         */
        public static $currency_code;

        /**
         * Integration Id.
         */
        public static $integration_id;

        /**
         * boolean true if debug mode is enabled.
         */
        public static $debug;

        /**
         * Shiprocket transaction id returned by Shiprocket Server.
         */
        public static $shiprocketTransactionId;

        /**
         * Fall back rate.
         */
        public static $fallback_rate;

        /**
         * Fall back rate.
         */
        public static $flat_rate;

        /**
         * Tax Calculation for Shipping rates.
         */
        public static $tax_calculation_mode;
        public static $cod;

        /**
         * Constructor.
         */
        public function __construct($instance_id = 0) {
            $plugin_configuration = Shiprocket_Woocommerce_Shipping::shiprocket_plugin_configuration();
            $this->id = $plugin_configuration['id'];
            $this->instance_id = absint($instance_id);
            $this->method_title = $plugin_configuration['method_title'];
            $this->method_description = SHIPROCKET_DESC_SHRT ?? $plugin_configuration['method_description'];
            $this->option_key = $this->id . '_shipping_method';
            $this->shiprocket_shipping_method_option = 'shiprocket_shipping_method_' . $this->instance_id;
            $this->shiprocket_shipping_methods_option = 'shiprocket_shipping_methods_' . $this->instance_id;
            $this->enabled            = "yes"; // This can be added as an setting but for this example its forced enabled
            $this->supports = array(
                'instance-settings',
                'instance-settings-modal',
                'settings'
               );
            $this->instance_form_fields = array(
	    	'custom_method_title' => array(
	            'title' => __('Custom Method title', 'shiprocket-woocommerce-shipping-calculator'),
	            'type' => 'text',
	            'default' => $plugin_configuration['method_title'],
	            'description' => __("Enter custom Method title (optional)", 'shiprocket-woocommerce-shipping-calculator'),
	            'desc_tip' => true,
	        ),
                'realtime_restriction_enabled' => array(
                    'title' 		=> __( 'Enable/Disable' ),
                    'type' 			=> 'checkbox',
                    'description' 	=> __( 'If Enabled with Realtime being on in Shiprocket settings, Realtime courier rate will be shown. Likewise, if this method is disabled. Realtime rates would not show for this zone inspite of being activated through shiprocket app configuration settings.' ),
                    'label' 		=> __( 'Disable Realtime Shipping for this zone' ),
                    'default' 		=> 'no',
                ),
			);
            $this->title              = $plugin_configuration['method_title'] ?? 'Shiprocket Shipping' ;
            // $this->countries = array(
            // 	'IN' // India
            // ); // Country restriction if needed - shipping zone countries
            $this->realtime_restriction_enabled = $this->get_option('realtime_restriction_enabled');
            $this->custom_method_title = $this->get_option('custom_method_title') ?? '';
            if(!empty($this->custom_method_title)) {
                $this->method_title = $this->custom_method_title;
            }
            $this->zones_settings = $this->id . 'zones_settings';
            $this->rates_settings = $this->id . 'rates_settings';
            $this->init();

            add_action('woocommerce_cart_calculate_fees', array($this, 'shipping_method_discount'));
            add_action('woocommerce_review_order_before_payment', array($this, 'shiprocket_update_shipping_charges'));
            // Save settings in admin
            add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
        }

        /**
         * Initialize the settings.
         */
        private function init() {
            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->method_title;
            $this->realtime_enabled = isset($this->settings['realtime_enabled']) ? $this->settings['realtime_enabled'] : 'no';
            // $this->realtime_restriction_enabled = isset($this->realtime_restriction_enabled) && ($this->realtime_restriction_enabled) == "yes" ? true : false;
            $this->zone_wise_enabled = isset($this->settings['zone_wise_enabled']) ? $this->settings['zone_wise_enabled'] : 'no';
            if($this->zone_wise_enabled == "yes") {
                array_push($this->supports, 'shipping-zones');
            }
            self::$integration_id = $this->settings['integration_id'] ? $this->settings['integration_id'] : null;
            self::$debug = false; // ( isset($this->settings['debug']) && $this->settings['debug']=='yes' ) ? true : false;
            self::$fallback_rate = !empty($this->settings['fallback_rate']) ? $this->settings['fallback_rate'] : 0;
            $this->shipping_title = !empty($this->settings['shipping_title']) ? $this->settings['shipping_title'] : false;
            self::$flat_rate = !empty($this->settings['flat_rate']) ? $this->settings['flat_rate'] : 0;
            $this->shipping_title_flat = !empty($this->settings['shipping_title_flat']) ? $this->settings['shipping_title_flat'] : false;
            self::$tax_calculation_mode = !empty($this->settings['tax_calculation_mode']) ? $this->settings['tax_calculation_mode'] : false;
        }

        /**
         * Settings Form fileds.
         */
        public function init_form_fields() {
            $this->form_fields = include('data-shiprocket-settings.php');
        }

        /**
         * Calculate shipping.
         */
            public function calculate_shipping($package = array()) {
            $shipping_zone = WC_Shipping_Zones::get_zone_matching_package( $package );
            $zone=$shipping_zone->get_zone_name();
            $zone_shipping_methods = $shipping_zone->get_shipping_methods();
            $woocommerce_shipping_methods_present = 0;
            foreach($zone_shipping_methods as $shipping_method) {
                if($shipping_method->enabled == "yes") {
                    if(in_array($shipping_method->id, ['flat_rate', 'free_shipping', 'local_pickup'])) {
                        $woocommerce_shipping_methods_present = 1;
                    }
                }
            }
            // Get Disabled Realtime Rate for current shipping Zone
            $disable_realtime_shipping_zone = WC_Shipping_Method::get_option('realtime_restriction_enabled');
            $zone_realtime_disabled = 0;
            if($disable_realtime_shipping_zone == "yes") {
                $zone_realtime_disabled = 1;
                self::debug(__('Shiprocket- Realtime Rates Disabled for zone '.$zone, 'shiprocket-woocommerce-shipping-calculator'));
            }
            
            if ($this->realtime_enabled == "yes") {

                if (empty(self::$integration_id)) {
                    self::debug(__('Shiprocket Integration Id Missing.', 'shiprocket-woocommerce-shipping-calculator'));
                    return;
                }

                $this->found_rates = array();

                if (empty(self::$weight_unit)) {
                    self::$weight_unit = get_option('woocommerce_weight_unit');
                }
                if (empty(self::$dimension_unit)) {
                    self::$dimension_unit = get_option('woocommerce_dimension_unit');
                }
                if (empty(self::$currency_code)) {
                    self::$currency_code = get_woocommerce_currency();
                }

                $formatted_package = self::get_formatted_data($package);

                // Required to get the debug info from api
                if (self::$debug) {
                    $data['isDebug'] = true;
                }
                
                if(!$zone_realtime_disabled) {
                    $response = $this->get_rates_from_server($formatted_package);
                    if(isset($response->status) && $response->status != 200) $response = false;
                    if ($response !== false) {
                        $this->process_result($response);
                    }
                }
                // Handle Fallback rates if no rates returned
                if(self::$fallback_rate && $this->shipping_title) {
                    if($this->zone_wise_enabled == "no") {
                        if (empty($this->found_rates)) {
                            $this->fallbackRateGenerator();
                        }
                    }
                    else {
                        if(!$woocommerce_shipping_methods_present && $zone_realtime_disabled || (!$zone_realtime_disabled && !$woocommerce_shipping_methods_present && empty($this->found_rates))) {
                            $this->fallbackRateGenerator();
                        }
                    }
                }      
            } else {
                if (self::$flat_rate && $this->shipping_title_flat) {
                    if($this->zone_wise_enabled == "no") {
                        $this->flatRateGenerator();
                    }
                    else {
                        if(!$woocommerce_shipping_methods_present) {
                            $this->flatRateGenerator();
                        }
                    }
                }
            }
            $this->add_found_rates();
        }

        /**
         * Fallback Rate Generator
         */
        private function fallbackRateGenerator() {
            $shipping_method_detail = new stdClass();
            $shipping_method_detail->ruleName = $this->shipping_title;
            $shipping_method_detail->displayName = $this->shipping_title;
            $shipping_method_detail->rate = self::$fallback_rate;
            $shipping_method_detail->ruleName = $this->shipping_title;
            $shipping_method_detail->ruleId = null;
            $shipping_method_detail->serviceId = null;
            $shipping_method_detail->etd = $this->shipping_title;
            $shipping_method_detail->carrierId = 'fallback_rate';
            $this->prepare_rate($shipping_method_detail);
        }

         /** Flat Rate Generator */
        private function flatRateGenerator() {
            $shipping_method_detail = new stdClass();
            $shipping_method_detail->ruleName = $this->shipping_title_flat;
            $shipping_method_detail->displayName = $this->shipping_title_flat;
            $shipping_method_detail->rate = self::$flat_rate;
            $shipping_method_detail->ruleName = $this->shipping_title_flat;
            $shipping_method_detail->ruleId = null;
            $shipping_method_detail->serviceId = null;
            $shipping_method_detail->etd = $this->shipping_title_flat;
            $shipping_method_detail->carrierId = 'flat_rate';
            $this->prepare_rate($shipping_method_detail);
        }

        /**
         * Get formatted data from woocommerce cart package.
         * @param $package array Package.
         * @return array Formatted package.
         */
        public static function get_formatted_data($package) {

            $l = $b = $h = $w = 0;

            foreach ($package['contents'] as $key => $line_item) {
                $quantity = $line_item['quantity'];
                $w += $line_item['data']->get_weight() * $quantity;
                $temp = array($line_item['data']->get_length(), $line_item['data']->get_width(), $line_item['data']->get_height());
                sort($temp);
                $h += empty($temp[0]) || !is_numeric($temp[0]) ? 0 : $temp[0];
                $l = max($l, empty($temp[1]) || !is_numeric($temp[1]) ? 0 : $temp[1]);
                $b = max($b, empty($temp[2]) || !is_numeric($temp[2]) ? 0 : $temp[2]);
            }

            // Convert weight into Kgs
            if (!empty(self::$weight_unit) && self::$weight_unit == 'grams') {
                $weight /= 1000;
            }

            // Convert dimensions into cm
            if (!empty(self::$dimension_unit) && self::$dimension_unit == 'inches') {
                $l *= 2.54;
                $b *= 2.54;
                $h *= 2.54;
            }

            $data_to_send = array('length' => $l, 'width' => $b, 'height' => $h, 'weight' => $w, 'declared_value' => $package['cart_subtotal']);

            $chosen_payment_method = WC()->session->get('chosen_payment_method');

            $data_to_send['cod'] = ($chosen_payment_method != "cod") ? '0' : '1';
            $data_to_send['currency'] = self::$currency_code;
            $data_to_send['declared_value'] = $package['cart_subtotal'];
            $data_to_send['delivery_postcode'] = $package['destination']['postcode'];
            $data_to_send['reference_id'] = uniqid();
            $data_to_send['merchant_id'] = self::$integration_id;

            WC()->session->set('ph_shiprocket_rates_unique_id', $data_to_send['reference_id']);
            return $data_to_send;
        }

        /**
         * Get the rates from Shiprocket Server.
         * @param $data string Encrypted data
         * @return
         */
        public function get_rates_from_server($data) {

            // Get the response from server.
            $response = wp_remote_get(
                    SHIPROCKET_WC_RATE_URL . '?' . http_build_query($data),
                    array(
                        'headers' => array(
                            'authorization' => "ACCESS_TOKEN:" . SHIPROCKET_ACCESS_TOKEN
                        ),
                        'timeout' => 20
                    )
            );

            // WP_error while getting the response
            if (is_wp_error($response)) {
                $error_string = $response->get_error_message();
                self::debug('Wordpreess Error: <a href="#" class="debug_reveal">Reveal</a><pre class="debug_info" style="background:#EEE;border:1px solid #DDD;padding:5px;">' . __('WP Error : ') . print_r($error_string, true) . '</pre>');
                return false;
            }

            // Successful response
            if ($response['response']['code'] == '200') {
                $body = $response['body'];
                $body = json_decode($body);
                return $body;
            } else {
                self::debug('Shiprocket Error: <a href="#" class="debug_reveal">Reveal</a><pre class="debug_info" style="background:#EEE;border:1px solid #DDD;padding:5px;">' . __('Error Code : ') . print_r($response['response']['code'], true) . '<br/>' . __('Error Message : ') . print_r($response['response']['message'], true) . '</pre>');
                return false;
            }
        }

        /**
         * Add debug info to the Front end.
         */
        public static function debug($message, $type = 'notice') {
            if (self::$debug) {
                wc_add_notice($message, $type);
            }
        }

        /**
         * Process the Response body received from server.
         */
        public function process_result($body) {
            if ($body->status == '200' && !empty($body->data)) {
                $json_decoded_data = $body->data;

                $available_courier_companies = $json_decoded_data->available_courier_companies;
                if (is_array($available_courier_companies)) {
                    $limit = 5;
                    foreach ($available_courier_companies as $couriers) {
                        if ($limit == 0) {
                            break;
                        }
                        self::prepare_rate($couriers);
                        $limit--;
                    }
                }
            }
        }

        /**
         * Prepare the rates.
         * @param $shipping_method_detail object Rate returned from API.
         */
        public function prepare_rate($shipping_method_detail) {
            // print(json_encode($shipping_method_detail));
            $rate_name = isset($shipping_method_detail->courier_name) ? $shipping_method_detail->courier_name : '';
            
            if (isset($shipping_method_detail->carrierId) && $shipping_method_detail->etd != '' && ($shipping_method_detail->carrierId == "fallback_rate" || $shipping_method_detail->carrierId == "flat_rate" )) {
                $rate_name .= $shipping_method_detail->etd;
            }else if($shipping_method_detail->etd != ''){
                $rate_name .= ' ( Delivery By ' . $shipping_method_detail->etd . ')';
            }

            if(isset($shipping_method_detail->courier_company_id)) {
                if (isset($shipping_method_detail->cod) && $shipping_method_detail->cod) {
                    $rate_id = $this->id . '_cod:' . $shipping_method_detail->courier_company_id;
                } else {
                    $rate_id = $this->id . '_prepaid:' . $shipping_method_detail->courier_company_id;
                }
            }

            $rate_cost = $shipping_method_detail->rate;

            $this->found_rates[$rate_id ?? 1] = array(
                'id' => $rate_id ?? 1,
                'label' => $rate_name,
                'cost' => $rate_cost,
                'taxes' => !empty(self::$tax_calculation_mode) ? '' : false,
                'calc_tax' => self::$tax_calculation_mode,
                'meta_data' => array(
                    'ph_shiprocket_shipping_rates' => array(
                        'courier_company_id' => $shipping_method_detail->courier_company_id ?? 0,
                        'uniqueId' => WC()->session->get('ph_shiprocket_rates_unique_id'),
                        'serviceId' => $shipping_method_detail->courier_name ?? '',
                        'carrierId' => $shipping_method_detail->courier_company_id ?? 0,
                        'shiprocketTransactionId' => self::$shiprocketTransactionId,
                    ),
                ),
            );
        }

        /**
         * Add found rates to woocommerce shipping rate.
         */
        public function add_found_rates() {
            if(!empty($this->found_rates)) {
                foreach ($this->found_rates as $key => $rate) {
                    $this->add_rate($rate);
                }
            }
        }

        public function shipping_method_discount($cart_object) {

            if (is_admin() && !defined('DOING_AJAX')) {
                return;
            }
        }

        public function shiprocket_update_shipping_charges() {
            // jQuery code
            ?>
            <script type="text/javascript">
                (function ($) {
                    $('form.checkout').on('change', 'input[name^="payment_method"]', function () {
                        $('body').trigger('update_checkout');
                    });
                })(jQuery);
            </script>
            <?php

        }

    }

}