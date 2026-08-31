<?php

declare(strict_types=1);

/**
 * Fails when a shipped locale has an untranslated string.
 *
 * This exists because `translate:pot` once omitted templates/ from its include
 * list, so regenerating silently deleted every email-template translation from
 * both German locales. Nothing caught it: the .po files still parsed, the .mo
 * files still built, and the tests never look at translations. A missing msgstr
 * is the observable symptom of that whole class of bug, so assert on it.
 *
 * Usage: php scripts/check-translations.php
 */
$root = dirname(__DIR__);
$localeDir = $root.'/languages';

/**
 * Header metadata that is intentionally left untranslated: the plugin name is a
 * proper noun, and the URLs and author are the same in every locale.
 */
$allowed = [
    'Bank Transfer Payments for WooCommerce',
    'https://github.com/zirkeldesign/bank-transfer-payments-for-woocommerce',
    'zirkel.design',
    'https://zirkel.design',
];

/**
 * Read a .po file into a list of entries.
 *
 * Handles the three shapes gettext actually emits: singular (msgstr), plural
 * (msgid_plural with msgstr[N]), and context-qualified (msgctxt). Long values
 * are wrapped onto bare "…" continuation lines, which belong to whichever
 * field preceded them.
 *
 * @return list<array{msgid: string, msgstrs: list<string>}>
 */
function btpw_parse_po(string $path): array
{
    $entries = [];
    $msgid = null;
    $msgstrs = [];
    $current = null;

    $flush = static function () use (&$entries, &$msgid, &$msgstrs): void {
        if ($msgid !== null && $msgid !== '') {
            $entries[] = ['msgid' => $msgid, 'msgstrs' => array_values($msgstrs)];
        }

        $msgid = null;
        $msgstrs = [];
    };

    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        // A new msgid (or msgctxt) closes the previous entry.
        if (preg_match('/^msgctxt "(.*)"$/', $line, $m) === 1) {
            $flush();
            $current = 'msgctxt';

            continue;
        }

        if (preg_match('/^msgid "(.*)"$/', $line, $m) === 1) {
            if ($current !== 'msgctxt') {
                $flush();
            }

            $msgid = $m[1];
            $current = 'msgid';

            continue;
        }

        // The plural source string is never translated itself; ignore its value
        // but keep parsing so its continuation lines are not misattributed.
        if (preg_match('/^msgid_plural "(.*)"$/', $line, $m) === 1) {
            $current = 'msgid_plural';

            continue;
        }

        if (preg_match('/^msgstr(?:\[(\d+)\])? "(.*)"$/', $line, $m) === 1) {
            $index = $m[1] === '' ? 0 : (int) $m[1];
            $msgstrs[$index] = $m[2];
            $current = 'msgstr:'.$index;

            continue;
        }

        if (preg_match('/^"(.*)"$/', $line, $m) === 1 && $current !== null) {
            if ($current === 'msgid') {
                $msgid .= $m[1];

                continue;
            }

            if (str_starts_with($current, 'msgstr:')) {
                $index = (int) substr($current, 7);
                $msgstrs[$index] .= $m[1];
            }
        }
    }

    $flush();

    return $entries;
}

$failures = [];
$locales = glob($localeDir.'/*.po') ?: [];

foreach ($locales as $po) {
    foreach (btpw_parse_po($po) as $entry) {
        if (in_array($entry['msgid'], $allowed, true)) {
            continue;
        }

        // A plural entry needs every form filled, not just the first.
        $missing = $entry['msgstrs'] === [];

        foreach ($entry['msgstrs'] as $msgstr) {
            if (trim($msgstr) === '') {
                $missing = true;
            }
        }

        if ($missing) {
            $failures[] = sprintf('%s: %s', basename($po), $entry['msgid']);
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, "✖ Untranslated strings found:\n\n");

    foreach ($failures as $failure) {
        fwrite(STDERR, '  '.$failure."\n");
    }

    fwrite(STDERR, "\nRun `bun run translate`, then translate the new strings in\n");
    fwrite(STDERR, "languages/*.po (Du and Sie) and rebuild with `wp i18n make-mo`.\n");

    exit(1);
}

printf("✅ All strings translated across %d locale(s).\n", count($locales));
