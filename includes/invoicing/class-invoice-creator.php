<?php
/**
 * Step 3 — creates the FluentCart order (invoice) for an approved firm
 * row: find-or-create the FluentCart Customer for the Owner's email,
 * build custom (non-catalog) line items from the frozen roster snapshot,
 * and create the order in-process via FluentCart's own order-placement
 * service (this plugin lives on the same WordPress install, so there's
 * no HTTP round-trip to itself).
 *
 * CONFIRM AGAINST THE LIVE SITE BEFORE FIRST REAL USE: the FluentCart
 * classes/methods below were reconstructed from https://dev.fluentcart.com's
 * public developer docs (the Customer/Order/OrderItem model field lists,
 * the Cart::addByCustom() custom-line-item shape, and the invoicing
 * spec's own OrderResource::updatedPlaceOrder() call) — this plugin
 * doesn't ship with FluentCart's source, so none of this could be
 * verified against the actual live implementation the way the rest of
 * this plugin's FluentCRM calls could be (those were checked against
 * patterns already proven elsewhere in this codebase). Run one real test
 * invoice (a throwaway test firm) on staging before relying on this for
 * actual dues billing.
 */
class MyNJILGA_Invoice_Creator {

    public static function fluentcart_active(): bool {
        return class_exists( '\\FluentCart\\Api\\Resource\\OrderResource' )
            && class_exists( '\\FluentCart\\App\\Models\\Customer' );
    }

    /**
     * @return array{ok:bool, error?:string}
     */
    public static function create_for_row( object $invoiceRow ): array {
        if ( ! self::fluentcart_active() ) {
            return [ 'ok' => false, 'error' => 'FluentCart is not active.' ];
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

            $orderItems = self::build_order_items( $members, (int) $invoiceRow->dues_year );
            if ( empty( $orderItems ) ) {
                return [ 'ok' => false, 'error' => 'Every roster member owes $0 — nothing to invoice.' ];
            }

            $order = \FluentCart\Api\Resource\OrderResource::updatedPlaceOrder( [
                'type'           => 'payment', // one-time, not subscription
                'customer_id'    => (int) $customer->id,
                'payment_status' => 'pending',
                'order_items'    => $orderItems,
            ] );

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
     * One custom (non-catalog) line item per fee — dues tier and, when
     * owed, the Trustee Dinner Fee — so the printed invoice reads
     * per-person, per-fee, e.g.:
     *   "Jane Doe — 2027 Membership Dues"      $125
     *   "John Smith — 2027 Membership Dues"    $75
     *   "John Smith — Trustee Dinner Fee"      $200
     * Members whose tier price is $0 (6th+, dues-exempt, or inactive) get
     * no dues line; members who don't owe the fee (not an active
     * Officer/Trustee/Senior Trustee/Past President) get no fee line.
     *
     * @param array<int,array{contact_id:int,name:string,tier_price_cents:int,trustee_fee_cents:int}> $members
     * @return array<int,array<string,mixed>>
     */
    private static function build_order_items( array $members, int $duesYear ): array {
        $items = [];

        foreach ( $members as $member ) {
            if ( $member['tier_price_cents'] > 0 ) {
                $items[] = self::custom_line_item(
                    $member['name'] . ' — ' . $duesYear . ' Membership Dues',
                    $member['tier_price_cents']
                );
            }
            if ( $member['trustee_fee_cents'] > 0 ) {
                $items[] = self::custom_line_item(
                    $member['name'] . ' — Trustee Dinner Fee',
                    $member['trustee_fee_cents']
                );
            }
        }

        return $items;
    }

    /**
     * Shape reconstructed from FluentCart's own custom-item fields (see
     * Cart::addByCustom() and the OrderItem is_custom accessor in the
     * public docs) — object_id/post_id are 0 since these carry no real
     * catalog Product; other_info.is_custom marks it as a custom line.
     */
    private static function custom_line_item( string $title, int $priceCents ): array {
        return [
            'object_id'    => 0,
            'post_id'      => 0,
            'post_title'   => $title,
            'title'        => $title,
            'quantity'     => 1,
            'price'        => $priceCents,
            'unit_price'   => $priceCents,
            'payment_type' => 'one_time',
            'other_info'   => [ 'is_custom' => true ],
        ];
    }
}
