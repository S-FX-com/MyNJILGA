<?php
/**
 * FluentCart implementation of MyNJILGA_Invoice_Gateway. This is the ONLY
 * file in the plugin allowed to name a FluentCart class.
 *
 * Every call below was checked against FluentCart 1.6.3's source, not just
 * its docs:
 *   - OrderResource::updatedPlaceOrder($data) is what FluentCart's own
 *     admin "create order" screen calls. It hardcodes the `offline_payment`
 *     gateway (hence readiness_errors()), derives type/status/
 *     payment_status itself (on-hold + pending — right for an unpaid
 *     invoice), and reports failures by RETURNING a WP_Error.
 *   - AdminOrderProcessor::prepareOrderItems() reads `unit_price` from the
 *     item we pass (so a catalog line can carry OUR price), honours
 *     `product_title`/`variation_title` overrides, and — importantly —
 *     sets every new OrderItem's `line_meta` to [] regardless of input.
 *     That's why line_meta is written back onto the saved OrderItem rows
 *     AFTER the order exists (write_line_meta()).
 *   - OrderService::validateProducts() skips catalog checks only when a
 *     TOP-LEVEL `is_custom` is truthy; catalog lines need a real
 *     ProductVariation whose product is publish/private.
 *   - PaymentHelper::getCustomPaymentLink($uuid) is the same link
 *     FluentCart's own due-invoice emails use.
 *   - `fluent_cart/order_paid_done` receives ['order' => Order, ...] and is
 *     FluentCart's documented hook for third-party "order fully paid"
 *     integrations.
 *   - Product statuses are `publish`, `draft`, `private`, `future`, `trash`
 *     (Status::getProductStatuses()) — there is no `pending` product
 *     status; that value only exists elsewhere, for payments/transactions.
 *     list_products() reads the real list via
 *     Status::productAdminAllStatuses() rather than a hardcoded array, so a
 *     scheduled ("future") product is selectable and this can't drift from
 *     FluentCart's own status list again.
 */
class MyNJILGA_FluentCart_Invoice_Gateway implements MyNJILGA_Invoice_Gateway {

    public function name(): string {
        return 'FluentCart';
    }

    public function is_available(): bool {
        return class_exists( '\\FluentCart\\Api\\Resource\\OrderResource' )
            && class_exists( '\\FluentCart\\App\\Models\\Customer' )
            && class_exists( '\\FluentCart\\App\\Models\\OrderItem' );
    }

    public function readiness_errors(): array {
        if ( ! $this->is_available() ) {
            return [ 'FluentCart is not active.' ];
        }
        if ( ! $this->offline_gateway_active() ) {
            return [ 'FluentCart\'s Offline/Cash payment method is disabled — enable it under FluentCart → Settings → Payment Methods. FluentCart places admin-created orders through that gateway and rejects them while it\'s off. (Firms still pay online through whatever gateways the store offers.)' ];
        }
        return [];
    }

    public function find_or_create_customer( array $billTo ): ?int {
        if ( ! $this->is_available() ) {
            return null;
        }
        $email = (string) ( $billTo['email'] ?? '' );
        if ( $email === '' ) {
            return null;
        }
        try {
            $customer = \FluentCart\App\Models\Customer::where( 'email', $email )->first();
            if ( ! $customer ) {
                $customer = \FluentCart\App\Models\Customer::create( [
                    'email'      => $email,
                    'first_name' => (string) ( $billTo['first_name'] ?? '' ),
                    'last_name'  => (string) ( $billTo['last_name'] ?? '' ),
                    'contact_id' => (int) ( $billTo['contact_id'] ?? 0 ),
                    'status'     => 'active',
                ] );
            }
            return ( $customer && ! empty( $customer->id ) ) ? (int) $customer->id : null;
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    public function create_order( int $customerId, array $lineItems, array $context ): array {
        if ( ! $this->is_available() ) {
            return [ 'ok' => false, 'error' => 'FluentCart is not active.' ];
        }
        $ready = $this->readiness_errors();
        if ( $ready ) {
            return [ 'ok' => false, 'error' => $ready[0] ];
        }
        if ( empty( $lineItems ) ) {
            return [ 'ok' => false, 'error' => 'No line items.' ];
        }

        try {
            $orderItems = [];
            foreach ( $lineItems as $line ) {
                $orderItems[] = $this->to_fluentcart_item( $line );
            }

            $order = \FluentCart\Api\Resource\OrderResource::updatedPlaceOrder( [
                'customer_id' => $customerId,
                'order_items' => $orderItems,
            ] );

            if ( is_wp_error( $order ) ) {
                return [ 'ok' => false, 'error' => $order->get_error_message() ];
            }
            if ( ! $order || empty( $order->id ) ) {
                return [ 'ok' => false, 'error' => 'FluentCart did not return an order.' ];
            }

            $this->write_line_meta( (int) $order->id, $lineItems, $context );

            if ( class_exists( '\\FluentCart\\App\\Events\\Order\\OrderCreated' ) ) {
                try {
                    ( new \FluentCart\App\Events\Order\OrderCreated( $order, null, $order->customer ?? null ) )->dispatch();
                } catch ( \Throwable $e ) {
                    // The order exists; a failed side-effect event must not
                    // report the invoice as uncreated.
                }
            }

            return [
                'ok'         => true,
                'order_id'   => (int) $order->id,
                'order_uuid' => (string) ( $order->uuid ?? '' ),
            ];
        } catch ( \Throwable $e ) {
            return [ 'ok' => false, 'error' => $e->getMessage() ];
        }
    }

    public function payment_link( string $orderUuid ): string {
        if ( $orderUuid === '' || ! class_exists( '\\FluentCart\\App\\Services\\Payments\\PaymentHelper' ) ) {
            return '';
        }
        try {
            return (string) \FluentCart\App\Services\Payments\PaymentHelper::getCustomPaymentLink( $orderUuid );
        } catch ( \Throwable $e ) {
            return '';
        }
    }

    public function order_status( int $orderId ): ?array {
        if ( ! class_exists( '\\FluentCart\\App\\Models\\Order' ) || $orderId <= 0 ) {
            return null;
        }
        try {
            $order = \FluentCart\App\Models\Order::find( $orderId );
            if ( ! $order ) {
                return null;
            }
            return [
                'payment_status' => (string) ( $order->payment_status ?? '' ),
                'status'         => (string) ( $order->status ?? '' ),
                'total_cents'    => (int) ( $order->total_amount ?? 0 ),
                'paid_cents'     => (int) ( $order->total_paid ?? 0 ),
            ];
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    public function list_products(): array {
        if ( ! class_exists( '\\FluentCart\\App\\Models\\Product' ) ) {
            return [];
        }
        try {
            // FluentCart's real statuses (publish/draft/private/future/trash,
            // minus trash) — not a hand-copied list. See the class docblock:
            // 'pending' was never one of them.
            $products = \FluentCart\App\Models\Product::query()
                ->whereIn( 'post_status', \FluentCart\App\Helpers\Status::productAdminAllStatuses() )
                ->with( 'variants' )
                ->orderBy( 'post_title', 'asc' )
                ->get();

            $out = [];
            foreach ( $products as $p ) {
                $variations = [];
                foreach ( $p->variants ?? [] as $v ) {
                    $variations[] = [
                        'id'           => (int) $v->id,
                        'title'        => (string) ( $v->variation_title ?? '' ),
                        'price_cents'  => (int) ( $v->item_price ?? 0 ),
                        'payment_type' => (string) ( $v->payment_type ?? '' ),
                    ];
                }
                $out[] = [
                    'id'         => (int) $p->ID,
                    'title'      => (string) $p->post_title,
                    'status'     => (string) $p->post_status,
                    'variations' => $variations,
                ];
            }
            return $out;
        } catch ( \Throwable $e ) {
            return [];
        }
    }

    public function check_variation( int $productId, int $variationId ): array {
        if ( $variationId <= 0 ) {
            return [ 'ok' => false, 'label' => '', 'price_cents' => 0, 'error' => 'No product mapped.' ];
        }
        if ( ! class_exists( '\\FluentCart\\App\\Models\\ProductVariation' ) ) {
            return [ 'ok' => false, 'label' => '', 'price_cents' => 0, 'error' => 'FluentCart is not active.' ];
        }
        try {
            $variation = \FluentCart\App\Models\ProductVariation::find( $variationId );
            if ( ! $variation ) {
                return [ 'ok' => false, 'label' => '', 'price_cents' => 0, 'error' => "Variation #$variationId no longer exists." ];
            }
            $product = $variation->product;
            if ( ! $product ) {
                return [ 'ok' => false, 'label' => (string) $variation->variation_title, 'price_cents' => (int) $variation->item_price, 'error' => 'Variation has no parent product.' ];
            }
            if ( $productId > 0 && (int) $product->ID !== $productId ) {
                return [ 'ok' => false, 'label' => '', 'price_cents' => 0, 'error' => "Variation #$variationId belongs to a different product." ];
            }
            $label = trim( (string) $product->post_title . ' — ' . (string) $variation->variation_title, ' —' );
            if ( ! in_array( (string) $product->post_status, [ 'publish', 'private' ], true ) ) {
                return [ 'ok' => false, 'label' => $label, 'price_cents' => (int) $variation->item_price, 'error' => 'Product is ' . $product->post_status . ' — FluentCart only accepts published or private products on an order.' ];
            }
            if ( (string) ( $variation->payment_type ?? '' ) === 'subscription' ) {
                return [ 'ok' => false, 'label' => $label, 'price_cents' => (int) $variation->item_price, 'error' => 'This variation is a subscription. Dues products must be one-time.' ];
            }
            return [ 'ok' => true, 'label' => $label, 'price_cents' => (int) ( $variation->item_price ?? 0 ) ];
        } catch ( \Throwable $e ) {
            return [ 'ok' => false, 'label' => '', 'price_cents' => 0, 'error' => $e->getMessage() ];
        }
    }

    public function on_order_paid( callable $callback ): void {
        add_action( 'fluent_cart/order_paid_done', static function ( $data ) use ( $callback ) {
            $order = is_array( $data ) ? ( $data['order'] ?? null ) : null;
            if ( $order && ! empty( $order->id ) ) {
                $callback( (int) $order->id );
            }
        }, 10, 1 );
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * FluentCart's admin order path hardcodes `offline_payment`, whose
     * handler throws "Offline payment is not activated" when that method
     * is off.
     */
    private function offline_gateway_active(): bool {
        if ( ! class_exists( '\\FluentCart\\App\\App' ) ) {
            return false;
        }
        try {
            $gateway = \FluentCart\App\App::gateway( 'offline_payment' );
            if ( ! $gateway || ! method_exists( $gateway, 'meta' ) ) {
                return false;
            }
            return ! empty( $gateway->meta()['status'] );
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    /**
     * One gateway-agnostic line → one FluentCart checkout item.
     *
     * Catalog line (variation resolvable & purchasable): object_id /
     * post_id point at the real variation/product so FluentCart reports,
     * stock and product-level hooks all see a real sale; our own title and
     * price override the catalog's (AdminOrderProcessor takes both from
     * the item). Custom line otherwise — see the key-by-key notes below,
     * all load-bearing.
     *
     * @param array<string,mixed> $line
     * @return array<string,mixed>
     */
    private function to_fluentcart_item( array $line ): array {
        $title  = (string) ( $line['title'] ?? '' );
        $price  = max( 0, (int) ( $line['unit_price_cents'] ?? 0 ) );
        $qty    = max( 1, (int) ( $line['quantity'] ?? 1 ) );
        $prodId = (int) ( $line['product_id'] ?? 0 );
        $varId  = (int) ( $line['variation_id'] ?? 0 );

        $catalog = null;
        if ( $varId > 0 ) {
            $check = $this->check_variation( $prodId, $varId );
            if ( $check['ok'] ) {
                $variation = \FluentCart\App\Models\ProductVariation::find( $varId );
                $catalog   = [
                    'object_id'        => $varId,
                    'post_id'          => (int) $variation->post_id,
                    'product_title'    => $title,
                    'variation_title'  => $title,
                    'quantity'         => $qty,
                    'unit_price'       => $price,
                    'fulfillment_type' => (string) ( $variation->fulfillment_type ?: 'digital' ),
                    'other_info'       => [
                        'payment_type' => 'onetime',
                    ],
                ];
            }
        }
        if ( $catalog ) {
            return $catalog;
        }

        // Custom (non-catalog) line:
        //   - `is_custom` MUST be top level (validateProducts reads it there)
        //     AND under other_info (OrderItem::getIsCustomAttribute reads it there).
        //   - product_title/variation_title are what prepareOrderItems maps
        //     onto post_title/title; set both to the same string so
        //     getDisplayTitle() prints it once.
        //   - other_info.payment_type 'onetime' (top-level payment_type is ignored).
        //   - fulfillment_type digital keeps it off the shipping path.
        return [
            'object_id'        => 0,
            'post_id'          => 0,
            'is_custom'        => true,
            'product_title'    => $title,
            'variation_title'  => $title,
            'quantity'         => $qty,
            'unit_price'       => $price,
            'fulfillment_type' => 'digital',
            'other_info'       => [
                'is_custom'    => true,
                'payment_type' => 'onetime',
            ],
        ];
    }

    /**
     * Stamp each saved OrderItem with our line_meta (contact_id, dues_year,
     * kind, category…). FluentCart creates the rows in the order given and
     * resets line_meta to [] on the way in, so: match by position when the
     * counts agree, by title otherwise. Best-effort — a mismatch is logged
     * onto the order note, never thrown.
     *
     * @param array<int,array<string,mixed>> $lineItems
     * @param array<string,mixed>            $context
     */
    private function write_line_meta( int $orderId, array $lineItems, array $context ): void {
        try {
            $saved = \FluentCart\App\Models\OrderItem::where( 'order_id', $orderId )->orderBy( 'id', 'asc' )->get();
            $rows  = [];
            foreach ( $saved as $item ) {
                $rows[] = $item;
            }

            $byPosition = count( $rows ) === count( $lineItems );
            foreach ( $lineItems as $i => $line ) {
                $meta = (array) ( $line['line_meta'] ?? [] );
                if ( empty( $meta ) ) {
                    continue;
                }
                $meta['source'] = 'my-njilga';
                foreach ( $context as $k => $v ) {
                    if ( is_scalar( $v ) && ! isset( $meta[ $k ] ) ) {
                        $meta[ $k ] = $v;
                    }
                }

                $target = null;
                if ( $byPosition ) {
                    $target = $rows[ $i ] ?? null;
                } else {
                    foreach ( $rows as $candidate ) {
                        if ( (string) $candidate->title === (string) $line['title'] && empty( $candidate->line_meta['source'] ) ) {
                            $target = $candidate;
                            break;
                        }
                    }
                }
                if ( $target ) {
                    $target->line_meta = $meta;
                    $target->save();
                }
            }
        } catch ( \Throwable $e ) {
            // Non-fatal: the invoice is correct without line_meta; it only
            // loses the per-line contact back-reference.
        }
    }
}
