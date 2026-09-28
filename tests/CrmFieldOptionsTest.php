<?php
/**
 * The NJ County and Municipality selects offer exactly the choices of the
 * FluentCRM custom fields they write to.
 */
class CrmFieldOptionsTest extends NJILGA_TestCase {

    private function fields(): array {
        return [
            [ 'slug' => 'nj_attorney_id', 'type' => 'text', 'label' => 'NJ Attorney ID' ],
            [ 'slug' => 'nj_county', 'type' => 'select-one', 'label' => 'NJ County', 'options' => [ 'Atlantic', 'Bergen', ' Burlington ', '', 'Bergen' ] ],
            [ 'slug' => 'municipality', 'type' => 'select-one', 'label' => 'Municipality', 'options' => [ [ 'label' => 'Hoboken', 'value' => 'Hoboken' ], [ 'label' => 'Jersey City', 'value' => 'Jersey City' ] ] ],
            [ 'slug' => 'interests', 'type' => 'checkbox', 'options' => [ 'Pro bono' ] ],
        ];
    }

    public function test_select_options_come_back_in_order_trimmed_and_deduplicated(): void {
        $this->assertSame( [ 'Atlantic', 'Bergen', 'Burlington' ], MyNJILGA_Dues_Settings::options_from_fields( $this->fields(), 'nj_county' ) );
    }

    public function test_label_value_pairs_use_the_value(): void {
        $this->assertSame( [ 'Hoboken', 'Jersey City' ], MyNJILGA_Dues_Settings::options_from_fields( $this->fields(), 'municipality' ) );
    }

    public function test_fields_without_choices_or_unknown_slugs_give_nothing(): void {
        $this->assertSame( [], MyNJILGA_Dues_Settings::options_from_fields( $this->fields(), 'nj_attorney_id' ), 'A text field has no choices.' );
        $this->assertSame( [], MyNJILGA_Dues_Settings::options_from_fields( $this->fields(), 'no_such_field' ) );
        $this->assertSame( [], MyNJILGA_Dues_Settings::options_from_fields( [ 'junk', null ], 'nj_county' ) );
        $this->assertSame( [ 'Pro bono' ], MyNJILGA_Dues_Settings::options_from_fields( $this->fields(), 'interests' ), 'Checkbox fields offer choices too.' );
    }
}
