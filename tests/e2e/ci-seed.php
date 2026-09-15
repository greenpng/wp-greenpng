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

use GreenPNG\Storage\Gr_Contact_Repository;
use GreenPNG\Storage\Gr_Daily_Aggregator;

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
				'post_type'   => 'wpcf7_contact_form',
				'post_status' => 'publish',
				'post_title'  => 'Contact',
			)
		);
		if ( ! is_int( $form_id ) || $form_id < 1 ) {
			fwrite( STDERR, 'form insert failed' );
			exit( 1 );
		}

		// CF7 6.x reads the template from the _form post meta (the
		// post body never carries it), and demo_mode keeps the
		// submission a success without a mail transport.
		update_post_meta( $form_id, '_form', '[text* your-name] [email* your-email] [submit "Send"]' );
		update_post_meta( $form_id, '_additional_settings', "demo_mode: on" );

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
		// WooCommerce fires woocommerce_payment_complete only from
		// an unpaid status (on-hold/pending/failed/cancelled): a
		// Store API COD order is created 'processing', so a bare
		// status move never triggers it. This task models the
		// card-gateway reality instead: checkout leaves the order
		// pending, and the gateway's webhook completes the payment
		// out-of-band from a request carrying no session state.
		if ( $order->get_status() !== 'pending' ) {
			$order->set_status( 'pending' );
			$order->save();
		}
		$order->payment_complete();
		echo 'completed ' . $order_id;
		break;

	case 'latest-conversion':
		// Read through $wpdb instead of `wp db query`: this file
		// already exists to keep payloads off the argument layer.
		// The row is scoped to one order: a parallel worker's CF7
		// conversion can own the table's latest id.
		$order_id = isset( $args[1] ) ? (int) $args[1] : 0;
		if ( ! $order_id ) {
			fwrite( STDERR, 'order id required' );
			exit( 1 );
		}
		global $wpdb;
		$table = $wpdb->prefix . 'gr_conversions';
		$row = $wpdb->get_row( "SELECT source_type, source_id, amount FROM {$table} WHERE source_type = 'woocommerce' AND source_id = {$order_id} ORDER BY id DESC LIMIT 1" );
		if ( ! $row ) {
			echo 'none';
			break;
		}
		echo $row->source_type . ' ' . $row->source_id . ' ' . $row->amount;
		break;

	case 'conversion-count':
		// Rows bound to one order, for the idempotency assertion: the
		// meta lock and the source UNIQUE key must collapse replays.
		$order_id = isset( $args[1] ) ? (int) $args[1] : 0;
		if ( ! $order_id ) {
			fwrite( STDERR, 'order id required' );
			exit( 1 );
		}
		global $wpdb;
		$table3 = $wpdb->prefix . 'gr_conversions';
		echo (string) (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table3} WHERE source_type = 'woocommerce' AND source_id = {$order_id}" );
		break;

	case 'scanner-hits':
		// Sum of folded scanner-UA hits, off the argument layer. The
		// fold writer keys rows by address+rule+hour window, so a hit
		// from any agent folds into the window's row: the count is
		// the only per-walk signal a shared address cannot erase.
		global $wpdb;
		$table2 = $wpdb->prefix . 'gr_security_logs';
		echo (string) (int) $wpdb->get_var( "SELECT SUM(hit_count) FROM {$table2} WHERE rule_id = 'scanner_ua'" );
		break;

	case 'seed-contact':
		// One CRM contact through the real capture path: hashed email
		// + encrypted envelope, a custom tag, a fixed score, and an
		// RFM segment word from the engine's closed vocabulary.
		$repo = new Gr_Contact_Repository();
		$id   = $repo->capture( 'e2e-contact@example.test', 'E2E', 'Contact', '' );
		if ( $id < 1 ) {
			fwrite( STDERR, 'contact capture failed' );
			exit( 1 );
		}
		$repo->attach_tag( $id, 'e2e-tag', 'E2E tag' );
		$repo->set_score( $id, 37 );
		$repo->set_segment_and_ltv( $id, 'champions', 123.45 );
		echo 'contact ' . $id;
		break;

	case 'purge-contact':
		// The disposable CI site has no admin surface for deleting a
		// contact, and the fixture is the spec's alone: the row, its
		// tag bindings, and the fixture tag leave through SQL.
		global $wpdb;
		$fixture_id = ( new Gr_Contact_Repository() )->id_for_email( 'e2e-contact@example.test' );
		if ( $fixture_id > 0 ) {
			$wpdb->delete( $wpdb->prefix . 'gr_contact_tags', array( 'contact_id' => $fixture_id ) );
			$wpdb->delete( $wpdb->prefix . 'gr_contacts', array( 'id' => $fixture_id ) );
		}
		$wpdb->delete( $wpdb->prefix . 'gr_tags', array( 'slug' => 'e2e-tag' ) );
		echo 'purged';
		break;

	case 'seed-behavior':
		// Four behavior events on the bus (the collect endpoint's
		// inner payloads, so the drill-down lists and the aggregate
		// tiles read the real shapes), then the day's aggregation so
		// the KPI tiles have their numbers without waiting for the
		// nightly pass.
		gr_dispatch_event( 'dwell', array( 'event_group' => 'behavior', 'bucket' => '60-180', 'path' => '/', 'visitor_id' => 'e2efixture' ) );
		gr_dispatch_event( 'scroll_depth', array( 'event_group' => 'behavior', 'milestone' => 75, 'path' => '/', 'visitor_id' => 'e2efixture' ) );
		gr_dispatch_event( 'rage_click', array( 'event_group' => 'behavior', 'clicks' => 7, 'locator' => 'button#buy-now', 'path' => '/', 'visitor_id' => 'e2efixture' ) );
		gr_dispatch_event( 'dead_click', array( 'event_group' => 'behavior', 'locator' => 'div.hero', 'path' => '/', 'visitor_id' => 'e2efixture' ) );
		$rows = Gr_Daily_Aggregator::aggregate_date( gmdate( 'Y-m-d' ) );
		echo 'aggregated ' . (string) $rows;
		break;

	case 'purge-behavior':
		// The four fixture events leave by their in-payload fixture
		// marker (the events row carries the identity service's own
		// visitor id, which a wp-cli run does not share), and the
		// day's aggregate is recomputed afterwards — the same pass
		// the nightly job would run.
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}gr_events WHERE event_name IN (%s, %s, %s, %s) AND payload_json LIKE %s",
				'dwell',
				'scroll_depth',
				'rage_click',
				'dead_click',
				'%"e2efixture"%'
			)
		);
		Gr_Daily_Aggregator::aggregate_date( gmdate( 'Y-m-d' ) );
		echo 'purged';
		break;

	default:
		fwrite( STDERR, 'unknown task: ' . $task );
		exit( 1 );
}
