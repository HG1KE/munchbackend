<?php

namespace App\Services;

use App\CentralLogics\Helpers;
use App\Model\Branch;
use App\Model\BusinessSetting;
use App\Model\SocialMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class ReceiptTemplateService
{
    public const VERSION = 1;

    public const SETTINGS_KEY = 'receipt_templates';

    public const PAPER = ['58mm', '80mm'];

    public const FONT_SIZES = ['small', 'medium', 'large'];

    public const FONT_WEIGHTS = ['normal', 'bold'];

    public const SPACING = ['compact', 'normal', 'wide'];

    public const DIVIDERS = ['solid', 'dashed', 'none'];

    public const LOGO_MODES = ['company', 'upload', 'none'];

    public const KITCHEN_FORBIDDEN = [
        'unit_price',
        'line_total',
        'subtotal',
        'discount',
        'tax',
        'delivery_fee',
        'total',
        'paid_amount',
        'change',
        'payment_method',
        'payment_status',
    ];

    public function companyTemplates(): array
    {
        $stored = Helpers::get_business_settings(self::SETTINGS_KEY);

        return $this->normalizeCompany(is_array($stored) ? $stored : []);
    }

    public function saveCompany(array $payload): array
    {
        $normalized = $this->normalizeCompany($payload);
        BusinessSetting::updateOrCreate(
            ['key' => self::SETTINGS_KEY],
            ['value' => json_encode($normalized)]
        );
        Helpers::forgetBusinessSettingsRuntimeCache();

        return $normalized;
    }

    public function restoreCompanyDefaults(): array
    {
        return $this->saveCompany($this->factoryCompany());
    }

    /**
     * @return array{use_company: bool, customer: array<string, mixed>, kitchen: array<string, mixed>, print: array<string, mixed>, inherited: bool}
     */
    public function forBranch(?Branch $branch): array
    {
        $company = $this->companyTemplates();
        $stored = $this->branchStored($branch);
        $useCompany = (bool) ($stored['use_company'] ?? true);
        $print = $this->mergePrint($company['print'], is_array($stored['print'] ?? null) ? $stored['print'] : []);

        $customer = $useCompany
            ? $company['customer']
            : $this->mergeKind('customer', $company['customer'], is_array($stored['customer'] ?? null) ? $stored['customer'] : []);
        $kitchen = $useCompany
            ? $company['kitchen']
            : $this->mergeKind('kitchen', $company['kitchen'], is_array($stored['kitchen'] ?? null) ? $stored['kitchen'] : []);

        return [
            'version' => self::VERSION,
            'use_company' => $useCompany,
            'inherited' => $useCompany,
            'customer' => $this->withResolvedAssets($customer, $branch),
            'kitchen' => $this->withResolvedAssets($kitchen, $branch),
            'print' => $print,
            'context' => $this->placeholderContext($branch),
        ];
    }

    public function saveBranch(Branch $branch, array $payload): array
    {
        if (! Schema::hasColumn('branches', 'receipt_settings')) {
            return $this->forBranch($branch);
        }

        $company = $this->companyTemplates();
        $useCompany = ! array_key_exists('use_company', $payload) || (bool) $payload['use_company'];
        $stored = [
            'version' => self::VERSION,
            'use_company' => $useCompany,
            'print' => $this->mergePrint($company['print'], is_array($payload['print'] ?? null) ? $payload['print'] : []),
            'customer' => null,
            'kitchen' => null,
        ];

        if (! $useCompany) {
            $stored['customer'] = $this->mergeKind('customer', $company['customer'], is_array($payload['customer'] ?? null) ? $payload['customer'] : []);
            $stored['kitchen'] = $this->mergeKind('kitchen', $company['kitchen'], is_array($payload['kitchen'] ?? null) ? $payload['kitchen'] : []);
        }

        $branch->receipt_settings = json_encode($stored);
        $branch->save();

        return $this->forBranch($branch->fresh());
    }

    public function restoreBranchToCompany(Branch $branch): array
    {
        return $this->saveBranch($branch, [
            'use_company' => true,
            'print' => $this->companyTemplates()['print'],
        ]);
    }

    public function restoreBranchCustomFromCompany(Branch $branch): array
    {
        $company = $this->companyTemplates();

        return $this->saveBranch($branch, [
            'use_company' => false,
            'customer' => $company['customer'],
            'kitchen' => $company['kitchen'],
            'print' => $this->branchStored($branch)['print'] ?? $company['print'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function editorPayload(?Branch $branch, bool $canEditCompany): array
    {
        $company = $this->companyTemplates();
        $resolved = $this->forBranch($branch);
        $context = $this->placeholderContext($branch);

        return [
            'version' => self::VERSION,
            'scope' => $branch ? 'branch' : 'company',
            'branch_id' => $branch?->id,
            'can_edit_company' => $canEditCompany,
            'can_edit' => $branch ? true : $canEditCompany,
            'use_company' => $resolved['use_company'],
            'company' => [
                'customer' => $this->withResolvedAssets($company['customer'], $branch),
                'kitchen' => $this->withResolvedAssets($company['kitchen'], $branch),
                'print' => $company['print'],
            ],
            'factory' => $this->factoryCompany(),
            'customer' => $resolved['customer'],
            'kitchen' => $resolved['kitchen'],
            'print' => $resolved['print'],
            'context' => $context,
            'sample_job' => $this->sampleJob($context),
            'section_catalog' => $this->sectionCatalog(),
            'placeholders' => [
                '{branch_name}',
                '{phone}',
                '{website}',
                '{instagram}',
                '{address}',
                '{tax_pin}',
            ],
        ];
    }

    public function storeLogo(UploadedFile $file, ?string $oldPath = null): string
    {
        if ($oldPath) {
            return Helpers::update('receipt/', $oldPath, 'png', $file);
        }

        return Helpers::upload('receipt/', 'png', $file);
    }

    public function logoUrl(?string $path): ?string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return null;
        }

        return asset('storage/app/public/receipt/'.$path);
    }

    public function companyLogoUrl(): ?string
    {
        $logo = Helpers::get_business_settings('logo');
        if (! is_string($logo) || trim($logo) === '') {
            return null;
        }

        return asset('storage/app/public/restaurant/'.$logo);
    }

    /**
     * @return array<string, string>
     */
    public function placeholderContext(?Branch $branch): array
    {
        $instagram = '';
        try {
            $row = SocialMedia::query()->where('name', 'instagram')->where('status', 1)->first();
            $instagram = (string) ($row->link ?? '');
        } catch (\Throwable) {
            $instagram = '';
        }

        return [
            'branch_name' => (string) ($branch?->name ?: Helpers::get_business_settings('restaurant_name') ?: 'MUNCH'),
            'phone' => (string) ($branch?->phone ?: Helpers::get_business_settings('phone') ?: ''),
            'address' => (string) ($branch?->address ?: Helpers::get_business_settings('address') ?: ''),
            'website' => (string) (Helpers::get_business_settings('restaurant_website') ?: 'https://munch.co.ke'),
            'instagram' => $instagram !== '' ? $instagram : '@munch_ke',
            'tax_pin' => (string) (Helpers::get_business_settings('tax_pin') ?: ''),
            'restaurant_name' => (string) (Helpers::get_business_settings('restaurant_name') ?: 'MUNCH'),
        ];
    }

    /**
     * @param  array<string, string>  $context
     * @return array<string, mixed>
     */
    public function sampleJob(array $context): array
    {
        return [
            'orderId' => 1042,
            'number' => '#M-1042',
            'branch' => $context['branch_name'],
            'date' => '10 Sep 2026',
            'time' => '12:41',
            'orderType' => 'Delivery',
            'salesChannel' => 'glovo',
            'isDelivery' => true,
            'items' => [
                [
                    'name' => 'Chicken Burger',
                    'quantity' => 2,
                    'options' => ['Extra cheese', 'No onion'],
                    'notes' => 'Cut in half',
                    'unit_price' => 850,
                    'line_total' => 1700,
                ],
                [
                    'name' => 'Fries',
                    'quantity' => 1,
                    'options' => [],
                    'notes' => '',
                    'unit_price' => 250,
                    'line_total' => 250,
                ],
            ],
            'customer' => 'Jane W.',
            'phone' => '0700 000 000',
            'address' => 'Westlands, Nairobi',
            'notes' => 'Leave at the gate',
            'subtotal' => 1950,
            'delivery_fee' => 150,
            'discount' => 100,
            'tax' => 0,
            'grand_total' => 2000,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'cash_received' => 2000,
            'change' => 0,
            'mpesa_till' => '123456',
            'cashier' => $context['branch_name'],
            'riderName' => 'Alex',
            'riderPhone' => '0711 111 111',
        ];
    }

    /**
     * @return array<string, array<string, list<array{key: string, label: string}>>>
     */
    public function sectionCatalog(): array
    {
        return [
            'customer' => [
                'header' => [
                    ['key' => 'logo', 'label' => 'Logo'],
                    ['key' => 'branch_name', 'label' => 'Branch Name'],
                    ['key' => 'branch_address', 'label' => 'Branch Address'],
                    ['key' => 'branch_phone', 'label' => 'Branch Phone'],
                    ['key' => 'tax_pin', 'label' => 'Tax PIN'],
                    ['key' => 'receipt_title', 'label' => 'Receipt Title'],
                ],
                'order' => [
                    ['key' => 'order_number', 'label' => 'Order Number'],
                    ['key' => 'order_type', 'label' => 'Order Type'],
                    ['key' => 'sales_channel', 'label' => 'Sales Channel'],
                    ['key' => 'date', 'label' => 'Date'],
                    ['key' => 'time', 'label' => 'Time'],
                    ['key' => 'cashier', 'label' => 'Cashier'],
                    ['key' => 'customer_name', 'label' => 'Customer Name'],
                    ['key' => 'customer_phone', 'label' => 'Customer Phone'],
                    ['key' => 'delivery_address', 'label' => 'Delivery Address'],
                    ['key' => 'rider_name', 'label' => 'Rider Name'],
                    ['key' => 'rider_phone', 'label' => 'Rider Phone'],
                ],
                'items' => [
                    ['key' => 'product_name', 'label' => 'Product Name'],
                    ['key' => 'variations', 'label' => 'Variations'],
                    ['key' => 'modifiers', 'label' => 'Modifiers'],
                    ['key' => 'notes', 'label' => 'Notes'],
                    ['key' => 'quantity', 'label' => 'Quantity'],
                    ['key' => 'unit_price', 'label' => 'Unit Price'],
                    ['key' => 'line_total', 'label' => 'Line Total'],
                ],
                'summary' => [
                    ['key' => 'subtotal', 'label' => 'Subtotal'],
                    ['key' => 'discount', 'label' => 'Discount'],
                    ['key' => 'tax', 'label' => 'Tax'],
                    ['key' => 'delivery_fee', 'label' => 'Delivery Fee'],
                    ['key' => 'total', 'label' => 'Total'],
                    ['key' => 'paid_amount', 'label' => 'Paid Amount'],
                    ['key' => 'change', 'label' => 'Change'],
                ],
                'payment' => [
                    ['key' => 'payment_method', 'label' => 'Payment Method'],
                    ['key' => 'payment_status', 'label' => 'Payment Status'],
                ],
                'footer' => [
                    ['key' => 'qr_code', 'label' => 'QR Code'],
                    ['key' => 'barcode', 'label' => 'Barcode'],
                    ['key' => 'thank_you_message', 'label' => 'Thank You Message'],
                    ['key' => 'footer_text', 'label' => 'Footer Text'],
                    ['key' => 'return_policy', 'label' => 'Return Policy'],
                    ['key' => 'social_media', 'label' => 'Social Media'],
                ],
                'marketing' => [
                    ['key' => 'promotion_banner', 'label' => 'Promotion Banner'],
                ],
            ],
            'kitchen' => [
                'header' => [
                    ['key' => 'logo', 'label' => 'Logo'],
                    ['key' => 'branch_name', 'label' => 'Branch Name'],
                    ['key' => 'receipt_title', 'label' => 'Ticket Title'],
                ],
                'order' => [
                    ['key' => 'order_number', 'label' => 'Order Number'],
                    ['key' => 'order_type', 'label' => 'Order Type'],
                    ['key' => 'sales_channel', 'label' => 'Sales Channel'],
                    ['key' => 'date', 'label' => 'Date'],
                    ['key' => 'time', 'label' => 'Time'],
                    ['key' => 'customer_name', 'label' => 'Customer'],
                    ['key' => 'customer_phone', 'label' => 'Customer Phone'],
                    ['key' => 'delivery_address', 'label' => 'Delivery Address'],
                    ['key' => 'rider_name', 'label' => 'Rider Name'],
                    ['key' => 'rider_phone', 'label' => 'Rider Phone'],
                ],
                'items' => [
                    ['key' => 'product_name', 'label' => 'Product Name'],
                    ['key' => 'variations', 'label' => 'Variations'],
                    ['key' => 'modifiers', 'label' => 'Modifiers'],
                    ['key' => 'notes', 'label' => 'Notes'],
                    ['key' => 'quantity', 'label' => 'Quantity'],
                    ['key' => 'special_instructions', 'label' => 'Special Instructions'],
                ],
                'footer' => [
                    ['key' => 'footer_text', 'label' => 'Footer Text'],
                ],
            ],
        ];
    }

    public function factoryCompany(): array
    {
        return [
            'version' => self::VERSION,
            'customer' => $this->factoryCustomer(),
            'kitchen' => $this->factoryKitchen(),
            'print' => $this->factoryPrint(),
        ];
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    private function normalizeCompany(array $stored): array
    {
        $factory = $this->factoryCompany();

        return [
            'version' => self::VERSION,
            'customer' => $this->mergeKind('customer', $factory['customer'], is_array($stored['customer'] ?? null) ? $stored['customer'] : []),
            'kitchen' => $this->mergeKind('kitchen', $factory['kitchen'], is_array($stored['kitchen'] ?? null) ? $stored['kitchen'] : []),
            'print' => $this->mergePrint($factory['print'], is_array($stored['print'] ?? null) ? $stored['print'] : []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function branchStored(?Branch $branch): array
    {
        if (! $branch || ! Schema::hasColumn('branches', 'receipt_settings')) {
            return ['use_company' => true];
        }

        $raw = $branch->receipt_settings;
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || trim($raw) === '') {
            return ['use_company' => true];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : ['use_company' => true];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overlay
     * @return array<string, mixed>
     */
    private function mergeKind(string $kind, array $base, array $overlay): array
    {
        $sections = $base['sections'] ?? [];
        $overlaySections = is_array($overlay['sections'] ?? null) ? $overlay['sections'] : [];
        foreach ($sections as $group => $flags) {
            $incoming = is_array($overlaySections[$group] ?? null) ? $overlaySections[$group] : [];
            foreach ($flags as $key => $default) {
                if ($kind === 'kitchen' && in_array($key, self::KITCHEN_FORBIDDEN, true)) {
                    $sections[$group][$key] = false;
                    continue;
                }
                if (array_key_exists($key, $incoming)) {
                    $sections[$group][$key] = (bool) $incoming[$key];
                } else {
                    $sections[$group][$key] = (bool) $default;
                }
            }
        }

        $style = $base['style'] ?? $this->factoryStyle($kind === 'kitchen' ? 'large' : 'medium');
        $overlayStyle = is_array($overlay['style'] ?? null) ? $overlay['style'] : [];
        $style = [
            'font_size' => $this->pick($overlayStyle['font_size'] ?? $style['font_size'], self::FONT_SIZES, $style['font_size']),
            'font_weight' => $this->pick($overlayStyle['font_weight'] ?? $style['font_weight'], self::FONT_WEIGHTS, $style['font_weight']),
            'section_spacing' => $this->pick($overlayStyle['section_spacing'] ?? $style['section_spacing'], self::SPACING, $style['section_spacing']),
            'divider' => $this->pick($overlayStyle['divider'] ?? $style['divider'], self::DIVIDERS, $style['divider']),
        ];

        $logo = $base['logo'] ?? ['mode' => 'none', 'path' => null];
        $overlayLogo = is_array($overlay['logo'] ?? null) ? $overlay['logo'] : [];
        $logo = [
            'mode' => $this->pick($overlayLogo['mode'] ?? $logo['mode'], self::LOGO_MODES, $logo['mode']),
            'path' => array_key_exists('path', $overlayLogo) ? ($overlayLogo['path'] ?: null) : ($logo['path'] ?? null),
        ];

        $texts = $base['texts'] ?? [];
        $overlayTexts = is_array($overlay['texts'] ?? null) ? $overlay['texts'] : [];
        foreach ($texts as $key => $value) {
            if (array_key_exists($key, $overlayTexts)) {
                $texts[$key] = (string) $overlayTexts[$key];
            }
        }

        return [
            'sections' => $sections,
            'logo' => $logo,
            'style' => $style,
            'texts' => $texts,
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overlay
     * @return array<string, mixed>
     */
    private function mergePrint(array $base, array $overlay): array
    {
        $copiesReceipt = (int) ($overlay['receipt_copies'] ?? $base['receipt_copies']);
        $copiesKitchen = (int) ($overlay['kitchen_copies'] ?? $base['kitchen_copies']);

        return [
            'paper' => $this->pick($overlay['paper'] ?? $base['paper'], self::PAPER, '80mm'),
            'receipt_copies' => max(1, min(5, $copiesReceipt)),
            'kitchen_copies' => max(1, min(5, $copiesKitchen)),
            'auto_cut' => array_key_exists('auto_cut', $overlay) ? (bool) $overlay['auto_cut'] : (bool) $base['auto_cut'],
            'drawer_kick' => array_key_exists('drawer_kick', $overlay) ? (bool) $overlay['drawer_kick'] : (bool) $base['drawer_kick'],
        ];
    }

    /**
     * @param  array<string, mixed>  $kind
     * @return array<string, mixed>
     */
    private function withResolvedAssets(array $kind, ?Branch $branch): array
    {
        $mode = $kind['logo']['mode'] ?? 'none';
        $url = null;
        if ($mode === 'company') {
            $url = $this->companyLogoUrl();
        } elseif ($mode === 'upload') {
            $url = $this->logoUrl($kind['logo']['path'] ?? null);
        }
        $kind['logo_url'] = $url;
        $kind['qr_payload'] = $this->placeholderContext($branch)['website'];
        $kind['qr_data_uri'] = $this->qrDataUri((string) $kind['qr_payload']);

        return $kind;
    }

    private function qrDataUri(string $payload): ?string
    {
        $payload = trim($payload);
        if ($payload === '') {
            return null;
        }

        try {
            $svg = QrCode::format('svg')->size(140)->margin(0)->generate($payload);

            return 'data:image/svg+xml;base64,'.base64_encode((string) $svg);
        } catch (\Throwable) {
            return null;
        }
    }

    private function pick(mixed $value, array $allowed, string $fallback): string
    {
        $value = (string) $value;

        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    /**
     * @return array<string, mixed>
     */
    private function factoryCustomer(): array
    {
        return [
            'sections' => [
                'header' => [
                    'logo' => false,
                    'branch_name' => true,
                    'branch_address' => false,
                    'branch_phone' => false,
                    'tax_pin' => false,
                    'receipt_title' => false,
                ],
                'order' => [
                    'order_number' => true,
                    'order_type' => true,
                    'sales_channel' => true,
                    'date' => true,
                    'time' => true,
                    'cashier' => true,
                    'customer_name' => true,
                    'customer_phone' => true,
                    'delivery_address' => true,
                    'rider_name' => true,
                    'rider_phone' => true,
                ],
                'items' => [
                    'product_name' => true,
                    'variations' => true,
                    'modifiers' => true,
                    'notes' => true,
                    'quantity' => true,
                    'unit_price' => false,
                    'line_total' => true,
                ],
                'summary' => [
                    'subtotal' => true,
                    'discount' => true,
                    'tax' => false,
                    'delivery_fee' => true,
                    'total' => true,
                    'paid_amount' => true,
                    'change' => true,
                ],
                'payment' => [
                    'payment_method' => true,
                    'payment_status' => false,
                ],
                'footer' => [
                    'qr_code' => false,
                    'barcode' => false,
                    'thank_you_message' => true,
                    'footer_text' => false,
                    'return_policy' => false,
                    'social_media' => false,
                ],
                'marketing' => [
                    'promotion_banner' => false,
                ],
            ],
            'logo' => ['mode' => 'company', 'path' => null],
            'style' => $this->factoryStyle('medium'),
            'texts' => [
                'receipt_title' => 'Receipt',
                'thank_you_message' => 'Thank you for choosing Munch',
                'footer_text' => "Thank you for dining with us.\n\nInstagram:\n{instagram}\n\nComplaints:\n{phone}\n\nVisit again!",
                'return_policy' => '',
                'promotion_banner' => "FREE DESSERT\nOn your next visit!",
                'tax_pin' => '',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function factoryKitchen(): array
    {
        return [
            'sections' => [
                'header' => [
                    'logo' => false,
                    'branch_name' => true,
                    'receipt_title' => true,
                ],
                'order' => [
                    'order_number' => true,
                    'order_type' => true,
                    'sales_channel' => true,
                    'date' => false,
                    'time' => true,
                    'customer_name' => true,
                    'customer_phone' => false,
                    'delivery_address' => false,
                    'rider_name' => true,
                    'rider_phone' => true,
                ],
                'items' => [
                    'product_name' => true,
                    'variations' => true,
                    'modifiers' => true,
                    'notes' => true,
                    'quantity' => true,
                    'special_instructions' => true,
                ],
                'footer' => [
                    'footer_text' => false,
                ],
            ],
            'logo' => ['mode' => 'none', 'path' => null],
            'style' => $this->factoryStyle('large'),
            'texts' => [
                'receipt_title' => 'Kitchen Order',
                'footer_text' => '',
            ],
        ];
    }

    /**
     * @return array{font_size: string, font_weight: string, section_spacing: string, divider: string}
     */
    private function factoryStyle(string $fontSize): array
    {
        return [
            'font_size' => $fontSize,
            'font_weight' => 'bold',
            'section_spacing' => 'normal',
            'divider' => 'dashed',
        ];
    }

    /**
     * @return array{paper: string, receipt_copies: int, kitchen_copies: int, auto_cut: bool, drawer_kick: bool}
     */
    private function factoryPrint(): array
    {
        return [
            'paper' => '80mm',
            'receipt_copies' => 1,
            'kitchen_copies' => 1,
            'auto_cut' => true,
            'drawer_kick' => false,
        ];
    }
}
