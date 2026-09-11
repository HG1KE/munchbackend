(function (root) {
    'use strict';

    var CHANNEL_LABELS = {
        pos: 'POS',
        delivery: 'Delivery',
        takeaway: 'Take Away',
        dine_in: 'Dine In',
        glovo: 'Glovo',
        uber: 'Uber',
        bolt_food: 'Bolt Food'
    };

    var MARKETPLACE = { glovo: true, uber: true, bolt_food: true };
    var KITCHEN_FORBIDDEN = {
        unit_price: true,
        line_total: true,
        subtotal: true,
        discount: true,
        tax: true,
        delivery_fee: true,
        total: true,
        paid_amount: true,
        change: true,
        payment_method: true,
        payment_status: true
    };

    var CUSTOMER_BLOCKS = [
        'logo', 'branch_name', 'receipt_title', 'branch_details', 'order_type',
        'order_number', 'customer', 'delivery_customer', 'items', 'totals', 'payment', 'mpesa_till',
        'promotion', 'qr_code', 'barcode', 'footer'
    ];

    var KITCHEN_BLOCKS = [
        'logo', 'branch_name', 'receipt_title', 'order_type',
        'order_number', 'customer', 'items', 'footer'
    ];

    var DEFAULT_BLOCK_STYLE = {
        font_size: 'normal',
        bold: false,
        align: 'left',
        divider_before: false,
        divider_after: false,
        margin_top: false,
        margin_bottom: false
    };

    var DEFAULT_CUSTOMER = {
        sections: {
            header: { logo: false, branch_name: true, branch_address: false, branch_phone: false, tax_pin: false, receipt_title: false },
            order: { order_number: true, order_type: true, sales_channel: true, date: true, time: true, cashier: true, customer_name: true, customer_phone: true, delivery_address: true, rider_name: true, rider_phone: true },
            delivery_customer: { customer_name: true, customer_phone: true, delivery_address: true, delivery_fee: true },
            items: { product_name: true, variations: true, modifiers: true, notes: true, quantity: true, unit_price: false, line_total: true },
            summary: { subtotal: true, discount: true, tax: false, delivery_fee: true, total: true, paid_amount: true, change: true },
            payment: { payment_method: true, payment_status: true, mpesa_till: true },
            footer: { qr_code: false, barcode: false, thank_you_message: true, footer_text: false, return_policy: false, social_media: false },
            marketing: { promotion_banner: false }
        },
        logo: { mode: 'none', path: null, thermal_path: null, size: 'medium', optimize_thermal: false },
        style: { font_size: 'medium', font_weight: 'bold', section_spacing: 'normal', divider: 'dashed' },
        texts: { receipt_title: 'Receipt', thank_you_message: 'Thank you for choosing Munch', footer_text: '', return_policy: '', promotion_banner: '', tax_pin: '' },
        order: CUSTOMER_BLOCKS.slice(),
        block_styles: {},
        qr: { type: 'website', url: '', size: 'medium' }
    };

    var DEFAULT_KITCHEN = {
        sections: {
            header: { logo: false, branch_name: true, receipt_title: true },
            order: { order_number: true, order_type: true, sales_channel: true, date: false, time: true, customer_name: true, customer_phone: false, delivery_address: false, rider_name: true, rider_phone: true },
            items: { product_name: true, variations: true, modifiers: true, notes: true, quantity: true, special_instructions: true },
            footer: { footer_text: false }
        },
        logo: { mode: 'none', path: null, thermal_path: null, size: 'medium', optimize_thermal: false },
        style: { font_size: 'large', font_weight: 'bold', section_spacing: 'normal', divider: 'dashed' },
        texts: { receipt_title: 'Kitchen Order', footer_text: '' },
        order: KITCHEN_BLOCKS.slice(),
        block_styles: {},
        qr: null
    };

    function clone(value) {
        return JSON.parse(JSON.stringify(value));
    }

    function defaults(kind) {
        return kind === 'kitchen' ? DEFAULT_KITCHEN : DEFAULT_CUSTOMER;
    }

    function allowedBlocks(kind) {
        return kind === 'kitchen' ? KITCHEN_BLOCKS : CUSTOMER_BLOCKS;
    }

    function mergeOrder(kind, template) {
        var allowed = allowedBlocks(kind);
        var incoming = (template && Array.isArray(template.order) && template.order.length)
            ? template.order
            : allowed;
        var seen = {};
        var out = [];
        incoming.forEach(function (id) {
            if (allowed.indexOf(id) !== -1 && !seen[id]) {
                seen[id] = true;
                out.push(id);
            }
        });
        allowed.forEach(function (id) {
            if (seen[id]) return;
            if (id === 'delivery_customer') {
                var after = out.indexOf('customer');
                if (after !== -1) {
                    out.splice(after + 1, 0, id);
                    seen[id] = true;
                    return;
                }
            }
            seen[id] = true;
            out.push(id);
        });
        return out;
    }

    function sectionGroup(incoming, group) {
        var extra = incoming && incoming[group];
        if (!extra || typeof extra !== 'object' || Array.isArray(extra)) return {};
        return extra;
    }

    function mergeSections(kind, template) {
        var base = defaults(kind).sections;
        var incoming = (template && template.sections && typeof template.sections === 'object' && !Array.isArray(template.sections))
            ? template.sections
            : {};
        var out = {};
        Object.keys(base).forEach(function (group) {
            out[group] = Object.assign({}, base[group], sectionGroup(incoming, group));
        });
        Object.keys(incoming).forEach(function (group) {
            if (!out[group]) out[group] = sectionGroup(incoming, group);
        });
        if (kind !== 'kitchen' && !incoming.delivery_customer && incoming.order && typeof incoming.order === 'object' && !Array.isArray(incoming.order)) {
            out.delivery_customer = Object.assign({}, out.delivery_customer, {
                customer_name: incoming.order.customer_name !== false,
                customer_phone: incoming.order.customer_phone !== false,
                delivery_address: incoming.order.delivery_address !== false,
                delivery_fee: !(incoming.summary && incoming.summary.delivery_fee === false)
            });
        }
        return out;
    }

    function mergeBlockStyles(kind, template) {
        var order = mergeOrder(kind, template);
        var incoming = (template && template.block_styles) || {};
        var out = {};
        order.forEach(function (id) {
            var extra = incoming[id];
            if (!extra || typeof extra !== 'object' || Array.isArray(extra)) extra = {};
            out[id] = Object.assign({}, DEFAULT_BLOCK_STYLE, extra);
        });
        return out;
    }

    function normalizeTemplate(kind, template) {
        var base = clone(defaults(kind));
        if (!template || typeof template !== 'object' || Array.isArray(template)) return base;
        var out = Object.assign({}, base, template);
        out.sections = mergeSections(kind, template);
        out.logo = Object.assign({}, base.logo, template.logo || {});
        if (!out.logo.size) out.logo.size = 'medium';
        out.style = Object.assign({}, base.style, template.style || {});
        out.texts = Object.assign({}, base.texts, template.texts || {});
        out.order = mergeOrder(kind, template);
        out.block_styles = mergeBlockStyles(kind, template);
        if (kind === 'kitchen') {
            out.qr = null;
        } else {
            out.qr = Object.assign({}, base.qr, template.qr || {});
        }
        return out;
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
        });
    }

    function escapeAttr(value) {
        return escapeHtml(value);
    }

    function on(template, group, key) {
        if (!template || !template.sections || !template.sections[group]) return false;
        return !!template.sections[group][key];
    }

    function kitchenSafe(kind, key) {
        return kind !== 'kitchen' || !KITCHEN_FORBIDDEN[key];
    }

    function show(kind, template, group, key) {
        return kitchenSafe(kind, key) && on(template, group, key);
    }

    function channelKey(job) {
        return String((job && (job.salesChannel || job.sales_channel)) || '').toLowerCase();
    }

    function channelLabel(job) {
        var key = channelKey(job);
        if (CHANNEL_LABELS[key]) return CHANNEL_LABELS[key];
        return String((job && (job.orderType || job.sales_channel_label)) || key || 'POS');
    }

    function applyPlaceholders(text, ctx) {
        ctx = ctx || {};
        return String(text == null ? '' : text).replace(/\{([a-z_]+)\}/gi, function (_, key) {
            var value = ctx[key];
            return value == null ? '' : String(value);
        });
    }

    function nl2br(text) {
        return escapeHtml(text).replace(/\n/g, '<br>');
    }

    function paymentLabel(method) {
        var key = String(method || '').toLowerCase();
        if (key === 'cash') return 'Cash';
        if (key === 'card') return 'Card';
        if (key === 'mpesa' || key === 'wallet') return 'M-PESA';
        if (key === 'pay_after_eating') return 'Pay after eating';
        if (key === 'cash_on_delivery') return 'Cash On Delivery';
        return key ? key.replace(/_/g, ' ') : '';
    }

    function money(value, currency) {
        var n = Number(value || 0);
        var symbol = currency || '';
        return symbol + n.toLocaleString(undefined, { maximumFractionDigits: 2 });
    }

    function barcodeHtml(text) {
        var value = String(text || '');
        if (!value) return '';
        var bars = '';
        for (var i = 0; i < value.length; i++) {
            var code = value.charCodeAt(i);
            bars += '<span style="display:inline-block;width:' + (2 + (code % 3)) + 'px;height:12mm;background:#000;margin-right:1px"></span>';
            bars += '<span style="display:inline-block;width:' + (1 + (code % 2)) + 'px;height:12mm;background:#fff"></span>';
        }
        return '<div class="ticket-barcode">' + bars + '<p>' + escapeHtml(value) + '</p></div>';
    }

    function dividerHtml(style) {
        var kind = (style && style.divider) || 'dashed';
        if (kind === 'none') return '';
        return '<hr class="rule rule--' + escapeAttr(kind) + '">';
    }

    function blockStyle(template, id) {
        var src = (template && template.block_styles && template.block_styles[id] && typeof template.block_styles[id] === 'object')
            ? template.block_styles[id]
            : {};
        return Object.assign({}, DEFAULT_BLOCK_STYLE, src);
    }

    function wrapBlock(id, html, template) {
        if (!html) return '';
        template = template || {};
        var st = blockStyle(template, id);
        var align = st.align === 'center' || st.align === 'right' ? st.align : 'left';
        var fs = st.font_size || 'normal';
        var cls = ['ticket-block', 'ticket-block--' + id, 'fs-' + fs];
        cls.push(st.bold ? 'is-bold' : 'is-normal');
        cls.push('is-' + align);
        if (st.margin_top) cls.push('mt-extra');
        if (st.margin_bottom) cls.push('mb-extra');
        var out = '';
        if (st.divider_before) out += dividerHtml(template.style);
        out += '<div class="' + cls.join(' ') + '">' + html + '</div>';
        if (st.divider_after) out += dividerHtml(template.style);
        return out;
    }

    function printTune(print) {
        var mode = (print && print.print_mode) || 'normal';
        var save = (print && print.paper_saving) || 'off';
        var weightBoost = mode === 'extra_dark' ? '900' : mode === 'dark' ? '800' : '';
        var lineHeight = save === 'maximum' ? '1.05' : save === 'normal' ? '1.2' : '1.35';
        var gapScale = save === 'maximum' ? 0.45 : save === 'normal' ? 0.7 : 1;
        var imgFilter = mode === 'extra_dark'
            ? 'contrast(1.45) brightness(0.88)'
            : mode === 'dark' ? 'contrast(1.22) brightness(0.94)' : 'none';
        var ruleWidth = mode === 'extra_dark' ? '2px' : mode === 'dark' ? '1.5px' : '1px';
        return {
            mode: mode,
            save: save,
            weightBoost: weightBoost,
            lineHeight: lineHeight,
            gapScale: gapScale,
            imgFilter: imgFilter,
            ruleWidth: ruleWidth
        };
    }

    function ticketCss(kind, template, print) {
        var kitchen = kind === 'kitchen';
        var paper = (print && print.paper) === '58mm' ? '58mm' : '80mm';
        var inner = paper === '58mm' ? '50mm' : '72mm';
        var size = (template && template.style && template.style.font_size) || (kitchen ? 'large' : 'medium');
        var weight = (template && template.style && template.style.font_weight) === 'normal' ? '600' : '800';
        var spacing = (template && template.style && template.style.section_spacing) || 'normal';
        var tune = printTune(print);
        var baseGap = spacing === 'compact' ? 1.5 : spacing === 'wide' ? 4.5 : 3;
        var gap = (baseGap * tune.gapScale) + 'mm';
        if (tune.weightBoost) weight = tune.weightBoost;
        var meta = size === 'small' ? '11px' : size === 'large' ? '14px' : '12px';
        var item = kitchen
            ? (size === 'small' ? '15px' : size === 'large' ? '18px' : '16px')
            : (size === 'small' ? '12px' : size === 'large' ? '14px' : '13px');
        var brand = size === 'small' ? '18px' : size === 'large' ? '22px' : '20px';
        var pad = tune.save === 'maximum' ? '0.5mm 0' : tune.save === 'normal' ? '1.2mm 0' : '2mm 0';
        return '@page{size:' + paper + ' auto;margin:0}' +
            'html,body{margin:0;padding:0;width:' + paper + ';background:#fff;color:#000;' +
            'font-family:"Courier New",Courier,ui-monospace,monospace}' +
            '*{box-sizing:border-box}' +
            '.ticket{width:' + inner + ';margin:0 auto;padding:' + pad + ';font-weight:' + weight + ';line-height:' + tune.lineHeight + '}' +
            '.brand{text-align:center;font-size:' + brand + ';font-weight:900;letter-spacing:.12em;margin:0}' +
            '.logo{display:block;max-width:28mm;max-height:18mm;margin:0 auto 2mm;filter:' + tune.imgFilter + ';image-rendering:pixelated}' +
            '.logo.logo-small{max-width:16mm;max-height:10mm}' +
            '.logo.logo-medium{max-width:28mm;max-height:18mm}' +
            '.logo.logo-large{max-width:42mm;max-height:28mm}' +
            '.title{text-align:center;font-size:' + (kitchen ? '20px' : '16px') + ';font-weight:900;margin:2mm 0 3mm}' +
            '.meta{font-size:' + meta + ';font-weight:' + weight + ';line-height:' + tune.lineHeight + '}' +
            '.meta p{margin:0 0 1mm}' +
            '.rule{border:0;margin:' + gap + ' 0}' +
            '.rule--dashed{border-top:' + tune.ruleWidth + ' dashed #000}' +
            '.rule--solid{border-top:' + tune.ruleWidth + ' solid #000}' +
            '.item{font-size:' + item + ';font-weight:900;margin:2.5mm 0 0}' +
            '.opt{padding-left:4mm;font-size:' + (kitchen ? '15px' : '12px') + ';font-weight:800}' +
            '.row{display:flex;justify-content:space-between;gap:2mm;font-weight:800;font-size:' + meta + '}' +
            '.row.is-grand{font-size:15px;font-weight:900;margin-top:1mm}' +
            'table{width:100%;border-collapse:collapse;font-weight:800;font-size:' + item + '}' +
            'td{vertical-align:top;padding:1mm 0}' +
            'td.qty{width:10mm;white-space:nowrap}' +
            'td.price{width:22mm;text-align:right;white-space:nowrap}' +
            '.thanks{text-align:center;font-weight:800;margin-top:3mm;font-size:' + meta + '}' +
            '.footer-block{text-align:center;font-size:' + meta + ';white-space:pre-wrap;margin:' + gap + ' 0}' +
            '.promo{text-align:center;border:1px dashed #000;padding:2mm;margin:' + gap + ' 0;font-weight:900}' +
            '.order-type{text-align:center;margin:3mm 0 2mm}' +
            '.order-type__label{font-size:' + (kitchen ? '15px' : '14px') + ';font-weight:900;letter-spacing:.1em;margin:0}' +
            '.order-type__value{font-size:' + (kitchen ? '24px' : '22px') + ';font-weight:900;margin:1mm 0 0;letter-spacing:.04em}' +
            '.order-type__channel{font-size:' + (kitchen ? '16px' : '14px') + ';font-weight:900;margin:1mm 0 0}' +
            '.ticket-channel{display:inline-block;padding:1mm 2.5mm;border-radius:2mm;font-weight:900}' +
            '.ticket-channel--glovo{background:#facc15;color:#1c1917}' +
            '.ticket-channel--uber{background:#111827;color:#fff}' +
            '.ticket-channel--bolt_food{background:#16a34a;color:#fff}' +
            '.ticket-channel--delivery{background:#2563eb;color:#fff}' +
            '.ticket-channel--takeaway{background:#ea580c;color:#fff}' +
            '.ticket-channel--dine_in{background:#7c3aed;color:#fff}' +
            '.ticket-channel--pos{background:#334155;color:#fff}' +
            '.ticket-qr{text-align:center;margin:' + gap + ' 0}' +
            '.ticket-qr img{width:28mm;height:28mm;filter:' + tune.imgFilter + '}' +
            '.ticket-qr.qr-small img{width:18mm;height:18mm}' +
            '.ticket-qr.qr-medium img{width:28mm;height:28mm}' +
            '.ticket-qr.qr-large img{width:38mm;height:38mm}' +
            '.ticket-barcode{text-align:center;margin:' + gap + ' 0}' +
            '.ticket-barcode p{margin:1mm 0 0;font-size:11px}' +
            '.meta p,.brand,.title{overflow-wrap:anywhere;word-break:break-word}' +
            '.ticket-block.is-normal,.ticket-block.is-normal *{font-weight:500}' +
            '.ticket-block.is-bold,.ticket-block.is-bold *{font-weight:900}' +
            '.ticket-block.is-left{text-align:left}' +
            '.ticket-block.is-left .brand,.ticket-block.is-left .title,.ticket-block.is-left .meta,.ticket-block.is-left .meta p,.ticket-block.is-left .thanks,.ticket-block.is-left .footer-block,.ticket-block.is-left .promo,.ticket-block.is-left .order-type,.ticket-block.is-left .order-type__label,.ticket-block.is-left .order-type__value,.ticket-block.is-left .order-type__channel,.ticket-block.is-left .ticket-qr,.ticket-block.is-left .ticket-barcode{text-align:left}' +
            '.ticket-block.is-left .logo{margin-left:0;margin-right:auto}' +
            '.ticket-block.is-center{text-align:center}' +
            '.ticket-block.is-center .brand,.ticket-block.is-center .title,.ticket-block.is-center .meta,.ticket-block.is-center .meta p,.ticket-block.is-center .thanks,.ticket-block.is-center .footer-block,.ticket-block.is-center .promo,.ticket-block.is-center .order-type,.ticket-block.is-center .order-type__label,.ticket-block.is-center .order-type__value,.ticket-block.is-center .order-type__channel,.ticket-block.is-center .ticket-qr,.ticket-block.is-center .ticket-barcode{text-align:center}' +
            '.ticket-block.is-center .logo{margin-left:auto;margin-right:auto}' +
            '.ticket-block.is-left .row{display:flex;justify-content:space-between;text-align:left}' +
            '.ticket-block.is-center .row{display:flex;justify-content:center;gap:3mm;text-align:center}' +
            '.ticket-block.is-center .row span{display:inline}' +
            '.ticket-block.is-right{text-align:right}' +
            '.ticket-block.is-right .brand,.ticket-block.is-right .title,.ticket-block.is-right .meta,.ticket-block.is-right .meta p,.ticket-block.is-right .thanks,.ticket-block.is-right .footer-block,.ticket-block.is-right .promo,.ticket-block.is-right .order-type,.ticket-block.is-right .order-type__label,.ticket-block.is-right .order-type__value,.ticket-block.is-right .order-type__channel,.ticket-block.is-right .ticket-qr,.ticket-block.is-right .ticket-barcode{text-align:right}' +
            '.ticket-block.is-right .logo{margin-left:auto;margin-right:0}' +
            '.ticket-block.is-right .row{display:flex;justify-content:flex-end;gap:3mm;text-align:right}' +
            '.ticket-block.is-right .row span{display:inline}' +
            '.ticket-block table{width:100%;text-align:left}' +
            '.ticket-block .item,.ticket-block table td{overflow-wrap:anywhere;word-break:break-word}' +
            '.ticket-block.is-left td.qty,.ticket-block.is-center td.qty,.ticket-block.is-right td.qty{text-align:left;white-space:nowrap}' +
            '.ticket-block.is-left td.price,.ticket-block.is-center td.price,.ticket-block.is-right td.price{text-align:right;white-space:nowrap}' +
            '.ticket-block.fs-small,.ticket-block.fs-small .meta,.ticket-block.fs-small .meta p,.ticket-block.fs-small .row,.ticket-block.fs-small .thanks,.ticket-block.fs-small .footer-block,.ticket-block.fs-small .order-type__label,.ticket-block.fs-small .order-type__channel{font-size:11px}' +
            '.ticket-block.fs-small .brand{font-size:16px}' +
            '.ticket-block.fs-small .title,.ticket-block.fs-small .order-type__value{font-size:14px}' +
            '.ticket-block.fs-small .item,.ticket-block.fs-small table{font-size:12px}' +
            '.ticket-block.fs-large,.ticket-block.fs-large .meta,.ticket-block.fs-large .meta p,.ticket-block.fs-large .row,.ticket-block.fs-large .thanks,.ticket-block.fs-large .footer-block,.ticket-block.fs-large .order-type__label,.ticket-block.fs-large .order-type__channel{font-size:15px}' +
            '.ticket-block.fs-large .brand{font-size:22px}' +
            '.ticket-block.fs-large .title,.ticket-block.fs-large .order-type__value{font-size:18px}' +
            '.ticket-block.fs-large .item,.ticket-block.fs-large table{font-size:16px}' +
            '.ticket-block.fs-extra_large,.ticket-block.fs-extra_large .meta,.ticket-block.fs-extra_large .meta p,.ticket-block.fs-extra_large .row,.ticket-block.fs-extra_large .thanks,.ticket-block.fs-extra_large .footer-block,.ticket-block.fs-extra_large .order-type__label,.ticket-block.fs-extra_large .order-type__channel{font-size:18px}' +
            '.ticket-block.fs-extra_large .brand{font-size:26px}' +
            '.ticket-block.fs-extra_large .title,.ticket-block.fs-extra_large .order-type__value{font-size:22px}' +
            '.ticket-block.fs-extra_large .item,.ticket-block.fs-extra_large table{font-size:18px}' +
            '.ticket-block.mt-extra{margin-top:4mm}' +
            '.ticket-block.mb-extra{margin-bottom:4mm}' +
            '.ticket.is-dark,.ticket.is-extra_dark{-webkit-font-smoothing:none}' +
            '.ticket.is-extra_dark .ticket-channel{box-shadow:inset 0 0 0 1px #000}' +
            '.ticket.save-maximum .item{margin:1mm 0 0}' +
            '.ticket.save-maximum .order-type{margin:1mm 0}' +
            '@media print{html,body{width:' + paper + ';margin:0;padding:0}}' +
            'html,body{-webkit-print-color-adjust:exact;print-color-adjust:exact}';
    }

    function channelBadgeHtml(job) {
        var key = channelKey(job);
        var label = channelLabel(job).toUpperCase();
        var cls = CHANNEL_LABELS[key] ? key : 'pos';
        return '<span class="ticket-channel ticket-channel--' + escapeAttr(cls) + '">' + escapeHtml(label) + '</span>';
    }

    function logoSrc(template) {
        var logo = template.logo || {};
        if (logo.optimize_thermal && (template.logo_thermal_url || template.print_logo_url)) {
            return template.logo_thermal_url || template.print_logo_url;
        }
        return template.print_logo_url || template.logo_url || '';
    }

    function logoHtml(kind, template) {
        var size = (template.logo && template.logo.size) || 'medium';
        if (size === 'hide') return '';
        if (!show(kind, template, 'header', 'logo')) return '';
        var src = logoSrc(template);
        if (!src) return '';
        return '<img class="logo logo-' + escapeAttr(size) + '" src="' + escapeAttr(src) + '" alt="">';
    }

    function branchNameHtml(kind, template, job, ctx) {
        if (!show(kind, template, 'header', 'branch_name')) return '';
        return '<p class="brand">' + escapeHtml(job.branch || ctx.branch_name || ctx.restaurant_name || 'MUNCH') + '</p>';
    }

    function receiptTitleHtml(kind, template, ctx) {
        if (!show(kind, template, 'header', 'receipt_title')) return '';
        var title = (template.texts && template.texts.receipt_title) || (kind === 'kitchen' ? 'Kitchen Order' : 'Receipt');
        return '<p class="title">' + escapeHtml(applyPlaceholders(title, ctx)) + '</p>';
    }

    function branchDetailsHtml(kind, template, job, ctx) {
        var html = '';
        if (show(kind, template, 'header', 'branch_address') && (job.branch_address || ctx.address)) {
            html += '<p>' + escapeHtml(job.branch_address || ctx.address) + '</p>';
        }
        if (show(kind, template, 'header', 'branch_phone') && (job.branch_phone || ctx.phone)) {
            html += '<p>' + escapeHtml(job.branch_phone || ctx.phone) + '</p>';
        }
        if (show(kind, template, 'header', 'tax_pin')) {
            var pin = (template.texts && template.texts.tax_pin) || ctx.tax_pin;
            if (pin) html += '<p>PIN ' + escapeHtml(applyPlaceholders(pin, ctx)) + '</p>';
        }
        return html ? '<div class="meta">' + html + '</div>' : '';
    }

    function orderTypeBannerHtml(kind, template, job) {
        if (!show(kind, template, 'order', 'order_type') && !show(kind, template, 'order', 'sales_channel')) return '';
        var html = '<div class="order-type">';
        if (show(kind, template, 'order', 'order_type')) {
            html += '<p class="order-type__label">ORDER TYPE</p>' +
                '<p class="order-type__value">' + escapeHtml(String(job.orderType || channelLabel(job)).toUpperCase()) + '</p>';
        }
        if (show(kind, template, 'order', 'sales_channel')) {
            html += '<p class="order-type__channel">' + channelBadgeHtml(job) + '</p>';
        }
        html += '</div>';
        return html;
    }

    function metaLine(label, value) {
        if (value == null || String(value).trim() === '') return '';
        return '<p>' + escapeHtml(label) + '</p><p>' + escapeHtml(value) + '</p>';
    }

    function orderNumberHtml(kind, template, job) {
        var bits = '';
        if (show(kind, template, 'order', 'order_number')) {
            var number = String(job.number || '').replace(/^#/, '');
            bits += '<p>ORDER #' + escapeHtml(number) + '</p>';
        }
        if (show(kind, template, 'order', 'date') && job.date) bits += '<p>Date ' + escapeHtml(job.date) + '</p>';
        if (show(kind, template, 'order', 'time') && job.time) bits += '<p>Time ' + escapeHtml(job.time) + '</p>';
        if (show(kind, template, 'order', 'cashier') && job.cashier) bits += metaLine('Cashier', job.cashier);
        return bits ? '<div class="meta">' + bits + '</div>' : '';
    }

    function isPosDeliveryJob(job) {
        var channel = channelKey(job);
        if (channel === 'delivery') return true;
        if (channel) return false;
        return !!(job && job.isDelivery);
    }

    function customerHtml(kind, template, job) {
        var bits = '';
        var name = String((job && job.customer) || '').trim();
        if (isPosDeliveryJob(job) && name.toLowerCase() === 'walk-in') name = '';
        var phone = String((job && job.phone) || '').trim();
        var address = String((job && job.address) || '').trim();
        var riderName = String((job && (job.riderName || job.rider_name)) || '').trim();
        var riderPhone = String((job && (job.riderPhone || job.rider_phone)) || '').trim();

        if (kind === 'kitchen') {
            if (show(kind, template, 'order', 'customer_name') && name) bits += metaLine('Customer', name);
            if (show(kind, template, 'order', 'customer_phone') && phone) bits += metaLine('Phone', phone);
            if (show(kind, template, 'order', 'delivery_address') && address) bits += metaLine('Address', address);
            if (show(kind, template, 'order', 'rider_name') && riderName) bits += metaLine('Rider Name', riderName);
            if (show(kind, template, 'order', 'rider_phone') && riderPhone) bits += metaLine('Rider Phone', riderPhone);
            return bits ? '<div class="meta">' + bits + '</div>' : '';
        }

        if (isPosDeliveryJob(job)) return '';

        if (show(kind, template, 'order', 'customer_name') && name) bits += metaLine('Customer', name);
        if (show(kind, template, 'order', 'customer_phone') && phone) bits += metaLine('Phone', phone);
        if (show(kind, template, 'order', 'rider_name') && riderName) bits += metaLine('Rider Name', riderName);
        if (show(kind, template, 'order', 'rider_phone') && riderPhone) bits += metaLine('Rider Phone', riderPhone);
        return bits ? '<div class="meta">' + bits + '</div>' : '';
    }

    function deliveryCustomerHtml(kind, template, job, currency) {
        if (kind === 'kitchen' || !isPosDeliveryJob(job)) return '';
        var name = String((job && job.customer) || '').trim();
        if (name.toLowerCase() === 'walk-in') name = '';
        var phone = String((job && job.phone) || '').trim();
        var address = String((job && job.address) || '').trim();
        var bits = '';
        if (show(kind, template, 'delivery_customer', 'customer_name') && name) bits += metaLine('CUSTOMER', name);
        if (show(kind, template, 'delivery_customer', 'customer_phone') && phone) bits += metaLine('PHONE', phone);
        if (show(kind, template, 'delivery_customer', 'delivery_address') && address) bits += metaLine('ADDRESS', address);
        if (show(kind, template, 'delivery_customer', 'delivery_fee') && job.delivery_fee != null && job.delivery_fee !== '') {
            bits += metaLine('DELIVERY FEE', money(job.delivery_fee, currency));
        }
        var riderName = String((job && (job.riderName || job.rider_name)) || '').trim();
        var riderPhone = String((job && (job.riderPhone || job.rider_phone)) || '').trim();
        if (show(kind, template, 'order', 'rider_name') && riderName) bits += metaLine('Rider Name', riderName);
        if (show(kind, template, 'order', 'rider_phone') && riderPhone) bits += metaLine('Rider Phone', riderPhone);
        return bits ? '<div class="meta">' + bits + '</div>' : '';
    }

    function itemOptions(item) {
        if (!item || typeof item !== 'object') return [];
        var opts = item.options;
        return Array.isArray(opts) ? opts : [];
    }

    function itemsHtml(kind, template, job, currency) {
        var items = Array.isArray(job.items) ? job.items : [];
        if (!items.length) return '';
        var showName = show(kind, template, 'items', 'product_name');
        var showQty = show(kind, template, 'items', 'quantity');
        var showVars = show(kind, template, 'items', 'variations') || show(kind, template, 'items', 'modifiers');
        var showNotes = show(kind, template, 'items', 'notes') || show(kind, template, 'items', 'special_instructions');
        var showUnit = kind !== 'kitchen' && show(kind, template, 'items', 'unit_price');
        var showTotal = kind !== 'kitchen' && show(kind, template, 'items', 'line_total');
        var html = '<div class="meta"><p>Items</p></div>';
        if (kind === 'kitchen' || (!showUnit && !showTotal)) {
            items.forEach(function (item) {
                if (!item || typeof item !== 'object') return;
                var name = showName ? item.name : '';
                var qty = showQty ? (item.quantity + ' x ') : '';
                html += '<p class="item">' + escapeHtml(qty + name) + '</p>';
                if (showVars) {
                    itemOptions(item).forEach(function (opt) {
                        html += '<p class="opt">- ' + escapeHtml(opt) + '</p>';
                    });
                }
                if (showNotes && item.notes) html += '<p class="opt">' + escapeHtml(item.notes) + '</p>';
            });
        } else {
            html += '<table>';
            items.forEach(function (item) {
                if (!item || typeof item !== 'object') return;
                html += '<tr>';
                html += '<td class="qty">' + (showQty ? escapeHtml(item.quantity) : '') + '</td>';
                html += '<td>' + (showName ? escapeHtml(item.name) : '');
                if (showUnit) html += '<div class="opt">' + escapeHtml(money(item.unit_price, currency)) + '</div>';
                html += '</td>';
                html += '<td class="price">' + (showTotal ? escapeHtml(money(item.line_total, currency)) : '') + '</td>';
                html += '</tr>';
                if (showVars) {
                    itemOptions(item).forEach(function (opt) {
                        html += '<tr><td></td><td class="opt">- ' + escapeHtml(opt) + '</td><td></td></tr>';
                    });
                }
                if (showNotes && item.notes) {
                    html += '<tr><td></td><td class="opt">' + escapeHtml(item.notes) + '</td><td></td></tr>';
                }
            });
            html += '</table>';
        }
        if (kind === 'kitchen' && job.notes && (show(kind, template, 'items', 'notes') || show(kind, template, 'items', 'special_instructions'))) {
            html += '<div class="meta"><p>Notes</p><p>' + escapeHtml(job.notes) + '</p></div>';
        }
        if (kind !== 'kitchen' && job.notes && show(kind, template, 'items', 'notes') && job.isDelivery) {
            html += '<div class="meta"><p>Delivery Notes</p><p>' + escapeHtml(job.notes) + '</p></div>';
        }
        return html;
    }

    function summaryHtml(kind, template, job, currency) {
        if (kind === 'kitchen') return '';
        var html = '';
        if (show(kind, template, 'summary', 'subtotal')) {
            html += '<div class="row"><span>Subtotal</span><span>' + escapeHtml(money(job.subtotal, currency)) + '</span></div>';
        }
        var groupedDeliveryFee = isPosDeliveryJob(job) && show(kind, template, 'delivery_customer', 'delivery_fee');
        if (show(kind, template, 'summary', 'delivery_fee') && (job.isDelivery || isPosDeliveryJob(job)) && !groupedDeliveryFee) {
            html += '<div class="row"><span>Delivery Fee</span><span>' + escapeHtml(money(job.delivery_fee, currency)) + '</span></div>';
        }
        if (show(kind, template, 'summary', 'discount') && Number(job.discount) > 0) {
            html += '<div class="row"><span>Discount</span><span>−' + escapeHtml(money(job.discount, currency)) + '</span></div>';
        }
        if (show(kind, template, 'summary', 'tax') && Number(job.tax) > 0) {
            html += '<div class="row"><span>Tax</span><span>' + escapeHtml(money(job.tax, currency)) + '</span></div>';
        }
        if (show(kind, template, 'summary', 'total')) {
            html += '<div class="row is-grand"><span>Grand Total</span><span>' + escapeHtml(money(job.grand_total, currency)) + '</span></div>';
        }
        if (show(kind, template, 'summary', 'paid_amount')) {
            var paid = job.paid_amount != null && job.paid_amount !== '' ? job.paid_amount : (job.cash_received != null && job.cash_received !== '' ? job.cash_received : job.grand_total);
            if (paid != null && paid !== '') {
                html += '<div class="row"><span>Paid Amount</span><span>' + escapeHtml(money(paid, currency)) + '</span></div>';
            }
        }
        if (show(kind, template, 'summary', 'change') && Number(job.change) > 0) {
            html += '<div class="row"><span>Change</span><span>' + escapeHtml(money(job.change, currency)) + '</span></div>';
        }
        return html;
    }

    function isImmediatePosPayment(method) {
        return method === 'cash' || method === 'card' || method === 'mpesa';
    }

    function paymentStatusLabel(job) {
        var status = String((job && job.payment_status) || '').toLowerCase();
        var method = String((job && job.payment_method) || '').toLowerCase();
        if (status === 'unpaid' || status === 'pending' || status.indexOf('due') !== -1 || status.indexOf('remaining') !== -1) {
            return '';
        }
        if (isImmediatePosPayment(method) || status === 'paid' || MARKETPLACE[method] || MARKETPLACE[channelKey(job)]) {
            return 'PAID';
        }
        if (!status) return '';
        return String(job.payment_status).toUpperCase();
    }

    function paymentHtml(kind, template, job, currency) {
        if (kind === 'kitchen') return '';
        var method = String(job.payment_method || '');
        var html = '';
        if (show(kind, template, 'payment', 'payment_method')) {
            html += '<div class="row"><span>Payment Method</span><span>' + escapeHtml(paymentLabel(method)) + '</span></div>';
        }
        if (show(kind, template, 'payment', 'payment_status')) {
            var statusLabel = paymentStatusLabel(job);
            if (statusLabel) {
                html += '<div class="row"><span>Payment Status</span><span>' + escapeHtml(statusLabel) + '</span></div>';
            }
        }
        return html;
    }

    function isMarketplaceJob(job) {
        var channel = channelKey(job);
        var method = String((job && job.payment_method) || '').toLowerCase();
        return !!(MARKETPLACE[channel] || MARKETPLACE[method]);
    }

    function mpesaTillHtml(kind, template, job) {
        if (kind === 'kitchen') return '';
        if (isMarketplaceJob(job)) return '';
        var till = String((job && job.mpesa_till) || '').trim();
        if (!till) return '';
        return '<div class="meta"><p>M-PESA Till</p><p>' + escapeHtml(till) + '</p></div>';
    }

    function qrSizeClass(template) {
        var size = (template.qr && template.qr.size) || 'medium';
        if (size === 'small' || size === 'large') return size;
        return 'medium';
    }

    function qrHtml(kind, template) {
        if (kind === 'kitchen' || !show(kind, template, 'footer', 'qr_code')) return '';
        if (template.qr_data_uri) {
            return '<div class="ticket-qr qr-' + escapeAttr(qrSizeClass(template)) + '"><img src="' + escapeAttr(template.qr_data_uri) + '" alt="QR"></div>';
        }
        if (template.qr_payload) {
            return '<div class="footer-block">' + escapeHtml(template.qr_payload) + '</div>';
        }
        return '';
    }

    function promotionHtml(kind, template, ctx) {
        var texts = template.texts || {};
        if (kind === 'kitchen' || !show(kind, template, 'marketing', 'promotion_banner') || !texts.promotion_banner) return '';
        return '<div class="promo">' + nl2br(applyPlaceholders(texts.promotion_banner, ctx)) + '</div>';
    }

    function barcodeBlockHtml(kind, template, job) {
        if (kind === 'kitchen' || !show(kind, template, 'footer', 'barcode')) return '';
        return barcodeHtml(job.number || '');
    }

    function footerHtml(kind, template, ctx) {
        var html = '';
        var texts = template.texts || {};
        if (kind !== 'kitchen' && show(kind, template, 'footer', 'thank_you_message') && texts.thank_you_message) {
            html += '<p class="thanks">' + escapeHtml(applyPlaceholders(texts.thank_you_message, ctx)) + '</p>';
        }
        if (show(kind, template, 'footer', 'footer_text') && texts.footer_text) {
            html += '<div class="footer-block">' + nl2br(applyPlaceholders(texts.footer_text, ctx)) + '</div>';
        }
        if (kind !== 'kitchen' && show(kind, template, 'footer', 'return_policy') && texts.return_policy) {
            html += '<div class="footer-block">' + nl2br(applyPlaceholders(texts.return_policy, ctx)) + '</div>';
        }
        if (kind !== 'kitchen' && show(kind, template, 'footer', 'social_media') && ctx.instagram) {
            html += '<div class="footer-block">' + escapeHtml(ctx.instagram) + '</div>';
        }
        return html;
    }

    function bodyHtml(kind, template, job, options) {
        template = normalizeTemplate(kind, template);
        job = job || {};
        options = options || {};
        var ctx = Object.assign({}, options.context || {}, {
            branch_name: job.branch || (options.context && options.context.branch_name) || '',
            phone: job.branch_phone || (options.context && options.context.phone) || ''
        });
        var currency = options.currency || '';
        var blocks = {
            logo: function () { return logoHtml(kind, template); },
            branch_name: function () { return branchNameHtml(kind, template, job, ctx); },
            receipt_title: function () { return receiptTitleHtml(kind, template, ctx); },
            branch_details: function () { return branchDetailsHtml(kind, template, job, ctx); },
            order_type: function () { return orderTypeBannerHtml(kind, template, job); },
            order_number: function () { return orderNumberHtml(kind, template, job); },
            customer: function () { return customerHtml(kind, template, job); },
            delivery_customer: function () { return deliveryCustomerHtml(kind, template, job, currency); },
            items: function () { return itemsHtml(kind, template, job, currency); },
            totals: function () { return summaryHtml(kind, template, job, currency); },
            payment: function () { return paymentHtml(kind, template, job, currency); },
            mpesa_till: function () { return mpesaTillHtml(kind, template, job); },
            promotion: function () { return promotionHtml(kind, template, ctx); },
            qr_code: function () { return qrHtml(kind, template); },
            barcode: function () { return barcodeBlockHtml(kind, template, job); },
            footer: function () { return footerHtml(kind, template, ctx); }
        };
        var html = '';
        var order = Array.isArray(template.order) ? template.order : [];
        order.forEach(function (id) {
            var render = blocks[id];
            if (!render) return;
            try {
                html += wrapBlock(id, render(), template);
            } catch (err) {
                if (typeof console !== 'undefined' && console.error) console.error(err);
            }
        });
        return html;
    }

    function renderDocument(kind, template, job, options) {
        try {
            options = options || {};
            template = normalizeTemplate(kind, template);
            var print = options.print || { paper: '80mm' };
            var tune = printTune(print);
            var title = kind === 'kitchen' ? 'Kitchen Order' : 'Receipt';
            var cls = [kind];
            if (tune.mode === 'dark') cls.push('is-dark');
            if (tune.mode === 'extra_dark') cls.push('is-extra_dark');
            if (tune.save === 'normal') cls.push('save-normal');
            if (tune.save === 'maximum') cls.push('save-maximum');
            return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' + escapeHtml(title) +
                '</title><style>' + ticketCss(kind, template, print) + '</style></head><body class="' + escapeAttr(kind) +
                '" data-auto-cut="' + (print.auto_cut ? '1' : '0') + '" data-drawer-kick="' + (print.drawer_kick ? '1' : '0') +
                '" data-print-mode="' + escapeAttr(tune.mode) + '" data-paper-saving="' + escapeAttr(tune.save) + '">' +
                '<div class="ticket ' + cls.join(' ') + '">' + bodyHtml(kind, template, job, options) + '</div></body></html>';
        } catch (err) {
            if (typeof console !== 'undefined' && console.error) console.error(err);
            return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Receipt</title></head><body style="font-family:sans-serif;padding:8px;color:#b91c1c">' +
                '<p>Receipt failed to render.</p></body></html>';
        }
    }

    function copies(kind, print) {
        print = print || {};
        var n = kind === 'kitchen' ? Number(print.kitchen_copies || 1) : Number(print.receipt_copies || 1);
        if (!isFinite(n) || n < 1) return 1;
        return Math.min(5, Math.floor(n));
    }

    root.MunchReceiptTicket = {
        VERSION: 1,
        CHANNEL_LABELS: CHANNEL_LABELS,
        CUSTOMER_BLOCKS: CUSTOMER_BLOCKS,
        KITCHEN_BLOCKS: KITCHEN_BLOCKS,
        renderDocument: renderDocument,
        renderBody: bodyHtml,
        css: ticketCss,
        copies: copies,
        applyPlaceholders: applyPlaceholders,
        channelBadgeHtml: channelBadgeHtml,
        defaults: defaults,
        normalizeTemplate: normalizeTemplate,
        wrapBlock: wrapBlock
    };
})(typeof window !== 'undefined' ? window : this);
