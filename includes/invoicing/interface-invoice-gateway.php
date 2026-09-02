<?php
/**
 * InvoiceGateway (spec §9) — the ONLY seam between this plugin and the
 * commerce system that actually issues invoices and collects payment.
 * Nothing outside an implementation of this interface may reference a
 * Stripe (or other gateway SDK) class; everything else talks in the plain
 * arrays below.
 *
 * Money is integer cents everywhere. Every invoice/customer id is a
 * STRING (a Stripe object id such as `in_...`/`cus_...`, or whatever a
 * future gateway's own id format is) — never assume it's numeric or
 * safe to cast to int.
 *
 * Line item (input to create_order):
 *   [ 'title'            => string,   // printed on the invoice
 *     'unit_price_cents' => int,
 *     'quantity'         => int,      // always 1 for dues
 *     'product_id'       => int,      // catalog product (0 = custom line)
 *     'variation_id'     => int,      // catalog variation (0 = custom line)
 *     'line_meta'        => array ]   // e.g. contact_id, dues_year, kind, category
 *
 * Bill-to (input to find_or_create_customer):
 *   [ 'contact_id' => int, 'email' => string, 'first_name' => string, 'last_name' => string ]
 */
interface MyNJILGA_Invoice_Gateway {

    /** Human-readable name shown in admin notices ("Stripe"). */
    public function name(): string;

    /** True when the gateway's credentials/SDK are usable right now. */
    public function is_available(): bool;

    /**
     * Configuration problems that would make EVERY create_order() fail
     * (e.g. not connected, a required setting missing). Empty when ready.
     *
     * @return array<int,string>
     */
    public function readiness_errors(): array;

    /**
     * Find the gateway's customer for this email, or create one.
     *
     * @param array{contact_id:int,email:string,first_name:string,last_name:string} $billTo
     * @return string|null Customer id, or null on failure.
     */
    public function find_or_create_customer( array $billTo ): ?string;

    /**
     * Create an unpaid invoice for the customer.
     *
     * @param array<int,array<string,mixed>> $lineItems See interface docblock.
     * @param array<string,mixed>            $context   Free-form: dues_year, company_id, invoice_row_id.
     * @return array{ok:bool,invoice_id?:string,invoice_number?:string,hosted_url?:string,pdf_url?:string,due_date?:string,error?:string}
     *   invoice_id is the gateway's own id (Stripe: `in_...`); invoice_number
     *   is the gateway's human-readable number (Stripe: the `number` field,
     *   e.g. "NJILGA-0001") — these are DIFFERENT strings, never conflated.
     */
    public function create_order( string $customerId, array $lineItems, array $context ): array;

    /**
     * Live status of an invoice.
     *
     * @return array{status:string,stripe_status:string,amount_due_cents:int,amount_paid_cents:int,total_cents:int}|null
     *   `status` is OUR vocabulary (open/paid/void/uncollectible), mapped
     *   from the gateway's own value; `stripe_status` is that gateway
     *   value verbatim, unmapped, for diagnostics.
     */
    public function invoice_status( string $invoiceId ): ?array;

    /**
     * Catalog listing for the Settings page pickers. OPTIONAL — a gateway
     * with no catalog concept (Stripe, billing inline line items) returns
     * [] and callers (the Settings page) must tolerate that: no product a
     * dues line can be mapped to, so the price ($) field alone is what's
     * charged.
     *
     * @return array<int,array{id:int,title:string,status:string,variations:array<int,array{id:int,title:string,price_cents:int,payment_type:string}>}>
     */
    public function list_products(): array;

    /**
     * Verify a product/variation pair exists and is purchasable. OPTIONAL
     * — a catalog-less gateway returns array{ok:true,label:'Inline line
     * item',price_cents:0}.
     *
     * @return array{ok:bool,label:string,price_cents:int,error?:string}
     */
    public function check_variation( int $productId, int $variationId ): array;

    /**
     * Register a callback fired once when an invoice becomes fully paid.
     * The callback receives the invoice id (string) and the raw payment
     * details the gateway captured for that event.
     *
     * @param callable(string,array<string,mixed>):void $callback
     */
    public function on_invoice_paid( callable $callback ): void;

    /**
     * Mark an open invoice paid outside the gateway (check/cash/wire).
     * $meta carries payment_method/check_number/check_date/recorded_by
     * keys, free-form — gateway-specific what it does with them (e.g.
     * Stripe: pay the invoice out-of-band and note the detail on it).
     *
     * @param array<string,mixed> $meta
     * @return array{ok:bool,error?:string}
     */
    public function mark_paid_out_of_band( string $invoiceId, array $meta ): array;

    /**
     * Void an invoice. Terminal — a voided invoice cannot be paid or
     * reopened.
     *
     * @return array{ok:bool,error?:string}
     */
    public function void_invoice( string $invoiceId ): array;

    /**
     * Full normalized live state of an invoice, for reconciliation — same
     * shape family as invoice_status() (status/stripe_status/
     * amount_due_cents/amount_paid_cents/total_cents are always present)
     * but may carry additional gateway-native detail useful to a
     * reconciler (e.g. hosted_url, pdf_url, customer id, timestamps).
     * Null when the invoice can't be found. A later phase's reconciler is
     * the only consumer.
     *
     * @return array<string,mixed>|null
     */
    public function fetch_invoice( string $invoiceId ): ?array;

    /**
     * Page through the gateway's invoices tagged as belonging to this
     * plugin/year. A later phase's reconciler is the only consumer.
     *
     * @return array{invoices:array<int,array<string,mixed>>,has_more:bool,next_starting_after:?string}
     */
    public function list_our_invoices( int $duesYear, ?string $startingAfter ): array;
}
