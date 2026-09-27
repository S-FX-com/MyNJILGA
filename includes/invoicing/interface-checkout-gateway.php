<?php
/**
 * CheckoutGateway — the seam the online join flow (includes/join/) uses to
 * take a payment while the applicant waits, as opposed to
 * MyNJILGA_Invoice_Gateway, which raises an invoice for someone to pay
 * later.
 *
 * Deliberately a SEPARATE interface rather than new methods on
 * MyNJILGA_Invoice_Gateway: a site that swaps the invoice gateway through
 * the `my_njilga_invoice_gateway` filter keeps working unchanged, and the
 * join form simply reports itself unavailable when the active gateway
 * doesn't also implement this one.
 *
 * Same conventions as the invoice gateway: money is integer cents, every
 * id is a STRING, and nothing outside an implementation names a Stripe
 * endpoint. $mode ('test'|'live') is explicit on every read because a
 * join is pinned to the mode it was started in — staff flipping the
 * site's active mode mid-checkout must not strand it.
 */
interface MyNJILGA_Checkout_Gateway {

    /**
     * Start a hosted checkout.
     *
     * @param array<int,array<string,mixed>> $lineItems Same shape as MyNJILGA_Invoice_Gateway::create_order()
     *                                                   takes (title, unit_price_cents, quantity, line_meta).
     * @param array<string,mixed>            $context   join_id (int, required), attempt (int), dues_year (int),
     *                                                   customer_email, success_url, cancel_url, description,
     *                                                   company_name, allow_ach (bool), create_invoice (bool),
     *                                                   plus mode/currency/footer overrides (tests).
     * @return array{ok:bool,session_id?:string,url?:string,expires_at?:int,ach_offered?:bool,error?:string}
     */
    public function create_checkout( array $lineItems, array $context ): array;

    /**
     * Current state of a checkout, normalized:
     *   id, status (open|complete|expired), payment_status (paid|unpaid|no_payment_required),
     *   amount_total (int cents), currency, livemode (bool), customer (id or ''),
     *   customer_email, metadata (array), client_reference_id, url, expires_at (int),
     *   payment_intent_id, payment_intent (expanded array incl. latest_charge, or null),
     *   invoice_id, invoice (expanded array, or null).
     * Null when it can't be read (not found, not connected, transport failure).
     *
     * @return array<string,mixed>|null
     */
    public function fetch_checkout( string $sessionId, string $mode ): ?array;

    /**
     * Expire an OPEN checkout so it can no longer be paid. ok:false with
     * the session's current status when it is not open any more (e.g.
     * 'complete' — the caller must then treat it as possibly paid).
     *
     * @return array{ok:bool,status?:string,error?:string}
     */
    public function expire_checkout( string $sessionId, string $mode ): array;

    /**
     * Make the customer a checkout created the firm's own billing
     * customer — used when a join brought a brand-new firm into being, so
     * the firm's first annual invoice lands on the same Stripe Customer.
     * Never overrides a firm that already has one.
     */
    public function adopt_customer_for_company( string $customerId, int $companyId, string $companyName, int $ownerContactId, string $mode ): bool;
}
