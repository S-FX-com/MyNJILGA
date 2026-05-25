<?php
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\{Alignment, Fill};

/**
 * Generates the My NJILGA workbook with three sheets:
 *   - Active Members
 *   - Trustees
 *   - Companies (sectioned by paid-member bucket)
 *
 * Call ::stream() to push the file to the browser.
 */
class MyNJILGA_Report_Xlsx {

    const COLOR_HEADER_BG   = 'FF003366';
    const COLOR_HEADER_TEXT = 'FFFFFFFF';
    const COLOR_TIER_BG     = 'FFD9E1F2';
    const COLOR_TOTAL_BG    = 'FFE2EFDA';
    const COLOR_PAID        = 'FF70AD47';
    const COLOR_UNPAID      = 'FFD63638';

    public static function stream(): void {
        $spreadsheet = self::build();
        $filename    = 'MyNJILGA_Report_' . (int) date( 'Y' ) . '.xlsx';

        header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Cache-Control: max-age=0' );

        ( new Xlsx( $spreadsheet ) )->save( 'php://output' );
        exit;
    }

    private static function build(): Spreadsheet {
        $book = new Spreadsheet();

        self::build_members_sheet( $book->getActiveSheet(), MyNJILGA_Members_Data::get_active_members() );

        $trustees_sheet = $book->createSheet();
        self::build_trustees_sheet( $trustees_sheet, MyNJILGA_Members_Data::get_trustees() );

        $companies_sheet = $book->createSheet();
        self::build_companies_sheet( $companies_sheet, MyNJILGA_Members_Data::get_companies_bucketed() );

        $book->setActiveSheetIndex( 0 );
        return $book;
    }

    // -------------------------------------------------------------------------
    // Sheets
    // -------------------------------------------------------------------------

    private static function build_members_sheet( $sheet, array $rows ): void {
        $sheet->setTitle( 'Active Members' );

        self::write_headers( $sheet, [ 'Member', 'Firm', 'Trustee?', 'Payment Method' ] );

        $row = 2;
        foreach ( $rows as $r ) {
            $sheet->setCellValue( 'A' . $row, $r['member'] );
            $sheet->setCellValue( 'B' . $row, $r['firm'] );
            $sheet->setCellValue( 'C' . $row, $r['is_trustee'] ? 'Yes' : 'No' );
            $sheet->setCellValue( 'D' . $row, $r['payment_method'] );
            $row++;
        }

        self::autosize( $sheet, [ 'A' => 28, 'B' => 36, 'C' => 10, 'D' => 18 ] );
        $sheet->freezePane( 'A2' );
    }

    private static function build_trustees_sheet( $sheet, array $rows ): void {
        $sheet->setTitle( 'Trustees' );

        self::write_headers( $sheet, [ 'Trustee', 'Firm', 'Dues Paid?', 'Payment Method' ] );

        $row = 2;
        foreach ( $rows as $r ) {
            $sheet->setCellValue( 'A' . $row, $r['member'] );
            $sheet->setCellValue( 'B' . $row, $r['firm'] );
            $sheet->setCellValue( 'C' . $row, $r['is_paid'] ? 'Paid' : 'Unpaid' );
            $sheet->setCellValue( 'D' . $row, $r['payment_method'] );

            $sheet->getStyle( 'C' . $row )->getFont()->getColor()->setARGB(
                $r['is_paid'] ? self::COLOR_PAID : self::COLOR_UNPAID
            );
            $sheet->getStyle( 'C' . $row )->getFont()->setBold( true );

            $row++;
        }

        self::autosize( $sheet, [ 'A' => 28, 'B' => 36, 'C' => 12, 'D' => 18 ] );
        $sheet->freezePane( 'A2' );
    }

    private static function build_companies_sheet( $sheet, array $data ): void {
        $sheet->setTitle( 'Companies' );

        self::write_headers( $sheet, [ 'Company', 'Paid Members', 'Total Members', 'Member', 'Status' ] );

        $row          = 2;
        $bucket_order = [ '1', '2-5', '6+', '0' ];

        foreach ( $bucket_order as $key ) {
            $companies = $data['buckets'][ $key ] ?? [];
            if ( empty( $companies ) ) continue;

            $sheet->setCellValue( 'A' . $row, $data['bucket_labels'][ $key ] );
            $sheet->mergeCells( 'A' . $row . ':E' . $row );
            $sheet->getStyle( 'A' . $row )->applyFromArray( [
                'font' => [ 'bold' => true ],
                'fill' => [ 'fillType' => Fill::FILL_SOLID, 'startColor' => [ 'argb' => self::COLOR_TIER_BG ] ],
            ] );
            $row++;

            foreach ( $companies as $c ) {
                if ( empty( $c['members'] ) ) {
                    $sheet->setCellValue( 'A' . $row, $c['name'] );
                    $sheet->setCellValue( 'B' . $row, $c['paid_count'] );
                    $sheet->setCellValue( 'C' . $row, $c['total_count'] );
                    $sheet->setCellValue( 'D' . $row, '(no contacts)' );
                    $row++;
                    continue;
                }
                $first = true;
                foreach ( $c['members'] as $m ) {
                    if ( $first ) {
                        $sheet->setCellValue( 'A' . $row, $c['name'] );
                        $sheet->setCellValue( 'B' . $row, $c['paid_count'] );
                        $sheet->setCellValue( 'C' . $row, $c['total_count'] );
                        $first = false;
                    }
                    $sheet->setCellValue( 'D' . $row, $m['name'] );
                    $sheet->setCellValue( 'E' . $row, $m['is_paid'] ? 'Paid' : 'Unpaid' );
                    $sheet->getStyle( 'E' . $row )->getFont()->getColor()->setARGB(
                        $m['is_paid'] ? self::COLOR_PAID : self::COLOR_UNPAID
                    );
                    $sheet->getStyle( 'E' . $row )->getFont()->setBold( true );
                    $row++;
                }
            }
        }

        self::autosize( $sheet, [ 'A' => 36, 'B' => 14, 'C' => 14, 'D' => 28, 'E' => 10 ] );
        $sheet->freezePane( 'A2' );
    }

    // -------------------------------------------------------------------------
    // Style helpers
    // -------------------------------------------------------------------------

    private static function write_headers( $sheet, array $titles ): void {
        $col = 'A';
        foreach ( $titles as $t ) {
            $sheet->setCellValue( $col . '1', $t );
            $col++;
        }
        $last = chr( ord( 'A' ) + count( $titles ) - 1 );
        $sheet->getStyle( 'A1:' . $last . '1' )->applyFromArray( [
            'font' => [ 'bold' => true, 'color' => [ 'argb' => self::COLOR_HEADER_TEXT ] ],
            'fill' => [ 'fillType' => Fill::FILL_SOLID, 'startColor' => [ 'argb' => self::COLOR_HEADER_BG ] ],
            'alignment' => [ 'horizontal' => Alignment::HORIZONTAL_CENTER ],
        ] );
    }

    private static function autosize( $sheet, array $widths ): void {
        foreach ( $widths as $col => $w ) {
            $sheet->getColumnDimension( $col )->setWidth( $w );
        }
    }
}
