<?php

namespace Tests\Unit;

use App\Support\SmsGatewayKeys;
use App\Support\SmsGatewayMigrator;
use App\Support\SmsTemplateCatalog;
use Tests\TestCase;

class SmsGatewayMigratorTest extends TestCase
{
    public function test_prefers_active_customer_confirm_with_api_key(): void
    {
        $source = SmsGatewayMigrator::pickTransactionalSource(
            ['status' => 1, 'api_key' => 'confirm-key', 'partner_id' => '1', 'sender_id' => 'MUNCH'],
            ['status' => 1, 'api_key' => 'otp-key'],
            ['status' => 1, 'api_key' => 'branch-key']
        );

        $this->assertSame('confirm-key', $source['api_key']);
    }

    public function test_falls_back_to_active_otp_then_branch_then_any_key(): void
    {
        $fromOtp = SmsGatewayMigrator::pickTransactionalSource(
            ['status' => 0, 'api_key' => 'confirm-key'],
            ['status' => 1, 'api_key' => 'otp-key'],
            ['status' => 1, 'api_key' => 'branch-key']
        );
        $this->assertSame('otp-key', $fromOtp['api_key']);

        $fromBranch = SmsGatewayMigrator::pickTransactionalSource(
            ['status' => 0, 'api_key' => ''],
            ['status' => 0, 'api_key' => ''],
            ['status' => 1, 'api_key' => 'branch-key']
        );
        $this->assertSame('branch-key', $fromBranch['api_key']);

        $fromInactiveKey = SmsGatewayMigrator::pickTransactionalSource(
            ['status' => 0, 'api_key' => 'leftover-key'],
            ['status' => 0, 'api_key' => ''],
            null
        );
        $this->assertSame('leftover-key', $fromInactiveKey['api_key']);
    }

    public function test_transactional_is_active_if_any_legacy_gateway_was_active(): void
    {
        $this->assertTrue(SmsGatewayMigrator::transactionalShouldBeActive(
            ['status' => 0],
            ['status' => 1],
            ['status' => 0]
        ));
        $this->assertFalse(SmsGatewayMigrator::transactionalShouldBeActive(
            ['status' => 0],
            ['status' => 0],
            null
        ));
    }

    public function test_seeded_templates_default_to_transactional_and_keep_legacy_copy(): void
    {
        $templates = SmsGatewayMigrator::seedTemplates(
            [
                'status' => 1,
                'order_placed_template' => 'Placed {order_id}',
                'processing_template' => 'Processing {order_id}',
            ],
            [
                'status' => 1,
                'otp_template' => 'Code #OTP#',
            ],
            [
                'status' => 0,
                'notification_template' => 'Branch #{order_id}',
            ]
        );

        $this->assertSame(SmsGatewayKeys::ASSIGNMENT_TRANSACTIONAL, $templates[SmsTemplateCatalog::CUSTOMER_OTP]['gateway']);
        $this->assertSame(1, $templates[SmsTemplateCatalog::CUSTOMER_OTP]['status']);
        $this->assertSame('Code #OTP#', $templates[SmsTemplateCatalog::CUSTOMER_OTP]['message']);
        $this->assertSame(1, $templates[SmsTemplateCatalog::ORDER_PLACED]['status']);
        $this->assertSame('Placed {order_id}', $templates[SmsTemplateCatalog::ORDER_PLACED]['message']);
        $this->assertSame(1, $templates[SmsTemplateCatalog::PROCESSING]['status']);
        $this->assertSame(0, $templates[SmsTemplateCatalog::BRANCH_NEW_ORDER]['status']);
        $this->assertSame('Branch #{order_id}', $templates[SmsTemplateCatalog::BRANCH_NEW_ORDER]['message']);
        $this->assertSame(0, $templates[SmsTemplateCatalog::DELIVERED]['status']);
        $this->assertSame(SmsGatewayKeys::ASSIGNMENT_TRANSACTIONAL, $templates[SmsTemplateCatalog::DELIVERED]['gateway']);
    }

    public function test_promotional_stays_intact_when_credentials_already_exist(): void
    {
        $payload = SmsGatewayMigrator::buildPromotionalPayload([
            'status' => 1,
            'api_key' => 'promo-key',
            'partner_id' => '9',
            'sender_id' => 'MUNCHP',
        ], false);

        $this->assertSame(1, $payload['status']);
        $this->assertSame('promo-key', $payload['api_key']);
        $this->assertSame(SmsGatewayKeys::DEFAULT_SENDSMS_ENDPOINT, $payload['endpoint']);
    }

    public function test_promotional_empty_disabled_when_creating_fresh(): void
    {
        $payload = SmsGatewayMigrator::buildPromotionalPayload(null, true);

        $this->assertSame(0, $payload['status']);
        $this->assertSame('', $payload['api_key']);
        $this->assertSame(SmsGatewayKeys::DEFAULT_SENDSMS_ENDPOINT, $payload['endpoint']);
    }
}
