<?php

/**
 * Payleo Mobile Payments Gateway.
 *
 * Provides a Payleo Mobile Payments Payment Gateway.
 *
 * @class       WC_Gateway_Payleo
 * @extends     WC_Payment_Gateway
 * @version     2.1.0
 * @package     WooCommerce/Classes/Payment
 */
class WC_Gateway_Payleo extends WC_Payment_Gateway {

	private $response;

	/**
	 * Constructor for the gateway.
	 */
	public function __construct() {
		// Setup general properties.
		$this->setup_properties();

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Get settings.
		$this->title              = $this->get_option( 'title' );
		$this->description        = $this->get_option( 'description' );
		$this->api_key            = $this->get_option( 'api_key' );
		$this->widget_id          = $this->get_option( 'widget_id' );
		$this->instructions       = $this->get_option( 'instructions' );
		$this->enable_for_methods = $this->get_option( 'enable_for_methods', array() );
		$this->enable_for_virtual = $this->get_option( 'enable_for_virtual', 'yes' ) === 'yes';

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
		add_filter( 'woocommerce_payment_complete_order_status', array( $this, 'change_payment_complete_order_status' ), 10, 3 );

		// Customer Emails.
		add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );

		//add_action( 'woocommerce_review_order_after_submit', 'show_modal_for_payment' );
		
		//add_action( 'woocommerce_custom_order_review', array( $this, 'custom_order_review' ) );
		//add_filter( 'woocommerce_order_button_html', 'custom_order_button_html');
		add_action( 'woocommerce_review_order_after_submit', array( $this, 'display_paypal_button' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
		//add_action( 'woocommerce_after_checkout_form', array( $this, 'send_before_payment' ) );
		add_action( 'woocommerce_review_order_after_submit', array( $this, 'recuperarUrlPaymentMethod' ) );

		//add_action('woocommerce_after_checkout_validation', 'after_checkout_otp_validation');

        //add_action('wp_enqueue_scripts', 'ttp_scripts');
        //add_action('woocommerce_before_checkout_form', 'ttp_wc_show_coupon_js', 10);
        //add_action('woocommerce_before_order_notes', 'ttp_wc_show_coupon', 10);
        add_action( 'woocommerce_checkout_after_order_review', array( $this, 'after_checkout_otp_validation' ) );
	}

	public function recuperarUrlPaymentMethod(){ //var_dump('$_POST', $_POST);exit;
	    if ($_POST) {
	        if ($_POST['post_data']) {
                $decode_url = parse_str(urldecode($_POST['post_data']), $content);
            }
        }

        //var_dump('POST',$content['billing_email'], $content['billing_phone'], $content['billing_document']);exit;

	    $products = array();
	    $totalMount    = 0;
        $currencyCode = get_option( 'woocommerce_currency' );
        foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
            $product = $cart_item['data'];
            $quantity = $cart_item['quantity'];
            $products [] = array($product->name,$product->price*$quantity);
            $totalMount += $product->price*$quantity;
        }
        /** send payment gateway RXTR**/
        $url = 'http://devkqtest.eastus2.cloudapp.azure.com/KXPaymentTR/HostedService.svc/CreateOrder';
        $credentials = "{6D8B0B7B-2953-41E1-A95F-AFA8795605A5}{FDACC33B-AE00-4DED-B208-D17BDEB85BC6}";
        $data = array(
            'Amount'                => $totalMount*100,
            'CurrencyCode'          => $currencyCode,
            'SystemTraceAuditNumber'=> 'XYTS20210301222658',
            'credentials'           => $credentials,
            'EmailShooper'          => $_POST ? ($_POST['post_data'] ? $content['billing_email'] : '') : '',
            'AdditionalData'        => $products,
            'IData'                 => array(
                'client_phone_number' => $_POST ? ($_POST['post_data'] ? $content['billing_phone'] : '') : '',
                'customer_document_number' => $_POST ? ($_POST['post_data'] ? $content['billing_document'] : '') : ''
            )

        );
        $json_data = json_encode($data);
        $s = curl_init();
        curl_setopt($s, CURLOPT_URL, $url);
        curl_setopt($s, CURLOPT_POST, true);
        curl_setopt($s, CURLOPT_POSTFIELDS, $json_data);
        curl_setopt($s, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($s, CURLOPT_CONNECTTIMEOUT, 20);
        curl_setopt($s, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
                'Content-Length: ' . strlen($json_data))
        );
        $_out = curl_exec($s);
        $status = curl_getinfo($s, CURLINFO_HTTP_CODE);
        if (!$status) {
            throw new Exception("No se pudo conectar con la Pasarela de Pago");
        }
        curl_close($s);

        $res = json_decode($_out);
        $this->response = $res;
        $params = array('payment_response' => $this->response);
        wp_localize_script( 'custom-ajax-requests', 'wc_settings', $params );
        /** send payment gateway RXTR**/
    }

    /********************************** modificar fields ********************************************/
    /*Coupon move checkout*/

    /**
     * Need the jQuery UI library for dialogs.
     **/
    function ttp_scripts() {
        wp_enqueue_script('jquery-ui-dialog');
    }

    /**
     * Processing before the checkout form to:
     * 1. Hide the existing Click here link at the top of the page.
     * 2. Instantiate the jQuery dialog with contents of
     *    form.checkout_coupon which is in checkout/form-coupon.php.
     * 3. Bind the Click here link to toggle the dialog.
     **/
    function ttp_wc_show_coupon_js() {
        /* Hide the Have a coupon? Click here to enter your code section
         * Note that this flashes when the page loads and then disappears.
         * Alternatively, you can add a filter on
         * woocommerce_checkout_coupon_message to remove the html. */
        wc_enqueue_js('$("a.showcoupon").parent().hide();');

        /* Use jQuery UI's dialog feature to load the coupon html code
         * into the anchor div. The width controls the width of the
         * modal dialog window. Check here for all the dialog options:
         * http://api.jqueryui.com/dialog/ */
        wc_enqueue_js('dialog = $("form.checkout_coupon").dialog({                                                      
                       autoOpen: false,                                                                             
                       width: 500,                                                                                  
                       minHeight: 0,                                                                                
                       modal: false,                                                                                
                       appendTo: "#coupon-anchor",                                                                  
                       position: { my: "left", at: "left", of: "#coupon-anchor"},                                   
                       draggable: false,                                                                            
                       resizable: false,                                                                            
                       dialogClass: "coupon-special",                                                               
                       closeText: "Close",                                                                          
                       buttons: {}});');

        /* Bind the Click here to enter coupon link to load the
         * jQuery dialog with the coupon code. Note that this
         * implementation is a toggle. Click on the link again
         * and the coupon field will be hidden. This works in
         * conjunction with the hidden close button in the
         * optional CSS in style.css shown below. */
        wc_enqueue_js('$("#show-coupon-form").click( function() {                                                       
                       if (dialog.dialog("isOpen")) {                                                               
                           $(".checkout_coupon").hide();                                                            
                           dialog.dialog( "close" );                                                                
                       } else {                                                                                     
                           $(".checkout_coupon").show();                                                            
                           dialog.dialog( "open" );                                                                 
                       }                                                                                            
                       return false;});');
    }


    /**
     * Show a coupon link before order notes section.
     * This is the 'coupon-anchor' div which the modal dialog
     * window will attach to.
     **/
    function ttp_wc_show_coupon() {
        global $woocommerce;

        if ($woocommerce->cart->needs_payment()) {
            echo '<p style="padding-bottom: 1.2em;"> Have a voucher? <a href="#" id="show-coupon-form">Click here to enter your code</a>.</p><div id="coupon-anchor"></div>';
        }
    }

	function after_checkout_otp_validation( $posted ) {
		// you can use wc_add_notice with a second parameter as "error" to stop the order from being placed
		var_dump('despues');
	}
	/********************************** modificar fields ********************************************/

	public function display_paypal_button(){

		wp_enqueue_script(  'custom-ajax-requests' );
		?>
		<!--<div id="woo_button_checkout"><input type="button" value="Pasarela de Pago"/></div>-->
		<?php
	}

	public function send_before_payment(){
        var_dump('enviado');
	}
	public function payment_scripts() {

        wp_enqueue_script('jquery-ui-dialog');
		wp_register_script( 'custom-ajax-requests', plugins_url( '../assets/js/custom-ajax-requests.js', __FILE__ ), array('jquery'), '1.0', true );
		wp_enqueue_script(  'jquery');
		wp_enqueue_script(  'custom-ajax-requests');
		//wp_localize_script( 'custom-ajax-requests', 'wc_ppec_context', $this->response );
	}

	function custom_order_button_html() {

		// The text of the button
		$order_button_text = __('Place order', 'woocommerce');

		// HERE your Javascript Event
		$js_event = "fbq('track', 'AddPaymentInfo');";

		// HERE you make changes (Replacing the code of the button):
		$button = '<input type="submit" onClick="'.$js_event.'" class="button alt" name="woocommerce_checkout_place_order" id="place_order" value="' . esc_attr( $order_button_text ) . '" data-value="' . esc_attr( $order_button_text ) . '" />';

		return $button;
	}

	/**
	 * Setup general properties for the gateway.
	 */
	protected function setup_properties() {
		$this->id                 = 'payleo';
		$this->icon               = apply_filters( 'woocommerce_payleo_icon', plugins_url('../assets/payment.jpg', __FILE__ ) );
		$this->method_title       = __( 'Payleo Mobile Payments', 'payleo-payments-woo' );
		$this->api_key            = __( 'Add API Key', 'payleo-payments-woo' );
		$this->widget_id          = __( 'Add Widget ID', 'payleo-payments-woo' );
		$this->method_description = __( 'Have your customers pay with Payleo Mobile Payments.', 'payleo-payments-woo' );
		$this->has_fields         = false;
	}

	/**
	 * Initialise Gateway Settings Form Fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'            => array(
				'title'       => __( 'Enable/Disable', 'payleo-payments-woo' ),
				'label'       => __( 'Enable Payleo Mobile Payments', 'payleo-payments-woo' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no',
			),
			'title'              => array(
				'title'       => __( 'Title', 'payleo-payments-woo' ),
				'type'        => 'text',
				'description' => __( 'Payleo Mobile Payment method description that the customer will see on your checkout.', 'payleo-payments-woo' ),
				'default'     => __( 'Payleo Mobile Payments', 'payleo-payments-woo' ),
				'desc_tip'    => true,
			),
			'api_key'             => array(
				'title'       => __( 'API Key', 'payleo-payments-woo' ),
				'type'        => 'text',
				'description' => __( 'Add your API key', 'payleo-payments-woo' ),
				'desc_tip'    => true,
			),
			'widget_id'           => array(
				'title'       => __( 'Widget ID', 'payleo-payments-woo' ),
				'type'        => 'text',
				'description' => __( 'Add your Widget key', 'payleo-payments-woo' ),
				'desc_tip'    => true,
			),
			'description'        => array(
				'title'       => __( 'Description', 'payleo-payments-woo' ),
				'type'        => 'textarea',
				'description' => __( 'Payleo Mobile Payment method description that the customer will see on your website.', 'payleo-payments-woo' ),
				'default'     => __( 'Pagos XRPaymentTR antes de la Entrega.', 'payleo-payments-woo' ),
				'desc_tip'    => true,
			),
			'instructions'       => array(
				'title'       => __( 'Instructions', 'payleo-payments-woo' ),
				'type'        => 'textarea',
				'description' => __( 'Instructions that will be added to the thank you page.', 'payleo-payments-woo' ),
				'default'     => __( 'Pagos XRPaymentTR antes de la Entrega.', 'payleo-payments-woo' ),
				'desc_tip'    => true,
			),
			'enable_for_methods' => array(
				'title'             => __( 'Enable for shipping methods', 'payleo-payments-woo' ),
				'type'              => 'multiselect',
				'class'             => 'wc-enhanced-select',
				'css'               => 'width: 400px;',
				'default'           => '',
				'description'       => __( 'If payleo is only available for certain methods, set it up here. Leave blank to enable for all methods.', 'payleo-payments-woo' ),
				'options'           => $this->load_shipping_method_options(),
				'desc_tip'          => true,
				'custom_attributes' => array(
					'data-placeholder' => __( 'Select shipping methods', 'payleo-payments-woo' ),
				),
			),
			'enable_for_virtual' => array(
				'title'   => __( 'Accept for virtual orders', 'payleo-payments-woo' ),
				'label'   => __( 'Accept payleo if the order is virtual', 'payleo-payments-woo' ),
				'type'    => 'checkbox',
				'default' => 'yes',
			),
		);
	}

	/**
	 * Check If The Gateway Is Available For Use.
	 *
	 * @return bool
	 */
	public function is_available() {
		$order          = null;
		$needs_shipping = false;

		// Test if shipping is needed first.
		if ( WC()->cart && WC()->cart->needs_shipping() ) {
			$needs_shipping = true;
		} elseif ( is_page( wc_get_page_id( 'checkout' ) ) && 0 < get_query_var( 'order-pay' ) ) {
			$order_id = absint( get_query_var( 'order-pay' ) );
			$order    = wc_get_order( $order_id );

			// Test if order needs shipping.
			if ( 0 < count( $order->get_items() ) ) {
				foreach ( $order->get_items() as $item ) {
					$_product = $item->get_product();
					if ( $_product && $_product->needs_shipping() ) {
						$needs_shipping = true;
						break;
					}
				}
			}
		}

		$needs_shipping = apply_filters( 'woocommerce_cart_needs_shipping', $needs_shipping );

		// Virtual order, with virtual disabled.
		if ( ! $this->enable_for_virtual && ! $needs_shipping ) {
			return false;
		}

		// Only apply if all packages are being shipped via chosen method, or order is virtual.
		if ( ! empty( $this->enable_for_methods ) && $needs_shipping ) {
			$order_shipping_items            = is_object( $order ) ? $order->get_shipping_methods() : false;
			$chosen_shipping_methods_session = WC()->session->get( 'chosen_shipping_methods' );

			if ( $order_shipping_items ) {
				$canonical_rate_ids = $this->get_canonical_order_shipping_item_rate_ids( $order_shipping_items );
			} else {
				$canonical_rate_ids = $this->get_canonical_package_rate_ids( $chosen_shipping_methods_session );
			}

			if ( ! count( $this->get_matching_rates( $canonical_rate_ids ) ) ) {
				return false;
			}
		}

		return parent::is_available();
	}

	/**
	 * Checks to see whether or not the admin settings are being accessed by the current request.
	 *
	 * @return bool
	 */
	private function is_accessing_settings() {
		if ( is_admin() ) {
			// phpcs:disable WordPress.Security.NonceVerification
			if ( ! isset( $_REQUEST['page'] ) || 'wc-settings' !== $_REQUEST['page'] ) {
				return false;
			}
			if ( ! isset( $_REQUEST['tab'] ) || 'checkout' !== $_REQUEST['tab'] ) {
				return false;
			}
			if ( ! isset( $_REQUEST['section'] ) || 'payleo' !== $_REQUEST['section'] ) {
				return false;
			}
			// phpcs:enable WordPress.Security.NonceVerification

			return true;
		}

		return false;
	}

	/**
	 * Loads all of the shipping method options for the enable_for_methods field.
	 *
	 * @return array
	 */
	private function load_shipping_method_options() {
		// Since this is expensive, we only want to do it if we're actually on the settings page.
		if ( ! $this->is_accessing_settings() ) {
			return array();
		}

		$data_store = WC_Data_Store::load( 'shipping-zone' );
		$raw_zones  = $data_store->get_zones();

		foreach ( $raw_zones as $raw_zone ) {
			$zones[] = new WC_Shipping_Zone( $raw_zone );
		}

		$zones[] = new WC_Shipping_Zone( 0 );

		$options = array();
		foreach ( WC()->shipping()->load_shipping_methods() as $method ) {

			$options[ $method->get_method_title() ] = array();

			// Translators: %1$s shipping method name.
			$options[ $method->get_method_title() ][ $method->id ] = sprintf( __( 'Any &quot;%1$s&quot; method', 'payleo-payments-woo' ), $method->get_method_title() );

			foreach ( $zones as $zone ) {

				$shipping_method_instances = $zone->get_shipping_methods();

				foreach ( $shipping_method_instances as $shipping_method_instance_id => $shipping_method_instance ) {

					if ( $shipping_method_instance->id !== $method->id ) {
						continue;
					}

					$option_id = $shipping_method_instance->get_rate_id();

					// Translators: %1$s shipping method title, %2$s shipping method id.
					$option_instance_title = sprintf( __( '%1$s (#%2$s)', 'payleo-payments-woo' ), $shipping_method_instance->get_title(), $shipping_method_instance_id );

					// Translators: %1$s zone name, %2$s shipping method instance name.
					$option_title = sprintf( __( '%1$s &ndash; %2$s', 'payleo-payments-woo' ), $zone->get_id() ? $zone->get_zone_name() : __( 'Other locations', 'payleo-payments-woo' ), $option_instance_title );

					$options[ $method->get_method_title() ][ $option_id ] = $option_title;
				}
			}
		}

		return $options;
	}

	/**
	 * Converts the chosen rate IDs generated by Shipping Methods to a canonical 'method_id:instance_id' format.
	 *
	 * @since  3.4.0
	 *
	 * @param  array $order_shipping_items  Array of WC_Order_Item_Shipping objects.
	 * @return array $canonical_rate_ids    Rate IDs in a canonical format.
	 */
	private function get_canonical_order_shipping_item_rate_ids( $order_shipping_items ) {

		$canonical_rate_ids = array();

		foreach ( $order_shipping_items as $order_shipping_item ) {
			$canonical_rate_ids[] = $order_shipping_item->get_method_id() . ':' . $order_shipping_item->get_instance_id();
		}

		return $canonical_rate_ids;
	}

	/**
	 * Converts the chosen rate IDs generated by Shipping Methods to a canonical 'method_id:instance_id' format.
	 *
	 * @since  3.4.0
	 *
	 * @param  array $chosen_package_rate_ids Rate IDs as generated by shipping methods. Can be anything if a shipping method doesn't honor WC conventions.
	 * @return array $canonical_rate_ids  Rate IDs in a canonical format.
	 */
	private function get_canonical_package_rate_ids( $chosen_package_rate_ids ) {

		$shipping_packages  = WC()->shipping()->get_packages();
		$canonical_rate_ids = array();

		if ( ! empty( $chosen_package_rate_ids ) && is_array( $chosen_package_rate_ids ) ) {
			foreach ( $chosen_package_rate_ids as $package_key => $chosen_package_rate_id ) {
				if ( ! empty( $shipping_packages[ $package_key ]['rates'][ $chosen_package_rate_id ] ) ) {
					$chosen_rate          = $shipping_packages[ $package_key ]['rates'][ $chosen_package_rate_id ];
					$canonical_rate_ids[] = $chosen_rate->get_method_id() . ':' . $chosen_rate->get_instance_id();
				}
			}
		}

		return $canonical_rate_ids;
	}

	/**
	 * Indicates whether a rate exists in an array of canonically-formatted rate IDs that activates this gateway.
	 *
	 * @since  3.4.0
	 *
	 * @param array $rate_ids Rate ids to check.
	 * @return boolean
	 */
	private function get_matching_rates( $rate_ids ) {
		// First, match entries in 'method_id:instance_id' format. Then, match entries in 'method_id' format by stripping off the instance ID from the candidates.
		return array_unique( array_merge( array_intersect( $this->enable_for_methods, $rate_ids ), array_intersect( $this->enable_for_methods, array_unique( array_map( 'wc_get_string_before_colon', $rate_ids ) ) ) ) );
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {//var_dump('process_payment', debug_backtrace(false));exit;
		$order = wc_get_order( $order_id );

		if ( $order->get_total() > 0 ) {
			$this->payleo_payment_processing( $order );
		}
	}

	private function payleo_payment_processing( $order ) {
//var_dump(WC()->cart->get_cart());exit;
		$total = intval( $order->get_total() );
        $currencyCode = get_option( 'woocommerce_currency' );

		$phone = esc_attr( $_POST['payment_number'] );
		$network_id = '1'; // mtn
		$reason = 'Test';
        $products = array();
        foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
            $product = $cart_item['data'];
            $quantity = $cart_item['quantity'];
            $products [] = array($product->name,$product->price*$quantity);
            //var_dump('product',$product->name, $product->price, $quantity);
        }//var_dump('$products',$products);exit;

		//$url = 'https://e.patasente.com/phantom-api/pay-with-patasente/' . $this->api_key . '/' . $this->widget_id . '?phone=' . $phone . '&amount=' . $total . '&mobile_money_company_id=' . $network_id . '&reason=' . 'Payment for Order: ' .$order_id;
		/** send payment gateway RXTR**/
        /*$url = 'http://devkqtest.eastus2.cloudapp.azure.com/KXPaymentTR/HostedService.svc/CreateOrder';
        $credentials = "{6D8B0B7B-2953-41E1-A95F-AFA8795605A5}{FDACC33B-AE00-4DED-B208-D17BDEB85BC6}";
        $data = array(
                'Amount'                => $total*100,
                'CurrencyCode'          => $currencyCode,
                'SystemTraceAuditNumber'=> 'XYTS20210301222658',
                'credentials'           => $credentials,
                'EmailShooper'          => $_POST['billing_email'],
                'AdditionalData'        => $products,
                'IData'                 => array('client_phone_number'=>$_POST['billing_phone'],'customer_document_number'=>$_POST['billing_document']),

            );
        $json_data = json_encode($data);
        $s = curl_init();
        curl_setopt($s, CURLOPT_URL, $url);
        curl_setopt($s, CURLOPT_POST, true);
        curl_setopt($s, CURLOPT_POSTFIELDS, $json_data);
        curl_setopt($s, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($s, CURLOPT_CONNECTTIMEOUT, 20);
        curl_setopt($s, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
                'Content-Length: ' . strlen($json_data))
        );
        $_out = curl_exec($s);
        $status = curl_getinfo($s, CURLINFO_HTTP_CODE);
        if (!$status) {
            throw new Exception("No se pudo conectar con la Pasarela de Pago");
        }
        curl_close($s);

        $res = json_decode($_out);
        $this->response = $res;*/
		/** send payment gateway RXTR**/

		//var_dump($url);

		//$response = wp_remote_post( $url, array( 'timeout' => 45 ) );

		/*if ( $res->message != null) {
			$error_message = $res->message;
			return "Algo salio Mal: $error_message";
		}*/

		/*if ( 200 !== $status ) {
			$order->update_status( apply_filters( 'woocommerce_payleo_process_payment_order_status', $order->has_downloadable_item() ? 'wc-invoiced' : 'processing', $order ), __( 'Payments pending.', 'payleo-payments-woo' ) );
		}*/
		//var_dump('respuesta', $res, $status,$res->message);exit;
		//if ( 200 === $status ) {

            $order->payment_complete();


			//wp_register_script( 'custom-ajax-requests', plugins_url( '../assets/js/custom-ajax-requests.js', __FILE__ ), array('jquery'), '1.0', true );
			//wp_localize_script( 'custom-ajax-requests', 'wc_ppec_context', $this->response );
			//do_action( 'woocommerce_review_order_after_submit');
            // Remove cart.
            //WC()->cart->empty_cart();
			//var_dump('respuesta', $this->get_return_url( $order ), $order);exit;
			//var_dump('respuesta', $status,$this->response->item->back_url);exit;
            // Return thankyou redirect.
            //$this->show_modal_for_payment();
            return array(
                'result'   => 'success',
                'redirect' => $this->get_return_url( $order ),
            );
			//var_dump('respuesta', $this->response->item->back_url, $this->get_return_url( $order ));
			//return $this->get_return_url( $order );

			/*echo json_encode(array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			));
			wp_redirect( $this->get_return_url( $order ),301);*/

		//}
	}

	function show_modal_for_payment(){
		//var_dump('show_modal_for_payment',$this->response);Exit;
		echo '
			<div class="modal" tabindex="2" role="dialog" id="modal-results">
				<div class="modal-dialog" role="document">
					<div class="modal-content">
						<div class="modal-body">
							<iframe id="iframe-container" class="iframe-container"
									src="'.$this->response->item->back_url.'"
									height="400" width="800">
							</iframe>
							<p id="message-error" style="display: none;"></p>
						</div>
						<div class="modal-footer">
							<button type="button" class="btn btn-primary" data-dismiss="modal">Aceptar</button>
						</div>
					</div>
				</div>
			</div>
		';
	}

	/**
	 * Output for the order received page.
	 */
	public function thankyou_page() {
		if ( $this->instructions ) {
			echo wp_kses_post( wpautop( wptexturize( $this->instructions ) ) );
		}
	}

	/**
	 * Change payment complete order status to completed for payleo orders.
	 *
	 * @since  3.1.0
	 * @param  string         $status Current order status.
	 * @param  int            $order_id Order ID.
	 * @param  WC_Order|false $order Order object.
	 * @return string
	 */
	public function change_payment_complete_order_status( $status, $order_id = 0, $order = false ) {
		if ( $order && 'payleo' === $order->get_payment_method() ) {
			$status = 'completed';
		}
		return $status;
	}

	/**
	 * Add content to the WC emails.
	 *
	 * @param WC_Order $order Order object.
	 * @param bool     $sent_to_admin  Sent to admin.
	 * @param bool     $plain_text Email format: plain text or HTML.
	 */
	public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
		if ( $this->instructions && ! $sent_to_admin && $this->id === $order->get_payment_method() ) {
			echo wp_kses_post( wpautop( wptexturize( $this->instructions ) ) . PHP_EOL );
		}
	}
}