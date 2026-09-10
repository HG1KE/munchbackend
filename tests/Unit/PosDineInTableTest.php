<?php

namespace Tests\Unit;

use App\Support\PosOrderTypes;
use Tests\TestCase;

class PosDineInTableTest extends TestCase
{
    public function test_pos_dine_in_table_is_wired_through_ui_payload_and_session(): void
    {
        $page = file_get_contents(resource_path('views/branch-views/pos/index.blade.php'));
        $app = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));
        $controller = file_get_contents(app_path('Http/Controllers/Branch/POSController.php'));

        $this->assertStringContainsString('id="pos-dine-in"', $page);
        $this->assertStringContainsString('id="pos-table"', $page);
        $this->assertStringContainsString('id="pos-people"', $page);
        $this->assertStringContainsString('munch-pos-dine-in.js', $page);

        $this->assertStringContainsString('MunchPosDineIn', $app);
        $this->assertStringContainsString('state.cart.tableId', $app);
        $this->assertStringContainsString('readDineInFields', $app);
        $this->assertStringContainsString('table_id: dineIn.table_id', $app);
        $this->assertStringContainsString('people_number: dineIn.people_number', $app);
        $this->assertStringContainsString("els.dineIn.hidden = state.cart.orderType !== 'dine_in'", $app);

        $this->assertStringContainsString('jsonPosDineInValidationError', $controller);
        $this->assertStringContainsString("session()->put('table_id', \$tableId)", $controller);
        $this->assertStringContainsString("session()->put('people_number', \$people)", $controller);
        $this->assertStringNotContainsString("if (\$this->isJsonPosOrder(\$request)) {\n                \$order->table_id = null;", $controller);
        $this->assertStringContainsString('PosOrderTypes::isDineIn($orderType)', $controller);
    }

    public function test_node_dine_in_table_scenarios(): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is required for POS dine-in table scenarios');
        }

        $script = base_path('tests/Js/pos-dine-in-table.test.js');
        $output = [];
        $code = 0;
        exec(escapeshellcmd($node).' '.escapeshellarg($script).' 2>&1', $output, $code);

        $this->assertSame(0, $code, implode("\n", $output));
        $joined = implode("\n", $output);
        $this->assertStringContainsString('Dine In with selected table succeeds', $joined);
        $this->assertStringContainsString('Dine In without table is rejected', $joined);
        $this->assertStringContainsString('Selected table reaches offline queue payload', $joined);
        $this->assertStringContainsString('Selected table reaches online POST payload', $joined);
        $this->assertStringContainsString('Switching payment method does not clear table', $joined);
        $this->assertStringContainsString('Switching customer does not clear table', $joined);
        $this->assertStringContainsString('Switching between Dine In and other order types keeps table for return', $joined);
        $this->assertStringContainsString('Existing POS order types remain unaffected', $joined);
    }

    public function test_other_channels_never_require_a_pos_table(): void
    {
        foreach (['take_away', 'delivery', 'glovo', 'uber', 'bolt_food'] as $type) {
            $this->assertNull(PosOrderTypes::jsonDineInError([
                'order_type' => $type,
                'table_id' => '',
            ]));
        }
    }
}
