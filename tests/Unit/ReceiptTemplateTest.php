<?php

namespace Tests\Unit;

use App\Services\ReceiptTemplateService;
use App\Services\ReceiptThermalImageService;
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
        $this->assertTrue($factory['customer']['sections']['payment']['mpesa_till']);
        $this->assertTrue($factory['customer']['sections']['payment']['payment_status']);
        $this->assertTrue($factory['customer']['sections']['delivery_customer']['customer_name']);
        $this->assertTrue($factory['customer']['sections']['delivery_customer']['delivery_fee']);
        $this->assertSame(ReceiptTemplateService::CUSTOMER_BLOCKS, $factory['customer']['order']);
        $this->assertContains('delivery_customer', $factory['customer']['order']);
        $this->assertNotContains('delivery_customer', $factory['kitchen']['order']);
        $this->assertSame(ReceiptTemplateService::KITCHEN_BLOCKS, $factory['kitchen']['order']);
        $this->assertArrayHasKey('logo', $factory['customer']['block_styles']);
        $this->assertSame('website', $factory['customer']['qr']['type']);
        $this->assertNull($factory['kitchen']['qr']);
        $this->assertSame('normal', $factory['print']['print_mode']);
        $this->assertSame('off', $factory['print']['paper_saving']);
        $this->assertFalse($factory['customer']['logo']['optimize_thermal']);
        $this->assertNotContains('qr_code', $factory['kitchen']['order']);
        $this->assertNotContains('totals', $factory['kitchen']['order']);
        $this->assertNotContains('mpesa_till', $factory['kitchen']['order']);
    }

    public function test_older_templates_receive_order_typography_qr_and_printer_defaults(): void
    {
        $service = new ReceiptTemplateService();
        $merged = $service->applyKindOverlay('customer', [
            'sections' => [
                'header' => ['logo' => true, 'branch_name' => true],
            ],
            'logo' => ['mode' => 'upload', 'path' => 'old.png'],
            'texts' => ['receipt_title' => 'Classic'],
        ]);

        $this->assertTrue($merged['sections']['header']['logo']);
        $this->assertSame('Classic', $merged['texts']['receipt_title']);
        $this->assertSame('upload', $merged['logo']['mode']);
        $this->assertSame('old.png', $merged['logo']['path']);
        $this->assertSame('medium', $merged['logo']['size']);
        $this->assertFalse($merged['logo']['optimize_thermal']);
        $this->assertSame(ReceiptTemplateService::CUSTOMER_BLOCKS, $merged['order']);
        $this->assertSame('normal', $merged['block_styles']['items']['font_size']);
        $this->assertFalse($merged['block_styles']['items']['bold']);
        $this->assertSame('website', $merged['qr']['type']);
        $this->assertSame('medium', $merged['qr']['size']);

        $print = $service->applyPrintOverlay(['paper' => '58mm', 'receipt_copies' => 2]);
        $this->assertSame('58mm', $print['paper']);
        $this->assertSame(2, $print['receipt_copies']);
        $this->assertSame('normal', $print['print_mode']);
        $this->assertSame('off', $print['paper_saving']);
    }

    public function test_section_reorder_is_persisted_and_unknown_ids_are_dropped(): void
    {
        $service = new ReceiptTemplateService();
        $merged = $service->applyKindOverlay('customer', [
            'order' => ['footer', 'items', 'logo', 'not-a-block', 'items'],
        ]);

        $this->assertSame('footer', $merged['order'][0]);
        $this->assertSame('items', $merged['order'][1]);
        $this->assertSame('logo', $merged['order'][2]);
        $this->assertNotContains('not-a-block', $merged['order']);
        $this->assertSame(count(ReceiptTemplateService::CUSTOMER_BLOCKS), count($merged['order']));
        $this->assertContains('qr_code', $merged['order']);
        $this->assertContains('mpesa_till', $merged['order']);
        $this->assertSame(count($merged['order']), count(array_unique($merged['order'])));
    }

    public function test_kitchen_order_stays_independent_of_customer_reorder(): void
    {
        $service = new ReceiptTemplateService();
        $customer = $service->applyKindOverlay('customer', [
            'order' => ['qr_code', 'mpesa_till', 'logo'],
            'block_styles' => [
                'qr_code' => ['font_size' => 'extra_large', 'bold' => true, 'align' => 'center'],
            ],
        ]);
        $kitchen = $service->applyKindOverlay('kitchen', [
            'order' => ['items', 'footer', 'logo'],
        ]);

        $this->assertSame('qr_code', $customer['order'][0]);
        $this->assertSame('items', $kitchen['order'][0]);
        $this->assertSame('footer', $kitchen['order'][1]);
        $this->assertNotContains('qr_code', $kitchen['order']);
        $this->assertNotContains('totals', $kitchen['order']);
        $this->assertNotContains('payment', $kitchen['order']);
        $this->assertNotContains('mpesa_till', $kitchen['order']);
        $this->assertSame('extra_large', $customer['block_styles']['qr_code']['font_size']);
        $this->assertSame('normal', $kitchen['block_styles']['items']['font_size']);
        $this->assertNull($kitchen['qr']);
    }

    public function test_company_defaults_are_used_when_branch_inherits(): void
    {
        $service = new ReceiptTemplateService();
        $company = $service->applyKindOverlay('customer', [
            'order' => ['logo', 'branch_name', 'items'],
            'logo' => ['mode' => 'company', 'size' => 'large'],
        ]);
        $inherited = $service->applyKindOverlay('customer', []);

        $this->assertSame('logo', $inherited['order'][0]);
        $this->assertSame('company', $inherited['logo']['mode']);
        $this->assertNotSame($company['order'], $inherited['order']);
        $this->assertSame('large', $company['logo']['size']);
        $this->assertSame('medium', $inherited['logo']['size']);
    }

    public function test_qr_url_resolution_and_data_uri_generation(): void
    {
        $service = new ReceiptTemplateService();
        $context = ['website' => 'https://munch.co.ke'];
        $website = $service->applyKindOverlay('customer', ['qr' => ['type' => 'website', 'url' => '', 'size' => 'small']]);
        $custom = $service->applyKindOverlay('customer', ['qr' => ['type' => 'google_reviews', 'url' => 'https://g.page/r/review', 'size' => 'large']]);

        $this->assertSame('https://munch.co.ke', $service->resolveQrUrl($website, $context));
        $this->assertSame('https://g.page/r/review', $service->resolveQrUrl($custom, $context));

        $uri = $service->qrDataUri('https://munch.co.ke', 96);
        $this->assertNotNull($uri);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $uri);
    }

    public function test_thermal_image_generation_uses_floyd_steinberg_and_keeps_source(): void
    {
        $this->assertTrue(function_exists('imagecreatetruecolor'));
        $source = tempnam(sys_get_temp_dir(), 'receipt-src-');
        $dest = tempnam(sys_get_temp_dir(), 'receipt-thm-');
        $sourcePng = $source.'.png';
        $destPng = $dest.'.png';
        @unlink($source);
        @unlink($dest);

        $im = imagecreatetruecolor(80, 80);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        $transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
        imagefilledrectangle($im, 0, 0, 79, 79, $transparent);
        for ($y = 0; $y < 80; $y++) {
            for ($x = 0; $x < 80; $x++) {
                $gray = (int) min(255, ($x + $y) * 1.6);
                $color = imagecolorallocate($im, $gray, $gray, $gray);
                imagesetpixel($im, $x, $y, $color);
            }
        }
        imagepng($im, $sourcePng);
        imagedestroy($im);

        $ok = (new ReceiptThermalImageService())->optimize($sourcePng, $destPng, 40, 128);
        $this->assertTrue($ok);
        $this->assertFileExists($sourcePng);
        $this->assertFileExists($destPng);
        $info = getimagesize($destPng);
        $this->assertIsArray($info);
        $this->assertSame(40, $info[0]);

        $out = imagecreatefrompng($destPng);
        $this->assertNotFalse($out);
        $colors = [];
        for ($y = 0; $y < imagesy($out); $y++) {
            for ($x = 0; $x < imagesx($out); $x++) {
                $rgb = imagecolorat($out, $x, $y);
                $colors[($rgb >> 16) & 0xFF] = true;
            }
        }
        imagedestroy($out);
        foreach (array_keys($colors) as $value) {
            $this->assertContains($value, [0, 255]);
        }

        @unlink($sourcePng);
        @unlink($destPng);
    }

    public function test_admin_and_branch_receipt_template_pages_are_wired(): void
    {
        $adminRoutes = file_get_contents(base_path('routes/admin.php'));
        $branchRoutes = file_get_contents(base_path('routes/branch.php'));
        $this->assertStringContainsString("->name('receipt-templates')", $adminRoutes);
        $this->assertStringContainsString('ReceiptTemplateController', $adminRoutes);
        $this->assertStringContainsString("->name('receipt-templates.qr')", $adminRoutes);
        $this->assertStringContainsString("->name('receipt-templates')", $branchRoutes);
        $this->assertStringContainsString("->name('receipt-templates.qr')", $branchRoutes);

        $menu = file_get_contents(resource_path('views/admin-views/business-settings/partials/_business-setup-inline-menu.blade.php'));
        $this->assertStringContainsString("translate('Receipt Templates')", $menu);

        $view = file_get_contents(resource_path('views/admin-views/business-settings/receipt-templates.blade.php'));
        $this->assertStringContainsString('munch-receipt-ticket.js', $view);
        $this->assertStringContainsString('munch-receipt-templates.js', $view);

        $partial = file_get_contents(resource_path('views/admin-views/business-settings/partials/_receipt-template-editor.blade.php'));
        $this->assertStringContainsString("translate('Customer Receipt')", $partial);
        $this->assertStringContainsString("translate('Kitchen Ticket')", $partial);
        $this->assertStringContainsString("translate('Printer')", $partial);
        $this->assertStringContainsString("translate('Use Company Template')", $partial);
        $this->assertStringContainsString("translate('Custom Branch Template')", $partial);
        $this->assertStringContainsString("translate('Print Test Receipt')", $partial);
        $this->assertStringContainsString("translate('Print Test Kitchen Ticket')", $partial);
        $this->assertStringContainsString("translate('Restore Company Default')", $partial);
        $this->assertStringContainsString("translate('Restore Branch Default')", $partial);
        $this->assertStringContainsString("translate('Reset Section')", $partial);
        $this->assertStringContainsString('receipt-preview-frame', $partial);
        $this->assertStringContainsString('value="delivery" selected', $partial);

        $js = file_get_contents(public_path('assets/admin/js/munch-receipt-templates.js'));
        $this->assertStringContainsString('refreshPreview', $js);
        $this->assertStringContainsString('srcdoc', $js);
        $this->assertStringNotContainsString("els.preview.srcdoc = ''", $js);
        $this->assertStringContainsString('hydrateState', $js);
        $this->assertStringContainsString('previewSeq', $js);
        $this->assertStringContainsString('MunchReceiptTicket.renderDocument', $js);
        $this->assertStringContainsString('st.align', $js);
        $this->assertStringContainsString('font_size', $js);
        $this->assertStringContainsString("data-style-bool=\"bold\"", $js);
        $this->assertStringContainsString('persistOrderFromDom', $js);
        $this->assertStringContainsString('delivery_customer', $js);
        $this->assertStringContainsString("withGroup(groups.delivery_customer || [], 'delivery_customer')", $js);
        $this->assertStringContainsString('field.group || fieldGroup(field.key)', $js);
        $this->assertStringContainsString('qrCache', $js);
        $this->assertStringContainsString('print-mode', $js);
        $this->assertStringContainsString('Optimize Logo For Thermal Printing', $js);
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
        $this->assertStringContainsString('wrapBlock', $ticket);
        $this->assertStringContainsString('fs-extra_large', $ticket);
        $this->assertStringContainsString('print_mode', $ticket);
        $this->assertStringContainsString('paper_saving', $ticket);
        $this->assertStringContainsString('logo_thermal_url', $ticket);
        $this->assertStringContainsString('Array.isArray(template.order)', $ticket);
        $this->assertStringContainsString('html += wrapBlock(id, render(), template)', $ticket);

        $kitchenBlock = substr($ticket, strpos($ticket, 'function summaryHtml'), strpos($ticket, 'function paymentHtml') - strpos($ticket, 'function summaryHtml'));
        $this->assertStringContainsString("kind === 'kitchen') return ''", $kitchenBlock);

        $pos = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));
        $this->assertStringContainsString('MunchReceiptTicket.renderDocument', $pos);
        $this->assertStringContainsString('kitchenTicketHtml', $pos);
        $this->assertStringContainsString('receiptTicketHtml', $pos);
        $this->assertStringContainsString("if (state.catalog && state.catalog.receipt) return state.catalog.receipt", $pos);
        $this->assertStringContainsString('CFG.catalog = catalog', $pos);
        $this->assertStringContainsString("munch-receipt-ticket.js') }}?v=1.9", file_get_contents(resource_path('views/branch-views/pos/index.blade.php')));
        $this->assertStringContainsString("munch-pos-app.js') }}?v=4.5", file_get_contents(resource_path('views/branch-views/pos/index.blade.php')));
        $this->assertStringContainsString("munch-receipt-templates.js') }}?v=1.4", file_get_contents(resource_path('views/admin-views/business-settings/receipt-templates.blade.php')));
        $this->assertStringContainsString("munch-receipt-ticket.js') }}?v=1.9", file_get_contents(resource_path('views/admin-views/business-settings/receipt-templates.blade.php')));
        $this->assertStringContainsString("munch-receipt-templates.js') }}?v=1.4", file_get_contents(resource_path('views/branch-views/business-settings/receipt-templates.blade.php')));
    }

    public function test_renderer_honors_order_typography_qr_and_printer_mode(): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is required to execute the shared ticket renderer');
        }

        $ticket = public_path('assets/admin/js/munch-receipt-ticket.js');
        $script = <<<'JS'
const fs = require('fs');
const vm = require('vm');
const sandbox = { window: {}, console };
sandbox.window = sandbox;
vm.runInNewContext(fs.readFileSync(process.argv[2], 'utf8'), sandbox);
const T = sandbox.window.MunchReceiptTicket;
const job = {
  number: '#M-1042',
  branch: 'Westlands',
  orderType: 'Delivery',
  salesChannel: 'glovo',
  isDelivery: true,
  items: [{ name: 'Burger', quantity: 1, options: [], notes: '', unit_price: 850, line_total: 850 }],
  customer: 'Jane',
  subtotal: 850,
  grand_total: 850,
  payment_method: 'cash',
  mpesa_till: '123456'
};
const customer = T.normalizeTemplate('customer', {
  sections: T.defaults('customer').sections,
  order: ['qr_code', 'items', 'logo', 'totals'],
  block_styles: {
    items: { font_size: 'extra_large', bold: true, align: 'center', divider_before: true, divider_after: false, margin_top: true, margin_bottom: false }
  },
  qr: { type: 'custom', url: 'https://munch.co.ke/menu', size: 'large' },
  qr_data_uri: 'data:image/svg+xml;base64,QQ==',
  logo: { mode: 'upload', size: 'large', optimize_thermal: true },
  logo_url: 'https://example.com/logo.png',
  logo_thermal_url: 'https://example.com/thermal.png'
});
customer.sections.footer.qr_code = true;
customer.sections.header.logo = true;
const receipt = T.renderDocument('customer', customer, job, { print: { paper: '58mm', print_mode: 'extra_dark', paper_saving: 'maximum' } });
const kitchen = T.renderDocument('kitchen', T.defaults('kitchen'), job, { print: { paper: '80mm', print_mode: 'normal' } });
process.stdout.write(JSON.stringify({ receipt, kitchen }));
JS;
        $tmp = tempnam(sys_get_temp_dir(), 'receipt-js-');
        file_put_contents($tmp, $script);
        $json = shell_exec(escapeshellarg($node).' '.escapeshellarg($tmp).' '.escapeshellarg($ticket).' 2>/dev/null');
        @unlink($tmp);
        $this->assertNotEmpty($json);
        $out = json_decode((string) $json, true);
        $this->assertIsArray($out);
        $receipt = $out['receipt'];
        $kitchen = $out['kitchen'];

        $qrPos = strpos($receipt, 'ticket-block--qr_code');
        $itemsPos = strpos($receipt, 'ticket-block--items');
        $this->assertNotFalse($qrPos);
        $this->assertNotFalse($itemsPos);
        $this->assertLessThan($itemsPos, $qrPos);
        $this->assertStringContainsString('ticket-block--items fs-extra_large is-bold is-center mt-extra', $receipt);
        $this->assertStringContainsString('qr-large', $receipt);
        $this->assertStringContainsString('logo-large', $receipt);
        $this->assertStringContainsString('thermal.png', $receipt);
        $this->assertStringNotContainsString('logo.png', $receipt);
        $this->assertStringContainsString('is-extra_dark', $receipt);
        $this->assertStringContainsString('save-maximum', $receipt);
        $this->assertStringContainsString('data-print-mode="extra_dark"', $receipt);
        $this->assertStringContainsString('size:58mm', $receipt);

        $this->assertStringNotContainsString('Grand Total', $kitchen);
        $this->assertStringNotContainsString('M-PESA Till', $receipt);
        $this->assertStringNotContainsString('M-PESA Till', $kitchen);
        $this->assertStringNotContainsString('ticket-block--qr_code', $kitchen);
        $this->assertStringNotContainsString('class="ticket-qr', $kitchen);
        $this->assertStringNotContainsString('Payment Method', $kitchen);
        $this->assertStringNotContainsString('Payment Status', $kitchen);
        $this->assertStringContainsString('Kitchen Order', $kitchen);
        $this->assertStringContainsString('1 x Burger', $kitchen);
    }

    public function test_owned_pos_receipts_show_branch_till_and_marketplace_receipts_do_not(): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is required to render POS tickets');
        }

        $ticket = public_path('assets/admin/js/munch-receipt-ticket.js');
        $script = <<<'JS'
const fs = require('fs');
const vm = require('vm');
const sandbox = { window: {}, console };
sandbox.window = sandbox;
vm.runInNewContext(fs.readFileSync(process.argv[2], 'utf8'), sandbox);
const T = sandbox.window.MunchReceiptTicket;
const template = T.defaults('customer');
template.sections.payment.mpesa_till = false;
const kitchenTemplate = T.defaults('kitchen');
function job(channel, till) {
  return {
    number: '#M-3001',
    orderType: channel,
    salesChannel: channel,
    payment_method: channel === 'glovo' || channel === 'uber' || channel === 'bolt_food' ? channel : 'cash',
    payment_status: 'paid',
    mpesa_till: till,
    items: [{ name: 'Burger', quantity: 1, options: [], unit_price: 500, line_total: 500 }],
    grand_total: 500
  };
}
const out = {
  dine_in: T.renderDocument('customer', template, job('dine_in', '554433')),
  takeaway: T.renderDocument('customer', template, job('takeaway', '554433')),
  delivery: T.renderDocument('customer', template, job('delivery', '554433')),
  empty: T.renderDocument('customer', template, job('delivery', '')),
  glovo: T.renderDocument('customer', template, job('glovo', '554433')),
  uber: T.renderDocument('customer', template, job('uber', '554433')),
  bolt_food: T.renderDocument('customer', template, job('bolt_food', '554433')),
  kitchen: T.renderDocument('kitchen', kitchenTemplate, job('dine_in', '554433'))
};
process.stdout.write(JSON.stringify(out));
JS;
        $tmp = tempnam(sys_get_temp_dir(), 'till-js-');
        file_put_contents($tmp, $script);
        $json = shell_exec(escapeshellarg($node).' '.escapeshellarg($tmp).' '.escapeshellarg($ticket).' 2>/dev/null');
        @unlink($tmp);
        $this->assertNotEmpty($json);
        $out = json_decode((string) $json, true);
        $this->assertIsArray($out);

        foreach (['dine_in', 'takeaway', 'delivery'] as $channel) {
            $this->assertStringContainsString('M-PESA Till', $out[$channel], $channel);
            $this->assertStringContainsString('554433', $out[$channel], $channel);
        }
        $this->assertStringNotContainsString('M-PESA Till', $out['empty']);
        $this->assertStringNotContainsString('554433', $out['empty']);
        foreach (['glovo', 'uber', 'bolt_food', 'kitchen'] as $hidden) {
            $this->assertStringNotContainsString('M-PESA Till', $out[$hidden], $hidden);
            $this->assertStringNotContainsString('554433', $out[$hidden], $hidden);
        }
    }

    public function test_templates_are_versioned_json_not_html(): void
    {
        $service = file_get_contents(app_path('Services/ReceiptTemplateService.php'));
        $this->assertStringContainsString("SETTINGS_KEY = 'receipt_templates'", $service);
        $this->assertStringContainsString('json_encode($normalized)', $service);
        $this->assertStringContainsString('receipt_settings', $service);
        $this->assertStringContainsString('VERSION = 1', $service);
        $this->assertStringContainsString('block_styles', $service);
        $this->assertStringContainsString('optimizeStoredLogo', $service);
        $this->assertStringNotContainsString('<html', $service);

        $migration = file_get_contents(database_path('migrations/2026_09_10_160000_add_receipt_settings_to_branches_table.php'));
        $this->assertStringContainsString('receipt_settings', $migration);
        $this->assertStringContainsString('longText', $migration);
    }

    public function test_payment_status_and_delivery_customer_are_persisted_in_template_json(): void
    {
        $service = new ReceiptTemplateService();
        $disabled = $service->applyKindOverlay('customer', [
            'sections' => [
                'payment' => ['payment_status' => false, 'payment_method' => true],
                'delivery_customer' => [
                    'customer_name' => false,
                    'customer_phone' => true,
                    'delivery_address' => true,
                    'delivery_fee' => false,
                ],
            ],
        ]);

        $this->assertFalse($disabled['sections']['payment']['payment_status']);
        $this->assertTrue($disabled['sections']['payment']['payment_method']);
        $this->assertFalse($disabled['sections']['delivery_customer']['customer_name']);
        $this->assertTrue($disabled['sections']['delivery_customer']['customer_phone']);
        $this->assertFalse($disabled['sections']['delivery_customer']['delivery_fee']);
        $this->assertContains('delivery_customer', $disabled['order']);
        $blockLabels = array_column($service->blockCatalog()['customer'], 'label', 'id');
        $this->assertSame('Delivery Customer Information', $blockLabels['delivery_customer']);

        $legacy = $service->applyKindOverlay('customer', [
            'sections' => [
                'order' => ['customer_name' => true, 'customer_phone' => false, 'delivery_address' => true],
                'summary' => ['delivery_fee' => false],
            ],
            'order' => ['logo', 'customer', 'items', 'totals', 'payment'],
        ]);
        $this->assertTrue($legacy['sections']['delivery_customer']['customer_name']);
        $this->assertFalse($legacy['sections']['delivery_customer']['customer_phone']);
        $this->assertTrue($legacy['sections']['delivery_customer']['delivery_address']);
        $this->assertFalse($legacy['sections']['delivery_customer']['delivery_fee']);
        $customerIndex = array_search('customer', $legacy['order'], true);
        $this->assertNotFalse($customerIndex);
        $this->assertSame($customerIndex + 1, array_search('delivery_customer', $legacy['order'], true));
        $this->assertNotContains('delivery_customer', $service->applyKindOverlay('kitchen', [])['order']);

        $sample = $service->sampleJob(['branch_name' => 'Nyali']);
        $this->assertSame('A10001', $sample['number']);
        $this->assertSame('delivery', $sample['salesChannel']);
        $this->assertSame('John Doe', $sample['customer']);
        $this->assertSame('0712345678', $sample['phone']);
        $this->assertSame('Nyali, Mombasa', $sample['address']);
        $this->assertSame(100, $sample['delivery_fee']);
        $this->assertSame('', $sample['riderName']);
        $this->assertSame('', $sample['riderPhone']);
    }

    public function test_block_styles_persist_and_missing_properties_fall_back(): void
    {
        $service = new ReceiptTemplateService();
        $styled = $service->applyKindOverlay('customer', [
            'sections' => [
                'order' => ['order_number' => true],
                'payment' => ['payment_status' => true, 'payment_method' => true],
                'delivery_customer' => [
                    'customer_name' => true,
                    'customer_phone' => true,
                    'delivery_address' => true,
                    'delivery_fee' => true,
                ],
            ],
            'block_styles' => [
                'order_number' => ['align' => 'center', 'font_size' => 'extra_large', 'bold' => true],
                'delivery_customer' => ['align' => 'right', 'font_size' => 'large', 'bold' => true],
                'payment' => ['align' => 'center'],
            ],
        ]);

        $this->assertSame('center', $styled['block_styles']['order_number']['align']);
        $this->assertSame('extra_large', $styled['block_styles']['order_number']['font_size']);
        $this->assertTrue($styled['block_styles']['order_number']['bold']);
        $this->assertSame('right', $styled['block_styles']['delivery_customer']['align']);
        $this->assertSame('large', $styled['block_styles']['delivery_customer']['font_size']);
        $this->assertTrue($styled['block_styles']['delivery_customer']['bold']);
        $this->assertSame('center', $styled['block_styles']['payment']['align']);
        $this->assertSame('normal', $styled['block_styles']['payment']['font_size']);
        $this->assertFalse($styled['block_styles']['payment']['bold']);
        $this->assertTrue($styled['sections']['payment']['payment_status']);
        $this->assertTrue($styled['sections']['order']['order_number']);

        $flipped = $service->applyKindOverlay('customer', [
            'sections' => [
                'order' => ['order_number' => true],
                'payment' => ['payment_status' => false],
                'delivery_customer' => [
                    'customer_name' => true,
                    'customer_phone' => true,
                    'delivery_address' => true,
                    'delivery_fee' => true,
                ],
            ],
            'block_styles' => [
                'order_number' => ['align' => 'left', 'font_size' => 'normal', 'bold' => false],
                'delivery_customer' => ['align' => 'left', 'font_size' => 'normal', 'bold' => false],
            ],
        ]);
        $this->assertSame('left', $flipped['block_styles']['order_number']['align']);
        $this->assertSame('normal', $flipped['block_styles']['order_number']['font_size']);
        $this->assertFalse($flipped['block_styles']['order_number']['bold']);
        $this->assertSame('left', $flipped['block_styles']['delivery_customer']['align']);
        $this->assertFalse($flipped['sections']['payment']['payment_status']);

        $kitchen = $service->applyKindOverlay('kitchen', [
            'block_styles' => [
                'delivery_customer' => ['align' => 'right', 'bold' => true],
                'payment' => ['align' => 'center'],
                'order_number' => ['align' => 'center', 'font_size' => 'large'],
            ],
        ]);
        $this->assertArrayNotHasKey('delivery_customer', $kitchen['block_styles']);
        $this->assertArrayNotHasKey('payment', $kitchen['block_styles']);
        $this->assertSame('center', $kitchen['block_styles']['order_number']['align']);
        $this->assertSame('large', $kitchen['block_styles']['order_number']['font_size']);
    }

    public function test_legacy_templates_gain_payment_status_and_delivery_customer(): void
    {
        $service = new ReceiptTemplateService();
        $merged = $service->applyKindOverlay('customer', [
            'sections' => [
                'order' => ['order_number' => true, 'customer_name' => true, 'customer_phone' => true, 'delivery_address' => true],
                'summary' => ['delivery_fee' => true],
                'payment' => ['payment_method' => true],
            ],
            'order' => ['order_number', 'customer', 'items', 'totals', 'payment', 'footer'],
        ]);

        $this->assertTrue($merged['sections']['payment']['payment_status']);
        $this->assertTrue($merged['sections']['delivery_customer']['customer_name']);
        $this->assertTrue($merged['sections']['delivery_customer']['customer_phone']);
        $this->assertTrue($merged['sections']['delivery_customer']['delivery_address']);
        $this->assertTrue($merged['sections']['delivery_customer']['delivery_fee']);
        $this->assertContains('delivery_customer', $merged['order']);
        $this->assertSame('left', $merged['block_styles']['delivery_customer']['align']);
        $this->assertSame('normal', $merged['block_styles']['order_number']['font_size']);
    }

    public function test_empty_or_partial_overlays_still_produce_a_renderable_template(): void
    {
        $service = new ReceiptTemplateService();
        $empty = $service->applyKindOverlay('customer', []);
        $partial = $service->applyKindOverlay('customer', [
            'logo' => ['mode' => 'none'],
            'sections' => ['header' => ['branch_name' => true]],
        ]);

        foreach ([$empty, $partial] as $merged) {
            $this->assertTrue($merged['sections']['order']['order_number']);
            $this->assertTrue($merged['sections']['payment']['payment_status']);
            $this->assertTrue($merged['sections']['delivery_customer']['customer_name']);
            $this->assertContains('delivery_customer', $merged['order']);
            $this->assertArrayHasKey('align', $merged['block_styles']['order_number']);
        }
        $this->assertSame('none', $partial['logo']['mode']);
    }

    public function test_node_receipt_template_element_scenarios(): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is required for receipt template element scenarios');
        }

        $script = base_path('tests/Js/receipt-template-elements.test.js');
        $output = [];
        $code = 0;
        exec(escapeshellcmd($node).' '.escapeshellarg($script).' 2>&1', $output, $code);

        $this->assertSame(0, $code, implode("\n", $output));
        $joined = implode("\n", $output);
        $this->assertStringContainsString('payment status renders when the template toggle is enabled', $joined);
        $this->assertStringContainsString('payment status is omitted when the template toggle is disabled', $joined);
        $this->assertStringContainsString('delivery customer information renders from saved order fields', $joined);
        $this->assertStringContainsString('delivery customer information disappears when the block is disabled', $joined);
        $this->assertStringContainsString('legacy templates without delivery_customer still merge and render it', $joined);
        $this->assertStringContainsString('empty partial and malformed templates still render a receipt', $joined);
        $this->assertStringContainsString('a malformed optional item cannot blank the rest of the receipt', $joined);
        $this->assertStringContainsString('order number visibility alignment and weight reach the renderer', $joined);
        $this->assertStringContainsString('delivery customer alignment and weight reach the renderer', $joined);
        $this->assertStringContainsString('acceptance: styled config is visible in the shared renderer', $joined);
        $this->assertStringContainsString('acceptance: flipping styles updates the shared renderer', $joined);
        $this->assertStringContainsString('preview and print share one renderer and schema keys', $joined);
        $this->assertStringContainsString('80mm and 58mm paper sizes change the shared CSS', $joined);
    }

    public function test_node_receipt_template_editor_initialization(): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is required for receipt template editor initialization');
        }

        $script = base_path('tests/Js/receipt-template-editor-init.test.js');
        $output = [];
        $code = 0;
        exec(escapeshellcmd($node).' '.escapeshellarg($script).' 2>&1', $output, $code);

        $this->assertSame(0, $code, implode("\n", $output));
        $joined = implode("\n", $output);
        $this->assertStringContainsString('empty payload still initializes a default customer preview', $joined);
        $this->assertStringContainsString('null payload and missing optional sections still load', $joined);
        $this->assertStringContainsString('legacy template without delivery_customer or payment_status still previews', $joined);
        $this->assertStringContainsString('payment status enabled and disabled update the live preview', $joined);
        $this->assertStringContainsString('delivery customer information can be toggled in the live preview', $joined);
        $this->assertStringContainsString('alignment and typography changes update the live preview', $joined);
        $this->assertStringContainsString('save payload round-trip keeps styled config on reload', $joined);
        $this->assertStringContainsString('failed optional QR request cannot crash editor initialization', $joined);
        $this->assertStringContainsString('editor source never blanks srcdoc before writing preview html', $joined);
    }
}
