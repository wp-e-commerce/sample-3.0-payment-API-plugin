<?php
/**
 * This gateway should always extend WPSC_Payment_Gateway.
 *
 * Payment gateways must meet the following minimum criteria:
 *
 * 0) The filename MUST match the class. For example, this is file name is sample.php
 *    That means this class must be WPSC_Payment_Gateway_Sample.
 *    If we were to name this file 'test-gateway-example', we would need to name
 *    the class WPSC_Payment_Gateway_Test_Gateway_Example
 *
 * 1) A Constructor that sets the title, any support, and any settings.
 * 2) A process() method that processes the payment.
 * 3) A setup_form() method that sets up admin form fields.
 * 4) If checkout form fields are required - either payment_fields() method or
 *    declare default_credit_card_form support.
 *
 * And that's it! That's all you HAVE to have. Read the documentation below to
 * see you you can support everything else!
 */
class WPSC_Payment_Gateway_Sample extends WPSC_Payment_Gateway {

	/**
	 * Constructor of Sample Payment Gateway
	 *
	 * @access public
	 * @since 1.0.0
	 */
	public function __construct() {

		// Always call the parent constructor at the top of the child constructor.
		parent::__construct();

		// Set your title here.
		$this->title    = __( 'Sample Payment Gateway', 'wp-e-commerce' );

		/**
		 * Set your supports here. Current supports are as follows:
		 *
		 * {
		 *    tev1 : by default, payment gateways only support tev2 out of the box.
		 *           given that it will be some time before the majority of our users
		 *           are using tev2, it is advisable to make the effort to support tev1.
		 *
		 *    refunds : Provides a UI for refunds in the sales log page if support is provided
		 *              More information below at process_refund()
		 *
		 *    partial_refunds : Provides a UI for partial refunds in the sales
		 *                      log page if support is provided.
		 *
		 *    auth-capture : Provides a UI for capturing authorized payment.
		 *                   More information below in capture_payment() method.
		 *
		 *    default-credit-card-form : Provides a default credit card form.
		 *                               If not using, payment_fields() should
		 *                               contain credit card form fields.
		 * }
		 * @var array
		 */
		$this->supports = array( 'tev1', 'refunds', 'partial-refunds', 'auth-capture', 'default_credit_card_form' );

		/**
		 * Your constructor is a good place to define any properties that will be useful
		 * throughout your gateway. Often times this is secret keys, merchant IDs, etc.
		 *
		 * A good practice is to set this variables as private or protected properties above
		 * the constructor.
		 *
		 * @var [type]
		 */
		$this->account_number      = $this->setting->get( 'account_number' );
		$this->sandbox			   = $this->setting->get( 'sandbox_mode' ) == '1' ? true : false;
		$this->endpoint			   = $this->sandbox ? 'http://sandbox.sampleapi.com' : 'https://sampleapi.com';
		$this->payment_capture 	   = $this->setting->get( 'payment_capture' ) !== null ? $this->setting->get( 'payment_capture' ) : '';

		/**
		 * Finally, we'll talk about the init() method below as a good place for hooks.
		 * There are some hooks you may want to run even when the payment gateway is inactive
		 *
		 * Your constructor is a good place to execute those.
		 */
		$this->admin_scripts();
	}

	/**
	 * One recent integration needed the ability for the admin settings to be able
	 * to execute JavaScript triggers, even if the gateway was inactive, but settings
	 * were being set for it.  Because of that, this method was necessary for use
	 * in the constructor, rather than in init().
	 *
	 */
	public function admin_scripts() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
	}

	/**
	 * For all hooks that should be run any time the gateway is activated, your init()
	 * method is the place to be.
	 *
	 * @return [type] [description]
	 */
	public function init() {

		/**
		 * Always run the parent::init() function at the top. While WP eCommerce core
		 * does not currently execute anything here, it's very likely that it may in the
		 * future. Running this on any new integrations will ensure forwards compatibility.
		 */
		parent::init();

		/**
		 * Often times, payment gateways will require a JavaScript file to be loaded on the checkout page
		 * Examples of this include Stripe, Authorize.net (Accept.js) and PayPal Digital Goods.
		 *
		 * See the checkout_scripts() method (not required to be named as such) for an example of this.
		 */
		add_action( 'wp_enqueue_scripts'                  , array( $this, 'checkout_scripts' ) );

		/**
		 * New filters in 3.12.0 allow for easy addition of spinner feedback in the proper places.
		 * Especially useful when loading external iframes for hosted pages like sample or PayPal Pro Hosted.
		 */
		add_action( 'wpsc_gateway_v2_inside_gateway_label', array( $this, 'add_spinner' ) );
		add_filter( 'wpsc_form_input_append_to_label'     , array( $this, 'tev2_sample_spinner' ), 10, 2 );

		/**
		 * Tev1/tev2 (respectively) hooks for adding an iframe for a hosted solution.
		 * Solutions like Authorize.net DPM, sample Hosted, and PayPal Pro Hosted will use this.
		 */
		add_action( 'wpsc_inside_shopping_cart'             , array( $this, 'add_sample_iframe' ) );
		add_action( 'wpsc_get_form_output_after_form_fields', array( $this, 'add_sample_iframe' ) );

		// Any other hooks (AJAX, Cron, etc) can go in here as well.
	}

	/**
	 * A simple spinner. Notice, in tev2, this should be returned, not echoed.
	 *
	 * @param  [type] $label [description]
	 * @param  [type] $atts  [description]
	 * @return [type]        [description]
	 */
	public function tev2_sample_spinner( $label, $atts ) {
		$method  = isset( $atts['name'] )  && 'wpsc_payment_method' === $atts['name'];
		$value   = isset( $atts['value'] ) && 'sample' === $atts['value'];

		if ( $method && $value ) {
			ob_start();

			$this->add_spinner( 'sample' );
			$spinner = ob_get_clean();
			$label = $spinner . $label;
		}

		return $label;
	}

	public function add_sample_iframe( $r = '' ) {

		// While there a myriad ways to check if you are on a payment page in
		// the 1.0 and 2.0 theme engines, this is a simple approach.
		$is_tev2_payment_page = ! empty( $r ) && 'wpsc-checkout-form' === $r['id'] && 'payment' === _wpsc_get_current_controller_slug();
		$is_tev1_payment_page = empty( $r );

		if ( ! $is_tev1_payment_page && ! $is_tev2_payment_page ) {
			return;
		}
		?>
		<iframe scrolling="no"  id="sample_iframe" name="sample_iframe" class="sample-iframe"></iframe>
		<?php
	}

	/**
	 * You can use the load() method to return true or false, for the conditions
	 * under which the gateway should be available.
	 *
	 * Common examples would be curl_init() being available, SoapClient being available,
	 * or more user-specific values - like being located in the US, for example.
	 *
	 * @return [type] [description]
	 */
	public function load() {
		return 'USD' === wpsc_get_currency_code() && 'US' === wpsc_get_base_country();
	}

	/**
	 * A simple spinner for UI feedback. We've not standardized on any particular UI
	 * But this seems to be an easy enough one to use.
	 *
	 * @param [type] $gateway [description]
	 */
	public function add_spinner( $gateway ) {
		if ( 'sample' !== $gateway ) {
			return;
		}

		?>
		<div class="spinner"></div>
		<style>
		.spinner {
			background: url(<?php echo admin_url( 'images/spinner.gif' ) ?>) no-repeat;
			-webkit-background-size: 20px 20px;
			background-size: 20px 20px;
			display: inline-block;
			vertical-align: middle;
			opacity: .7;
			filter: alpha(opacity=70);
			width: 20px;
			height: 20px;
			margin: 4px 10px 0;
			display: none;
		}
		@media print, (-webkit-min-device-pixel-ratio: 1.25), (min-resolution: 120dpi) {
			.spinner {
				background-image: url(<?php echo admin_url( 'images/spinner-2x.gif' ) ?>);
			}
		}
		</style>
		<?php
	}

	/**
	 * A great place to enqueue any checkout scripts you may have.
	 *
	 * It wouldn't be surprising if, in the future we have a hook in our abstract
	 * class that could be hooked into for this. The likely scenario is that it
	 * could check for tev1 support, do the $is_cart checks that are happening here,
	 * and execute the hook within those conditions.
	 *
	 * @return [type] [description]
	 */
	public function checkout_scripts() {

		$is_cart = wpsc_is_theme_engine( '1.0' ) ? wpsc_is_checkout() : ( wpsc_is_checkout() || wpsc_is_cart() );

		if ( $is_cart ) {
			wp_enqueue_script( 'sample-js', WPSC_MERCHANT_V3_SDKS_URL . '/sample/js/sample-checkout.js', array( 'jquery' ), WPSC_VERSION );
			wp_localize_script( 'sample-js', 'WPSC_Sample_Checkout', array(
					'checkout_nonce' => wp_create_nonce( 'checkout_nonce' ),
					'ajaxurl'        => admin_url( 'admin-ajax.php', 'relative' ),
					'iframe_id'      => 'sample_iframe',
					'debug'          => WPSC_DEBUG,
				)
			);
		}
	}

	/**
	 * If you DO need to enqueue any admin scripts that should be available for
	 * your settings - even when the gateway is inactive - note the hook name.
	 * @param  [type] $hook [description]
	 * @return [type]       [description]
	 */
	public function enqueue_admin_scripts( $hook ) {

		if ( 'settings_page_wpsc-settings' !== $hook ) {
			return;
		}

		wp_enqueue_script( 'sample-admin-js', WPSC_MERCHANT_V3_SDKS_URL . '/sample/js/sample-admin.js', array( 'jquery' ), WPSC_VERSION, true );

	}

	/**
	 * Settings Form Template
	 *
	 * In the future, this may be converted to something more akin to CMB2, wherein
	 * you might these fields programatically in an array or an object.
	 *
	 * But for now, HTML is quite a sufficient rendering API for HTML.
	 *
	 * @since 3.12.0
	 */
	public function setup_form() {
	?>
		<!-- Account Credentials -->
		<tr>
			<td colspan="2">
				<h4><?php _e( 'Account Credentials', 'wp-e-commerce' ); ?></h4>
			</td>
		</tr>
		<tr>
			<td>
				<label for="wpsc-sample-merchant-profile-id"><?php _e( 'Merchant Profile ID', 'wp-e-commerce' ); ?></label>
			</td>
			<td>
				<input type="text" name="<?php echo esc_attr( $this->setting->get_field_name( 'merchant_profile_id' ) ); ?>" value="<?php echo esc_attr( $this->setting->get( 'merchant_profile_id' ) ); ?>" id="wpsc-sample-merchant-profile-id" />
				<?php if ( empty( $this->merchant_profile_id ) ) : ?>
				<div id="wpsc-sample-merchant-profile-create">
					<p><span class="small description"><?php _e( 'If you have not yet received a merchant profile ID, create one below.', 'wp-e-commerce' ); ?></span></p>
					<br /><a href="#" class="button-primary create-merchant-profile"><?php _e( 'Create Merchant Profile ID' ); ?></a><div class="spinner" style="float:none"></div>
				</div>
			<?php endif; ?>
			</td>
		</tr>
		<tr>
			<td>
				<label for="wpsc-sample-payment-capture"><?php _e( 'Payment Capture', 'wp-e-commerce' ); ?></label>
			</td>
			<td>
				<select id="wpsc-sample-payment-capture" name="<?php echo esc_attr( $this->setting->get_field_name( 'payment_capture' ) ); ?>">
					<option value='' <?php selected( '', $this->setting->get( 'payment_capture' ) ); ?>><?php _e( 'Authorize and capture the payment when the order is placed.', 'wp-e-commerce' )?></option>
					<option value='authorize' <?php selected( 'authorize', $this->setting->get( 'payment_capture' ) ); ?>><?php _e( 'Authorize the payment when the order is placed.', 'wp-e-commerce' )?></option>
				</select>
			</td>
		</tr>
		<tr>
			<td>
				<label><?php _e( 'Sandbox Mode', 'wp-e-commerce' ); ?></label>
			</td>
			<td>
				<label><input <?php checked( $this->setting->get( 'sandbox_mode' ) ); ?> type="radio" name="<?php echo esc_attr( $this->setting->get_field_name( 'sandbox_mode' ) ); ?>" value="1" /> <?php _e( 'Yes', 'wp-e-commerce' ); ?></label>&nbsp;&nbsp;&nbsp;
				<label><input <?php checked( (bool) $this->setting->get( 'sandbox_mode' ), false ); ?> type="radio" name="<?php echo esc_attr( $this->setting->get_field_name( 'sandbox_mode' ) ); ?>" value="0" /> <?php _e( 'No', 'wp-e-commerce' ); ?></label>
			</td>
		</tr>
		<!-- Error Logging -->
		<tr>
			<td colspan="2">
				<h4><?php _e( 'Error Logging', 'wp-e-commerce' ); ?></h4>
			</td>
		</tr>
		<tr>
			<td>
				<label><?php _e( 'Enable Debugging', 'wp-e-commerce' ); ?></label>
			</td>
			<td>
				<label><input <?php checked( $this->setting->get( 'debugging' ) ); ?> type="radio" name="<?php echo esc_attr( $this->setting->get_field_name( 'debugging' ) ); ?>" value="1" /> <?php _e( 'Yes', 'wp-e-commerce' ); ?></label>&nbsp;&nbsp;&nbsp;
				<label><input <?php checked( (bool) $this->setting->get( 'debugging' ), false ); ?> type="radio" name="<?php echo esc_attr( $this->setting->get_field_name( 'debugging' ) ); ?>" value="0" /> <?php _e( 'No', 'wp-e-commerce' ); ?></label>
			</td>
		</tr>
	<?php
	}

	/**
	 * If your gateway requires credit card fields to be output, and you're not declaring
	 * support for the default credit card form, this method must be set to render the fields.
	 *
	 * @return [type] [description]
	 */
	public function payment_fields() {}

	/**
	 * The process() method is where the transaction is actually processed.
	 *
	 * This is where you would capture any POSTed data from the checkout fields and
	 * send them to your gateways API, setting an order status based on the result.
	 *
	 * @return [type] [description]
	 */
	public function process() {

		$token          = sanitize_text_field( $_POST['token'] );
		$order          = $this->purchase_log;

		// One might do something like the following at this point:
		if ( $this->payment_capture ) {
			$result = API::capture_payment( $token, wpsc_cart_total() );
		} else {
			$result = API::authorize_payment( $token, wpsc_cart_total() );
		}

		switch ( $result->get_status() ) :
			case 'accepted' :
				$status = WPSC_Purchase_Log::ACCEPTED_PAYMENT;
				break;
			case 'pending' :
				$status = WPSC_Purchase_Log::ORDER_RECEIVED;
				break;
			case 'declined' :
				$status = WPSC_Purchase_Log::PAYMENT_DECLINED;
				break;
		endswitch;

		// You can save metadata, like tokens, and order object properties
		// (DB columns) in the same manner.
		// Take special care to save "transaction IDs" as `transactid`. Many
		// APIs rely on a transaction ID to be passed to different API calls, and
		// some methods coming up assume a transaction id saved in that column.
		$order->set( 'processed', $status )->save();
		$order->set( 'token', $token )->save();

		// This is necessary to be sent to the transaction results page.
		$this->go_to_transaction_results();
	}

	/**
	 * Only necessary if you support `auth-capture`. If you are unfamiliar with
	 * the idea of authorize and capture - you've almost certainly experienced it.
	 *
	 * Ever been to a gas station and they authorized your card for a dollar on
	 * what amounted to be a $24.72 fill-up? That's auth-capture.
	 *
	 * Sometimes, you need the ability to authorize funds now - and only capture them
	 * when you ship the product. That's auth-capture.
	 *
	 * Note: This method will be passed the value of the transaction ID `transactid`
	 * column. Most APIs provide this from the authorization, and expect it for the capture.
	 *
	 * @param  [type] $log            [description]
	 * @param  [type] $transaction_id [description]
	 * @return [type]                 [description]
	 */
	public function capture_payment( $log, $transaction_id ) {

		if ( $log->get( 'gateway' ) == 'sample' ) {


			$capture = API::capture_payment( $transaction_id );

			// This method allows you to return false, throw an Exception, or throw a WP_Error to indicate failure.
			if ( empty( $capture ) ) {
				throw new Exception( __( 'Could not generate a captured payment transaction ID.', 'wp-e-commerce' ) );
			}

			$log->set( 'processed', WPSC_Purchase_Log::ACCEPTED_PAYMENT )->save();
			$log->set( 'transactid', $results )->save();

			return true;
		}

		return false;
	}

	/**
	 * Only necessary if you support `refunds` `partial-refunds`. It is almost never
	 * the case that you will support only `partial-refunds`.
	 *
	 * In our experience, most gateways have API support for refunds, but fewer
	 * have the support for partial refunds, hence the separation. From a UI
	 * perspective, all support for partial-refunds does is gives the store owner
	 * a field to enter the refund amount.
	 *
	 * @param  WPSC_Purchase_Log $log
	 */
	public function process_refund( $log, $amount = 0.00, $reason = '', $manual = false ) {

		/**
		 * Like in capture_payment(), you can throw Exceptions, WP_Error objects, or return false.
		 */
		if ( 0.00 == $amount ) {
			return new WP_Error( 'sample_refund_error', __( 'Refund Error: You need to specify a refund amount.', 'wp-e-commerce' ) );
		}

		$log = wpsc_get_order( $log );

		// While most APIs require a transaction ID, perhaps not all do.
		if ( ! $log->get( 'transactid' ) ) {
			return new WP_Error( 'error', __( 'Refund Failed: No transaction ID', 'wp-e-commerce' ) );
		}

		$max_refund  = $log->get( 'totalprice' ) - $log->get_total_refunded();

		// Another best practive - make sure you're not refunding more than you should.
		// Nearly all gateways will have their own checks for this.
		if ( $amount && $max_refund < $amount || 0 > $amount ) {
			throw new Exception( __( 'Invalid refund amount', 'wp-e-commerce' ) );
		}

		// Some store owners may manually refund an order - this can be checked here.
		if ( $manual ) {
			$current_refund = $log->get_total_refunded();

			// Set a log meta entry, and save log before adding refund note.
			$log->set( 'total_order_refunded' , $amount + $current_refund )->save();

			// This creates an order note about the refund.
			$log->add_refund_note(
				sprintf( __( 'Refunded %s via Manual Refund', 'wp-e-commerce' ), wpsc_currency_display( $amount ) ),
				$reason
			);

			return true;
		}

		$transaction_id = $log->get( 'transactid' );

		// do API call
		$refund = API::refund( $transaction_id );

		if ( $refund ) {

			$current_refund = $log->get_total_refunded();

			// Set a log meta entry, and save log before adding refund note.
			$log->set( 'total_order_refunded' , $amount + $current_refund )->save();

			$log->add_refund_note(
				sprintf( __( 'Refunded %s - Refund ID: %s', 'wp-e-commerce' ), wpsc_currency_display( $refund->CurrencyConvertedAmount / 100 ), $refund->TransactionHistoryId ),
				$reason
			);

			return true;

		} else {
			return false;
		}
	}
}

/**
 * Imaginary API - in reality, you'll likely have your on SDK included.
 */
class API {
	public static function authorize_payment( $id, $amount ) {
		return true;
	}

	public static function capture_payment( $id ) {
		return true;
	}

	public static function refund( $id ) {
		return true;
	}
}
