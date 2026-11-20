<?php
/**
 * Plugin Name: Disembark quiet mode
 * Description: This site is a copy made with Disembark. Emails are held, scheduled jobs are paused, checkout is closed, and payment, marketing and shipping APIs are blocked, so the copy can't reach real customers. Turn it off in Tools → Disembark → Settings or with `wp disembark quiet-mode off`.
 * Author: Disembark
 *
 * Written by Disembark (the template lives in the plugin's mu-plugin/ folder).
 * Self-contained on purpose: it keeps working when Disembark is deactivated.
 * To lift quiet mode by hand, delete this file.
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Disembark_Quiet_Mode' ) ) {

	final class Disembark_Quiet_Mode {

		/** Replaced when Disembark writes this file: base64 JSON of the settings. */
		const CONFIG = '__DISEMBARK_QUIET_CONFIG__';

		const HELD_OPTION    = 'disembark_quiet_held';
		const BLOCKED_OPTION = 'disembark_quiet_blocked';
		const HELD_KEEP      = 50;
		const BODY_KEEP      = 20000;

		private static $config = null;

		public static function config() {
			if ( self::$config === null ) {
				$decoded      = json_decode( (string) base64_decode( self::CONFIG ), true );
				self::$config = is_array( $decoded ) ? $decoded : [];
			}
			return self::$config;
		}

		/** "example.com/path" from a URL: scheme and trailing slash don't count. */
		public static function address( $url ) {
			$url = strtolower( trim( (string) $url ) );
			$url = preg_replace( '#^[a-z]+://#', '', $url );
			$url = preg_replace( '#^www\.#', '', $url );
			return rtrim( $url, '/' );
		}

		/**
		 * Quiet mode is bound to the address it was turned on for. When the
		 * site moves to another address (a copy that went live on the real
		 * domain), it stands down instead of silencing a live site.
		 */
		public static function active() {
			// Turned on during a restore: applies whatever the address says
			// until the restore finishes (mid-restore, the database already
			// holds the source's address) and binds it to the final one.
			if ( ! empty( self::config()['pending'] ) ) {
				return true;
			}
			$home = self::config()['home'] ?? '';
			if ( $home === '' ) {
				return false;
			}
			return self::address( $home ) === self::address( self::install_home() );
		}

		/**
		 * The address of the whole install, which quiet mode is bound to. On a
		 * network that's the main site's: every subsite came with the copy,
		 * whatever its own address. On a WP Freighter install it's the primary
		 * site's, so tenants with their own domains stay quiet too.
		 */
		public static function install_home() {
			global $wpdb;
			$prefix = '';
			if ( is_multisite() && function_exists( 'get_main_site_id' ) ) {
				$main = (int) get_main_site_id();
				if ( $main > 0 && $main !== (int) get_current_blog_id() ) {
					$prefix = $wpdb->get_blog_prefix( $main );
				}
			} elseif ( defined( 'TABLE_PREFIX' ) && is_string( TABLE_PREFIX ) && TABLE_PREFIX !== $wpdb->prefix && is_file( ABSPATH . 'wp-content/freighter.php' ) ) {
				// A Freighter tenant: its bootstrap keeps the primary's prefix here.
				$prefix = TABLE_PREFIX;
			}
			if ( $prefix !== '' && preg_match( '/^[A-Za-z0-9_]+$/', $prefix ) ) {
				$home = $wpdb->get_var( "SELECT option_value FROM `{$prefix}options` WHERE option_name = 'home' LIMIT 1" );
				if ( is_string( $home ) && $home !== '' ) {
					return $home;
				}
			}
			return (string) get_option( 'home' );
		}

		public static function boot() {
			add_action( 'admin_notices', [ __CLASS__, 'admin_notice' ] );
			add_action( 'network_admin_notices', [ __CLASS__, 'admin_notice' ] );
			if ( ! self::active() ) {
				return;
			}

			// Emails: held and logged, never sent. pre_wp_mail catches anything
			// that reaches core's wp_mail(); the pluggable wp_mail() below wins
			// over SMTP plugins that replace wp_mail() (this loads first).
			add_filter( 'pre_wp_mail', [ __CLASS__, 'hold_mail' ], PHP_INT_MAX, 2 );

			// Scheduled jobs: WP-Cron runs nothing, even when a server cron
			// requests wp-cron.php directly. Action Scheduler (WooCommerce,
			// Mailchimp, Google and Meta syncs, webhooks) rides on WP-Cron; its
			// async runner is switched off too. `wp action-scheduler run` still
			// works by hand.
			add_filter( 'pre_get_ready_cron_jobs', [ __CLASS__, 'no_cron_jobs' ], PHP_INT_MAX );
			add_filter( 'action_scheduler_allow_async_request_runner', '__return_false', PHP_INT_MAX );

			// WooCommerce: no payment methods, no orders, no webhooks.
			add_filter( 'woocommerce_available_payment_gateways', [ __CLASS__, 'no_gateways' ], PHP_INT_MAX );
			add_action( 'woocommerce_checkout_process', [ __CLASS__, 'refuse_checkout' ], 0 );
			add_action( 'woocommerce_before_checkout_form', [ __CLASS__, 'checkout_notice' ], 0 );
			add_action( 'woocommerce_before_cart', [ __CLASS__, 'checkout_notice' ], 0 );
			add_filter( 'rest_pre_dispatch', [ __CLASS__, 'refuse_store_api_checkout' ], 0, 3 );
			add_filter( 'woocommerce_webhook_should_deliver', '__return_false', PHP_INT_MAX );

			// Outbound calls to payment, marketing, shipping-label and email
			// APIs fail fast (refunds on copied orders would be real refunds).
			add_filter( 'pre_http_request', [ __CLASS__, 'block_http' ], PHP_INT_MAX, 3 );

			// Search engines: discouraged while quiet.
			add_filter( 'pre_option_blog_public', [ __CLASS__, 'not_public' ] );

			add_action( 'admin_bar_menu', [ __CLASS__, 'admin_bar' ], 1000 );
		}

		/* ------------------------------------------------------------ Mail */

		public static function hold_mail( $return, $atts ) {
			if ( $return !== null ) {
				return $return;
			}
			self::record_mail( $atts['to'] ?? '', $atts['subject'] ?? '', $atts['message'] ?? '', $atts['headers'] ?? '' );
			return true; // Report success so flows (password resets, orders) carry on.
		}

		public static function record_mail( $to, $subject, $message, $headers = '' ) {
			$held = get_option( self::HELD_OPTION );
			$held = is_array( $held ) ? $held : [ 'total' => 0, 'messages' => [] ];
			$held['total'] = (int) ( $held['total'] ?? 0 ) + 1;
			array_unshift( $held['messages'], [
				'time'    => time(),
				'to'      => implode( ', ', array_map( 'strval', (array) ( is_string( $to ) ? explode( ',', $to ) : $to ) ) ),
				'subject' => (string) $subject,
				'headers' => is_array( $headers ) ? implode( "\n", array_map( 'strval', $headers ) ) : (string) $headers,
				'message' => function_exists( 'mb_substr' ) ? mb_substr( (string) $message, 0, self::BODY_KEEP ) : substr( (string) $message, 0, self::BODY_KEEP ),
			] );
			$held['messages'] = array_slice( $held['messages'], 0, self::HELD_KEEP );
			update_option( self::HELD_OPTION, $held, false );
		}

		/* ------------------------------------------------------------ Jobs */

		public static function no_cron_jobs() {
			return [];
		}

		/* ----------------------------------------------------- WooCommerce */

		private static function closed_message() {
			$source = self::config()['source'] ?? '';
			return 'This is a copy of ' . ( $source !== '' ? self::address( $source ) : 'a live store' ) . ' and checkout is closed. No orders can be placed here.';
		}

		public static function no_gateways( $gateways ) {
			// The settings screens list gateways from elsewhere; this only empties
			// what a customer could pay with.
			return ( is_admin() && ! wp_doing_ajax() ) ? $gateways : [];
		}

		public static function refuse_checkout() {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( self::closed_message(), 'error' );
			}
		}

		public static function checkout_notice() {
			if ( function_exists( 'wc_print_notice' ) ) {
				wc_print_notice( self::closed_message(), 'notice' );
			}
		}

		public static function refuse_store_api_checkout( $result, $server, $request ) {
			if ( $result !== null || ! is_object( $request ) ) {
				return $result;
			}
			$route = (string) $request->get_route();
			if ( preg_match( '#^/wc/store(/v\d+)?/checkout#', $route ) && strtoupper( $request->get_method() ) !== 'GET' ) {
				return new WP_Error( 'disembark_quiet_mode', self::closed_message(), [ 'status' => 403 ] );
			}
			return $result;
		}

		/* ------------------------------------------------------------ HTTP */

		/** Host => label. A host also matches its subdomains. */
		public static function blocked_hosts() {
			$hosts = [
				// Payments (live endpoints only; sandboxes stay reachable).
				'api.stripe.com'            => 'Stripe',
				'api-m.paypal.com'          => 'PayPal',
				'api.paypal.com'            => 'PayPal',
				'api.braintreegateway.com'  => 'Braintree',
				'connect.squareup.com'      => 'Square',
				'api.authorize.net'         => 'Authorize.net',
				'api2.authorize.net'        => 'Authorize.net',
				// Marketing and catalog syncs.
				'api.mailchimp.com'         => 'Mailchimp',
				'graph.facebook.com'        => 'Meta',
				'shoppingcontent.googleapis.com' => 'Google Merchant Center',
				'merchantapi.googleapis.com'     => 'Google Merchant Center',
				'googleads.googleapis.com'       => 'Google Ads',
				'a.klaviyo.com'             => 'Klaviyo',
				'api.hubapi.com'            => 'HubSpot',
				// Shipping labels.
				'onlinetools.ups.com'       => 'UPS',
				'apis.fedex.com'            => 'FedEx',
				'ssapi.shipstation.com'     => 'ShipStation',
				'api.easypost.com'          => 'EasyPost',
				'api.shipengine.com'        => 'ShipEngine',
				// Email APIs used without wp_mail().
				'api.mailgun.net'           => 'Mailgun',
				'api.eu.mailgun.net'        => 'Mailgun',
				'api.sendgrid.com'          => 'SendGrid',
				'api.postmarkapp.com'       => 'Postmark',
				'mandrillapp.com'           => 'Mailchimp Transactional',
				'api.brevo.com'             => 'Brevo',
				'api.sendinblue.com'        => 'Brevo',
				'api.sparkpost.com'         => 'SparkPost',
				'api.resend.com'            => 'Resend',
			];
			return (array) apply_filters( 'disembark_quiet_mode_blocked_hosts', $hosts );
		}

		/** Label of the service a URL belongs to, or '' when it may go through. */
		public static function blocked_label( $url ) {
			$parts = wp_parse_url( (string) $url );
			$host  = strtolower( (string) ( $parts['host'] ?? '' ) );
			$path  = (string) ( $parts['path'] ?? '' );
			if ( $host === '' ) {
				return '';
			}
			// WooPayments talks to its server through the WordPress.com API.
			if ( $host === 'public-api.wordpress.com' && strpos( $path, '/wcpay/' ) !== false ) {
				return 'WooPayments';
			}
			// Amazon SES.
			if ( preg_match( '#^email(-smtp)?\.[a-z0-9-]+\.amazonaws\.com$#', $host ) ) {
				return 'Amazon SES';
			}
			foreach ( self::blocked_hosts() as $blocked => $label ) {
				$blocked = strtolower( (string) $blocked );
				if ( $host === $blocked || substr( $host, -strlen( $blocked ) - 1 ) === '.' . $blocked ) {
					return (string) $label;
				}
			}
			return '';
		}

		public static function block_http( $pre, $args, $url ) {
			if ( $pre !== false ) {
				return $pre;
			}
			$label = self::blocked_label( $url );
			if ( $label === '' ) {
				return $pre;
			}
			$host    = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
			$blocked = get_option( self::BLOCKED_OPTION );
			$blocked = is_array( $blocked ) ? $blocked : [];
			$entry   = $blocked[ $host ] ?? [ 'label' => $label, 'count' => 0 ];
			$entry['count'] = (int) $entry['count'] + 1;
			$entry['last']  = time();
			$blocked[ $host ] = $entry;
			update_option( self::BLOCKED_OPTION, $blocked, false );
			return new WP_Error( 'disembark_quiet_mode', "Blocked by Disembark quiet mode: this site is a copy, so calls to {$label} ({$host}) are switched off." );
		}

		public static function not_public() {
			return '0';
		}

		/* -------------------------------------------------------------- UI */

		private static function can_see() {
			return is_user_logged_in() && current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' );
		}

		private static function manage_link() {
			return class_exists( 'Disembark\\Run' )
				? admin_url( 'tools.php?page=disembark#settings' )
				: '';
		}

		public static function held_total() {
			$held = get_option( self::HELD_OPTION );
			return is_array( $held ) ? (int) ( $held['total'] ?? 0 ) : 0;
		}

		public static function admin_bar( $bar ) {
			if ( ! self::can_see() ) {
				return;
			}
			$held = self::held_total();
			$bar->add_node( [
				'id'    => 'disembark-quiet-mode',
				'title' => '<span style="display:inline-block;padding:0 8px;border-radius:3px;background:#b45309;color:#fff;font-weight:600">Quiet mode' . ( $held ? ' · ' . number_format_i18n( $held ) . ' held' : '' ) . '</span>',
				'href'  => self::manage_link() ?: false,
				'meta'  => [ 'title' => 'This site is a copy: emails are held, scheduled jobs paused, checkout closed.' ],
			] );
		}

		public static function admin_notice() {
			if ( ! self::can_see() ) {
				return;
			}
			$config = self::config();
			$link   = self::manage_link();
			$manage = $link
				? ' <a href="' . esc_url( $link ) . '">Manage in Tools → Disembark → Settings</a>, or run <code>wp disembark quiet-mode off</code>.'
				: ' Disembark isn\'t active: delete <code>' . esc_html( basename( __FILE__ ) ) . '</code> from the mu-plugins folder to turn it off.';
			if ( ! self::active() ) {
				echo '<div class="notice notice-info"><p><strong>Disembark quiet mode is standing down.</strong> It was turned on for '
					. esc_html( self::address( $config['home'] ?? '' ) ) . ', and this site is now ' . esc_html( self::address( self::install_home() ) )
					. ', so emails, scheduled jobs and checkout run normally.' . $manage . '</p></div>';
				return;
			}
			$held   = self::held_total();
			$source = $config['source'] ?? '';
			echo '<div class="notice notice-warning"><p><strong>Quiet mode:</strong> this site is a copy'
				. ( $source !== '' ? ' of ' . esc_html( self::address( $source ) ) : '' )
				. '. Emails are held' . ( $held ? ' (' . esc_html( number_format_i18n( $held ) ) . ' so far)' : '' )
				. ', scheduled jobs are paused, checkout is closed, and payment, marketing and shipping APIs are blocked.'
				. $manage . '</p></div>';
		}
	}

	// A pluggable wp_mail() defined here (before any plugin loads) means SMTP
	// plugins that replace wp_mail() can't route around quiet mode.
	if ( Disembark_Quiet_Mode::active() && ! function_exists( 'wp_mail' ) ) {
		function wp_mail( $to, $subject, $message, $headers = '', $attachments = [] ) {
			$atts = apply_filters( 'wp_mail', compact( 'to', 'subject', 'message', 'headers', 'attachments' ) );
			Disembark_Quiet_Mode::record_mail( $atts['to'] ?? $to, $atts['subject'] ?? $subject, $atts['message'] ?? $message, $atts['headers'] ?? $headers );
			return true;
		}
	}

	Disembark_Quiet_Mode::boot();
}
