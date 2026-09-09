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
     * Public Key From Paystack Dashboard
     *
     */
    'publicKey' => getenv('PAYSTACK_PUBLIC_KEY','pk_test_984c15fc89e28647c149e49654c69475ef565eaf'),

    /**
     * Secret Key From Paystack Dashboard
     *
     */
    'secretKey' => getenv('PAYSTACK_SECRET_KEY','sk_test_77556985d455a0fd326da6662273ad1c3eb8f097'),

    /**
     * Paystack Payment URL
     *
     */
    'paymentUrl' => getenv('PAYSTACK_PAYMENT_URL',"https://api.paystack.co"),

    /**
     * Optional email address of the merchant
     *
     */
    'merchantEmail' => getenv('MERCHANT_EMAIL','showrov2185@gmail.com'),

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
