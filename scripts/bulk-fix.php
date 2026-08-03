<?php declare(strict_types=1);

// Resolve fixable migration errors in bulk, through the Admin API.
//
// The Error-resolution screen offers the fix operation ~25 rows at a time and the
// plugin ships no CLI, so at shop scale (tens of thousands of fixable errors) the
// UI is not a workflow. This does the same thing the UI does — write rows into
// `swag_migration_fix` — but for whole error groups at once, via the supported
// Sync API. Nothing here is irreversible; premapping and fixes can be redone.
//
// It is config-driven: describe the fixes in a JSON plan (see
// examples/bulk-fix-plan.example.json). Each fix group selects the failing rows
// for one entity+field from `swag_migration_logging` and gives them one value.
//
// A fix `value` is either a literal string, or one of these tokens:
//   "@language:<locale-code>"  resolve the Shopware language id for a locale (e.g. zh-CN)
//   "@placeholderAddress"      the address id of the migration placeholder customer
// An optional "storeIds": [..] restricts a group to source rows from those store views
// (matched on source_data.store_id) — useful when one field needs different values per store.
//
// Connection defaults are for a dockware install. Override via env:
//   SW_BASE, SW_ADMIN_USER, SW_ADMIN_PASS, SW_DB_DSN, SW_DB_USER, SW_DB_PASS.
//
// Usage: php bulk-fix.php <plan.json>

$planFile = $argv[1] ?? null;
if ($planFile === null || !is_file($planFile)) {
    fwrite(STDERR, "usage: php bulk-fix.php <plan.json>\n");
    exit(1);
}
$plan = json_decode((string) file_get_contents($planFile), true, 512, JSON_THROW_ON_ERROR);

$base = getenv('SW_BASE') ?: 'http://localhost';
$adminUser = getenv('SW_ADMIN_USER') ?: 'admin';
$adminPass = getenv('SW_ADMIN_PASS') ?: 'shopware';
$placeholderCustomerNumber = $plan['placeholderCustomerNumber'] ?? 'MIGRATION-PLACEHOLDER';

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

[$c, $raw] = call($base . '/api/oauth/token', [
    'client_id' => 'administration', 'grant_type' => 'password',
    'scopes' => 'write', 'username' => $adminUser, 'password' => $adminPass,
]);
if ($c !== 200) {
    fwrite(STDERR, "login failed: $raw\n");
    exit(1);
}
$auth = ['Authorization: Bearer ' . json_decode($raw, true)['access_token'], 'single-operation: 1'];
echo "logged in\n";

$one = static fn (string $sql): string => (string) $db->query($sql)->fetchColumn();
$connId = $one('SELECT LOWER(HEX(id)) FROM swag_migration_connection LIMIT 1');
if ($connId === '') {
    fwrite(STDERR, "no migration connection found — create it first\n");
    exit(1);
}

// resolve a value token to the literal string that goes into swag_migration_fix.value
$languageCache = [];
$resolveValue = static function (string $value) use ($db, &$languageCache, $placeholderCustomerNumber): string {
    if (str_starts_with($value, '@language:')) {
        $code = substr($value, strlen('@language:'));
        if (!isset($languageCache[$code])) {
            $st = $db->prepare("SELECT LOWER(HEX(l.id)) FROM language l
                JOIN locale lo ON lo.id = l.locale_id WHERE lo.code = ?");
            $st->execute([$code]);
            $languageCache[$code] = (string) $st->fetchColumn();
            if ($languageCache[$code] === '') {
                fwrite(STDERR, "  cannot resolve @language:$code — no such language in the target\n");
                exit(1);
            }
        }

        return $languageCache[$code];
    }

    if ($value === '@placeholderAddress') {
        $st = $db->prepare("SELECT LOWER(HEX(id)) FROM customer_address WHERE customer_id =
            (SELECT id FROM customer WHERE customer_number = ? LIMIT 1) LIMIT 1");
        $st->execute([$placeholderCustomerNumber]);
        $id = (string) $st->fetchColumn();
        if ($id === '') {
            fwrite(STDERR, "  cannot resolve @placeholderAddress — no customer '$placeholderCustomerNumber' with an address\n");
            exit(1);
        }

        return $id;
    }

    return $value;
};

// collect failing entity_ids for one entity+field, optionally filtered by source store_id
$selectRows = static function (string $entity, string $field, array $storeIds) use ($db): array {
    $sql = "SELECT LOWER(HEX(entity_id)) AS eid FROM swag_migration_logging
            WHERE entity_name = ? AND field_name = ? AND entity_id IS NOT NULL";
    $params = [$entity, $field];
    if ($storeIds) {
        $in = implode(',', array_fill(0, count($storeIds), '?'));
        $sql .= " AND JSON_UNQUOTE(JSON_EXTRACT(source_data, '$.store_id')) IN ($in)";
        foreach ($storeIds as $s) {
            $params[] = (string) $s;
        }
    }
    $st = $db->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_COLUMN);
};

// chunked upsert into swag_migration_fix
$writeFixes = static function (string $entity, string $field, string $value, array $eids) use ($db, $connId, $base, $auth): int {
    $done = 0;
    foreach (array_chunk($eids, 500) as $chunk) {
        $payload = array_map(static fn ($eid) => [
            'id' => bin2hex(random_bytes(16)),
            'connectionId' => $connId,
            'value' => $value,
            'path' => $field,
            'entityName' => $entity,
            'entityId' => $eid,
        ], $chunk);
        [$c, $raw] = call($base . '/api/_action/sync', [
            'w' => ['entity' => 'swag_migration_fix', 'action' => 'upsert', 'payload' => $payload],
        ], $auth);
        if ($c !== 200 && $c !== 204) {
            fwrite(STDERR, "  chunk failed ($c): " . substr($raw, 0, 300) . "\n");
            exit(1);
        }
        $done += count($chunk);
    }

    return $done;
};

foreach ($plan['fixes'] ?? [] as $fix) {
    $entity = $fix['entity'];
    $field = $fix['field'];
    $storeIds = $fix['storeIds'] ?? [];
    $value = $resolveValue((string) $fix['value']);

    $eids = $selectRows($entity, $field, $storeIds);
    if (!$eids) {
        echo "no rows: $entity.$field" . ($storeIds ? ' (stores ' . implode(',', $storeIds) . ')' : '') . "\n";
        continue;
    }
    $n = $writeFixes($entity, $field, $value, $eids);
    echo "fixed $entity.$field" . ($storeIds ? ' (stores ' . implode(',', $storeIds) . ')' : '') . ": $n\n";
}

echo "\ndone. Reload Error resolution — fixable groups should read Fixed=Total; then Continue.\n";
