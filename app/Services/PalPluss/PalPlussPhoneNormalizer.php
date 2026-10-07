<?php

namespace App\Services\PalPluss;

/**
 * Normalize Kenyan MSISDNs for PalPluss STK (254XXXXXXXXX).
 *
 * PalPluss also accepts 07… / 01… / +254… and normalizes server-side;
 * we still normalize before persist/API for consistent storage.
 */
final class PalPlussPhoneNormalizer
{
    public function normalize(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '254') && strlen($digits) === 12) {
            return $digits;
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '254'.substr($digits, 1);
        }

        if ((str_starts_with($digits, '7') || str_starts_with($digits, '1')) && strlen($digits) === 9) {
            return '254'.$digits;
        }

        return null;
    }
}
