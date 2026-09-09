<?php

namespace App\Services\Paystack;

use App\Services\PaystackService;

/**
 * Validates Paystack webhook HMAC signatures (x-paystack-signature).
 */
class PaystackWebhookSignatureVerifier
{
    public function __construct(
        private readonly PaystackService $paystack,
    ) {
    }

    public function hasSecretConfigured(): bool
    {
        $secret = $this->paystack->getSecretKey();

        return is_string($secret) && $secret !== '';
    }

    public function isValid(string $rawPayload, ?string $signatureHeader): bool
    {
        if (! $this->hasSecretConfigured() || $signatureHeader === null || $signatureHeader === '') {
            return false;
        }

        $expected = hash_hmac('sha512', $rawPayload, (string) $this->paystack->getSecretKey());

        return hash_equals($expected, $signatureHeader);
    }
}
