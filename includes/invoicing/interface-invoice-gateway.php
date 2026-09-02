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
 *     'line_meta'        => array ]   // e.g. contact_id, dues_year, kind, category
 *
 * Bill-to (input to find_or_create_customer):
 *   [ 'contact_id' => int, 'email' => string, 'first_name' => string, 'last_name' => string ]
 *   The Stripe gateway additionally expects, when present, 'company_id'
 *   (int) and 'company_name' (string) — Stripe bills one Customer per
 *   FIRM, not per bill-to contact, and needs the firm's identity to
 *   find-or-create it (see MyNJILGA_Stripe_Invoice_Gateway::
 *   find_or_create_customer()). Extra keys on an associative array
 *   passed to an `array $billTo` parameter are not a type violation, so
 *   this stays a superset of the shape documented above rather than a
 *   change to the interface itself.
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
     * @param array<string,mixed>            $context   Free-form: dues_year, company_id, invoice_row_id,
     *                                                   invoice_kind, and (Stripe gateway) company_name.
     * @return array{ok:bool,invoice_id?:string,invoice_number?:string,hosted_url?:string,pdf_url?:string,due_date?:string,amount_due_cents?:int,error?:string}
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
     * Register a callback fired once when an invoice becomes fully paid.
     * The callback receives the invoice id (string) and the raw payment
     * details the gateway captured for that event.
     *
     * @param callable(string,array<string,mixed>):void $callback
     */
    public function on_invoice_paid( callable $callback ): void;

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
     * plugin/year, so the reconciler can spot invoices that exist at the
     * gateway with no row here. $cursor is opaque: pass null for the
     * first page, then whatever the previous call returned as
     * next_cursor until has_more is false.
     *
     * ok is false when the gateway could not be asked at all (not
     * connected, rate limited, a transport failure). An implementation
     * MUST NOT report that as an empty page: the caller is comparing
     * two sets, and "nothing came back" is not "there is nothing".
     *
     * @return array{ok:bool,invoices:array<int,array<string,mixed>>,has_more:bool,next_cursor:?string}
     */
    public function list_our_invoices( int $duesYear, ?string $cursor ): array;
}
