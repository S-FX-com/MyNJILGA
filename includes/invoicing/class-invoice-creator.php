<?php
/**
 * Step 3 — creates the FluentCart order (invoice) for an approved firm
 * row: find-or-create the FluentCart Customer for the Owner's email,
 * build custom (non-catalog) line items from the frozen roster snapshot,
 * and create the order in-process via FluentCart's own order-placement
 * service (this plugin lives on the same WordPress install, so there's
 * no HTTP round-trip to itself).
 *
 * Every FluentCart call below was verified against FluentCart 1.6.3's
 * actual source, not just its public docs:
 *   - OrderResource::updatedPlaceOrder($data, $params = []) exists at
 *     api/Resource/OrderResource.php and is what FluentCart's own admin
 *     "create order" controller calls.
 *   - PaymentHelper::getCustomPaymentLink($orderUuid) exists at
 *     app/Services/Payments/PaymentHelper.php — the same call FluentCart's
 *     own due/overdue reminder emails use to link an unpaid order.
 *   - fct_customers really is keyed by `email` and accepts
 *     first_name/last_name/contact_id as fillable.
 *
 * Three things about updatedPlaceOrder() are worth knowing, because they
 * are not obvious from the call site:
 *   1. It hardcodes the `offline_payment` gateway, whose handler throws
 *      "Offline payment is not activated" when that payment method is
 *      disabled — hence offline_gateway_active() below.
 *   2. It derives the order's own `type`, `status` and `payment_status`
 *      internally (AdminOrderProcessor::prepareOrderData() → on-hold +
 *      payment pending, which is exactly what an unpaid invoice should
 *      be). The keys we pass for those are accepted but ignored; the ones
 *      that actually matter are `customer_id` and `order_items`.
 *   3. Money is in integer cents throughout, matching the cents stored on
 *      njilga_dues_invoices — no conversion needed in either direction.
 *
 * Still worth running one test invoice against a throwaway firm on
 * staging before the first real billing run — verified-by-reading is not
 * the same as having watched it place an order.
 */
class MyNJILGA_Invoice_Creator {

    public static function fluentcart_active(): bool {
        return class_exists( '\\FluentCart\\Api\\Resource\\OrderResource' )
            && class_exists( '\\FluentCart\\App\\Models\\Customer' );
    }

    /**
     * FluentCart places admin-created orders through its `offline_payment`
     * (Cash) gateway — OrderResource::updatedPlaceOrder() hardcodes it —
     * and CodHandler::handlePayment() throws "Offline payment is not
     * activated" when that method is switched off. Every dues invoice is
     * an admin-created order, so the gateway has to be enabled or the
     * whole batch fails one firm at a time. Checked up front so the
     * dashboard can say this plainly once, instead of repeating a raw
     * exception per firm.
     */
    public static function offline_gateway_active(): bool {
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
     * @return array{ok:bool, error?:string}
     */
    public static function create_for_row( object $invoiceRow ): array {
        if ( ! self::fluentcart_active() ) {
            return [ 'ok' => false, 'error' => 'FluentCart is not active.' ];
        }
        if ( ! self::offline_gateway_active() ) {
            return [
                'ok'    => false,
                'error' => 'FluentCart\'s Offline/Cash payment method is disabled — enable it under FluentCart → Settings → Payment Methods. Admin-created invoices are placed through that gateway and are rejected without it.',
            ];
        }

        try {
            $roster  = json_decode( (string) $invoiceRow->roster_snapshot, true );
            $members = $roster['members'] ?? [];
            if ( empty( $members ) ) {
                return [ 'ok' => false, 'error' => 'Empty roster snapshot.' ];
            }

            // Prefer the identity frozen into the snapshot at generation
            // time over a fresh Subscriber lookup — the FluentCart
            // Customer match has to use the email the invoice was
            // actually billed against, even if the contact's live email
            // has since changed.
            $ownerEmail     = (string) ( $roster['owner_email'] ?? '' );
            $ownerFirstName = (string) ( $roster['owner_first_name'] ?? '' );
            $ownerLastName  = (string) ( $roster['owner_last_name'] ?? '' );
            if ( $ownerEmail === '' ) {
                $owner          = \FluentCrm\App\Models\Subscriber::find( (int) $invoiceRow->fluentcrm_owner_contact_id );
                $ownerEmail     = $owner ? (string) ( $owner->email ?? '' ) : '';
                $ownerFirstName = $owner ? (string) ( $owner->first_name ?? '' ) : '';
                $ownerLastName  = $owner ? (string) ( $owner->last_name ?? '' ) : '';
            }
            if ( $ownerEmail === '' ) {
                return [ 'ok' => false, 'error' => 'Owner contact not found or has no email on file.' ];
            }

            $customer = self::find_or_create_customer(
                (int) $invoiceRow->fluentcrm_owner_contact_id,
                $ownerEmail,
                $ownerFirstName,
                $ownerLastName
            );
            if ( ! $customer || empty( $customer->id ) ) {
                return [ 'ok' => false, 'error' => 'Could not find or create a FluentCart customer for ' . $ownerEmail ];
            }

            // Guard on the TOTAL, not on an empty item list. Every member now
            // produces a line (including the $0 ones), so the list is never
            // empty and the old emptiness check would no longer catch an
            // all-$0 firm. That matters: FluentCart auto-settles a $0 order
            // the moment it's created (CodHandler::handleZeroTotalPayment()),
            // which would silently mark the whole firm paid instead of
            // reporting that there was nothing to bill.
            if ( MyNJILGA_Dues_Roster::total_cents( $members ) <= 0 ) {
                return [ 'ok' => false, 'error' => 'Every roster member owes $0 — nothing to invoice.' ];
            }

            $orderItems = self::build_order_items( $members, (int) $invoiceRow->dues_year );

            $order = \FluentCart\Api\Resource\OrderResource::updatedPlaceOrder( [
                'type'           => 'payment', // one-time, not subscription
                'customer_id'    => (int) $customer->id,
                'payment_status' => 'pending',
                'order_items'    => $orderItems,
            ] );

            // updatedPlaceOrder() reports failure by RETURNING a WP_Error
            // (bad customer id, inactive gateway, a throw inside its own
            // try) rather than by throwing, so the catch below never sees
            // those. Unwrap it here or the dashboard would show every real
            // reason as the same useless "did not return an order".
            if ( is_wp_error( $order ) ) {
                return [ 'ok' => false, 'error' => $order->get_error_message() ];
            }
            if ( ! $order || empty( $order->id ) ) {
                return [ 'ok' => false, 'error' => 'FluentCart did not return an order.' ];
            }

            if ( class_exists( '\\FluentCart\\App\\Events\\Order\\OrderCreated' ) ) {
                ( new \FluentCart\App\Events\Order\OrderCreated( $order, null, $order->customer ?? null ) )->dispatch();
            }

            MyNJILGA_Dues_Invoice_Table::mark_created(
                (int) $invoiceRow->id,
                (int) $customer->id,
                (int) $order->id,
                (string) ( $order->uuid ?? '' )
            );

            return [ 'ok' => true ];
        } catch ( \Throwable $e ) {
            return [ 'ok' => false, 'error' => $e->getMessage() ];
        }
    }

    /**
     * Payment link for an already-created order — used by the Send step
     * and for display on the dashboard.
     */
    public static function payment_link( string $orderUuid ): string {
        if ( $orderUuid === '' || ! class_exists( '\\FluentCart\\App\\Services\\Payments\\PaymentHelper' ) ) {
            return '';
        }
        return (string) \FluentCart\App\Services\Payments\PaymentHelper::getCustomPaymentLink( $orderUuid );
    }

    /**
     * @return object|null A FluentCart Customer-like object with at least ->id.
     */
    private static function find_or_create_customer( int $ownerContactId, string $email, string $firstName, string $lastName ) {
        $customer = \FluentCart\App\Models\Customer::where( 'email', $email )->first();
        if ( $customer ) {
            return $customer;
        }
        return \FluentCart\App\Models\Customer::create( [
            'email'      => $email,
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'contact_id' => $ownerContactId,
        ] );
    }

    /**
     * Custom (non-catalog) line items for the firm's invoice, in the
     * roster's own billing order (paying members, then dues-exempt, then
     * inactive — see MyNJILGA_Dues_Preview::sorted_members()).
     *
     * EVERY member gets a dues line, including the ones at $0:
     *   "Jane Doe — 2027 Membership Dues"                                 $125
     *   "John Smith — 2027 Membership Dues"                                $75
     *   "John Smith — Trustee Dinner Fee"                                 $200
     *   "Sam Lee — 2027 Membership Dues (no charge, 6th or later member)"   $0
     *   "Pat Roe — 2027 Membership Dues (no charge, dues exempt)"           $0
     *   "Chris Poe — 2027 Membership Dues (no charge, inactive)"            $0
     *
     * The $0 lines are the point of listing them: paying this invoice
     * settles every member in the snapshot (MyNJILGA_Payment_Listener
     * tags the whole roster, not just the priced entries), so a firm
     * reading its invoice needs to see everyone the payment covers — not
     * just the ones with money next to their name. Verified safe against
     * FluentCart 1.6.3: nothing in the order-creation path rejects a
     * zero unit_price (FluentCart creates its own bundle child items at
     * unit_price 0), and a $0 line contributes 0 to the order total.
     *
     * The fee line still only appears when the fee is actually owed —
     * an attorney who isn't an active Officer/Trustee/Senior Trustee/Past
     * President has no dinner fee to explain, so a $0 line for it would
     * be noise rather than reassurance.
     *
     * @param array<int,array{contact_id:int,name:string,tier_price_cents:int,trustee_fee_cents:int,dues_exempt?:bool,inactive?:bool}> $members
     * @return array<int,array<string,mixed>>
     */
    private static function build_order_items( array $members, int $duesYear ): array {
        $items = [];

        foreach ( $members as $member ) {
            $items[] = self::custom_line_item(
                MyNJILGA_Dues_Roster::dues_line_label( $member, $duesYear ),
                (int) ( $member['tier_price_cents'] ?? 0 )
            );

            if ( (int) ( $member['trustee_fee_cents'] ?? 0 ) > 0 ) {
                $items[] = self::custom_line_item(
                    MyNJILGA_Dues_Roster::fee_line_label( $member ),
                    (int) $member['trustee_fee_cents']
                );
            }
        }

        return $items;
    }

    /**
     * A single custom (non-catalog) line. Key names here are load-bearing
     * and were taken from FluentCart 1.6.3's source, not guessed:
     *
     *   - `is_custom` MUST be top level. OrderService::validateProducts()
     *     reads Arr::get($product, 'is_custom') to decide whether to skip
     *     the catalog checks. Nested only under other_info, it reads as
     *     false, FluentCart looks for a ProductVariation with id 0, finds
     *     none, and throws "[<title>] is not available." — which would
     *     fail every dues line of every firm.
     *   - `other_info.is_custom` is ALSO required, separately: that's what
     *     the saved row reads back through
     *     OrderItem::getIsCustomAttribute(). One is for validation on the
     *     way in, the other for recognition afterwards.
     *   - Titles arrive as `product_title` / `variation_title`.
     *     AdminOrderProcessor::prepareOrderItems() maps those onto
     *     OrderItem.post_title / OrderItem.title and ignores post_title
     *     and title if passed directly — with no catalog product behind
     *     the line to fall back to, that left the printed line blank.
     *     Both are set to the same string so
     *     OrderItem::getDisplayTitle() prints it once instead of
     *     "<post_title> - <title>".
     *   - `other_info.payment_type` is where the one-time/subscription
     *     flag is read from (top-level payment_type is ignored), and the
     *     value FluentCart uses is `onetime`, not `one_time`.
     *   - `unit_price` is integer cents; FluentCart computes
     *     subtotal = unit_price * quantity itself.
     *   - `fulfillment_type` digital keeps the order off the shipping
     *     path — without it FluentCart falls back to the (nonexistent)
     *     variation's type.
     */
    private static function custom_line_item( string $title, int $priceCents ): array {
        return [
            'object_id'        => 0, // No catalog ProductVariation behind a dues line.
            'post_id'          => 0, // No catalog Product either.
            'is_custom'        => true,
            'product_title'    => $title,
            'variation_title'  => $title,
            'quantity'         => 1,
            'price'            => $priceCents,
            'unit_price'       => $priceCents,
            'line_total'       => $priceCents,
            'fulfillment_type' => 'digital',
            'other_info'       => [
                'is_custom'    => true,
                'payment_type' => 'onetime',
            ],
        ];
    }
}
