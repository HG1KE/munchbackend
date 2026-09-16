<?php

namespace Tests\Unit;

use App\CentralLogics\CustomerLogic;
use App\Http\Middleware\VerifyCsrfToken;
use App\Model\Admin;
use App\Model\AdminRole;
use App\Model\BusinessSetting;
use App\Model\Currency;
use App\Model\WalletTransaction;
use App\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminWalletAdjustmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSchema();
        $this->seedBusinessSettings();
        \App\CentralLogics\Helpers::forgetBusinessSettingsRuntimeCache();
        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_admin_can_credit_wallet(): void
    {
        $customer = $this->makeCustomer(100);
        $result = CustomerLogic::adjust_wallet_by_admin($customer->id, 40, 'credit', 'Goodwill credit', 7, (string) Str::uuid());

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['replayed']);
        $this->assertSame(140.0, (float) $customer->fresh()->wallet_balance);
        $this->assertSame(CustomerLogic::ADMIN_WALLET_CREDIT_TYPE, $result['transaction']->transaction_type);
    }

    public function test_admin_can_debit_wallet(): void
    {
        $customer = $this->makeCustomer(100);
        $result = CustomerLogic::adjust_wallet_by_admin($customer->id, 30, 'debit', 'Chargeback debit', 7, (string) Str::uuid());

        $this->assertTrue($result['ok']);
        $this->assertSame(70.0, (float) $customer->fresh()->wallet_balance);
        $this->assertSame(CustomerLogic::ADMIN_WALLET_DEBIT_TYPE, $result['transaction']->transaction_type);
    }

    public function test_credit_increases_balance_correctly(): void
    {
        $customer = $this->makeCustomer(12.5);
        CustomerLogic::adjust_wallet_by_admin($customer->id, 7.25, 'credit', 'Top up', 1, (string) Str::uuid());

        $this->assertSame(19.75, (float) $customer->fresh()->wallet_balance);
    }

    public function test_debit_decreases_balance_correctly(): void
    {
        $customer = $this->makeCustomer(12.5);
        CustomerLogic::adjust_wallet_by_admin($customer->id, 2.5, 'debit', 'Correction', 1, (string) Str::uuid());

        $this->assertSame(10.0, (float) $customer->fresh()->wallet_balance);
    }

    public function test_ledger_entry_is_created_for_credit(): void
    {
        $customer = $this->makeCustomer(50);
        $result = CustomerLogic::adjust_wallet_by_admin($customer->id, 15, 'credit', 'Promo credit', 9, (string) Str::uuid());

        $this->assertSame(1, WalletTransaction::query()->count());
        $ledger = $result['transaction'];
        $this->assertSame($customer->id, (int) $ledger->user_id);
        $this->assertSame(15.0, (float) $ledger->credit);
        $this->assertSame(0.0, (float) $ledger->debit);
        $this->assertSame(65.0, (float) $ledger->balance);
        $this->assertNotEmpty($ledger->transaction_id);
        $this->assertStringStartsWith('ADMADJ_', $ledger->transaction_id);
        $this->assertNotEmpty($ledger->created_at);
    }

    public function test_ledger_entry_is_created_for_debit(): void
    {
        $customer = $this->makeCustomer(50);
        $result = CustomerLogic::adjust_wallet_by_admin($customer->id, 12, 'debit', 'Manual debit', 9, (string) Str::uuid());

        $this->assertSame(1, WalletTransaction::query()->count());
        $ledger = $result['transaction'];
        $this->assertSame(0.0, (float) $ledger->credit);
        $this->assertSame(12.0, (float) $ledger->debit);
        $this->assertSame(38.0, (float) $ledger->balance);
    }

    public function test_correct_manual_admin_source_type_is_recorded(): void
    {
        $customer = $this->makeCustomer(20);
        $credit = CustomerLogic::adjust_wallet_by_admin($customer->id, 5, 'credit', 'Admin credit', 1, (string) Str::uuid());
        $debit = CustomerLogic::adjust_wallet_by_admin($customer->id, 3, 'debit', 'Admin debit', 1, (string) Str::uuid());

        $this->assertSame('add_fund_by_admin', $credit['transaction']->transaction_type);
        $this->assertSame('debit_by_admin', $debit['transaction']->transaction_type);
        $this->assertNotSame('add_fund', $credit['transaction']->transaction_type);
        $this->assertNotSame('order_place', $debit['transaction']->transaction_type);
    }

    public function test_reason_is_persisted(): void
    {
        $customer = $this->makeCustomer(10);
        $result = CustomerLogic::adjust_wallet_by_admin($customer->id, 4, 'credit', 'Customer service goodwill', 1, (string) Str::uuid());

        $this->assertSame('Customer service goodwill', $result['transaction']->reference);
        $this->assertSame('Customer service goodwill', WalletTransaction::query()->first()->reference);
    }

    public function test_admin_actor_is_recorded(): void
    {
        $customer = $this->makeCustomer(10);
        $result = CustomerLogic::adjust_wallet_by_admin($customer->id, 4, 'credit', 'Actor check', 42, (string) Str::uuid());

        $this->assertSame(42, (int) $result['transaction']->admin_id);
        $this->assertSame(42, (int) WalletTransaction::query()->first()->admin_id);
    }

    public function test_debit_greater_than_balance_is_rejected(): void
    {
        $customer = $this->makeCustomer(40);
        $result = CustomerLogic::adjust_wallet_by_admin($customer->id, 40.01, 'debit', 'Too large', 1, (string) Str::uuid());

        $this->assertFalse($result['ok']);
        $this->assertSame(40.0, (float) $customer->fresh()->wallet_balance);
        $this->assertSame(0, WalletTransaction::query()->count());
        $this->assertStringContainsString('exceeds', strtolower($result['message'] ?? ''));
    }

    public function test_zero_amount_is_rejected(): void
    {
        $customer = $this->makeCustomer(40);
        $result = CustomerLogic::adjust_wallet_by_admin($customer->id, 0, 'credit', 'Zero', 1, (string) Str::uuid());

        $this->assertFalse($result['ok']);
        $this->assertSame(40.0, (float) $customer->fresh()->wallet_balance);
        $this->assertSame(0, WalletTransaction::query()->count());
    }

    public function test_negative_amount_is_rejected(): void
    {
        $customer = $this->makeCustomer(40);
        $result = CustomerLogic::adjust_wallet_by_admin($customer->id, -8, 'credit', 'Negative', 1, (string) Str::uuid());

        $this->assertFalse($result['ok']);
        $this->assertSame(40.0, (float) $customer->fresh()->wallet_balance);
        $this->assertSame(0, WalletTransaction::query()->count());
    }

    public function test_unauthorized_non_admin_request_is_rejected(): void
    {
        $customer = $this->makeCustomer(80);
        $response = $this->post(route('admin.customer.wallet.adjust', $customer->id), [
            'type' => 'credit',
            'amount' => '10',
            'reason' => 'Unauthenticated attempt',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertRedirect(route('admin.auth.login'));
        $this->assertSame(80.0, (float) $customer->fresh()->wallet_balance);
        $this->assertSame(0, WalletTransaction::query()->count());
    }

    public function test_staff_without_wallet_adjustment_permission_is_rejected(): void
    {
        $customer = $this->makeCustomer(80);
        $staff = $this->makeAdmin(2, ['user_management']);

        $response = $this->actingAs($staff, 'admin')->post(route('admin.customer.wallet.adjust', $customer->id), [
            'type' => 'credit',
            'amount' => '10',
            'reason' => 'No permission',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertRedirect();
        $this->assertSame(80.0, (float) $customer->fresh()->wallet_balance);
        $this->assertSame(0, WalletTransaction::query()->count());
    }

    public function test_transaction_rolls_back_if_ledger_creation_fails(): void
    {
        $customer = $this->makeCustomer(90);
        $dispatcher = WalletTransaction::getEventDispatcher();
        WalletTransaction::flushEventListeners();
        WalletTransaction::creating(function () {
            throw new \RuntimeException('ledger fail');
        });

        try {
            $result = CustomerLogic::adjust_wallet_by_admin($customer->id, 10, 'credit', 'Should roll back', 1, (string) Str::uuid());
            $this->assertFalse($result['ok']);
            $this->assertSame(90.0, (float) $customer->fresh()->wallet_balance);
            $this->assertSame(0, WalletTransaction::query()->count());
        } finally {
            WalletTransaction::setEventDispatcher($dispatcher);
        }
    }

    public function test_concurrent_adjustments_cannot_corrupt_balance(): void
    {
        $source = file_get_contents(app_path('CentralLogics/CustomerLogic.php'));
        $this->assertStringContainsString('lockForUpdate()', $source);
        $this->assertStringContainsString('adjust_wallet_by_admin', $source);

        $customer = $this->makeCustomer(100);
        $first = CustomerLogic::adjust_wallet_by_admin($customer->id, 50, 'credit', 'First concurrent', 1, (string) Str::uuid());
        $second = CustomerLogic::adjust_wallet_by_admin($customer->id, 50, 'credit', 'Second concurrent', 1, (string) Str::uuid());

        $this->assertTrue($first['ok']);
        $this->assertTrue($second['ok']);
        $this->assertSame(200.0, (float) $customer->fresh()->wallet_balance);
        $this->assertSame(2, WalletTransaction::query()->count());
        $this->assertSame(150.0, (float) $first['transaction']->balance);
        $this->assertSame(200.0, (float) $second['transaction']->balance);

        $overdraft = CustomerLogic::adjust_wallet_by_admin($customer->id, 80, 'debit', 'First debit', 1, (string) Str::uuid());
        $blocked = CustomerLogic::adjust_wallet_by_admin($customer->id, 130, 'debit', 'Would corrupt', 1, (string) Str::uuid());

        $this->assertTrue($overdraft['ok']);
        $this->assertFalse($blocked['ok']);
        $this->assertSame(120.0, (float) $customer->fresh()->wallet_balance);
        $this->assertSame(3, WalletTransaction::query()->count());
    }

    public function test_repeated_submission_idempotency_cannot_create_unintended_duplicate(): void
    {
        $customer = $this->makeCustomer(100);
        $key = (string) Str::uuid();

        $first = CustomerLogic::adjust_wallet_by_admin($customer->id, 25, 'credit', 'Retry safe', 1, $key);
        $second = CustomerLogic::adjust_wallet_by_admin($customer->id, 25, 'credit', 'Retry safe', 1, $key);

        $this->assertTrue($first['ok']);
        $this->assertTrue($second['ok']);
        $this->assertFalse($first['replayed']);
        $this->assertTrue($second['replayed']);
        $this->assertSame($first['transaction']->id, $second['transaction']->id);
        $this->assertSame(1, WalletTransaction::query()->count());
        $this->assertSame(125.0, (float) $customer->fresh()->wallet_balance);
    }

    public function test_existing_paystack_wallet_credit_still_works(): void
    {
        $customer = $this->makeCustomer(0);
        $txn = CustomerLogic::create_wallet_transaction($customer->id, 800, 'add_fund', 'add-fund');

        $this->assertNotFalse($txn);
        $this->assertSame('add_fund', $txn->transaction_type);
        $this->assertSame(800.0, (float) $txn->credit);
        $this->assertSame(0.0, (float) $txn->debit);
        $this->assertSame(800.0, (float) $customer->fresh()->wallet_balance);
        $this->assertNull($txn->admin_id);
    }

    public function test_existing_paystack_wallet_debit_still_works(): void
    {
        $customer = $this->makeCustomer(250);
        $txn = CustomerLogic::create_wallet_transaction($customer->id, 250, 'order_place', '99');

        $this->assertNotFalse($txn);
        $this->assertSame('order_place', $txn->transaction_type);
        $this->assertSame(250.0, (float) $txn->debit);
        $this->assertSame(0.0, (float) $txn->credit);
        $this->assertSame(0.0, (float) $customer->fresh()->wallet_balance);
    }

    public function test_paystack_ledger_entries_remain_distinguishable_from_manual_adjustments(): void
    {
        $customer = $this->makeCustomer(0);
        CustomerLogic::create_wallet_transaction($customer->id, 800, 'add_fund', 'add-fund');
        CustomerLogic::adjust_wallet_by_admin($customer->id, 50, 'credit', 'Manual top-up', 3, (string) Str::uuid());
        CustomerLogic::create_wallet_transaction($customer->id, 250, 'order_place', '101');
        CustomerLogic::adjust_wallet_by_admin($customer->id, 20, 'debit', 'Manual debit', 3, (string) Str::uuid());

        $types = WalletTransaction::query()->orderBy('id')->pluck('transaction_type')->all();
        $this->assertSame(['add_fund', 'add_fund_by_admin', 'order_place', 'debit_by_admin'], $types);
        $this->assertSame(580.0, (float) $customer->fresh()->wallet_balance);
    }

    public function test_existing_wallet_balance_calculations_remain_unchanged(): void
    {
        $customer = $this->makeCustomer(0);
        CustomerLogic::create_wallet_transaction($customer->id, 800, 'add_fund', 'add-fund');
        CustomerLogic::create_wallet_transaction($customer->id, 250, 'order_place', '101');

        $this->assertSame(550.0, (float) $customer->fresh()->wallet_balance);
        $this->assertSame(800.0, (float) WalletTransaction::query()->where('transaction_type', 'add_fund')->value('balance'));
        $this->assertSame(550.0, (float) WalletTransaction::query()->where('transaction_type', 'order_place')->value('balance'));
    }

    public function test_authorized_admin_http_credit_refreshes_balance_and_ignores_browser_math(): void
    {
        $customer = $this->makeCustomer(100);
        $admin = $this->makeAdmin(1, ['user_management', 'customer_wallet_adjustment']);

        $response = $this->actingAs($admin, 'admin')->post(route('admin.customer.wallet.adjust', $customer->id), [
            'type' => 'credit',
            'amount' => '15.5',
            'reason' => 'HTTP credit',
            'idempotency_key' => (string) Str::uuid(),
            'resulting_balance' => '9999',
            'customer_id' => 999,
        ]);

        $response->assertOk();
        $this->assertArrayNotHasKey('errors', $response->json() ?? []);
        $response->assertJsonPath('wallet_balance', 115.5);
        $this->assertSame(115.5, (float) $customer->fresh()->wallet_balance);
        $this->assertSame(1, WalletTransaction::query()->count());
        $this->assertSame($admin->id, (int) WalletTransaction::query()->first()->admin_id);
        $this->assertSame('HTTP credit', WalletTransaction::query()->first()->reference);
    }

    public function test_http_rejects_zero_negative_malformed_and_overdraft_amounts(): void
    {
        $customer = $this->makeCustomer(10);
        $admin = $this->makeAdmin(1, ['user_management', 'customer_wallet_adjustment']);

        foreach (['0', '-4', '10.1234', 'abc'] as $amount) {
            $response = $this->actingAs($admin, 'admin')->post(route('admin.customer.wallet.adjust', $customer->id), [
                'type' => 'credit',
                'amount' => $amount,
                'reason' => 'Invalid amount',
                'idempotency_key' => (string) Str::uuid(),
            ]);
            $response->assertOk();
            $this->assertNotEmpty($response->json('errors'));
        }

        $overdraft = $this->actingAs($admin, 'admin')->post(route('admin.customer.wallet.adjust', $customer->id), [
            'type' => 'debit',
            'amount' => '10.01',
            'reason' => 'Overdraft',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $overdraft->assertOk();
        $this->assertNotEmpty($overdraft->json('errors'));
        $this->assertSame(10.0, (float) $customer->fresh()->wallet_balance);
        $this->assertSame(0, WalletTransaction::query()->count());
    }

    public function test_http_missing_reason_and_missing_customer_are_rejected(): void
    {
        $customer = $this->makeCustomer(10);
        $admin = $this->makeAdmin(1, ['user_management', 'customer_wallet_adjustment']);

        $missingReason = $this->actingAs($admin, 'admin')->post(route('admin.customer.wallet.adjust', $customer->id), [
            'type' => 'credit',
            'amount' => '1',
            'reason' => '',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $missingReason->assertOk();
        $this->assertNotEmpty($missingReason->json('errors'));

        $missingCustomer = $this->actingAs($admin, 'admin')->post(route('admin.customer.wallet.adjust', 99999), [
            'type' => 'credit',
            'amount' => '1',
            'reason' => 'Ghost customer',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $missingCustomer->assertOk();
        $this->assertNotEmpty($missingCustomer->json('errors'));
        $this->assertSame(0, WalletTransaction::query()->count());
    }

    public function test_customer_api_does_not_expose_wallet_adjustment(): void
    {
        $api = file_get_contents(base_path('routes/api/v1/api.php'));
        $this->assertStringNotContainsString('wallet.adjust', $api);
        $this->assertStringNotContainsString('adjust_wallet_by_admin', $api);
        $this->assertStringNotContainsString("adjust/{customer_id}", $api);
    }

    public function test_paystack_fulfillment_files_were_not_modified_for_this_feature(): void
    {
        $files = [
            app_path('Services/Paystack/PaystackFulfillmentService.php'),
            app_path('Http/Controllers/PaystackController.php'),
            app_path('Http/Controllers/Api/V1/PaystackWebhookController.php'),
        ];
        foreach ($files as $file) {
            $contents = file_get_contents($file);
            $this->assertStringNotContainsString('adjust_wallet_by_admin', $contents);
            $this->assertStringNotContainsString('debit_by_admin', $contents);
            $this->assertStringNotContainsString('customer_wallet_adjustment', $contents);
        }
    }

    public function test_customer_details_ui_and_permission_are_wired(): void
    {
        $page = file_get_contents(resource_path('views/admin-views/customer/customer-view.blade.php'));
        $routes = file_get_contents(base_path('routes/admin.php'));
        $constants = file_get_contents(app_path('Support/legacy_constants.php'));

        $this->assertStringContainsString("translate('adjust_wallet')", $page);
        $this->assertStringContainsString('id="adjust-wallet-modal"', $page);
        $this->assertStringContainsString('customer-wallet-history-body', $page);
        $this->assertStringContainsString("MANAGEMENT_SECTION['customer_wallet_adjustment']", $page);
        $this->assertStringContainsString("name('adjust')", $routes);
        $this->assertStringContainsString("middleware('module:customer_wallet_adjustment')", $routes);
        $this->assertStringContainsString("'customer_wallet_adjustment' => 'customer_wallet_adjustment'", $constants);
    }

    private function makeCustomer(float $balance): User
    {
        $customer = new User();
        $customer->f_name = 'Ada';
        $customer->l_name = 'Okoye';
        $customer->email = 'ada'.Str::lower(Str::random(6)).'@example.com';
        $customer->phone = '07'.random_int(10000000, 99999999);
        $customer->password = bcrypt('secret');
        $customer->wallet_balance = $balance;
        $customer->save();

        return $customer;
    }

    private function makeAdmin(int $roleId, array $modules): Admin
    {
        $role = new AdminRole();
        $role->id = $roleId;
        $role->name = $roleId === 1 ? 'Master Admin' : 'Staff';
        $role->module_access = json_encode($modules);
        $role->status = 1;
        $role->save();

        $admin = new Admin();
        $admin->f_name = 'Ops';
        $admin->email = 'admin'.$roleId.Str::lower(Str::random(4)).'@munch.test';
        $admin->password = bcrypt('secret');
        $admin->admin_role_id = $roleId;
        $admin->status = 1;
        $admin->save();

        return $admin->fresh();
    }

    private function seedBusinessSettings(): void
    {
        BusinessSetting::query()->delete();
        BusinessSetting::query()->insert([
            ['key' => 'wallet_status', 'value' => '1', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'currency', 'value' => 'KES', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'currency_symbol_position', 'value' => 'left', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'decimal_point_settings', 'value' => '2', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $currency = new Currency();
        $currency->currency_code = 'KES';
        $currency->currency_symbol = 'KSh';
        $currency->save();
    }

    private function ensureSchema(): void
    {
        foreach ([
            'wallet_transactions',
            'business_settings',
            'users',
            'admins',
            'admin_roles',
            'currencies',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->string('user_type')->nullable();
            $table->decimal('wallet_balance', 24, 3)->default(0);
            $table->timestamps();
        });

        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->nullable();
            $table->longText('value')->nullable();
            $table->timestamps();
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('transaction_id');
            $table->decimal('credit', 24, 3)->default(0);
            $table->decimal('debit', 24, 3)->default(0);
            $table->decimal('admin_bonus', 24, 3)->default(0);
            $table->decimal('balance', 24, 3)->default(0);
            $table->string('transaction_type')->nullable();
            $table->string('reference')->nullable();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('admin_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('module_access')->nullable();
            $table->boolean('status')->default(1);
            $table->timestamps();
        });

        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('admin_role_id')->nullable();
            $table->boolean('status')->default(1);
            $table->timestamps();
        });

        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('country')->nullable();
            $table->string('currency_code')->nullable();
            $table->string('currency_symbol')->nullable();
            $table->decimal('exchange_rate', 8, 2)->nullable();
            $table->timestamps();
        });
    }
}
