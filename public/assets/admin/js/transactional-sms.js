(function () {
    'use strict';

    var PREVIEW_SAMPLES = {
        customer_otp: {
            OTP: '482916',
        },
        login_otp: {
            OTP: '482916',
        },
        default: {
            order_id: '12345',
            title: 'New order',
            description: '1x Beef Burger',
            customer_name: 'Jane Doe',
            order_amount: '2,450.00',
            branch_name: 'Munch Westlands',
            branch_phone: '0712345678',
            order_status: 'Pending',
            OTP: '482916',
            order_number: '12345',
            total_amount: 'Ksh 2,450.00',
            cancellation_reason: 'Customer changed mind',
        },
    };

    function activeTextarea() {
        var focused = document.querySelector('.js-tx-sms-template-body:focus');
        if (focused) {
            return focused;
        }
        return document.querySelector('.js-tx-sms-template-body');
    }

    function insertAtCursor(textarea, text) {
        if (!textarea) {
            return;
        }
        var start = textarea.selectionStart;
        var end = textarea.selectionEnd;
        var value = textarea.value;
        textarea.value = value.substring(0, start) + text + value.substring(end);
        textarea.selectionStart = textarea.selectionEnd = start + text.length;
        textarea.focus();
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function previewSampleFor(textarea) {
        var card = textarea ? textarea.closest('.js-tx-sms-template-card') : null;
        var type = card ? card.getAttribute('data-template-type') : null;
        if (type && PREVIEW_SAMPLES[type]) {
            return PREVIEW_SAMPLES[type];
        }
        return PREVIEW_SAMPLES.default;
    }

    function interpolatePreview(body, sample) {
        var message = body;
        Object.keys(sample).forEach(function (key) {
            message = message.split('{{' + key + '}}').join(sample[key]);
            message = message.split('{' + key + '}').join(sample[key]);
            message = message.split('#' + key.toUpperCase() + '#').join(sample[key]);
        });
        message = message.replace(/\{\{[a-z0-9_]+\}\}/gi, '');
        message = message.replace(/\{[a-z0-9_]+\}/gi, '');
        message = message.replace(/#[A-Z0-9_]+#/g, '');
        return message.trim();
    }

    function updateCounter(textarea) {
        var card = textarea.closest('.js-tx-sms-template-card');
        if (!card) {
            return;
        }
        var counter = card.querySelector('.js-tx-sms-char-counter');
        if (counter) {
            counter.textContent = textarea.value.length + ' chars';
        }
    }

    function updatePreview(textarea) {
        var preview = document.querySelector('.js-tx-sms-preview-text');
        if (!preview || !textarea) {
            return;
        }
        preview.textContent = interpolatePreview(textarea.value, previewSampleFor(textarea)) || '—';
    }

    document.querySelectorAll('.js-tx-sms-var-chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
            var token = chip.getAttribute('data-insert') || ('{{' + chip.getAttribute('data-var') + '}}');
            insertAtCursor(activeTextarea(), token);
        });
    });

    document.querySelectorAll('.js-tx-sms-template-body').forEach(function (textarea) {
        updateCounter(textarea);
        textarea.addEventListener('input', function () {
            updateCounter(textarea);
            updatePreview(textarea);
        });
        textarea.addEventListener('focus', function () {
            updatePreview(textarea);
        });
    });
})();
