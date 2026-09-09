<?php

namespace App\Support;

/**
 * Canonical TextSMS gateway keys after the transactional / promotional split.
 */
class SmsGatewayKeys
{
    public const TRANSACTIONAL = 'textsms_transactional';

    public const PROMOTIONAL = 'textsms_ke_promotional';

    public const TEMPLATES = 'textsms_templates';

    public const TEMPLATES_TYPE = 'sms_templates';

    public const ASSIGNMENT_TRANSACTIONAL = 'transactional';

    public const ASSIGNMENT_PROMOTIONAL = 'promotional';

    public const DEFAULT_SENDSMS_ENDPOINT = 'https://sms.textsms.co.ke/api/services/sendsms/';

    public const DEFAULT_SENDOTP_ENDPOINT = 'https://sms.textsms.co.ke/api/services/sendotp/';

    /** @deprecated Replaced by {@see self::TRANSACTIONAL}. Kept for leftover rows. */
    public const LEGACY_OTP = 'textsms_ke';

    /** @deprecated Replaced by templates + transactional/promotional routing. */
    public const LEGACY_BRANCH = 'textsms_ke_not';

    /** @deprecated Replaced by {@see self::TRANSACTIONAL}. */
    public const LEGACY_CUSTOMER_CONFIRM = 'textsms_ke_customer_confirm';

    /**
     * @return list<string>
     */
    public static function legacyGatewayKeys(): array
    {
        return [
            self::LEGACY_CUSTOMER_CONFIRM,
            self::LEGACY_OTP,
            self::LEGACY_BRANCH,
        ];
    }

    public static function isAssignment(string $value): bool
    {
        return in_array($value, [self::ASSIGNMENT_TRANSACTIONAL, self::ASSIGNMENT_PROMOTIONAL], true);
    }

    public static function keyForAssignment(string $assignment): string
    {
        return $assignment === self::ASSIGNMENT_PROMOTIONAL
            ? self::PROMOTIONAL
            : self::TRANSACTIONAL;
    }
}
