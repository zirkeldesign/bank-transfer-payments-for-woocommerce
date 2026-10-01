<?php

declare(strict_types=1);

namespace ZirkelDesign\BankTransfersForWooCommerce\Payment;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Builds an EPC069-12 ("GiroCode") QR payload for a SEPA credit transfer, so a
 * DACH customer can scan it in their banking app to pre-fill the transfer.
 *
 * EUR / SEPA only. The payload is a fixed set of newline-separated fields; a
 * malformed one is rejected by banking apps, so the format here is exact.
 */
final class GiroCode
{
    private const SERVICE_TAG = 'BCD';

    private const VERSION = '002';

    private const CHARSET_UTF8 = '1';

    private const IDENTIFICATION = 'SCT';

    /**
     * Build the GiroCode payload, or null when the data is unusable.
     *
     * @param  string  $name  Beneficiary (IBAN account holder), max 70 chars.
     * @param  string  $iban  Beneficiary IBAN.
     * @param  string  $bic  Beneficiary BIC (may be empty within the EU).
     * @param  float  $amount  Amount in EUR (0.01–999999999.99).
     * @param  string  $reference  Remittance info / payment reference, max 140 chars.
     */
    public static function payload(string $name, string $iban, string $bic, float $amount, string $reference): ?string
    {
        $name = trim($name);
        $iban = strtoupper((string) preg_replace('/\s+/', '', $iban));
        $bic = strtoupper(trim($bic));

        if ($name === '' || $iban === '' || $amount < 0.01 || $amount > 999999999.99) {
            return null;
        }

        $lines = [
            self::SERVICE_TAG,
            self::VERSION,
            self::CHARSET_UTF8,
            self::IDENTIFICATION,
            $bic,
            self::truncate($name, 70),
            $iban,
            'EUR'.number_format($amount, 2, '.', ''),
            '',                                    // Purpose (AT-44), optional.
            '',                                    // Structured remittance (AT-05); unused.
            self::truncate($reference, 140),       // Unstructured remittance (AT-05).
        ];

        return implode("\n", $lines);
    }

    private static function truncate(string $value, int $max): string
    {
        $value = trim($value);

        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }
}
