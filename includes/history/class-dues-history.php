<?php
/**
 * Dues History — the read model behind the "Dues History" tab on a
 * FluentCRM contact and company, and behind the invoice viewer.
 *
 * It answers one question — "what has been invoiced to this person / this
 * firm, and what has been paid?" — from TWO places and merges them:
 *
 *   • LIVE: the Stripe invoicing tables (njilga_dues_invoices +
 *     njilga_dues_payments), read through on every view. Nothing is copied,
 *     so the tab can never be out of date: an invoice that is created,
 *     sent, paid, voided or refunded shows its new state the next time the
 *     tab is opened.
 *   • HISTORY: invoices recreated from PMPro or a spreadsheet
 *     (njilga_dues_history), which no live process ever touches.
 *
 * Both are normalised to one "entry" shape, so the view doesn't care which
 * a row came from. Everything that turns a row into an entry, classifies a
 * status or adds figures up is PURE (no database, no WordPress) and unit
 * tested in tests/DuesHistoryTest.php; the few methods that read the
 * database sit at the bottom.
 *
 * Entry shape:
 *   key, src ('live'|'hist'), id, origin ('stripe'|'pmpro'|'import'),
 *   livemode, year, number, description, kind,
 *   company_id, company, bill_to_id, bill_to,
 *   status  — paid | open | processing | lapsed | void | uncollectible,
 *   total, paid, refunded, balance (cents),
 *   issued, due, paid_on (Y-m-d or ''),
 *   method (label), hosted_url, pdf_url, notes,
 *   lines      — [ { title, amount, contact_id } ],
 *   member_ids — [ contact id ] everyone the invoice names,
 *   payments   — [ payment ]
 *
 * Payment shape:
 *   when (Y-m-d H:i:s or Y-m-d), amount (signed cents — a refund is
 *   negative), kind (payment|refund), method (full label), reference,
 *   receipt_url, status, plus the owning invoice's key / number / year /
 *   company so a flat payment list still says what each payment was for.
 */
class MyNJILGA_Dues_History {

    const SRC_LIVE = 'live';
    const SRC_HIST = 'hist';

    const ST_PAID          = 'paid';
    const ST_OPEN          = 'open';
    const ST_PROCESSING    = 'processing';
    const ST_LAPSED        = 'lapsed';
    const ST_VOID          = 'void';
    const ST_UNCOLLECTIBLE = 'uncollectible';

    /** Statuses where money is still owed. A downgraded invoice was never paid, so it counts (the Payments ledger agrees). */
    const OPEN_STATUSES = [ self::ST_OPEN, self::ST_PROCESSING, self::ST_LAPSED ];

    // -------------------------------------------------------------------------
    // PURE — rows to entries
    // -------------------------------------------------------------------------

    /**
     * A live invoice row's status as a history status, or null for a row
     * that has not been issued to anyone (draft, approved) or was never
     * going to be (excluded) — those are Invoicing's business, not a
     * dues transaction that has "taken place".
     */
    public static function classify_live( string $status ): ?string {
        switch ( $status ) {
            case 'created':
            case 'sent':          return self::ST_OPEN;
            case 'processing':    return self::ST_PROCESSING;
            case 'paid':          return self::ST_PAID;
            case 'downgraded':    return self::ST_LAPSED;
            case 'voided':        return self::ST_VOID;
            case 'uncollectible': return self::ST_UNCOLLECTIBLE;
            default:              return null;
        }
    }

    /**
     * Normalise a njilga_dues_invoices row (and its ledger rows) into an
     * entry. Null when the row is not an issued invoice (see
     * classify_live()).
     *
     * @param object            $row      A njilga_dues_invoices row.
     * @param array<int,object> $payments That row's njilga_dues_payments rows.
     * @return array<string,mixed>|null
     */
    public static function live_entry( $row, array $payments = [] ): ?array {
        $status = self::classify_live( (string) $row->status );
        if ( $status === null ) {
            return null;
        }

        $year     = (int) $row->dues_year;
        $snapshot = MyNJILGA_Dues_Snapshot::decode( $row );
        $members  = (array) $snapshot['members'];
        $kind     = MyNJILGA_Dues_Snapshot::invoice_kind( $row );
        $billTo   = MyNJILGA_Dues_Snapshot::bill_to( $row );

        $lines = [];
        foreach ( MyNJILGA_Dues_Roster::line_items( $members, $year, $kind ) as $item ) {
            $lines[] = [
                'title'      => (string) $item['title'],
                'amount'     => (int) $item['unit_price_cents'] * max( 1, (int) ( $item['quantity'] ?? 1 ) ),
                'contact_id' => (int) ( $item['line_meta']['contact_id'] ?? 0 ),
            ];
        }

        $total    = (int) $row->total_amount_cents;
        $paid     = (int) $row->amount_paid_cents;
        $refunded = (int) $row->amount_refunded_cents;
        $balance  = 0;
        if ( in_array( $status, self::OPEN_STATUSES, true ) ) {
            $balance = (int) $row->amount_due_cents;
            // An open invoice whose amount_due was never copied back from
            // Stripe is still owed in full, not owed nothing.
            if ( $balance <= 0 ) {
                $balance = max( 0, $total - $paid );
            }
        }

        $memberIds = [];
        foreach ( array_merge( [ (int) $billTo['contact_id'] ], array_map( static function ( $m ) {
            return (int) ( $m['contact_id'] ?? 0 );
        }, $members ) ) as $cid ) {
            if ( $cid > 0 ) {
                $memberIds[ $cid ] = $cid;
            }
        }

        $entry = [
            'key'         => self::SRC_LIVE . ':' . (int) $row->id,
            'src'         => self::SRC_LIVE,
            'id'          => (int) $row->id,
            'origin'      => 'stripe',
            'livemode'    => ! empty( $row->livemode ),
            'year'        => $year,
            'number'      => (string) ( $row->gateway_invoice_number ?? '' ) !== '' ? (string) $row->gateway_invoice_number : '#' . (int) $row->id,
            'description' => self::live_description( $year, $kind, count( $members ) ),
            'kind'        => $kind,
            'company_id'  => (int) $row->fluentcrm_company_id,
            'company'     => MyNJILGA_Dues_Snapshot::company_name( $row ),
            'bill_to_id'  => (int) $billTo['contact_id'],
            'bill_to'     => $billTo['name'],
            'status'      => $status,
            'total'       => $total,
            'paid'        => $paid,
            'refunded'    => $refunded,
            'balance'     => $balance,
            'issued'      => self::first_date( [ $row->sent_at ?? '', $row->finalized_at ?? '', $row->created_at ?? '' ] ),
            'due'         => self::date_part( (string) ( $row->due_date ?? '' ) ),
            'paid_on'     => self::date_part( (string) ( $row->paid_at ?? '' ) ),
            'method'      => self::method_label( (string) ( $row->primary_method ?? '' ) ),
            'hosted_url'  => (string) ( $row->hosted_invoice_url ?? '' ),
            'pdf_url'     => (string) ( $row->invoice_pdf_url ?? '' ),
            'notes'       => '',
            'lines'       => $lines,
            'member_ids'  => array_values( $memberIds ),
            'payments'    => [],
        ];

        foreach ( $payments as $p ) {
            $entry['payments'][] = self::live_payment( $entry, $p );
        }
        usort( $entry['payments'], [ __CLASS__, 'by_when_desc' ] );
        return $entry;
    }

    /**
     * Normalise a njilga_dues_history row into an entry.
     *
     * @param object $row A njilga_dues_history row.
     * @return array<string,mixed>
     */
    public static function hist_entry( $row ): array {
        $lines = [];
        $raw   = json_decode( (string) ( $row->line_items ?? '' ), true );
        foreach ( is_array( $raw ) ? $raw : [] as $l ) {
            if ( ! is_array( $l ) ) {
                continue;
            }
            $lines[] = [
                'title'      => (string) ( $l['title'] ?? '' ),
                'amount'     => (int) ( $l['amount'] ?? 0 ),
                'contact_id' => (int) ( $l['contact_id'] ?? 0 ),
            ];
        }

        $stored = (string) $row->status;
        $total  = (int) $row->total_cents;
        $paid   = (int) $row->paid_cents;
        if ( $stored === MyNJILGA_Dues_History_Table::STATUS_VOID ) {
            $status  = self::ST_VOID;
            $balance = 0;
        } elseif ( $stored === MyNJILGA_Dues_History_Table::STATUS_OPEN ) {
            $status  = self::ST_OPEN;
            $balance = max( 0, $total - $paid );
        } else {
            $status  = self::ST_PAID;
            $balance = 0;
        }

        $origin = (string) $row->source;
        $number = (string) ( $row->invoice_number ?? '' ) !== '' ? (string) $row->invoice_number : '#H' . (int) $row->id;
        $issued = self::date_part( (string) ( $row->invoice_date ?? '' ) );
        $paidOn = self::date_part( (string) ( $row->paid_at ?? '' ) );

        $entry = [
            'key'         => self::SRC_HIST . ':' . (int) $row->id,
            'src'         => self::SRC_HIST,
            'id'          => (int) $row->id,
            'origin'      => $origin,
            'livemode'    => true,
            'year'        => (int) $row->dues_year,
            'number'      => $number,
            'description' => (string) ( $row->description ?? '' ) !== '' ? (string) $row->description : ( (int) $row->dues_year . ' membership dues' ),
            'kind'        => 'historical',
            'company_id'  => (int) $row->company_id,
            'company'     => (string) $row->company_name,
            'bill_to_id'  => (int) $row->contact_id,
            'bill_to'     => (string) ( $row->contact_name ?? '' ) !== '' ? (string) $row->contact_name : (string) ( $row->contact_email ?? '' ),
            'status'      => $status,
            'total'       => $total,
            'paid'        => $paid,
            'refunded'    => 0,
            'balance'     => $balance,
            'issued'      => $issued !== '' ? $issued : $paidOn,
            'due'         => self::date_part( (string) ( $row->due_date ?? '' ) ),
            'paid_on'     => $paidOn,
            'method'      => self::method_label( (string) ( $row->method ?? '' ) ),
            'hosted_url'  => '',
            'pdf_url'     => '',
            'notes'       => (string) ( $row->notes ?? '' ),
            'lines'       => $lines,
            'member_ids'  => self::parse_member_ids( (string) ( $row->member_ids ?? '' ), (int) $row->contact_id ),
            'payments'    => [],
        ];

        if ( $paid > 0 ) {
            $entry['payments'][] = [
                'when'           => $paidOn !== '' ? $paidOn : $entry['issued'],
                'amount'         => $paid,
                'kind'           => 'payment',
                'method'         => self::full_method( (string) ( $row->method ?? '' ), (string) ( $row->method_detail ?? '' ), (string) ( $row->reference ?? '' ) ),
                'reference'      => (string) ( $row->reference ?? '' ),
                'receipt_url'    => '',
                'status'         => 'recorded',
                'invoice_key'    => $entry['key'],
                'invoice_number' => $entry['number'],
                'year'           => $entry['year'],
                'company'        => $entry['company'],
            ];
        }
        return $entry;
    }

    /**
     * One njilga_dues_payments row as a payment entry.
     *
     * @param array<string,mixed> $entry The invoice entry it belongs to.
     * @param object              $p     A njilga_dues_payments row.
     * @return array<string,mixed>
     */
    private static function live_payment( array $entry, $p ): array {
        $isRefund = (string) $p->kind === 'refund';
        $cents    = (int) $p->amount_cents;
        return [
            'when'           => (string) $p->occurred_at,
            'amount'         => $isRefund ? -abs( $cents ) : $cents,
            'kind'           => $isRefund ? 'refund' : 'payment',
            'method'         => self::live_payment_method( $p ),
            'reference'      => (string) ( $p->reference ?? '' ),
            'receipt_url'    => (string) ( $p->receipt_url ?? '' ),
            'status'         => (string) ( $p->status ?? '' ),
            'invoice_key'    => $entry['key'],
            'invoice_number' => $entry['number'],
            'year'           => $entry['year'],
            'company'        => $entry['company'],
        ];
    }

    /**
     * "Visa ••4242", "ACH — Chase Bank ••6789", "Check #4417", "Marked
     * paid in Stripe" — the same wording the Payments ledger uses.
     *
     * @param object $p A njilga_dues_payments row.
     */
    public static function live_payment_method( $p ): string {
        $method = (string) ( $p->method ?? '' );
        $brand  = (string) ( $p->card_brand ?? '' );
        $last4  = (string) ( $p->last4 ?? '' );
        $bank   = (string) ( $p->bank_name ?? '' );
        $ref    = (string) ( $p->reference ?? '' );

        if ( $method === 'card' && $brand !== '' ) {
            return ucfirst( $brand ) . ( $last4 !== '' ? ' ••' . $last4 : '' );
        }
        if ( $method === 'us_bank_account' || $bank !== '' ) {
            return 'ACH' . ( $bank !== '' ? ' — ' . $bank : '' ) . ( $last4 !== '' ? ' ••' . $last4 : '' );
        }
        if ( $method === 'check' ) {
            return 'Check' . ( $ref !== '' ? ' #' . $ref : '' );
        }
        if ( $method === 'wire' ) {
            return 'Wire' . ( $ref !== '' ? ' ref ' . $ref : '' );
        }
        if ( $method === 'cash' ) {
            return 'Cash';
        }
        if ( $ref === 'Marked paid in Stripe' ) {
            return $ref;
        }
        $label = self::method_label( $method );
        return $label !== '' ? $label : 'Other';
    }

    /**
     * Coarse method label. Covers the Stripe ledger's values (card,
     * us_bank_account, check, cash, wire, other) and the ones history rows
     * add (ach, paypal).
     */
    public static function method_label( string $method ): string {
        $labels = [
            'card'            => 'Card',
            'us_bank_account' => 'ACH',
            'ach'             => 'ACH',
            'check'           => 'Check',
            'cash'            => 'Cash',
            'wire'            => 'Wire',
            'paypal'          => 'PayPal',
            'other'           => 'Other',
        ];
        return $labels[ strtolower( $method ) ] ?? '';
    }

    /**
     * Method label for a history row's payment: its detail when it has
     * one ("Visa ••4242"), else the method plus a check number / wire ref.
     */
    public static function full_method( string $method, string $detail, string $reference ): string {
        if ( $detail !== '' ) {
            return $detail;
        }
        $label = self::method_label( $method );
        if ( $label === '' ) {
            return '';
        }
        if ( strtolower( $method ) === 'check' && $reference !== '' ) {
            return 'Check #' . $reference;
        }
        if ( strtolower( $method ) === 'wire' && $reference !== '' ) {
            return 'Wire ref ' . $reference;
        }
        return $label;
    }

    /**
     * "2027 membership dues — 6 members", "2027 Trustee Dinner
     * assessment", "2027 dues, joined online".
     */
    public static function live_description( int $year, string $kind, int $members ): string {
        if ( $kind === MyNJILGA_Dues_Snapshot::KIND_ASSESSMENT ) {
            return $year . ' Trustee Dinner assessment';
        }
        if ( $kind === MyNJILGA_Dues_Snapshot::KIND_JOIN ) {
            return $year . ' membership dues, joined online';
        }
        $base = $year . ' membership dues';
        return $members > 0 ? sprintf( '%s — %d member%s', $base, $members, $members === 1 ? '' : 's' ) : $base;
    }

    // -------------------------------------------------------------------------
    // PURE — figures
    // -------------------------------------------------------------------------

    /**
     * Whether money is still owed on this entry.
     *
     * @param array<string,mixed> $entry
     */
    public static function is_open( array $entry ): bool {
        return in_array( $entry['status'], self::OPEN_STATUSES, true ) && (int) $entry['balance'] > 0;
    }

    /**
     * An open invoice whose due date has passed.
     *
     * @param array<string,mixed> $entry
     * @param string              $today Y-m-d.
     */
    public static function is_overdue( array $entry, string $today ): bool {
        return $entry['status'] === self::ST_OPEN && (int) $entry['balance'] > 0 && $entry['due'] !== '' && $entry['due'] < $today;
    }

    /**
     * What this contact's own lines add up to on an invoice — their share
     * of a firm invoice — or null when no line names them.
     *
     * @param array<string,mixed> $entry
     */
    public static function share_for( array $entry, int $contactId ): ?int {
        if ( $contactId <= 0 ) {
            return null;
        }
        $sum   = 0;
        $found = false;
        foreach ( $entry['lines'] as $l ) {
            if ( (int) $l['contact_id'] === $contactId ) {
                $sum  += (int) $l['amount'];
                $found = true;
            }
        }
        return $found ? $sum : null;
    }

    /**
     * Headline figures for a set of entries.
     *
     * paid_cents is what came in (every payment, before refunds);
     * refunded_cents what went back out. open_* is what is still owed.
     *
     * @param array<int,array<string,mixed>> $entries
     * @return array{invoices:int,open_count:int,open_cents:int,paid_cents:int,refunded_cents:int,last_payment:string,first_year:int,last_year:int}
     */
    public static function summarise( array $entries ): array {
        $out = [
            'invoices'       => 0,
            'open_count'     => 0,
            'open_cents'     => 0,
            'paid_cents'     => 0,
            'refunded_cents' => 0,
            'last_payment'   => '',
            'first_year'     => 0,
            'last_year'      => 0,
        ];
        foreach ( $entries as $e ) {
            $out['invoices']++;
            if ( self::is_open( $e ) ) {
                $out['open_count']++;
                $out['open_cents'] += (int) $e['balance'];
            }
            $out['paid_cents']     += (int) $e['paid'];
            $out['refunded_cents'] += (int) $e['refunded'];
            $year = (int) $e['year'];
            if ( $year > 0 ) {
                $out['first_year'] = $out['first_year'] === 0 ? $year : min( $out['first_year'], $year );
                $out['last_year']  = max( $out['last_year'], $year );
            }
            foreach ( $e['payments'] as $p ) {
                if ( $p['kind'] === 'payment' && $p['amount'] > 0 ) {
                    $day = substr( (string) $p['when'], 0, 10 );
                    if ( $day > $out['last_payment'] ) {
                        $out['last_payment'] = $day;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Newest dues year first; within a year, newest invoice first.
     *
     * @param array<int,array<string,mixed>> $entries
     * @return array<int,array<string,mixed>>
     */
    public static function sort_entries( array $entries ): array {
        usort( $entries, static function ( $a, $b ) {
            if ( $a['year'] !== $b['year'] ) {
                return $b['year'] <=> $a['year'];
            }
            if ( $a['issued'] !== $b['issued'] ) {
                return strcmp( (string) $b['issued'], (string) $a['issued'] );
            }
            return strcmp( (string) $b['key'], (string) $a['key'] );
        } );
        return $entries;
    }

    /**
     * Only the entries that still owe money, oldest dues year first — the
     * order someone chasing them wants.
     *
     * @param array<int,array<string,mixed>> $entries
     * @return array<int,array<string,mixed>>
     */
    public static function open_entries( array $entries ): array {
        $open = array_values( array_filter( $entries, [ __CLASS__, 'is_open' ] ) );
        usort( $open, static function ( $a, $b ) {
            return $a['year'] !== $b['year'] ? $a['year'] <=> $b['year'] : strcmp( (string) $a['key'], (string) $b['key'] );
        } );
        return $open;
    }

    /**
     * Every payment across the entries, newest first.
     *
     * @param array<int,array<string,mixed>> $entries
     * @return array<int,array<string,mixed>>
     */
    public static function all_payments( array $entries ): array {
        $out = [];
        foreach ( $entries as $e ) {
            foreach ( $e['payments'] as $p ) {
                $out[] = $p;
            }
        }
        usort( $out, [ __CLASS__, 'by_when_desc' ] );
        return $out;
    }

    /**
     * The member_ids column's value: ",5,9," (or '' for none). The
     * delimiters on both ends are what let `LIKE '%,5,%'` find id 5
     * without also matching 15 or 55.
     *
     * @param array<int,int> $ids
     */
    public static function member_ids_column( array $ids ): string {
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static function ( $i ) { return $i > 0; } ) ) );
        sort( $ids );
        return $ids ? ',' . implode( ',', $ids ) . ',' : '';
    }

    /**
     * @return array<int,int>
     */
    public static function parse_member_ids( string $column, int $alsoContactId = 0 ): array {
        $ids = [];
        foreach ( explode( ',', $column ) as $part ) {
            $id = (int) trim( $part );
            if ( $id > 0 ) {
                $ids[ $id ] = $id;
            }
        }
        if ( $alsoContactId > 0 ) {
            $ids[ $alsoContactId ] = $alsoContactId;
        }
        return array_values( $ids );
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     */
    private static function by_when_desc( array $a, array $b ): int {
        return strcmp( (string) $b['when'], (string) $a['when'] );
    }

    /** 'Y-m-d' from a MySQL date or datetime; '' for empty or zero dates. */
    public static function date_part( string $value ): string {
        $day = substr( trim( $value ), 0, 10 );
        return ( $day === '' || strpos( $day, '0000' ) === 0 ) ? '' : $day;
    }

    /**
     * @param array<int,mixed> $values
     */
    private static function first_date( array $values ): string {
        foreach ( $values as $v ) {
            $d = self::date_part( (string) $v );
            if ( $d !== '' ) {
                return $d;
            }
        }
        return '';
    }

    // -------------------------------------------------------------------------
    // Database — thin; everything above is what is tested
    // -------------------------------------------------------------------------

    /**
     * Whether the live half should read Live or Test invoices — the same
     * mode the Payments ledger follows, so the two screens never disagree.
     * History rows are not mode-bound.
     */
    public static function live_mode(): bool {
        return MyNJILGA_Stripe_Connection::active_mode() === MyNJILGA_Stripe_Connection::MODE_LIVE;
    }

    /**
     * Everything invoiced to a firm — live invoices and history rows —
     * newest year first.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function for_company( int $companyId ): array {
        if ( $companyId <= 0 ) {
            return [];
        }
        $live = MyNJILGA_Dues_Invoice_Table::get_for_companies( [ $companyId ], self::live_mode() );
        $hist = MyNJILGA_Dues_History_Table::for_companies( [ $companyId ] );
        return self::build( $live, $hist );
    }

    /**
     * Everything that names this contact — as bill-to or as a member of
     * the roster — newest year first. A firm invoice they are covered by
     * is included whether or not they paid it.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function for_contact( int $contactId ): array {
        if ( $contactId <= 0 ) {
            return [];
        }
        $live = MyNJILGA_Dues_Invoice_Table::rows_involving_contact( $contactId, self::live_mode() );
        $hist = MyNJILGA_Dues_History_Table::for_contact( $contactId );
        return self::build( $live, $hist );
    }

    /**
     * One invoice by source and id, for the viewer. Staff can look at a
     * Test-mode invoice too, so this is not mode-scoped; the entry says
     * which mode it is.
     *
     * @return array<string,mixed>|null
     */
    public static function entry( string $src, int $id ): ?array {
        if ( $id <= 0 ) {
            return null;
        }
        if ( $src === self::SRC_HIST ) {
            $row = MyNJILGA_Dues_History_Table::get( $id );
            return $row ? self::hist_entry( $row ) : null;
        }
        $row = MyNJILGA_Dues_Invoice_Table::get( $id );
        if ( ! $row ) {
            return null;
        }
        return self::live_entry( $row, MyNJILGA_Dues_Payments_Table::get_for_invoice_row( (int) $row->id ) );
    }

    /**
     * @param array<int,object> $liveRows
     * @param array<int,object> $histRows
     * @return array<int,array<string,mixed>>
     */
    private static function build( array $liveRows, array $histRows ): array {
        $paymentsByRow = MyNJILGA_Dues_Payments_Table::get_for_invoice_rows( array_map( static function ( $r ) {
            return (int) $r->id;
        }, $liveRows ) );

        $entries = [];
        foreach ( $liveRows as $row ) {
            $e = self::live_entry( $row, $paymentsByRow[ (int) $row->id ] ?? [] );
            if ( $e !== null ) {
                $entries[] = $e;
            }
        }
        foreach ( $histRows as $row ) {
            $entries[] = self::hist_entry( $row );
        }
        return self::sort_entries( $entries );
    }
}
