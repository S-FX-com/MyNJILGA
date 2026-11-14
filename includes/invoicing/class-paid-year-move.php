<?php
/**
 * Count a PAID dues payment toward the coming dues year instead of this
 * one — an online join, or a firm's dues invoice.
 *
 * A join made once next year's invoices exist (or after the "Joins cover
 * next year from" cutover) is recorded against NEXT year and covers the
 * rest of this one — MyNJILGA_Join_Form::dues_year(). A late-year join
 * made before either was in place is recorded against THIS year, and a
 * firm may have paid a this-year invoice late in the year (or one raised
 * by mistake). Either way next year's batch would bill those members
 * again. This puts it right after the fact:
 *
 *   - the paid row (and, for a join, the join itself) moves to the
 *     coming year. A firm's moved invoice becomes its coming-year
 *     invoice, already paid: the firm's coming-year preview drafts are
 *     cleared, and Refresh Firms leaves a paid invoice alone;
 *   - its members are tagged Dues Paid {coming year}, keeping this year's
 *     tag (the payment still covers the rest of this year). Any preview
 *     for the coming year prices someone with that tag at $0
 *     (MyNJILGA_Dues_Preview::year_paid_contacts()), so nobody on it is
 *     billed again whichever invoice they end up on;
 *   - a note goes on the firm's Company record.
 *
 * Nothing changes in Stripe: the payment stands, and the Stripe invoice
 * still reads the year it was raised for. Webhooks and the orphan scan
 * find the moved row by its Stripe invoice id.
 */
class MyNJILGA_Paid_Year_Move {

    /** Kinds that can be moved: those that pay dues. An assessment-only invoice can't. */
    const KINDS = [
        MyNJILGA_Dues_Snapshot::KIND_JOIN,
        MyNJILGA_Dues_Snapshot::KIND_COMBINED,
        MyNJILGA_Dues_Snapshot::KIND_DUES,
    ];

    /**
     * PURE: why this row can't be moved to $toYear ('' when it can).
     * Only a PAID dues invoice or online join, and only forward by exactly
     * one year, to the coming dues year.
     */
    public static function refusal( object $row, int $toYear ): string {
        if ( ! in_array( (string) ( $row->invoice_kind ?? '' ), self::KINDS, true ) ) {
            return 'Only a dues invoice or an online join can be moved to another year.';
        }
        if ( (string) ( $row->status ?? '' ) !== MyNJILGA_Dues_Invoice_Table::STATUS_PAID ) {
            return 'Only a paid invoice can be moved.';
        }
        if ( (int) ( $row->dues_year ?? 0 ) !== $toYear - 1 ) {
            return sprintf( 'Only a %d payment can be moved to %d.', $toYear - 1, $toYear );
        }
        return '';
    }

    /** Whether the Invoicing page should offer the move for this row. */
    public static function offered( object $row ): bool {
        return self::refusal( $row, MyNJILGA_Invoicing::default_dues_year() ) === '';
    }

    /**
     * PURE: for a firm invoice, which of the firm's rows in the target
     * year stand in the way. Drafts and exceptions are a preview — they
     * are cleared ('clear'). Anything further along is a real invoice
     * ('stop'): the move is refused until staff void it. Online joins
     * are someone's own payment, never in the way.
     *
     * @param array<int,object> $targetRows The firm's rows in the target year, this mode.
     * @return array{clear:array<int,int>,stop:array<int,object>}
     */
    public static function target_conflicts( array $targetRows ): array {
        $out = [ 'clear' => [], 'stop' => [] ];
        foreach ( $targetRows as $r ) {
            if ( (string) ( $r->invoice_kind ?? '' ) === MyNJILGA_Dues_Snapshot::KIND_JOIN ) {
                continue;
            }
            if ( in_array( (string) $r->status, [ MyNJILGA_Dues_Invoice_Table::STATUS_DRAFT, MyNJILGA_Dues_Invoice_Table::STATUS_EXCLUDED ], true ) ) {
                $out['clear'][] = (int) $r->id;
            } else {
                $out['stop'][] = $r;
            }
        }
        return $out;
    }

    /**
     * Do it.
     *
     * @return array{ok:bool,error:string,to_year:int,members:int,join:bool}
     */
    public static function move( int $rowId ): array {
        $toYear = MyNJILGA_Invoicing::default_dues_year();
        $out    = [ 'ok' => false, 'error' => '', 'to_year' => $toYear, 'members' => 0, 'join' => false ];

        $row = MyNJILGA_Dues_Invoice_Table::get( $rowId );
        if ( ! $row ) {
            $out['error'] = 'That invoice wasn\'t found.';
            return $out;
        }
        $refusal = self::refusal( $row, $toYear );
        if ( $refusal !== '' ) {
            $out['error'] = $refusal;
            return $out;
        }

        $fromYear  = (int) $row->dues_year;
        $live      = ! empty( $row->livemode );
        $kind      = (string) $row->invoice_kind;
        $companyId = (int) $row->fluentcrm_company_id;
        $isJoin    = $kind === MyNJILGA_Dues_Snapshot::KIND_JOIN;
        $out['join'] = $isJoin;

        if ( $isJoin ) {
            if ( MyNJILGA_Dues_Invoice_Table::find_row( $companyId, $toYear, $kind, (int) $row->bill_to_contact_id, $live ) ) {
                $out['error'] = sprintf( 'This person already has a %d online join on record, so this one can\'t be moved onto it.', $toYear );
                return $out;
            }
        } else {
            $targetRows = array_filter( MyNJILGA_Dues_Invoice_Table::get_for_companies( [ $companyId ], $live ), static function ( $r ) use ( $toYear ) {
                return (int) $r->dues_year === $toYear;
            } );
            $conflicts = self::target_conflicts( array_values( $targetRows ) );
            if ( $conflicts['stop'] ) {
                $first = $conflicts['stop'][0];
                $out['error'] = sprintf(
                    'This firm already has a %d invoice (%s). Void it on the %d Invoicing page first, then move this one.',
                    $toYear,
                    (string) $first->status,
                    $toYear
                );
                return $out;
            }
            // The firm's coming-year preview is superseded by this paid
            // invoice — and would collide with it on the table's unique key.
            if ( $conflicts['clear'] ) {
                MyNJILGA_Dues_Invoice_Table::delete_stale_drafts( $companyId, $toYear, [], $live );
            }
        }

        $snapshot              = MyNJILGA_Dues_Snapshot::decode( $row );
        $snapshot['dues_year'] = $toYear;
        if ( ! MyNJILGA_Dues_Invoice_Table::move_paid_year( $rowId, $fromYear, $toYear, $kind, MyNJILGA_Dues_Snapshot::encode( $snapshot ) ) ) {
            $out['error'] = 'The invoice changed while this was running — reload the page and try again.';
            return $out;
        }

        $joinId = (int) ( $snapshot['join_id'] ?? 0 );
        if ( $isJoin && $joinId > 0 ) {
            MyNJILGA_Join_Orders_Table::update( $joinId, [ 'dues_year' => $toYear ] );
        }

        // Paid for the coming year, and still for the rest of this one.
        $tagId = MyNJILGA_Tags::get_or_create_by_title( MyNJILGA_Dues_Settings::year_tag( 'year_paid_tag_pattern', $toYear ) );
        $names = [];
        foreach ( MyNJILGA_Dues_Snapshot::members( $row ) as $m ) {
            $contact = \FluentCrm\App\Models\Subscriber::find( (int) ( $m['contact_id'] ?? 0 ) );
            if ( $contact && $tagId ) {
                $contact->attachTags( [ $tagId ] );
            }
            $names[] = (string) ( $m['name'] ?? '' );
            $out['members']++;
        }
        MyNJILGA_Membership_Stats::flush();

        $actor = wp_get_current_user();
        MyNJILGA_Invoicing_Notes::log(
            $companyId,
            sprintf( '%s moved to %d', $isJoin ? 'Online join' : 'Paid dues invoice', $toYear ),
            sprintf(
                'The %d %s paid for %s now counts toward %d dues (it still covers the rest of %d), so the %d invoices won\'t bill for %s again. Moved by %s.',
                $fromYear,
                $isJoin ? 'online join' : 'dues invoice',
                implode( ', ', array_filter( $names ) ),
                $toYear,
                $fromYear,
                $toYear,
                count( $names ) === 1 ? 'them' : 'any of them',
                ( $actor && $actor->display_name !== '' ) ? $actor->display_name : 'a staff member'
            )
        );

        $out['ok'] = true;
        return $out;
    }
}
