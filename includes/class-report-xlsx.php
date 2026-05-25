<?php
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\{Alignment, Border, Fill, Font};
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * Generates the Member Dues Report .xlsx file.
 *
 * Call NJILGA_Report_Xlsx::stream( $data ) to push the file to the browser,
 * or NJILGA_Report_Xlsx::save( $data, $path ) to write it to disk.
 */
class NJILGA_Report_Xlsx {

    // Colour palette
    const COLOR_HEADER_BG   = 'FF003366'; // dark navy
    const COLOR_HEADER_TEXT = 'FFFFFFFF'; // white
    const COLOR_TIER_BG     = 'FFD9E1F2'; // light blue
    const COLOR_TOTAL_BG    = 'FFE2EFDA'; // light green
    const COLOR_GRAND_BG    = 'FFFFF2CC'; // light yellow
    const COLOR_PAID        = 'FF70AD47'; // green text
    const COLOR_UNPAID      = 'FFFF0000'; // red text
    const COLOR_PARTIAL     = 'FFED7D31'; // orange text

    /** Column layout: [ header, width, format ] */
    const COLUMNS = [
        'A' => [ 'Firm',           42, '@'        ],
        'B' => [ 'Member',         28, '@'        ],
        'C' => [ 'Status',         10, '@'        ],
        'D' => [ 'Open Balance',   14, '$#,##0;-' ],
        'E' => [ 'Amount Paid',    14, '$#,##0;-' ],
        'F' => [ 'Qty',             6, '0'        ],
        'G' => [ 'Invoiced Total', 14, '$#,##0;-' ],
    ];

    // -------------------------------------------------------------------------
    // Public interface
    // -------------------------------------------------------------------------

    /**
     * Stream the report directly to the browser as a download.
     */
    public static function stream( array $data ): void {
        $spreadsheet = self::build( $data );
        $filename    = 'NJILGA_Membership_Report_' . $data['year'] . '.xlsx';

        header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Cache-Control: max-age=0' );

        $writer = new Xlsx( $spreadsheet );
        $writer->save( 'php://output' );
        exit;
    }

    /**
     * Save the report to an absolute file path.
     */
    public static function save( array $data, string $path ): void {
        $spreadsheet = self::build( $data );
        ( new Xlsx( $spreadsheet ) )->save( $path );
    }

    // -------------------------------------------------------------------------
    // Builder
    // -------------------------------------------------------------------------

    private static function build( array $data ): Spreadsheet {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle( 'Member Dues Report' );

        // Freeze panes below headers
        $sheet->freezePane( 'A4' );

        $row = self::write_report_header( $sheet, $data );
        $row = self::write_column_headers( $sheet, $row );

        // Grand total accumulators
        $grand = [ 'open' => 0, 'paid' => 0, 'qty' => 0 ];

        foreach ( $data['tiers'] as $label => $tier ) {
            $row = self::write_tier_header( $sheet, $row, $label );
            $row = self::write_members( $sheet, $row, $tier['members'] );
            $row = self::write_tier_totals( $sheet, $row, $label, $tier['totals'] );

            $grand['open'] += $tier['totals']['open_balance'];
            $grand['paid'] += $tier['totals']['amount_paid'];
            $grand['qty']  += $tier['totals']['qty'];

            $row++; // blank spacer
        }

        $row = self::write_grand_totals( $sheet, $row, $grand );
        $row = self::write_summary( $sheet, $row + 1, $data['summary'] );

        // Set column widths
        foreach ( self::COLUMNS as $col => [ , $width ] ) {
            $sheet->getColumnDimension( $col )->setWidth( $width );
        }

        return $spreadsheet;
    }

    // -------------------------------------------------------------------------
    // Row writers
    // -------------------------------------------------------------------------

    private static function write_report_header( $sheet, array $data ): int {
        $sheet->setCellValue( 'A1', $data['title'] );
        $sheet->setCellValue( 'A2', $data['year'] . ' Member Dues Report' );

        $sheet->mergeCells( 'A1:G1' );
        $sheet->mergeCells( 'A2:G2' );

        $sheet->getStyle( 'A1' )->getFont()->setBold( true )->setSize( 14 );
        $sheet->getStyle( 'A2' )->getFont()->setBold( true )->setSize( 12 );
        $sheet->getStyle( 'A1:A2' )->getAlignment()->setHorizontal( Alignment::HORIZONTAL_CENTER );

        return 3; // next row
    }

    private static function write_column_headers( $sheet, int $row ): int {
        foreach ( self::COLUMNS as $col => [ $header ] ) {
            $sheet->setCellValue( $col . $row, $header );
        }

        $range = 'A' . $row . ':G' . $row;
        $sheet->getStyle( $range )->applyFromArray( [
            'font'      => [ 'bold' => true, 'color' => [ 'argb' => self::COLOR_HEADER_TEXT ] ],
            'fill'      => [ 'fillType' => Fill::FILL_SOLID, 'startColor' => [ 'argb' => self::COLOR_HEADER_BG ] ],
            'alignment' => [ 'horizontal' => Alignment::HORIZONTAL_CENTER ],
        ] );

        return $row + 1;
    }

    private static function write_tier_header( $sheet, int $row, string $label ): int {
        $sheet->setCellValue( 'A' . $row, $label );
        $sheet->mergeCells( 'A' . $row . ':G' . $row );

        $sheet->getStyle( 'A' . $row )->applyFromArray( [
            'font' => [ 'bold' => true ],
            'fill' => [ 'fillType' => Fill::FILL_SOLID, 'startColor' => [ 'argb' => self::COLOR_TIER_BG ] ],
        ] );

        return $row + 1;
    }

    private static function write_members( $sheet, int $row, array $members ): int {
        foreach ( $members as $m ) {
            $sheet->setCellValue( 'A' . $row, $m['firm'] );
            $sheet->setCellValue( 'B' . $row, $m['member'] );
            $sheet->setCellValue( 'C' . $row, $m['status'] );
            $sheet->setCellValue( 'D' . $row, $m['open_balance']    ?: '' );
            $sheet->setCellValue( 'E' . $row, $m['amount_paid']     ?: '' );
            $sheet->setCellValue( 'F' . $row, $m['qty'] );
            $sheet->setCellValue( 'G' . $row, $m['invoiced_total']  ?: '' );

            // Colour-code status cell
            $status_color = match ( strtolower( $m['status'] ) ) {
                'paid'    => self::COLOR_PAID,
                'partial' => self::COLOR_PARTIAL,
                default   => self::COLOR_UNPAID,
            };
            $sheet->getStyle( 'C' . $row )->getFont()->setColor(
                ( new \PhpOffice\PhpSpreadsheet\Style\Color( $status_color ) )
            );

            // Apply number formats
            foreach ( self::COLUMNS as $col => [ , , $fmt ] ) {
                if ( $fmt !== '@' ) {
                    $sheet->getStyle( $col . $row )->getNumberFormat()->setFormatCode( $fmt );
                }
            }

            $row++;
        }

        return $row;
    }

    private static function write_tier_totals( $sheet, int $row, string $label, array $totals ): int {
        $sheet->setCellValue( 'A' . $row, 'Total ' . $label );
        $sheet->setCellValue( 'D' . $row, $totals['open_balance'] ?: '' );
        $sheet->setCellValue( 'E' . $row, $totals['amount_paid']  ?: '' );
        $sheet->setCellValue( 'F' . $row, $totals['qty'] );

        $range = 'A' . $row . ':G' . $row;
        $sheet->getStyle( $range )->applyFromArray( [
            'font' => [ 'bold' => true ],
            'fill' => [ 'fillType' => Fill::FILL_SOLID, 'startColor' => [ 'argb' => self::COLOR_TOTAL_BG ] ],
        ] );

        foreach ( [ 'D', 'E', 'G' ] as $col ) {
            $sheet->getStyle( $col . $row )->getNumberFormat()->setFormatCode( '$#,##0;-' );
        }

        return $row + 1;
    }

    private static function write_grand_totals( $sheet, int $row, array $grand ): int {
        // Spacer
        $row++;

        $sheet->setCellValue( 'D' . $row, $grand['open'] );
        $sheet->setCellValue( 'E' . $row, $grand['paid'] );
        $sheet->setCellValue( 'F' . $row, $grand['qty'] );

        $range = 'A' . $row . ':G' . $row;
        $sheet->getStyle( $range )->applyFromArray( [
            'font' => [ 'bold' => true, 'size' => 11 ],
            'fill' => [ 'fillType' => Fill::FILL_SOLID, 'startColor' => [ 'argb' => self::COLOR_GRAND_BG ] ],
        ] );

        foreach ( [ 'D', 'E', 'G' ] as $col ) {
            $sheet->getStyle( $col . $row )->getNumberFormat()->setFormatCode( '$#,##0;-' );
        }

        return $row;
    }

    private static function write_summary( $sheet, int $row, array $summary ): int {
        $lines = [
            [ 'TOTAL Member Count:', $summary['total'] ],
            [ 'Unpaid Members:',     $summary['unpaid'] ],
            [ 'Paid Members:',       $summary['paid'] ],
            [ 'Partial Paid Members:', $summary['partial'] ],
            [ '$0 Members:',         $summary['zero'] ],
        ];

        foreach ( $lines as [ $label, $value ] ) {
            $sheet->setCellValue( 'A' . $row, $label );
            $sheet->setCellValue( 'B' . $row, $value );
            $sheet->getStyle( 'A' . $row )->getFont()->setBold( true );
            $row++;
        }

        return $row;
    }
}
