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
         * Already-refunded total feeding the remaining computation.
         *
         * @var float
         */
        public float $total_refunded = 0.0;

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
         * Order status; 'pending' is the Woo birth status, so tests
         * only set it when a status matters.
         *
         * @var string
         */
        public string $status = 'pending';

        /**
         * Line items, as WC_Order_Item_Product stand-ins.
         *
         * @var array<int, WC_Order_Item_Product>
         */
        public array $items = array();

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
         * Refunded-so-far accessor: one half of the remaining-total
         * pair the partial-refund convergence reads (ADR-0010 D2).
         *
         * @return float
         */
        public function get_total_refunded(): float {
            if ( $this->explode ) {
                throw new RuntimeException( 'order store unavailable' );
            }

            return $this->total_refunded;
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

        /**
         * Status accessor.
         *
         * @return string
         */
        public function get_status(): string {
            if ( $this->explode ) {
                throw new RuntimeException( 'order store unavailable' );
            }

            return $this->status;
        }

        /**
         * Status membership check, mirroring the target's public
         * conditional over a status or a list of them.
         *
         * @param string|array<int, string> $statuses Status or statuses.
         * @return bool
         */
        public function has_status( $statuses ): bool {
            if ( $this->explode ) {
                throw new RuntimeException( 'order store unavailable' );
            }

            return in_array( $this->status, (array) $statuses, true );
        }

        /**
         * Line items accessor for the order-path cart capture.
         *
         * @return array<int, WC_Order_Item_Product>
         */
        public function get_items(): array {
            if ( $this->explode ) {
                throw new RuntimeException( 'order store unavailable' );
            }

            return $this->items;
        }
    }
}

if ( ! class_exists( 'WC_Order_Item_Product' ) ) {

    /**
     * Line-item stand-in carrying the four public getters the cart
     * capture reads.
     */
    final class WC_Order_Item_Product {

        /**
         * Display name.
         *
         * @var string
         */
        public string $item_name = '';

        /**
         * Quantity.
         *
         * @var int
         */
        public int $item_quantity = 1;

        /**
         * Product id.
         *
         * @var int
         */
        public int $item_product_id = 0;

        /**
         * Variation id.
         *
         * @var int
         */
        public int $item_variation_id = 0;

        /**
         * Name getter.
         *
         * @return string
         */
        public function get_name(): string {
            return $this->item_name;
        }

        /**
         * Quantity getter.
         *
         * @return int
         */
        public function get_quantity(): int {
            return $this->item_quantity;
        }

        /**
         * Product id getter.
         *
         * @return int
         */
        public function get_product_id(): int {
            return $this->item_product_id;
        }

        /**
         * Variation id getter.
         *
         * @return int
         */
        public function get_variation_id(): int {
            return $this->item_variation_id;
        }
    }
}

if ( ! class_exists( 'Gr_Stub_Wc_Product' ) ) {

    /**
     * Product stand-in for cart item 'data' entries.
     */
    final class Gr_Stub_Wc_Product {

        /**
         * Display name.
         *
         * @var string
         */
        public string $product_name = '';

        /**
         * Name getter.
         *
         * @return string
         */
        public function get_name(): string {
            return $this->product_name;
        }
    }
}

if ( ! class_exists( 'Gr_Stub_Wc_Cart' ) ) {

    /**
     * Cart stand-in over an in-memory item list, recording adds and
     * clears so restore tests can assert what the shopper got back.
     */
    final class Gr_Stub_Wc_Cart {

        /**
         * Cart items: arrays with product_id, variation_id, quantity,
         * and a Gr_Stub_Wc_Product under 'data'.
         *
         * @var array<int, array<string, mixed>>
         */
        public array $cart_items = array();

        /**
         * Cart total, the numeric string get_total('edit') answers.
         *
         * @var float
         */
        public float $cart_total = 0.0;

        /**
         * Recorded add_to_cart calls.
         *
         * @var array<int, array<string, int>>
         */
        public array $added = array();

        /**
         * Recorded empty_cart calls.
         *
         * @var int
         */
        public int $emptied = 0;

        /**
         * Item list accessor.
         *
         * @return array<int, array<string, mixed>>
         */
        public function get_cart(): array {
            return $this->cart_items;
        }

        /**
         * Total accessor; the context argument is accepted and
         * ignored like the real cart's edit mode.
         *
         * @param string $context 'edit' or 'view'.
         * @return string
         */
        public function get_total( string $context = 'view' ): string {
            return number_format( $this->cart_total, 2, '.', '' );
        }

        /**
         * Clears the in-memory cart.
         *
         * @return void
         */
        public function empty_cart(): void {
            ++$this->emptied;
            $this->cart_items = array();
        }

        /**
         * Adds one item, mirroring the real signature's first three
         * arguments (the ones the restore path uses).
         *
         * @param int $product_id   Product id.
         * @param int $quantity     Quantity.
         * @param int $variation_id Variation id.
         * @return string|false Cart item key, or false on refusal.
         */
        public function add_to_cart( int $product_id, int $quantity = 1, int $variation_id = 0 ) {
            if ( $product_id < 1 || $quantity < 1 ) {
                return false;
            }

            $this->added[] = array(
                'product_id'   => $product_id,
                'quantity'     => $quantity,
                'variation_id' => $variation_id,
            );

            $product = new Gr_Stub_Wc_Product();
            $product->product_name = 'Stub product ' . $product_id;

            $this->cart_items[] = array(
                'product_id'   => $product_id,
                'variation_id' => $variation_id,
                'quantity'     => $quantity,
                'data'         => $product,
            );

            return 'stub-key-' . count( $this->cart_items );
        }
    }
}

if ( ! function_exists( 'wc_load_cart' ) ) {
    /**
     * Public loader stand-in: arms $GLOBALS['gr_stub_wc']->cart the
     * way the real one loads the session in non-frontend contexts.
     *
     * @return void
     */
    function wc_load_cart(): void {
        if ( isset( $GLOBALS['gr_stub_wc'] ) && is_object( $GLOBALS['gr_stub_wc'] ) ) {
            $GLOBALS['gr_stub_wc']->cart_loaded = true;
        }
    }
}

if ( ! function_exists( 'WC' ) ) {
    /**
     * Woo container stand-in over a per-test object.
     *
     * @return object|null
     */
    function WC() {
        return $GLOBALS['gr_stub_wc'] ?? null;
    }
}

if ( ! function_exists( 'wc_get_checkout_url' ) ) {
    /**
     * Checkout URL stand-in, overridable via gr_stub_checkout_url.
     *
     * @return string
     */
    function wc_get_checkout_url(): string {
        return $GLOBALS['gr_stub_checkout_url'] ?? 'https://example.com/checkout';
    }
}

if ( ! function_exists( 'get_woocommerce_currency' ) ) {
    /**
     * Currency stand-in, overridable via gr_stub_woo_currency.
     *
     * @return string
     */
    function get_woocommerce_currency(): string {
        return $GLOBALS['gr_stub_woo_currency'] ?? 'USD';
    }
}

if ( ! function_exists( 'is_checkout' ) ) {
    /**
     * The target's checkout conditional, overridable per test via
     * gr_stub_is_checkout (false by default, like a normal page).
     *
     * @return bool
     */
    function is_checkout(): bool {
        return (bool) ( $GLOBALS['gr_stub_is_checkout'] ?? false );
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
     * Order query over the registry: supports the billing_email and
     * status arguments the privacy chain and the recovery gates use,
     * mirroring the real store's behavior ('any' or absent means all
     * statuses).
     *
     * @param array<string, mixed> $args Query args.
     * @return array<int, WC_Order>
     */
    function wc_get_orders( $args ) {
        $email  = isset( $args['billing_email'] ) ? strtolower( trim( (string) $args['billing_email'] ) ) : '';
        $filter = isset( $args['status'] ) ? (array) $args['status'] : array();
        $any    = array() === $filter || in_array( 'any', $filter, true );

        $found = array();
        foreach ( $GLOBALS['gr_stub_wc_orders'] as $order ) {
            if ( '' !== $email && strtolower( $order->billing_email ) !== $email ) {
                continue;
            }
            if ( ! $any && ! in_array( $order->status, $filter, true ) ) {
                continue;
            }
            $found[] = $order;
        }

        return $found;
    }
}

if ( ! class_exists( 'Gr_Stub_Wc' ) ) {

    /**
     * Woo container stand-in: the cart object plus the loader flag.
     */
    final class Gr_Stub_Wc {

        /**
         * The cart instance behind WC()->cart.
         *
         * @var Gr_Stub_Wc_Cart|null
         */
        public $cart = null;

        /**
         * Whether wc_load_cart() ran in this process.
         *
         * @var bool
         */
        public bool $cart_loaded = false;
    }
}
