<?php

namespace Tests\Unit;

use App\CentralLogics\AdminOrderWhatsAppMessage;
use Tests\TestCase;

class AdminOrderWhatsAppMessageAddonsTest extends TestCase
{
    public function test_resolve_addon_name_uses_decode_json_list(): void
    {
        $source = file_get_contents(app_path('CentralLogics/AdminOrderWhatsAppMessage.php'));
        $this->assertStringContainsString(
            'foreach (self::decodeJsonList($productDetails[\'add_ons\'] ?? []) as $addon)',
            $source
        );
    }

    /**
     * A24922 residual: nested product_details.add_ons as a JSON string must not
     * TypeError foreach in resolveAddonName.
     */
    public function test_resolve_addon_name_survives_string_product_details_add_ons(): void
    {
        $method = new \ReflectionMethod(AdminOrderWhatsAppMessage::class, 'resolveAddonName');
        $method->setAccessible(true);

        // Nested JSON string of addon objects — bare foreach would TypeError.
        $name = $method->invoke(null, 3, [
            'add_ons' => '[{"id":3,"name":"Ketchup"},{"id":5,"name":"Mayo"}]',
        ]);
        $this->assertSame('Ketchup', $name);

        // Catalog-ID string list (live A24922 shape): decode then skip non-arrays.
        // Pass addonId 0 so resolveAddonName returns without AddOn::find.
        $skipped = $method->invoke(null, 0, [
            'add_ons' => '["5","2","3","4"]',
        ]);
        $this->assertSame('addon deleted', $skipped);
    }
}
