<?php
/**
 * InvoiceGateway (spec §9) — the ONLY seam between this plugin and the
 * commerce system that actually issues invoices and collects payment.
 * Nothing outside an implementation of this interface may reference a
 * FluentCart class; everything else talks in the plain arrays below.
 *
 * Money is integer cents everywhere.
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

    /** Human-readable name shown in admin notices ("FluentCart"). */
    public function name(): string;

    /** True when the commerce plugin is installed and its API is loaded. */
    public function is_available(): bool;

    /**
     * Configuration problems that would make EVERY create_order() fail
     * (e.g. a required payment method switched off). Empty when ready.
     *
     * @return array<int,string>
     */
    public function readiness_errors(): array;

    /**
     * Find the commerce customer for this email, or create one.
     *
     * @param array{contact_id:int,email:string,first_name:string,last_name:string} $billTo
     * @return int|null Customer id, or null on failure.
     */
    public function find_or_create_customer( array $billTo ): ?int;

    /**
     * Create an unpaid order (invoice) for the customer.
     *
     * @param array<int,array<string,mixed>> $lineItems See interface docblock.
     * @param array<string,mixed>            $context   Free-form: dues_year, company_id, invoice_row_id.
     * @return array{ok:bool,order_id?:int,order_uuid?:string,error?:string}
     */
    public function create_order( int $customerId, array $lineItems, array $context ): array;

    /** Public pay-now URL for an order, or '' if it can't be built. */
    public function payment_link( string $orderUuid ): string;

    /**
     * Live status of an order.
     *
     * @return array{payment_status:string,status:string,total_cents:int,paid_cents:int}|null
     */
    public function order_status( int $orderId ): ?array;

    /**
     * Catalog listing for the Settings page pickers.
     *
     * @return array<int,array{id:int,title:string,status:string,variations:array<int,array{id:int,title:string,price_cents:int,payment_type:string}>}>
     */
    public function list_products(): array;

    /**
     * Verify a product/variation pair exists and is purchasable.
     *
     * @return array{ok:bool,label:string,price_cents:int,error?:string}
     */
    public function check_variation( int $productId, int $variationId ): array;

    /**
     * Register a callback fired once when an order becomes fully paid.
     * The callback receives the order id (int) and nothing else.
     */
    public function on_order_paid( callable $callback ): void;
}
