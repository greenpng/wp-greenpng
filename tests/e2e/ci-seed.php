<?php
/**
 * E2E seed helpers (ADR-0008), executed through `wp eval-file`.
 *
 * The wp-env argument layer proved unreliable for values that carry
 * brackets, asterisks, or nested quotes (the contact form's template
 * arrived empty and rendered a fieldless form), while plain words and
 * paths always arrive intact. So this file takes a single-word task
 * name as its first argument and embeds every payload as a PHP
 * literal: nothing a shell could ever mangle crosses a container
 * boundary. It is a test asset — the workflow copies it into the
 * disposable slug-staged plugin directory, never into the shipped
 * plugin.
 *
 * @package GreenPNG\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- wp-cli eval-file context, no HTTP request involved.
$task = isset( $args, $args[0] ) ? (string) $args[0] : '';

switch ( $task ) {
	case 'seed-contact-form':
		$existing = get_page_by_path( 'contact', OBJECT, 'page' );
		if ( $existing instanceof WP_Post ) {
			echo 'page-exists ' . $existing->ID;
			break;
		}

		$form_id = wp_insert_post(
			array(
				'post_type'    => 'wpcf7_contact_form',
				'post_status'  => 'publish',
				'post_title'   => 'Contact',
				'post_content' => '[text* your-name] [email* your-email] [submit "Send"]',
			)
		);
		if ( ! is_int( $form_id ) || $form_id < 1 ) {
			fwrite( STDERR, 'form insert failed' );
			exit( 1 );
		}

		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Contact us',
				'post_name'    => 'contact',
				'post_content' => '[contact-form-7 id="' . $form_id . '"]',
			)
		);
		if ( ! is_int( $page_id ) || $page_id < 1 ) {
			fwrite( STDERR, 'page insert failed' );
			exit( 1 );
		}

		echo 'created form ' . $form_id . ' page ' . $page_id;
		break;

	case 'enable-cod':
		$settings = get_option( 'woocommerce_cod_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings['enabled'] = 'yes';
		update_option( 'woocommerce_cod_settings', $settings );
		echo 'cod-enabled';
		break;

	case 'make-product':
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			fwrite( STDERR, 'WooCommerce product API unavailable' );
			exit( 1 );
		}
		$product = new WC_Product_Simple();
		$product->set_name( 'E2E Cap' );
		$product->set_regular_price( '9.99' );
		$product->set_stock_status( 'instock' );
		$product_id = $product->save();
		if ( ! $product_id ) {
			fwrite( STDERR, 'product save failed' );
			exit( 1 );
		}
		echo (string) $product_id;
		break;

	case 'complete-order':
		$order_id = isset( $args[1] ) ? (int) $args[1] : 0;
		if ( ! function_exists( 'wc_get_order' ) || ! $order_id ) {
			fwrite( STDERR, 'order context unavailable' );
			exit( 1 );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			fwrite( STDERR, 'order not found: ' . $order_id );
			exit( 1 );
		}
		$order->update_status( 'completed' );
		echo 'completed ' . $order_id;
		break;

	default:
		fwrite( STDERR, 'unknown task: ' . $task );
		exit( 1 );
}
