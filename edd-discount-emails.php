<?php
/**
 * Plugin Name: EDD Discount Emails
 * Plugin URI: https://www.engagewp.com
 * Description: Set a list of emails to limit the usability of a discount code.
 * Version: 1.0
 * Author: Ren Ventura
 * Author URI: https://www.engagewp.com
 * Text Domain: edd-discount-emails
 * Domain Path: /languages/
 *
 * License: GPL 2.0+
 * License URI: http://www.opensource.org/licenses/gpl-license.php
 */

/*
	Copyright 2016  Ren Ventura  (email : mail@engagewp.com)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License, version 2, as
	published by the Free Software Foundation.

	Permission is hereby granted, free of charge, to any person obtaining a copy of this
	software and associated documentation files (the "Software"), to deal in the Software
	without restriction, including without limitation the rights to use, copy, modify, merge,
	publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons
	to whom the Software is furnished to do so, subject to the following conditions:

	The above copyright notice and this permission notice shall be included in all copies or
	substantial portions of the Software.

	THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
	IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
	FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
	AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
	LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
	OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
	THE SOFTWARE.
*/

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'EDD_Discount_Emails' ) ) :

class EDD_Discount_Emails {

	private static $instance;

	public static function instance() {

		if ( ! isset( self::$instance ) && ! ( self::$instance instanceof EDD_Discount_Emails ) ) {
			
			self::$instance = new EDD_Discount_Emails;

			self::$instance->constants();
			// self::$instance->includes();
			self::$instance->hooks();
		}

		return self::$instance;
	}

	/**
	 *	Constants
	 */
	public function constants() {

		// Plugin file
		if ( ! defined( 'EDD_EMAIL_DISCOUNTS_PLUGIN_FILE' ) ) {
			define( 'EDD_EMAIL_DISCOUNTS_PLUGIN_FILE', __FILE__ );
		}

		// Plugin basename
		if ( ! defined( 'EDD_EMAIL_DISCOUNTS_PLUGIN_BASENAME' ) ) {
			define( 'EDD_EMAIL_DISCOUNTS_PLUGIN_BASENAME', plugin_basename( EDD_EMAIL_DISCOUNTS_PLUGIN_FILE ) );
		}
	}

	/**
	 *	Include PHP files
	 */
	public function includes() {
		// None
	}

	/**
	 *	Action/filter hooks
	 */
	public function hooks() {

		register_activation_hook( EDD_EMAIL_DISCOUNTS_PLUGIN_FILE, array( $this, 'activate' ) );

		add_action( 'plugins_loaded', array( $this, 'loaded' ) );

		add_action( 'edd_add_discount_form_before_use_once', array( $this, 'add_email_input' ) );
		add_action( 'edd_edit_discount_form_before_use_once', array( $this, 'add_email_input' ) );

		add_filter( 'edd_insert_discount', array( $this, 'save_discount' ) );
		add_filter( 'edd_update_discount', array( $this, 'save_discount' ) );

		add_filter( 'edd_is_discount_valid', array( $this, 'verify_email' ), 10, 4 );

		add_filter( 'edd_ajax_discount_response', array( $this, 'invalid_response_ajax' ) );
	}

	/**
	 *	Plugin activation
	 */
	public function activate() {

		// Make sure EDD is active
		if ( ! $this->is_edd_active() ) {
			deactivate_plugins( EDD_EMAIL_DISCOUNTS_PLUGIN_FILE );
			wp_die( __( 'EDD Email Discounts requires Easy Digital Downloads to be active.', 'edd-discount-emails' ) );
		}
	}

	/**
	 *	Load text domain
	 */
	public function loaded() {
		load_plugin_textdomain( 'edd-discount-emails', false, trailingslashit( WP_LANG_DIR ) . 'plugins/' );
		load_plugin_textdomain( 'edd-discount-emails', false, trailingslashit( plugin_basename( dirname( __FILE__ ) ) ) . 'languages/' );
	}

	/**
	 *	Add the email input on the discount admin page
	 */
	public function add_email_input() {

		// Get the discount ID (for editing an existing discount only)
		$discount_id = isset( $_GET['discount'] ) ? intval( $_GET['discount'] ) : '';

		// Prepare emails for display
		$emails = get_post_meta( $discount_id, '_edd_discount_email_requirement', true );
		$emails = is_array( $emails ) ? $emails : array();
		$emails = implode( ', ', $emails );

		?>

		<tr>
			<th scope="row" valign="top">
				<label for="email_requirement"><?php _e( 'Email Requirement', 'edd-discount-emails' ); ?></label>
			</th>
			<td>
				<input name="email_requirement" id="email_requirement" class="large-text" type="text" value="<?php esc_attr_e( $emails ); ?>" />
				<p class="description"><?php _e( 'Limit this discount to a specific email, or a list of emails (each must be separated by a comma).', 'edd-discount-emails' ); ?></p>
			</td>
		</tr>
	
	<?php }

	/**
	 *	Make sure the email requirement is saved to the discount
	 */
	public function save_discount( $meta ) {

		if ( isset( $_POST['email_requirement'] ) ) {

			// List of multiple emails
			if ( strpos( $_POST['email_requirement'], ',' ) ) {

				$emails = explode( ',', $_POST['email_requirement'] );

			} else { // Only one email

				$emails = array();
				$emails[] = $_POST['email_requirement'];
			}

			foreach ( $emails as $key => $email ) {

				// Sanitize the email
				$email = filter_var( trim( strip_tags( $email ) ), FILTER_SANITIZE_EMAIL );

				// Skip if nothing or not an email
				if ( ! $email || ! strpos( $email, '@' ) ) {
					unset( $emails[$key] );
					continue;
				}

				// Replace the original
				$emails[$key] = $email;
			}

			// New meta element
			$meta['email_requirement'] = $emails;
		}

		return $meta;
	}

	/**
	 *	Verify the customer's email is in the list of required for the discount
	 */
	public function verify_email( $return, $discount_id, $code, $user ) {

		// Set up the custom object
		$by_user_id = is_email( $user ) ? false : true;
		$customer = new EDD_Customer( $user, $by_user_id );

		// Get the required emails
		$required_emails = get_post_meta( $discount_id, '_edd_discount_email_requirement', true );
		$required_emails = is_array( $required_emails ) ? $required_emails : array();

		// Check if customer's email is in the list of eligible emails
		if ( ! in_array( $customer->email, $required_emails ) ) {
			$return = false;
		}

		return $return;
	}

	/**
	 *	Show an error message if discount is invalid
	 */
	public function invalid_response_ajax( $return ) {

		if ( $return['msg'] == null ) {
			$return['msg'] = __( 'Invalid discount', 'edd-discount-emails' );
		}

		return $return;
	}

	/**
	 *	Check if EDD is active
	 */
	public function is_edd_active() {
		return is_plugin_active( 'easy-digital-downloads/easy-digital-downloads.php' );
	}
}

endif;

/**
 *	Main function
 *	@return object EDD_Discount_Emails instance
 */
function EDD_Discount_Emails() {
	return EDD_Discount_Emails::instance();
}

/**
 *	Kick off!
 */
EDD_Discount_Emails();