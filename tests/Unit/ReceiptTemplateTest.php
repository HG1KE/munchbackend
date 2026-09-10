<?php

namespace Tests\Unit;

use App\Services\ReceiptTemplateService;
use Tests\TestCase;

class ReceiptTemplateTest extends TestCase
{
    public function test_factory_kitchen_template_never_includes_prices_or_payments(): void
    {
        $factory = (new ReceiptTemplateService())->factoryCompany();
        $keys = [];
        foreach ($factory['kitchen']['sections'] as $flags) {
            $keys = array_merge($keys, array_keys($flags));
        }

        foreach (ReceiptTemplateService::KITCHEN_FORBIDDEN as $forbidden) {
            $this->assertNotContains($forbidden, $keys);
        }
        $this->assertSame(ReceiptTemplateService::VERSION, $factory['version']);
        $this->assertSame('80mm', $factory['print']['paper']);
        $this->assertTrue($factory['customer']['sections']['order']['order_number']);
        $this->assertFalse($factory['customer']['sections']['items']['unit_price']);
        $this->assertTrue($factory['customer']['sections']['items']['line_total']);
    }

    public function test_admin_and_branch_receipt_template_pages_are_wired(): void
    {
        $adminRoutes = file_get_contents(base_path('routes/admin.php'));
        $branchRoutes = file_get_contents(base_path('routes/branch.php'));
        $this->assertStringContainsString("->name('receipt-templates')", $adminRoutes);
        $this->assertStringContainsString('ReceiptTemplateController', $adminRoutes);
        $this->assertStringContainsString("->name('receipt-templates')", $branchRoutes);

        $menu = file_get_contents(resource_path('views/admin-views/business-settings/partials/_business-setup-inline-menu.blade.php'));
        $this->assertStringContainsString("translate('Receipt Templates')", $menu);

        $view = file_get_contents(resource_path('views/admin-views/business-settings/receipt-templates.blade.php'));
        $this->assertStringContainsString('munch-receipt-ticket.js', $view);
        $this->assertStringContainsString('munch-receipt-templates.js', $view);

        $partial = file_get_contents(resource_path('views/admin-views/business-settings/partials/_receipt-template-editor.blade.php'));
        $this->assertStringContainsString("translate('Customer Receipt')", $partial);
        $this->assertStringContainsString("translate('Kitchen Ticket')", $partial);
        $this->assertStringContainsString("translate('Use Company Template')", $partial);
        $this->assertStringContainsString("translate('Custom Branch Template')", $partial);
        $this->assertStringContainsString("translate('Print Test Receipt')", $partial);
        $this->assertStringContainsString("translate('Print Test Kitchen Ticket')", $partial);
        $this->assertStringContainsString("translate('Restore Company Default')", $partial);
        $this->assertStringContainsString("translate('Restore Branch Default')", $partial);
        $this->assertStringContainsString("translate('Reset Section')", $partial);
        $this->assertStringContainsString('receipt-preview-frame', $partial);

        $js = file_get_contents(public_path('assets/admin/js/munch-receipt-templates.js'));
        $this->assertStringContainsString('refreshPreview', $js);
        $this->assertStringContainsString('srcdoc', $js);
        $this->assertStringContainsString('Print Test', file_get_contents(resource_path('views/admin-views/business-settings/partials/_receipt-template-editor.blade.php')));
    }

    public function test_shared_renderer_keeps_kitchen_tickets_priceless(): void
    {
        $ticket = file_get_contents(public_path('assets/admin/js/munch-receipt-ticket.js'));
        $this->assertStringContainsString('KITCHEN_FORBIDDEN', $ticket);
        $this->assertStringContainsString('mpesa_till', $ticket);
        $this->assertStringContainsString("kind === 'kitchen'", $ticket);
        $this->assertStringContainsString('ticket-channel--glovo', $ticket);
        $this->assertStringContainsString('ticket-channel--uber', $ticket);
        $this->assertStringContainsString('ticket-channel--bolt_food', $ticket);
        $this->assertStringContainsString('ticket-channel--delivery', $ticket);
        $this->assertStringContainsString('applyPlaceholders', $ticket);
        $this->assertStringContainsString('auto_cut', $ticket);
        $this->assertStringContainsString('drawer_kick', $ticket);

        $kitchenBlock = substr($ticket, strpos($ticket, 'function summaryHtml'), strpos($ticket, 'function paymentHtml') - strpos($ticket, 'function summaryHtml'));
        $this->assertStringContainsString("kind === 'kitchen') return ''", $kitchenBlock);

        $pos = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));
        $this->assertStringContainsString('MunchReceiptTicket.renderDocument', $pos);
        $this->assertStringContainsString('kitchenTicketHtml', $pos);
        $this->assertStringContainsString('receiptTicketHtml', $pos);
        $this->assertStringContainsString("munch-receipt-ticket.js') }}?v=1.0", file_get_contents(resource_path('views/branch-views/pos/index.blade.php')));
    }

    public function test_templates_are_versioned_json_not_html(): void
    {
        $service = file_get_contents(app_path('Services/ReceiptTemplateService.php'));
        $this->assertStringContainsString("SETTINGS_KEY = 'receipt_templates'", $service);
        $this->assertStringContainsString('json_encode($normalized)', $service);
        $this->assertStringContainsString('receipt_settings', $service);
        $this->assertStringContainsString('VERSION = 1', $service);
        $this->assertStringNotContainsString('<html', $service);

        $migration = file_get_contents(database_path('migrations/2026_09_10_160000_add_receipt_settings_to_branches_table.php'));
        $this->assertStringContainsString('receipt_settings', $migration);
        $this->assertStringContainsString('longText', $migration);
    }
}
