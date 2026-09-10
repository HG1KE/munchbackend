<?php

namespace Tests\Unit;

use App\Support\PosOrderTypes;
use Tests\TestCase;

class PosDineInTableTest extends TestCase
{
    public function test_branch_pos_dine_in_no_longer_collects_table_or_people(): void
    {
        $page = file_get_contents(resource_path('views/branch-views/pos/index.blade.php'));
        $app = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));
        $controller = file_get_contents(app_path('Http/Controllers/Branch/POSController.php'));
        $types = file_get_contents(app_path('Support/PosOrderTypes.php'));

        $this->assertStringNotContainsString('id="pos-dine-in"', $page);
        $this->assertStringNotContainsString('id="pos-table"', $page);
        $this->assertStringNotContainsString('id="pos-people"', $page);
        $this->assertStringNotContainsString('munch-pos-dine-in.js', $page);
        $this->assertStringNotContainsString("translate('please select a table number')", $page);
        $this->assertStringNotContainsString("translate('please enter people number')", $page);
        $this->assertStringNotContainsString("translate('Select Table')", $page);

        $this->assertStringNotContainsString('MunchPosDineIn', $app);
        $this->assertStringNotContainsString('readDineInFields', $app);
        $this->assertStringNotContainsString('table_id:', $app);
        $this->assertStringNotContainsString('people_number:', $app);
        $this->assertStringNotContainsString('state.cart.tableId', $app);
        $this->assertStringNotContainsString('state.cart.people', $app);
        $this->assertStringNotContainsString("CFG.labels.table", $app);
        $this->assertStringContainsString("['dine_in', CFG.labels.dineIn]", $app);

        $validate = $this->functionBody($app, 'function validateCart');
        $this->assertStringContainsString('emptyCart', $validate);
        $this->assertStringNotContainsString('dine_in', $validate);

        $this->assertStringNotContainsString('jsonPosDineInValidationError', $controller);
        $this->assertStringNotContainsString('please select a table number', $controller);
        $this->assertStringNotContainsString('please enter people number', $controller);
        $this->assertStringNotContainsString('jsonDineInTableId', $controller);
        $this->assertStringContainsString('$order->table_id = null;', $controller);
        $this->assertStringContainsString('$order->number_of_people = null;', $controller);

        $this->assertStringNotContainsString('function jsonDineInError', $types);
        $this->assertStringNotContainsString('function jsonDineInTableId', $types);
        $this->assertStringNotContainsString('function jsonDineInPeople', $types);
        $this->assertFalse(file_exists(public_path('assets/admin/js/munch-pos-dine-in.js')));
    }

    public function test_dine_in_still_places_as_dine_in_without_table_fields(): void
    {
        $this->assertTrue(PosOrderTypes::isDineIn('dine_in'));
        $this->assertSame('dine_in', PosOrderTypes::databaseType('dine_in'));
        $this->assertTrue(PosOrderTypes::allowsManualDiscount('dine_in'));
        $this->assertFalse(method_exists(PosOrderTypes::class, 'jsonDineInError'));
        $this->assertFalse(method_exists(PosOrderTypes::class, 'jsonDineInTableId'));
        $this->assertFalse(method_exists(PosOrderTypes::class, 'jsonDineInPeople'));
    }

    public function test_other_channels_remain_independent_of_table_fields(): void
    {
        foreach (['take_away', 'delivery', 'glovo', 'uber', 'bolt_food'] as $type) {
            $this->assertFalse(PosOrderTypes::isDineIn($type));
        }
        $this->assertTrue(PosOrderTypes::isDelivery('delivery'));
        $this->assertTrue(PosOrderTypes::isMarketplace('glovo'));
    }

    private function functionBody(string $source, string $needle): string
    {
        $start = strpos($source, $needle);
        $this->assertNotFalse($start, $needle.' not found');

        return substr($source, $start, 800);
    }
}
