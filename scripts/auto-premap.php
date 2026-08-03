<?php declare(strict_types=1);

// Fill in the migration premapping automatically through the Migration Assistant
// API, instead of clicking every dropdown by hand.
//
// generate-premapping returns each entity (payment methods, shipping, order
// states, languages, tax, ...) with its source rows and the available target
// choices. This script picks a target per row by matching names semantically,
// then posts it back with write-premapping.
//
// It errs toward filling every row (an empty row blocks the migration) and PRINTS
// every choice it made, flagging the low-confidence ones, so a human can verify.
// Nothing here decides anything irreversible — premapping can be redone.
//
// IMPORTANT: the $rules table below is an EXAMPLE, tuned to the shops this came
// from. The needles ('paypal', 'ups', 'cash', ... and shop-specific ones like
// 'sap', 'chase') are illustrative — read your own generate-premapping output and
// adjust them. Rows it cannot match fall back to the first choice and are printed
// with "??? VERIFY". Treat this as an accelerator, not an oracle.
//
// Connection defaults are for a dockware install. Override via env:
//   SW_BASE, SW_ADMIN_USER, SW_ADMIN_PASS.
//
// Usage: php auto-premap.php

$base = getenv('SW_BASE') ?: 'http://localhost';
$adminUser = getenv('SW_ADMIN_USER') ?: 'admin';
$adminPass = getenv('SW_ADMIN_PASS') ?: 'shopware';
$dataSelectionIds = ['basicSettings', 'products', 'customersOrders', 'newsletterRecipient', 'media', 'seoUrls', 'productReviews'];

function call(string $method, string $url, array $body, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
    ]);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $raw];
}

[$c, $raw] = call('POST', $base . '/api/oauth/token', [
    'client_id' => 'administration', 'grant_type' => 'password',
    'scopes' => 'write', 'username' => $adminUser, 'password' => $adminPass,
]);
if ($c !== 200) { fwrite(STDERR, "login failed: $raw\n"); exit(1); }
$auth = ['Authorization: Bearer ' . json_decode($raw, true)['access_token']];
echo "logged in\n";

[$c, $raw] = call('POST', $base . '/api/_action/migration/generate-premapping',
    ['dataSelectionIds' => $dataSelectionIds], $auth);
if ($c !== 200) { fwrite(STDERR, "generate-premapping failed ($c): $raw\n"); exit(1); }

$groups = json_decode($raw, true);
if (!is_array($groups)) { fwrite(STDERR, "unexpected response: $raw\n"); exit(1); }

$norm = static fn (string $s): string => strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $s));

// EXAMPLE per-entity rules: ordered list of [needle in source description => needle to find in a choice].
// first source-needle that matches wins; then the first choice containing the target-needle is used.
// Adjust these to your own source's payment/shipping/status names.
$rules = [
    'payment_method' => [
        ['paypal', 'paypal'], ['quotation', 'quote'], ['quote', 'quote'],
        ['purchase', 'purchase order'], ['cash', 'cash on delivery'], ['cod', 'cash on delivery'],
        ['credit', 'credit card'], ['chase', 'credit card'], ['card', 'credit card'],
        ['check', 'paid in advance'], ['free', 'paid in advance'], ['standard', 'paid in advance'],
        ['advance', 'paid in advance'], ['prepay', 'paid in advance'], ['invoice', 'invoice'],
    ],
    'shipping_method' => [
        ['pickup', 'pickup'], ['store', 'pickup'], ['freight', 'freight'], ['retrans', 'freight'],
        ['quotation', 'quote'], ['quote', 'quote'], ['ups', 'ups'], ['multicarrier', 'ups'],
        ['admin', 'ups'], ['flat', 'standard'], ['free', 'standard'],
    ],
    'order_state' => [
        ['complete', 'complete'], ['closed', 'cancel'], ['cancel', 'cancel'], ['fraud', 'cancel'],
        ['refund', 'cancel'], ['process', 'progress'], ['sap', 'progress'], ['hold', 'progress'],
        ['review', 'progress'], ['pending', 'open'], ['new', 'open'], ['open', 'open'], ['fail', 'open'],
    ],
    'order_transaction_state' => [
        ['paid', 'paid'], ['complete', 'paid'], ['pending', 'open'], ['cancel', 'cancel'],
        ['refund', 'refund'], ['process', 'progress'],
    ],
    'order_delivery_state' => [
        ['ship', 'shipped'], ['complete', 'shipped'], ['cancel', 'cancel'], ['open', 'open'], ['pending', 'open'],
    ],
    'customer_group' => [
        ['wholesale', 'wholesale'], ['retail', 'retailer'], ['general', 'general'],
        ['default', 'general'], ['not logged', 'not logged'],
    ],
    'salutation' => [
        ['mrs', 'mrs'], ['frau', 'mrs'], ['misses', 'mrs'], ['ms', 'mrs'],
        ['mr', 'mr'], ['herr', 'mr'], ['mister', 'mr'],
    ],
    // consent: the target HAS OptOut, so map the refusal to it rather than lose it
    'newsletter_status' => [
        ['unsub', 'optout'], ['unconfirm', 'not set'], ['not active', 'not set'], ['inactive', 'not set'],
        ['standard', 'optin'], ['subscrib', 'optin'],
    ],
    // admin scope has no real storefront; the store's own default language is fine
    'admin_store' => [['', 'english']],
    // language: source description carries a locale (en_US / zh_Hans_CN / ja_JP) or "Standard language"
    'language' => [
        ['standard', 'english us'], ['en us', 'english us'], ['en gb', 'english'],
        ['zh hans', 'simplified'], ['zh cn', 'simplified'], ['zh hant', 'traditional'], ['zh tw', 'traditional'],
        ['ja', 'japanese'], ['ko', 'korean'], ['fr', 'french'], ['es', 'spanish'], ['de', 'deutsch'], ['en', 'english'],
    ],
    'currency' => [['', 'dollar'], ['', 'usd']],
    'country' => [['', 'united states of america'], ['', 'usa']],
    // tax: source uses an external tax engine, so a product tax class has no single
    // right rate — 0% is a deliberate example choice. Decide this for your shop.
    'product_tax_class' => [['', '0%']],
    'tax' => [['', '0%']],
];

// after rules fail, any leftover row falls back to the first choice rather than
// block the migration. Empty is never acceptable — the API rejects the whole
// payload if one row is blank. Everything flagged FALLBACK is printed for review.

$pickChoice = static function (array $choices, string $targetNeedle) use ($norm): ?string {
    foreach ($choices as $ch) {
        if ($targetNeedle !== '' && str_contains($norm($ch['description']), $targetNeedle)) {
            return $ch['uuid'];
        }
    }
    return null;
};

$report = [];
foreach ($groups as &$group) {
    $entity = $group['entity'];
    $choices = $group['choices'] ?? [];
    $entityRules = $rules[$entity] ?? [];

    foreach ($group['mapping'] as &$row) {
        if (!empty($row['destinationUuid'])) { continue; }  // already set (e.g. exact-name auto)
        $src = $norm($row['description'] ?? $row['sourceId']);
        $uuid = null; $how = '';

        foreach ($entityRules as [$srcNeedle, $tgtNeedle]) {
            if ($srcNeedle === '' || str_contains($src, $norm($srcNeedle))) {
                $hit = $pickChoice($choices, $norm($tgtNeedle));
                if ($hit) { $uuid = $hit; $how = "rule:{$srcNeedle}->{$tgtNeedle}"; break; }
            }
        }
        // exact/substring name match against a choice, if no rule fired
        if (!$uuid) {
            foreach ($choices as $ch) {
                if ($norm($ch['description']) === $src) { $uuid = $ch['uuid']; $how = 'exact-name'; break; }
            }
        }
        if (!$uuid && $choices) {
            $uuid = $choices[0]['uuid']; $how = 'FALLBACK-first';
        }

        if ($uuid) {
            $row['destinationUuid'] = $uuid;
            $chosen = '';
            foreach ($choices as $ch) { if ($ch['uuid'] === $uuid) { $chosen = $ch['description']; break; } }
            $flag = str_contains($how, 'FALLBACK') ? '  ??? VERIFY' : '';
            $report[] = sprintf('%-26s %-30s -> %-28s [%s]%s', $entity, $row['description'] ?? $row['sourceId'], $chosen, $how, $flag);
        } else {
            $report[] = sprintf('%-26s %-30s -> (no choice — left empty)  ??? VERIFY', $entity, $row['description'] ?? $row['sourceId']);
        }
    }
    unset($row);
}
unset($group);

echo "\n=== premapping decisions ===\n" . implode("\n", $report) . "\n\n";

[$c, $raw] = call('POST', $base . '/api/_action/migration/write-premapping', ['premapping' => $groups], $auth);
if ($c !== 200 && $c !== 204) { fwrite(STDERR, "write-premapping failed ($c): $raw\n"); exit(1); }

echo "premapping written. Reload the Assignments screen to see it; verify the rows flagged ??? .\n";
