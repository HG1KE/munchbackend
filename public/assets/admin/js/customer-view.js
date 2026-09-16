"use strict";

function parseWalletAdjustAmount(value) {
    if (value === null || value === undefined) {
        return NaN;
    }
    var text = String(value).trim();
    if (text === '' || !/^\d+(\.\d{1,3})?$/.test(text)) {
        return NaN;
    }
    return Number(text);
}

function isValidWalletAdjustAmount(value) {
    var amount = parseWalletAdjustAmount(value);
    return !isNaN(amount) && amount > 0;
}

function resultingWalletAdjustBalance(currentBalance, type, amount) {
    var current = Number(currentBalance);
    var parsedAmount = parseWalletAdjustAmount(amount);
    if (isNaN(current) || isNaN(parsedAmount)) {
        return NaN;
    }
    if (type === 'debit') {
        return Math.round((current - parsedAmount) * 1000) / 1000;
    }
    return Math.round((current + parsedAmount) * 1000) / 1000;
}

function debitExceedsWalletBalance(currentBalance, amount) {
    var resulting = resultingWalletAdjustBalance(currentBalance, 'debit', amount);
    return !isNaN(resulting) && resulting < 0;
}

function newWalletAdjustIdempotencyKey() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (char) {
        var rand = Math.random() * 16 | 0;
        var value = char === 'x' ? rand : (rand & 0x3 | 0x8);
        return value.toString(16);
    });
}

function buildWalletAdjustConfirmation(details) {
    return [
        details.typeLabel + ': ' + details.typeValue,
        details.amountLabel + ': ' + details.amount,
        details.reasonLabel + ': ' + details.reason,
        details.currentLabel + ': ' + details.currentBalance,
        details.resultingLabel + ': ' + details.resultingBalance
    ].join('\n');
}

function walletAdjustHistoryRowHtml(transaction) {
    var type = transaction.transaction_type || '';
    var badgeClass = (type === 'order_place' || type === 'debit_by_admin') ? 'info' : 'success';
    return '<tr>' +
        '<td>' + (transaction.transaction_id || '') + '</td>' +
        '<td><span class="badge badge-soft-' + badgeClass + '">' + type + '</span></td>' +
        '<td>' + (transaction.credit != null ? transaction.credit : '') + '</td>' +
        '<td>' + (transaction.debit != null ? transaction.debit : '') + '</td>' +
        '<td>' + (transaction.balance != null ? transaction.balance : '') + '</td>' +
        '<td>' + (transaction.reference || '') + '</td>' +
        '<td>' + (transaction.created_at || '') + '</td>' +
        '</tr>';
}

$(document).on('ready', function () {

    var datatable = $('#columnSearchDatatable').DataTable({
        "paging": false
    });

    $('#column1_search').on('keyup', function () {
        datatable
            .columns(1)
            .search(this.value)
            .draw();
    });

    $('#column3_search').on('change', function () {
        datatable
            .columns(2)
            .search(this.value)
            .draw();
    });

    var modal = $('#adjust-wallet-modal');
    if (!modal.length) {
        return;
    }

    var form = $('#adjust-wallet-form');
    var submitButton = $('#adjust-wallet-submit');
    var submitting = false;
    var idempotencyKey = newWalletAdjustIdempotencyKey();

    function currentBalance() {
        return Number(modal.attr('data-current-balance') || 0);
    }

    function resetAdjustForm() {
        form.trigger('reset');
        submitting = false;
        submitButton.prop('disabled', false);
        idempotencyKey = newWalletAdjustIdempotencyKey();
    }

    function showToastrErrors(errors) {
        var i;
        for (i = 0; i < errors.length; i++) {
            toastr.error(errors[i].message, {
                CloseButton: true,
                ProgressBar: true
            });
        }
    }

    function prependHistory(transaction) {
        var emptyRow = $('#customer-wallet-history-empty');
        if (emptyRow.length) {
            emptyRow.remove();
        }
        $('#customer-wallet-history-body').prepend(walletAdjustHistoryRowHtml(transaction));
    }

    form.on('submit', function (e) {
        e.preventDefault();
        if (submitting) {
            return;
        }

        var type = $('#adjust-wallet-type').val();
        var amountValue = $('#adjust-wallet-amount').val();
        var reason = $.trim($('#adjust-wallet-reason').val() || '');
        var labels = modal.data();

        if (!isValidWalletAdjustAmount(amountValue)) {
            toastr.error(modal.attr('data-invalid-amount-message'), {
                CloseButton: true,
                ProgressBar: true
            });
            return;
        }

        if (!reason) {
            toastr.error((modal.attr('data-reason-label') || 'Reason') + ' is required', {
                CloseButton: true,
                ProgressBar: true
            });
            return;
        }

        if (type === 'debit' && debitExceedsWalletBalance(currentBalance(), amountValue)) {
            toastr.error(modal.attr('data-exceeds-message'), {
                CloseButton: true,
                ProgressBar: true
            });
            return;
        }

        var resulting = resultingWalletAdjustBalance(currentBalance(), type, amountValue);
        var confirmationText = buildWalletAdjustConfirmation({
            typeLabel: modal.attr('data-type-label'),
            typeValue: type === 'debit' ? modal.attr('data-debit-label') : modal.attr('data-credit-label'),
            amountLabel: modal.attr('data-amount-label'),
            amount: amountValue,
            reasonLabel: modal.attr('data-reason-label'),
            reason: reason,
            currentLabel: modal.attr('data-current-label'),
            currentBalance: currentBalance(),
            resultingLabel: modal.attr('data-resulting-label'),
            resultingBalance: resulting
        });

        Swal.fire({
            title: modal.attr('data-confirm-title'),
            text: confirmationText,
            type: type === 'debit' ? 'warning' : 'info',
            showCancelButton: true,
            cancelButtonColor: 'default',
            confirmButtonColor: '#E7032D',
            cancelButtonText: labels.cancelLabel || 'no',
            confirmButtonText: labels.confirmLabel || 'yes',
            reverseButtons: true
        }).then(function (result) {
            if (!result.value) {
                return;
            }
            if (submitting) {
                return;
            }
            submitting = true;
            submitButton.prop('disabled', true);

            $.ajaxSetup({
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                }
            });

            $.post({
                url: modal.attr('data-adjust-url'),
                data: {
                    type: type,
                    amount: amountValue,
                    reason: reason,
                    idempotency_key: idempotencyKey
                },
                success: function (data) {
                    if (data.errors) {
                        submitting = false;
                        submitButton.prop('disabled', false);
                        showToastrErrors(data.errors);
                        return;
                    }

                    if (data.wallet_balance_formatted) {
                        $('#customer-wallet-balance').text(data.wallet_balance_formatted);
                        $('#adjust-wallet-current-balance-label').text(data.wallet_balance_formatted);
                    }
                    modal.attr('data-current-balance', data.wallet_balance);
                    if (data.transaction) {
                        prependHistory(data.transaction);
                    }
                    toastr.success(data.message || modal.attr('data-success-message'), {
                        CloseButton: true,
                        ProgressBar: true
                    });
                    resetAdjustForm();
                    modal.modal('hide');
                },
                error: function () {
                    submitting = false;
                    submitButton.prop('disabled', false);
                }
            });
        });
    });

    modal.on('hidden.bs.modal', function () {
        if (!submitting) {
            resetAdjustForm();
        }
    });
});
