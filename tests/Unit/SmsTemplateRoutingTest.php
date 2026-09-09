<?php

namespace Tests\Unit;

use App\CentralLogics\SMS_module;
use App\Support\SmsGatewayKeys;
use App\Support\SmsTemplateCatalog;
use Tests\TestCase;

class SmsTemplateRoutingTest extends TestCase
{
    public function test_template_gateway_assignment_resolves_to_canonical_keys(): void
    {
        $this->assertSame(
            SmsGatewayKeys::TRANSACTIONAL,
            SmsGatewayKeys::keyForAssignment(SmsGatewayKeys::ASSIGNMENT_TRANSACTIONAL)
        );
        $this->assertSame(
            SmsGatewayKeys::PROMOTIONAL,
            SmsGatewayKeys::keyForAssignment(SmsGatewayKeys::ASSIGNMENT_PROMOTIONAL)
        );
    }

    public function test_unknown_assignment_normalizes_to_transactional(): void
    {
        $template = SmsTemplateCatalog::normalizeTemplate(SmsTemplateCatalog::ORDER_PLACED, [
            'status' => 1,
            'message' => 'Hi',
            'gateway' => 'textsms_ke_customer_confirm',
        ]);

        $this->assertSame(SmsGatewayKeys::ASSIGNMENT_TRANSACTIONAL, $template['gateway']);
    }

    public function test_legacy_customer_confirm_fields_map_to_catalog_keys(): void
    {
        $this->assertSame(
            SmsTemplateCatalog::ORDER_PLACED,
            SmsTemplateCatalog::LEGACY_CUSTOMER_CONFIRM_FIELDS['order_placed_template']
        );
        $this->assertSame(
            SmsTemplateCatalog::PROCESSING,
            SmsTemplateCatalog::LEGACY_CUSTOMER_CONFIRM_FIELDS['processing_template']
        );
    }

    public function test_send_via_template_skips_disabled_template_without_calling_transport(): void
    {
        $this->assertFalse(SMS_module::isTemplateSendableFromParts(
            ['status' => 0, 'message' => 'Hi', 'gateway' => 'transactional'],
            ['status' => 1, 'api_key' => 'a', 'partner_id' => '1', 'sender_id' => 'MUNCH']
        ));
        $this->assertFalse(SMS_module::isTemplateSendableFromParts(
            ['status' => 1, 'message' => 'Hi', 'gateway' => 'promotional'],
            ['status' => 0, 'api_key' => 'a', 'partner_id' => '1', 'sender_id' => 'MUNCH']
        ));
        $this->assertTrue(SMS_module::isTemplateSendableFromParts(
            ['status' => 1, 'message' => 'Hi', 'gateway' => 'promotional'],
            ['status' => 1, 'api_key' => 'a', 'partner_id' => '1', 'sender_id' => 'MUNCH']
        ));
    }

    public function test_general_message_uses_configured_endpoint(): void
    {
        $this->assertSame(
            'https://promo.example/api',
            SMS_module::resolveSendSmsEndpoint([
                'endpoint' => 'https://promo.example/api',
            ])
        );
        $this->assertSame(
            SmsGatewayKeys::DEFAULT_SENDSMS_ENDPOINT,
            SMS_module::resolveSendSmsEndpoint([])
        );
    }

    public function test_only_two_textsms_gateway_implementations_remain(): void
    {
        $this->assertSame(SmsGatewayKeys::TRANSACTIONAL, SMS_module::TRANSACTIONAL_SMS_GATEWAY_KEY);
        $this->assertSame(SmsGatewayKeys::PROMOTIONAL, SMS_module::PROMOTIONAL_SMS_GATEWAY_KEY);
        $this->assertNotSame('textsms_ke', SMS_module::TRANSACTIONAL_SMS_GATEWAY_KEY);
        $this->assertNotSame('textsms_ke_not', SMS_module::TRANSACTIONAL_SMS_GATEWAY_KEY);
        $this->assertNotSame('textsms_ke_customer_confirm', SMS_module::TRANSACTIONAL_SMS_GATEWAY_KEY);
    }

    public function test_sms_config_ui_drops_legacy_textsms_cards(): void
    {
        $smsIndex = file_get_contents(base_path('resources/views/admin-views/business-settings/sms-index.blade.php'));
        $controller = file_get_contents(base_path('app/Http/Controllers/Admin/SMSModuleController.php'));

        $this->assertStringNotContainsString('textsms_ke_customer_confirm', $smsIndex);
        $this->assertStringNotContainsString('textsms_ke_not', $smsIndex);
        $this->assertStringContainsString('TextSMS Transactional', $smsIndex);
        $this->assertStringContainsString('TextSMS Promotional', $smsIndex);
        $this->assertStringContainsString('SMS Gateway Configuration', $smsIndex);
        $this->assertStringNotContainsString("'textsms_ke'", $controller);
        $this->assertStringNotContainsString('textsms_ke_not', $controller);
        $this->assertStringNotContainsString('textsms_ke_customer_confirm', $controller);
        $this->assertStringContainsString('textsms_transactional', $controller);
    }
}
