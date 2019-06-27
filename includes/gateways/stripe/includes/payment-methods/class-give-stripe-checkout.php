<?php
/**
 * Give - Stripe Checkout
 *
 * @package    Give
 * @subpackage Stripe Core
 * @copyright  Copyright (c) 2019, GiveWP
 * @license    https://opensource.org/licenses/gpl-license GNU Public License
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check for Give_Stripe_Checkout existence.
 *
 * @since 2.5.1
 */
if ( ! class_exists( 'Give_Stripe_Checkout' ) ) {

	/**
	 * Class Give_Stripe_Checkout.
	 *
	 * @since 2.5.1
	 */
	class Give_Stripe_Checkout extends Give_Stripe_Gateway {

		/**
		 * Give_Stripe_Checkout constructor.
		 *
		 * @since  2.5.1
		 * @access public
		 */
		public function __construct() {

			$this->id = 'stripe_checkout';

			parent::__construct();

			// Remove CC fieldset.
			add_action( 'give_stripe_checkout_cc_form', '__return_false' );

			add_filter( 'give_payment_gateways', array( $this, 'register_gateway' ) );
		}

		/**
		 * Register the payment methods supported by Stripe.
		 *
		 * @access public
		 * @since  2.5.1
		 *
		 * @param array $gateways List of registered gateways.
		 *
		 * @return array
		 */
		public function register_gateway( $gateways ) {

			$gateways['stripe_checkout'] = array(
				'admin_label'    => __( 'Stripe Checkout', 'give' ),
				'checkout_label' => __( 'Credit Card', 'give' ),
			);

			return $gateways;
		}

		/**
		 * This function will be used for donation processing.
		 *
		 * @param array $donation_data List of donation data.
		 *
		 * @since  2.5.1
		 * @access public
		 *
		 * @return void
		 */
		public function process_payment( $donation_data ) {

			// Bailout, if the current gateway and the posted gateway mismatched.
			if ( $this->id !== $donation_data['post_data']['give-gateway'] ) {
				return;
			}

			// Make sure we don't have any left over errors present.
			give_clear_errors();

			// Any errors?
			$errors = give_get_errors();

			// No errors, proceed.
			if ( ! $errors ) {

				$form_id          = ! empty( $donation_data['post_data']['give-form-id'] ) ? intval( $donation_data['post_data']['give-form-id'] ) : 0;
				$price_id         = ! empty( $donation_data['post_data']['give-price-id'] ) ? $donation_data['post_data']['give-price-id'] : 0;
				$donor_email      = ! empty( $donation_data['post_data']['give_email'] ) ? $donation_data['post_data']['give_email'] : 0;
				$donation_summary = give_payment_gateway_donation_summary( $donation_data, false );

				// Get an existing Stripe customer or create a new Stripe Customer and attach the source to customer.
				$give_stripe_customer = new Give_Stripe_Customer( $donor_email );
				$stripe_customer_id   = $give_stripe_customer->get_id();

				// We have a Stripe customer, charge them.
				if ( $stripe_customer_id ) {

					// Setup the payment details.
					$payment_data = array(
						'price'           => $donation_data['price'],
						'give_form_title' => $donation_data['post_data']['give-form-title'],
						'give_form_id'    => $form_id,
						'give_price_id'   => $price_id,
						'date'            => $donation_data['date'],
						'user_email'      => $donation_data['user_email'],
						'purchase_key'    => $donation_data['purchase_key'],
						'currency'        => give_get_currency( $form_id ),
						'user_info'       => $donation_data['user_info'],
						'status'          => 'pending',
						'gateway'         => $this->id,
					);

					// Record the pending payment in Give.
					$donation_id = give_insert_payment( $payment_data );

					// Assign required data to array of donation data for future reference.
					$donation_data['donation_id'] = $donation_id;
					$donation_data['description'] = $donation_summary;
					$donation_data['customer_id'] = $stripe_customer_id;

					// Create Checkout Session.
					$session = $this->create_checkout_session( $donation_data );

// echo "<pre>"; print_r($session); die();
					// Save Stripe Customer ID to Donation note, Donor and Donation for future reference.
					give_insert_payment_note( $donation_id, 'Stripe Customer ID: ' . $stripe_customer_id );
					$this->save_stripe_customer_id( $stripe_customer_id, $donation_id );
					give_update_meta( $donation_id, '_give_stripe_customer_id', $stripe_customer_id );

					// Save donation summary to donation.
					give_update_meta( $donation_id, '_give_stripe_donation_summary', $donation_summary );
echo give_stripe_get_publishable_key(); die();
					?>
					<script>
						const stripe = Stripe(give_stripe_get_publishable_key());
						stripe.redirectToCheckout({
							// Make the id field from the Checkout Session creation API response
							// available to this file, so you can provide it as parameter here
							// instead of the {{CHECKOUT_SESSION_ID}} placeholder.
							sessionId: '<?php echo $session->id; ?>'
						}).then( ( result ) => {
							console.log(result);
							// If `redirectToCheckout` fails due to a browser or network
							// error, display the localized error message to your customer
							// using `result.error.message`.
						});
					</script>
					<?php

				}
			}

		}

		/**
		 * This function will create a checkout session to process payment.
		 *
		 * @param array $data Session Data.
		 *
		 * @since  2.5.1
		 * @access public
		 *
		 * @return \Checkout\Session|bool
		 */
		public function create_checkout_session( $data ) {

			try {

				$form_id   = ! empty( $data['post_data']['give-form-id'] ) ? intval( $data['post_data']['give-form-id'] ) : 0;
				$form_name = ! empty( $data['post_data']['give-form-title'] ) ? intval( $data['post_data']['give-form-title'] ) : false;
				$amount    = ! empty( $data['post_data']['give-amount'] ) ? $data['post_data']['give-amount'] : 0;

				$session = \Stripe\Checkout\Session::create(
					/**
					 * This filter will be used to modify create checkout arguments.
					 *
					 * @since 2.5.1
					 */
					apply_filters(
						'give_stripe_create_checkout_session_args',
						array(
							// 'customer_email'       => $data['user_email'],
							'customer'             => $data['customer_id'],
							'client_reference_id'  => $data['purchase_key'],
							'payment_method_types' => array( 'card' ),
							'line_items'           => array(
								array(
									'name'        => $form_name,
									'description' => $data['description'],
									'images'      => ['https://example.com/t-shirt.png'],
									'amount'      => give_stripe_dollars_to_cents( $amount ),
									'currency'    => give_get_currency( $form_id ),
									'quantity'    => 1,
								),
							),
							'submit_type'          => 'donate',
							'success_url'          => give_get_success_page_uri(),
							'cancel_url'           => give_get_failed_transaction_uri(),
						)
					)
				);

				return $session;
			} catch ( \Stripe\Error\Base $e ) {
				$this->log_error( $e );

			} catch ( Exception $e ) {

				give_record_gateway_error(
					__( 'Stripe Error', 'give' ),
					sprintf(
						/* translators: %s Exception Message Body */
						__( 'The Stripe Gateway returned an error while creating the Checkout Session. Details: %s', 'give' ),
						$e
					)
				);
				give_set_error( 'stripe_error', __( 'An occurred while processing the donation with the gateway. Please try your donation again.', 'give' ) );
				give_send_back_to_checkout( "?payment-mode={$this->id}" );
			}

			return false;
		}
	}
}

new Give_Stripe_Checkout();
