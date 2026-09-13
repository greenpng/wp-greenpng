<?php
/**
 * Minimal WooCommerce stand-ins for unit tests (docs/11): an order
 * object recording CRUD meta writes, wc_get_order() over a per-test
 * registry, and an optional throw mode for the Throwable-isolation
 * case. The WooCommerce marker class itself is NOT defined here — the
 * availability probe must stay honest, so tests eval it only when a
 * present-target scenario needs it.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

if ( ! class_exists( 'WC_Order' ) ) {

    /**
     * CRUD order stand-in with recorded meta.
     */
    final class WC_Order {

        /**
         * Order id.
         *
         * @var int
         */
        private int $id;

        /**
         * Recorded meta store.
         *
         * @var array<string, mixed>
         */
        private array $meta = array();

        /**
         * Order total.
         *
         * @var float
         */
        public float $total = 0.0;

        /**
         * Order currency.
         *
         * @var string
         */
        public string $currency = 'USD';

        /**
         * Billing email.
         *
         * @var string
         */
        public string $billing_email = '';

        /**
         * When true, every method throws (Throwable-isolation fixture).
         *
         * @var bool
         */
        public bool $explode = false;

        /**
         * Constructor.
         *
         * @param int $id Order id.
         */
        public function __construct( int $id ) {
            $this->id = $id;
        }

        /**
         * Id accessor.
         *
         * @return int
         */
        public function get_id(): int {
            if ( $this->explode ) {
                throw new RuntimeException( 'order store unavailable' );
            }

            return $this->id;
        }

        /**
         * Meta read.
         *
         * @param string $key Meta key.
         * @return string
         */
        public function get_meta( string $key ): string {
            if ( $this->explode ) {
                throw new RuntimeException( 'order store unavailable' );
            }

            return (string) ( $this->meta[ $key ] ?? '' );
        }

        /**
         * Meta write (recorded, never persisted).
         *
         * @param string $key   Meta key.
         * @param mixed  $value Meta value.
         * @return void
         */
        public function update_meta_data( string $key, $value ): void {
            if ( $this->explode ) {
                throw new RuntimeException( 'order store unavailable' );
            }

            $this->meta[ $key ] = $value;
        }

        /**
         * Meta delete (recorded).
         *
         * @param string $key Meta key.
         * @return void
         */
        public function delete_meta_data( string $key ): void {
            if ( $this->explode ) {
                throw new RuntimeException( 'order store unavailable' );
            }

            unset( $this->meta[ $key ] );
        }

        /**
         * Save (recorded).
         *
         * @return int
         */
        public function save(): int {
            if ( $this->explode ) {
                throw new RuntimeException( 'order store unavailable' );
            }

            return $this->id;
        }

        /**
         * Total accessor.
         *
         * @return float
         */
        public function get_total(): float {
            return $this->total;
        }

        /**
         * Billing email accessor.
         *
         * @return string
         */
        public function get_billing_email(): string {
            if ( $this->explode ) {
                throw new RuntimeException( 'order store unavailable' );
            }

            return $this->billing_email;
        }

        /**
         * Currency accessor.
         *
         * @return string
         */
        public function get_currency(): string {
            return $this->currency;
        }
    }
}

if ( ! function_exists( 'wc_get_order' ) ) {
    /**
     * Order lookup over the per-test registry.
     *
     * @param int $id Order id.
     * @return WC_Order|false
     */
    function wc_get_order( $id ) {
        return $GLOBALS['gr_stub_wc_orders'][ (int) $id ] ?? false;
    }
}

if ( ! function_exists( 'wc_get_orders' ) ) {
    /**
     * Order query over the registry: supports the billing_email
     * argument the privacy mapping chain uses, mirroring the real
     * store's behavior.
     *
     * @param array<string, mixed> $args Query args.
     * @return array<int, WC_Order>
     */
    function wc_get_orders( $args ) {
        $email = isset( $args['billing_email'] ) ? strtolower( trim( (string) $args['billing_email'] ) ) : '';

        $found = array();
        foreach ( $GLOBALS['gr_stub_wc_orders'] as $order ) {
            if ( '' !== $email && strtolower( $order->billing_email ) !== $email ) {
                continue;
            }
            $found[] = $order;
        }

        return $found;
    }
}
