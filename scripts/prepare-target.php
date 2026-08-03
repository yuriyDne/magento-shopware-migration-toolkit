<?php declare(strict_types=1);

// Create in Shopware what the source needs, before the migration connection is made.
//
// Everything the premapping can offer has to exist first. A currency the target
// does not know fails every order that uses it; a tax rate it does not know is
// picked wrong in silence. Both are cheap to prevent here.
//
// Uses the Admin API sync endpoint, which is the supported bulk path. Idempotent:
// entities are matched on a natural key and skipped when already present.
//
// Connection defaults are for a dockware install. Override via env:
//   SW_BASE (http://localhost), SW_ADMIN_USER (admin), SW_ADMIN_PASS (shopware),
//   SW_DB_DSN, SW_DB_USER (root), SW_DB_PASS (root).
//
// Usage: php prepare-target.php <plan.json>   (see examples/prepare-plan.example.json)

$planFile = $argv[1] ?? null;
if ($planFile === null || !is_file($planFile)) {
    fwrite(STDERR, "usage: php prepare-target.php <plan.json>\n");
    exit(1);
}

$plan = json_decode((string) file_get_contents($planFile), true, 512, JSON_THROW_ON_ERROR);
$base = getenv('SW_BASE') ?: 'http://localhost';
$adminUser = getenv('SW_ADMIN_USER') ?: 'admin';
$adminPass = getenv('SW_ADMIN_PASS') ?: 'shopware';

$db = new PDO(
    getenv('SW_DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=shopware;charset=utf8mb4',
    getenv('SW_DB_USER') ?: 'root',
    getenv('SW_DB_PASS') ?: 'root',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

function call(string $url, array $body, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
    ]);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$code, $raw];
}

[$code, $raw] = call($base . '/api/oauth/token', [
    'client_id' => 'administration',
    'grant_type' => 'password',
    'scopes' => 'write',
    'username' => $adminUser,
    'password' => $adminPass,
]);
if ($code !== 200) {
    fwrite(STDERR, "login failed ($code): $raw\n");
    exit(1);
}
$token = json_decode($raw, true)['access_token'];
$auth = ['Authorization: Bearer ' . $token, 'single-operation: 1'];
echo "logged in\n";

function sync(string $entity, array $payload, array $auth, string $base): void
{
    if (!$payload) {
        return;
    }
    [$code, $raw] = call($base . '/api/_action/sync', [
        'write' => ['entity' => $entity, 'action' => 'upsert', 'payload' => $payload],
    ], $auth);

    if ($code !== 200 && $code !== 204) {
        fwrite(STDERR, "  FAILED ($code): " . substr($raw, 0, 600) . "\n");

        return;
    }
    echo '  wrote ' . count($payload) . " row(s)\n";
}

$languages = $db->query('SELECT LOWER(HEX(id)) FROM language')->fetchAll(PDO::FETCH_COLUMN);

// ---- tax rates -------------------------------------------------------------
echo "tax rates:\n";
$existing = $db->query('SELECT tax_rate FROM tax')->fetchAll(PDO::FETCH_COLUMN);
$existing = array_map(static fn ($r) => (float) $r, $existing);
$payload = [];
foreach ($plan['taxRates'] ?? [] as $rate) {
    if (in_array((float) $rate, $existing, true)) {
        echo "  $rate% already there\n";
        continue;
    }
    $payload[] = [
        'id' => bin2hex(random_bytes(16)),
        'name' => rtrim(rtrim(number_format((float) $rate, 2, '.', ''), '0'), '.') . '%',
        'taxRate' => (float) $rate,
        'position' => 1,
    ];
}
sync('tax', $payload, $auth, $base);

// ---- customer groups -------------------------------------------------------
echo "customer groups:\n";
$existing = $db->query('SELECT name FROM customer_group_translation')->fetchAll(PDO::FETCH_COLUMN);
$payload = [];
foreach ($plan['customerGroups'] ?? [] as $name) {
    if (in_array($name, $existing, true)) {
        echo "  $name already there\n";
        continue;
    }
    $row = ['id' => bin2hex(random_bytes(16)), 'translations' => []];
    foreach ($languages as $lang) {
        $row['translations'][$lang] = ['name' => $name];
    }
    $payload[] = $row;
}
sync('customer_group', $payload, $auth, $base);

// ---- payment methods -------------------------------------------------------
echo "payment methods:\n";
$existing = $db->query('SELECT technical_name FROM payment_method')->fetchAll(PDO::FETCH_COLUMN);
$payload = [];
foreach ($plan['paymentMethods'] ?? [] as $m) {
    if (in_array($m['technicalName'], $existing, true)) {
        echo "  {$m['technicalName']} already there\n";
        continue;
    }
    $row = [
        'id' => bin2hex(random_bytes(16)),
        // no real gateway behind these; they exist so the premapping has a target
        'handlerIdentifier' => 'Shopware\\Core\\Checkout\\Payment\\Cart\\PaymentHandler\\PrePayment',
        'technicalName' => $m['technicalName'],
        'active' => true,
        'afterOrderEnabled' => false,
        'translations' => [],
    ];
    foreach ($languages as $lang) {
        $row['translations'][$lang] = ['name' => $m['name'], 'description' => $m['description'] ?? ''];
    }
    $payload[] = $row;
}
sync('payment_method', $payload, $auth, $base);

// ---- shipping methods ------------------------------------------------------
echo "shipping methods:\n";
$existing = $db->query('SELECT technical_name FROM shipping_method')->fetchAll(PDO::FETCH_COLUMN);
$deliveryTime = $db->query('SELECT LOWER(HEX(id)) FROM delivery_time LIMIT 1')->fetchColumn();
$rule = $db->query('SELECT LOWER(HEX(id)) FROM rule LIMIT 1')->fetchColumn();
$payload = [];
foreach ($plan['shippingMethods'] ?? [] as $m) {
    if (in_array($m['technicalName'], $existing, true)) {
        echo "  {$m['technicalName']} already there\n";
        continue;
    }
    $row = [
        'id' => bin2hex(random_bytes(16)),
        'technicalName' => $m['technicalName'],
        'active' => true,
        'deliveryTimeId' => $deliveryTime,
        'availabilityRuleId' => $rule,
        'translations' => [],
    ];
    foreach ($languages as $lang) {
        $row['translations'][$lang] = ['name' => $m['name']];
    }
    $payload[] = $row;
}
sync('shipping_method', $payload, $auth, $base);

// ---- currencies ------------------------------------------------------------
echo "currencies:\n";
$existing = $db->query('SELECT iso_code FROM currency')->fetchAll(PDO::FETCH_COLUMN);
$payload = [];
foreach ($plan['currencies'] ?? [] as $c) {
    if (in_array($c['iso'], $existing, true)) {
        echo "  {$c['iso']} already there\n";
        continue;
    }
    $row = [
        'id' => bin2hex(random_bytes(16)),
        'isoCode' => $c['iso'],
        'factor' => (float) $c['factor'],
        'symbol' => $c['symbol'],
        'position' => 1,
        'itemRounding' => ['decimals' => 2, 'interval' => 0.01, 'roundForNet' => true],
        'totalRounding' => ['decimals' => 2, 'interval' => 0.01, 'roundForNet' => true],
        'translations' => [],
    ];
    foreach ($languages as $lang) {
        $row['translations'][$lang] = ['shortName' => $c['iso'], 'name' => $c['name']];
    }
    $payload[] = $row;
}
sync('currency', $payload, $auth, $base);

// ---- default currency ------------------------------------------------------
// The migration copies order amounts raw; they only mean the right thing if the
// target default currency matches the source. A fresh install defaults to EUR, so
// this must be set per source (USD, ...). The default is a fixed core UUID
// (Defaults::CURRENCY), so we redefine THAT row and re-add the old default as an
// ordinary currency, done here so prepare-target is one self-contained step.
if (!empty($plan['defaultCurrency'])) {
    $d = $plan['defaultCurrency'];
    $defaultId = 'b7d2554b0ce847cd82f3ac9bd1c0dfca';
    $current = $db->query("SELECT iso_code FROM currency WHERE id = UNHEX('$defaultId')")->fetchColumn();

    if ($current === $d['iso']) {
        echo "default currency: already {$d['iso']}\n";
    } else {
        echo "default currency: {$current} -> {$d['iso']}\n";
        // drop any standalone row for the incoming ISO so the default row can take it
        $db->prepare('DELETE ct FROM currency_translation ct JOIN currency c ON c.id = ct.currency_id
                      WHERE c.iso_code = ? AND c.id <> UNHEX(?)')->execute([$d['iso'], $defaultId]);
        $db->prepare('DELETE FROM currency WHERE iso_code = ? AND id <> UNHEX(?)')
           ->execute([$d['iso'], $defaultId]);
        // redefine the default row (factor 1: it is the unit everything is measured in)
        $db->prepare("UPDATE currency SET iso_code = ?, symbol = ?, factor = 1 WHERE id = UNHEX('$defaultId')")
           ->execute([$d['iso'], $d['symbol']]);
        $db->prepare("UPDATE currency_translation SET short_name = ?, name = ? WHERE currency_id = UNHEX('$defaultId')")
           ->execute([$d['iso'], $d['name']]);
        // keep the previous default as an ordinary currency
        if ($current) {
            $newId = bin2hex(random_bytes(16));
            $rounding = '{"decimals": "2", "interval": 0.01, "roundForNet": true}';
            $ins = $db->prepare('INSERT IGNORE INTO currency
                (id, iso_code, factor, symbol, position, item_rounding, total_rounding, tax_free_from, created_at)
                VALUES (UNHEX(?), ?, ?, ?, 1, ?, ?, 0, NOW(3))');
            $ins->execute([$newId, $current, (float) ($d['oldDefaultFactor'] ?? 1), $current, $rounding, $rounding]);
            foreach ($languages as $lang) {
                $db->prepare('INSERT IGNORE INTO currency_translation (currency_id, language_id, short_name, name, created_at)
                              VALUES (UNHEX(?), UNHEX(?), ?, ?, NOW(3))')
                   ->execute([$newId, $lang, $current, $current]);
            }
        }
        echo "  set. NOTE: clear cache and restart the worker afterwards (lookups cache in memory).\n";
    }
}

echo "\nresult:\n";
foreach ([
    'currencies' => 'SELECT iso_code FROM currency ORDER BY iso_code',
    'tax rates' => 'SELECT CONCAT(name, " = ", tax_rate) FROM tax ORDER BY tax_rate',
    'customer groups' => 'SELECT name FROM customer_group_translation',
    'payment methods' => 'SELECT technical_name FROM payment_method ORDER BY technical_name',
    'shipping methods' => 'SELECT technical_name FROM shipping_method ORDER BY technical_name',
] as $label => $sql) {
    $rows = $db->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    echo "  $label: " . implode(', ', array_unique($rows)) . "\n";
}
