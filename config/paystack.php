<?php

/*
 * This file is part of the Laravel Paystack package.
 *
 * (c) Prosper Otemuyiwa <prosperotemuyiwa@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

return [

    /**
     * Cached .env snapshots only. Runtime resolution is PaystackConfigResolver:
     * non-empty env overrides admin payment settings; empty values are ignored.
     */
    'publicKey' => env('PAYSTACK_PUBLIC_KEY'),
    'secretKey' => env('PAYSTACK_SECRET_KEY'),
    'paymentUrl' => env('PAYSTACK_PAYMENT_URL', 'https://api.paystack.co'),
    'merchantEmail' => env('MERCHANT_EMAIL'),
    'public_key' => env('PAYSTACK_PUBLIC_KEY'),
    'secret_key' => env('PAYSTACK_SECRET_KEY'),
    'payment_url' => env('PAYSTACK_PAYMENT_URL', 'https://api.paystack.co'),
    'merchant_email' => env('MERCHANT_EMAIL'),

    'recovery_min_age_minutes' => (int) env('PAYSTACK_RECOVERY_MIN_AGE_MINUTES', 2),
    'recovery_batch_limit' => (int) env('PAYSTACK_RECOVERY_BATCH_LIMIT', 25),
    'reconcile_min_age_minutes' => (int) env('PAYSTACK_RECONCILE_MIN_AGE_MINUTES', 5),
    'reconcile_batch_limit' => (int) env('PAYSTACK_RECONCILE_BATCH_LIMIT', 50),
    'reconcile_newest_first' => filter_var(
        env('PAYSTACK_RECONCILE_NEWEST_FIRST', true),
        FILTER_VALIDATE_BOOL
    ),
    'reconcile_terminal_not_paid_statuses' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'PAYSTACK_RECONCILE_TERMINAL_NOT_PAID_STATUSES',
            'abandoned,failed,reversed,deferred_abandoned'
        ))
    ))),
    'reconcile_in_progress_statuses' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'PAYSTACK_RECONCILE_IN_PROGRESS_STATUSES',
            'ongoing,processing,pending'
        ))
    ))),
    'reconcile_in_progress_cooldown_minutes' => (int) env('PAYSTACK_RECONCILE_IN_PROGRESS_COOLDOWN_MINUTES', 60),
    'reconcile_verify_failed_cooldown_minutes' => (int) env('PAYSTACK_RECONCILE_VERIFY_FAILED_COOLDOWN_MINUTES', 15),
    'webhook_path' => env('PAYSTACK_WEBHOOK_PATH', '/api/v1/paystack/webhook'),

];
