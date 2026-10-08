<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Vary: Origin');

$config = [];
$configFile = __DIR__ . '/config.local.php';
if (is_file($configFile)) {
    $config = require $configFile;
}
require_once __DIR__ . '/integrations.php';

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$origins = (string) (getenv('STORE_ALLOWED_ORIGINS') ?: ($config['allowed_origins'] ?? 'https://v4.indoatlmart.com,http://localhost:8000'));
if ($origin !== '' && in_array($origin, array_map('trim', explode(',', $origins)), true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function permissions_for_role(string $role): array
{
    $permissions = [
        'administrator' => ['dashboard.view', 'orders.view', 'orders.manage', 'orders.ship', 'products.view', 'products.manage_price', 'inventory.manage', 'payments.view', 'shipping.view', 'integrations.view', 'integrations.manage', 'users.manage'],
        'manager_toko' => ['dashboard.view', 'orders.view', 'orders.manage', 'orders.ship', 'products.view', 'products.manage_price', 'inventory.manage', 'payments.view', 'shipping.view', 'integrations.view'],
        'admin_toko' => ['dashboard.view', 'orders.view', 'orders.manage', 'orders.ship', 'products.view', 'inventory.manage', 'payments.view', 'shipping.view', 'integrations.view'],
        'staff_gudang' => ['dashboard.view', 'orders.view', 'orders.ship', 'products.view', 'inventory.manage', 'shipping.view', 'integrations.view'],
    ];
    return $permissions[$role] ?? [];
}

function all_permissions(): array
{
    $all = [];
    foreach (['administrator', 'manager_toko', 'admin_toko', 'staff_gudang'] as $role) {
        $all = [...$all, ...permissions_for_role($role)];
    }
    return array_values(array_unique($all));
}

function valid_permission_list(mixed $permissions): bool
{
    if (!is_array($permissions)) {
        return false;
    }
    $allowed = all_permissions();
    foreach ($permissions as $permission) {
        if (!is_string($permission) || !in_array($permission, $allowed, true)) {
            return false;
        }
    }
    return true;
}

function has_permission(string $permission): bool
{
    $user = $GLOBALS['current_admin'] ?? null;
    return is_array($user) && in_array($permission, $user['permissions'] ?? [], true);
}

function require_permission(array $config, string $permission): void
{
    require_admin($config);
    if (!has_permission($permission)) {
        respond(403, ['error' => 'forbidden', 'required_permission' => $permission]);
    }
}

function database(array $config): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $path = (string) (getenv('STORE_DB_PATH') ?: ($config['database_path'] ?? (__DIR__ . '/storage/store.sqlite')));
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Database storage is unavailable.');
    }
    $storageRoot = realpath(__DIR__ . '/storage');
    $resolvedDirectory = realpath($directory);
    if ($storageRoot === false || $resolvedDirectory === false || ($resolvedDirectory !== $storageRoot && !str_starts_with($resolvedDirectory, $storageRoot . DIRECTORY_SEPARATOR))) {
        throw new RuntimeException('Database must use the private backend storage directory.');
    }
    if (!chmod($directory, 0700)) {
        throw new RuntimeException('Database storage permissions could not be secured.');
    }

    $previousUmask = umask(0077);
    try {
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } finally {
        umask($previousUmask);
    }
    if (!chmod($path, 0600)) {
        throw new RuntimeException('Database file permissions could not be secured.');
    }
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('CREATE TABLE IF NOT EXISTS products (
        id INTEGER PRIMARY KEY, slug TEXT NOT NULL UNIQUE, name TEXT NOT NULL,
        status TEXT NOT NULL, min_price INTEGER NOT NULL DEFAULT 0,
        max_price INTEGER NOT NULL DEFAULT 0, sold_count INTEGER NOT NULL DEFAULT 0,
        source_json TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS orders (
        id TEXT PRIMARY KEY, order_number TEXT NOT NULL UNIQUE,
        customer_name TEXT NOT NULL, phone TEXT NOT NULL, email TEXT NOT NULL DEFAULT \'\',
        shipping_address TEXT NOT NULL, notes TEXT NOT NULL DEFAULT \'\',
        payment_method TEXT NOT NULL, status TEXT NOT NULL DEFAULT \'pending\',
        total_amount INTEGER NOT NULL, created_at TEXT NOT NULL
    )');
    $orderColumns = array_column($pdo->query('PRAGMA table_info(orders)')->fetchAll(), 'name');
    $newOrderColumns = [
        'shipping_amount' => 'INTEGER NOT NULL DEFAULT 0',
        'shipping_area_id' => 'TEXT NOT NULL DEFAULT \'\'',
        'shipping_area_name' => 'TEXT NOT NULL DEFAULT \'\'',
        'shipping_company' => 'TEXT NOT NULL DEFAULT \'\'',
        'shipping_service' => 'TEXT NOT NULL DEFAULT \'\'',
        'payment_status' => 'TEXT NOT NULL DEFAULT \'unpaid\'',
        'payment_token' => 'TEXT NOT NULL DEFAULT \'\'',
        'payment_url' => 'TEXT NOT NULL DEFAULT \'\'',
    ];
    foreach ($newOrderColumns as $column => $definition) {
        if (!in_array($column, $orderColumns, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN $column $definition");
        }
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS order_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id TEXT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
        product_id INTEGER NOT NULL, sku_id INTEGER, sku_code TEXT NOT NULL DEFAULT \'\',
        product_name TEXT NOT NULL, quantity INTEGER NOT NULL,
        unit_price INTEGER NOT NULL, subtotal INTEGER NOT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (
        id TEXT PRIMARY KEY, username TEXT NOT NULL UNIQUE, display_name TEXT NOT NULL,
        password_hash TEXT NOT NULL, role TEXT NOT NULL, permissions_json TEXT NOT NULL,
        is_active INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL, last_login_at TEXT NOT NULL DEFAULT \'\'
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS api_tokens (
        token_hash TEXT PRIMARY KEY, user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        expires_at TEXT NOT NULL, created_at TEXT NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_api_tokens_expires ON api_tokens(expires_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_products_status_name ON products(status, name)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_orders_created_at ON orders(created_at DESC)');

    if ((int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() === 0) {
        $seedPath = __DIR__ . '/data/products.json';
        if (is_file($seedPath)) {
            $seed = json_decode((string) file_get_contents($seedPath), true, 512, JSON_THROW_ON_ERROR);
            $insert = $pdo->prepare('INSERT OR IGNORE INTO products (id, slug, name, status, min_price, max_price, sold_count, source_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            foreach (($seed['data'] ?? []) as $product) {
                if (!is_array($product) || empty($product['id']) || empty($product['slug']) || empty($product['name'])) {
                    continue;
                }
                if (isset($product['primary_image']) && str_starts_with($product['primary_image'], '/')) {
                    $product['primary_image'] = 'https://ktitaniumbolt.com' . $product['primary_image'];
                }
                $insert->execute([
                    (int) $product['id'], (string) $product['slug'], (string) $product['name'],
                    (string) ($product['status'] ?? 'inactive'), (int) ($product['min_price'] ?? 0),
                    (int) ($product['max_price'] ?? 0), (int) ($product['sold_count'] ?? 0),
                    json_encode($product, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);
            }
        }
    }
    return $pdo;
}

function read_json_body(): array
{
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) {
        respond(413, ['error' => 'request_too_large']);
    }
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        respond(400, ['error' => 'invalid_json']);
    }
    return $body;
}

function require_admin(array $config): void
{
    $expected = (string) (getenv('STORE_ADMIN_TOKEN') ?: ($config['admin_token'] ?? ''));
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($expected === '' || str_contains($expected, 'REPLACE_WITH_')) {
        respond(503, ['error' => 'admin_not_configured']);
    }
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
        respond(401, ['error' => 'unauthorized']);
    }
    $token = trim($matches[1]);
    if (hash_equals($expected, $token)) {
        $GLOBALS['current_admin'] = ['id' => null, 'username' => 'bootstrap', 'display_name' => 'Administrator', 'role' => 'administrator', 'permissions' => permissions_for_role('administrator')];
        return;
    }
    $statement = database($config)->prepare('SELECT users.id, users.username, users.display_name, users.role, users.permissions_json FROM api_tokens JOIN users ON users.id = api_tokens.user_id WHERE api_tokens.token_hash = ? AND api_tokens.expires_at > ? AND users.is_active = 1 LIMIT 1');
    $statement->execute([hash('sha256', $token), gmdate('c')]);
    $user = $statement->fetch();
    if ($user === false) {
        respond(401, ['error' => 'unauthorized']);
    }
    $GLOBALS['current_admin'] = [
        'id' => $user['id'], 'username' => $user['username'], 'display_name' => $user['display_name'],
        'role' => $user['role'], 'permissions' => json_decode($user['permissions_json'], true) ?: [],
    ];
}

function save_local_config(array $config): void
{
    $path = __DIR__ . '/config.local.php';
    if (!is_file($path) || !is_writable($path)) {
        throw new RuntimeException('config_not_writable');
    }
    $temporary = $path . '.tmp.' . bin2hex(random_bytes(6));
    $contents = "<?php\nreturn " . var_export($config, true) . ";\n";
    if (file_put_contents($temporary, $contents, LOCK_EX) === false || !chmod($temporary, 0600) || !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('config_save_failed');
    }
    if (!chmod($path, 0600)) {
        throw new RuntimeException('config_permissions_failed');
    }
}

function persist_product(PDO $db, array $product): void
{
    $stock = 0;
    $minimumStock = null;
    $hasUnlimitedSku = false;
    foreach (($product['skus'] ?? []) as $sku) {
        if (empty($sku['is_active'])) {
            continue;
        }
        if (!empty($sku['is_unlimited_stock'])) {
            $hasUnlimitedSku = true;
            continue;
        }
        $skuStock = max(0, (int) ($sku['stock'] ?? 0));
        $stock += $skuStock;
        $minimumStock = $minimumStock === null ? $skuStock : min($minimumStock, $skuStock);
    }
    $product['total_stock'] = $stock;
    $product['min_variant_stock'] = $minimumStock ?? 0;
    $product['has_unlimited_sku'] = $hasUnlimitedSku;
    $statement = $db->prepare('UPDATE products SET min_price = ?, max_price = ?, sold_count = ?, source_json = ? WHERE id = ?');
    $statement->execute([
        (int) ($product['min_price'] ?? 0), (int) ($product['max_price'] ?? 0),
        (int) ($product['sold_count'] ?? 0),
        json_encode($product, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        (int) $product['id'],
    ]);
}

function restore_order_stock(PDO $db, string $orderId): void
{
    $items = $db->prepare('SELECT product_id, sku_id, quantity FROM order_items WHERE order_id = ?');
    $items->execute([$orderId]);
    $productQuery = $db->prepare('SELECT source_json FROM products WHERE id = ?');
    foreach ($items->fetchAll() as $item) {
        $productQuery->execute([(int) $item['product_id']]);
        $rawProduct = $productQuery->fetchColumn();
        if ($rawProduct === false) {
            continue;
        }
        $product = json_decode($rawProduct, true, 512, JSON_THROW_ON_ERROR);
        foreach ($product['skus'] as &$sku) {
            if ((string) $sku['id'] === (string) $item['sku_id'] && empty($sku['is_unlimited_stock'])) {
                $sku['stock'] = (int) ($sku['stock'] ?? 0) + (int) $item['quantity'];
                break;
            }
        }
        unset($sku);
        persist_product($db, $product);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$prefix = '/api/v1';
if ($requestPath !== $prefix && !str_starts_with($requestPath, $prefix . '/')) {
    respond(404, ['error' => 'not_found']);
}
$resource = trim(substr($requestPath, strlen($prefix)), '/');
$parts = $resource === '' ? [] : array_map('rawurldecode', explode('/', $resource));
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

try {
    $db = database($config);

    if ($method === 'POST' && $resource === 'auth/login') {
        $input = read_json_body();
        $bootstrapToken = (string) ($input['admin_token'] ?? '');
        $expected = (string) (getenv('STORE_ADMIN_TOKEN') ?: ($config['admin_token'] ?? ''));
        if ($expected !== '' && !str_contains($expected, 'REPLACE_WITH_') && $bootstrapToken !== '' && hash_equals($expected, $bootstrapToken)) {
            respond(200, ['data' => [
                'token' => $expected,
                'user' => ['username' => 'bootstrap', 'display_name' => 'Administrator', 'role' => 'administrator', 'permissions' => permissions_for_role('administrator')],
            ]]);
        }
        $username = strtolower(trim((string) ($input['username'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        $statement = $db->prepare('SELECT id, username, display_name, password_hash, role, permissions_json FROM users WHERE username = ? AND is_active = 1 LIMIT 1');
        $statement->execute([$username]);
        $user = $statement->fetch();
        if ($user === false || !password_verify($password, $user['password_hash'])) {
            respond(401, ['error' => 'invalid_credentials']);
        }
        $token = bin2hex(random_bytes(32));
        $expiresAt = gmdate('c', time() + 43200);
        $insertToken = $db->prepare('INSERT INTO api_tokens (token_hash, user_id, expires_at, created_at) VALUES (?, ?, ?, ?)');
        $insertToken->execute([hash('sha256', $token), $user['id'], $expiresAt, gmdate('c')]);
        $updateLogin = $db->prepare('UPDATE users SET last_login_at = ? WHERE id = ?');
        $updateLogin->execute([gmdate('c'), $user['id']]);
        respond(200, ['data' => [
            'token' => $token,
            'user' => [
                'username' => $user['username'], 'display_name' => $user['display_name'],
                'role' => $user['role'], 'permissions' => json_decode($user['permissions_json'], true) ?: [],
            ],
        ]]);
    }

    if ($resource === 'auth/me' && $method === 'GET') {
        require_admin($config);
        respond(200, ['data' => $GLOBALS['current_admin']]);
    }

    if ($resource === 'auth/logout' && $method === 'POST') {
        require_admin($config);
        if (($GLOBALS['current_admin']['id'] ?? null) !== null && preg_match('/^Bearer\s+(.+)$/i', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $matches)) {
            $deleteToken = $db->prepare('DELETE FROM api_tokens WHERE token_hash = ?');
            $deleteToken->execute([hash('sha256', trim($matches[1]))]);
        }
        respond(200, ['status' => 'ok']);
    }

    if ($method === 'GET' && $resource === 'health') {
        respond(200, [
            'status' => 'ok',
            'products' => (int) $db->query("SELECT COUNT(*) FROM products WHERE status = 'active'")->fetchColumn(),
            'database' => 'sqlite',
        ]);
    }

    if ($method === 'GET' && $resource === 'config') {
        $midtrans = midtrans_settings($config);
        $biteship = biteship_settings($config);
        respond(200, [
            'midtrans' => [
                'enabled' => $midtrans['enabled'],
                'client_key' => $midtrans['client_key'],
                'snap_url' => $midtrans['snap_url'],
                'environment' => $midtrans['environment'],
            ],
            'shipping' => ['enabled' => $biteship['enabled']],
        ]);
    }

    if ($method === 'GET' && $resource === 'shipping/areas') {
        $query = trim((string) ($_GET['q'] ?? ''));
        if (strlen($query) < 3 || strlen($query) > 100) {
            respond(422, ['error' => 'area_query_too_short']);
        }
        try {
            respond(200, ['data' => shipping_areas($config, $query)]);
        } catch (RuntimeException $error) {
            $code = $error->getMessage() === 'shipping_not_configured' ? 503 : 502;
            respond($code, ['error' => $error->getMessage()]);
        }
    }

    if ($method === 'POST' && $resource === 'shipping/rates') {
        $input = read_json_body();
        $destination = trim((string) ($input['destination_area_id'] ?? ''));
        $requestedItems = $input['items'] ?? null;
        if ($destination === '' || !is_array($requestedItems) || count($requestedItems) < 1 || count($requestedItems) > 30) {
            respond(422, ['error' => 'invalid_shipping_request']);
        }
        $quoteItems = [];
        $productQuery = $db->prepare("SELECT source_json FROM products WHERE id = ? AND status = 'active'");
        foreach ($requestedItems as $requestedItem) {
            if (!is_array($requestedItem)) {
                respond(422, ['error' => 'invalid_shipping_item']);
            }
            $quantity = filter_var($requestedItem['quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($quantity === false || $quantity < 1 || $quantity > 99 || !isset($requestedItem['product_id'], $requestedItem['sku_id'])) {
                respond(422, ['error' => 'invalid_shipping_item']);
            }
            $productQuery->execute([(int) $requestedItem['product_id']]);
            $rawProduct = $productQuery->fetchColumn();
            if ($rawProduct === false) {
                respond(404, ['error' => 'product_not_found']);
            }
            $product = json_decode($rawProduct, true, 512, JSON_THROW_ON_ERROR);
            $sku = null;
            foreach (($product['skus'] ?? []) as $candidate) {
                if ((string) $candidate['id'] === (string) $requestedItem['sku_id'] && !empty($candidate['is_active'])) {
                    $sku = $candidate;
                    break;
                }
            }
            if ($sku === null) {
                respond(422, ['error' => 'active_sku_required']);
            }
            $quoteItems[] = [
                'product_name' => (string) $product['name'], 'sku_code' => (string) ($sku['sku_code'] ?? ''),
                'unit_price' => (int) ($sku['base_price'] ?? 0), 'quantity' => $quantity,
            ];
        }
        try {
            respond(200, ['data' => quote_shipping($config, $destination, $quoteItems)]);
        } catch (RuntimeException $error) {
            $code = $error->getMessage() === 'shipping_not_configured' ? 503 : 502;
            respond($code, ['error' => $error->getMessage()]);
        }
    }

    if ($method === 'GET' && ($resource === 'products' || (count($parts) === 2 && $parts[0] === 'products'))) {
        if (count($parts) === 2) {
            $statement = $db->prepare("SELECT source_json FROM products WHERE slug = ? AND status = 'active' LIMIT 1");
            $statement->execute([$parts[1]]);
            $product = $statement->fetchColumn();
            if ($product === false) {
                respond(404, ['error' => 'product_not_found']);
            }
            header('Cache-Control: public, max-age=60');
            respond(200, ['data' => json_decode($product, true, 512, JSON_THROW_ON_ERROR)]);
        }

        $query = trim((string) ($_GET['q'] ?? ''));
        $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 24)));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $sorts = [
            'sold_desc' => 'sold_count DESC, id DESC',
            'price_asc' => 'min_price ASC, id DESC',
            'price_desc' => 'max_price DESC, id DESC',
            'name_asc' => 'name COLLATE NOCASE ASC',
        ];
        $sort = $sorts[(string) ($_GET['sort'] ?? 'sold_desc')] ?? $sorts['sold_desc'];
        $where = "status = 'active'";
        $parameters = [];
        if ($query !== '') {
            $where .= ' AND (name LIKE ? OR slug LIKE ?)';
            $parameters = ['%' . $query . '%', '%' . $query . '%'];
        }
        $count = $db->prepare("SELECT COUNT(*) FROM products WHERE $where");
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();
        $statement = $db->prepare("SELECT source_json FROM products WHERE $where ORDER BY $sort LIMIT ? OFFSET ?");
        $statement->execute([...$parameters, $perPage, ($page - 1) * $perPage]);
        $items = array_map(static fn (array $row): array => json_decode($row['source_json'], true, 512, JSON_THROW_ON_ERROR), $statement->fetchAll());
        header('Cache-Control: public, max-age=60');
        respond(200, ['data' => $items, 'meta' => [
            'current_page' => $page, 'last_page' => max(1, (int) ceil($total / $perPage)),
            'per_page' => $perPage, 'total' => $total,
        ]]);
    }

    if ($method === 'POST' && $resource === 'payments/midtrans/notification') {
        $settings = midtrans_settings($config);
        if (!$settings['enabled']) {
            respond(503, ['error' => 'payment_not_configured']);
        }
        $notification = read_json_body();
        $orderNumber = (string) ($notification['order_id'] ?? '');
        $statusCode = (string) ($notification['status_code'] ?? '');
        $grossAmount = (string) ($notification['gross_amount'] ?? '');
        $signature = (string) ($notification['signature_key'] ?? '');
        $expectedSignature = hash('sha512', $orderNumber . $statusCode . $grossAmount . $settings['server_key']);
        if ($orderNumber === '' || $signature === '' || !hash_equals($expectedSignature, $signature)) {
            respond(401, ['error' => 'invalid_payment_signature']);
        }
        $lookup = $db->prepare('SELECT id, status, payment_status, total_amount FROM orders WHERE order_number = ?');
        $lookup->execute([$orderNumber]);
        $order = $lookup->fetch();
        if ($order === false) {
            respond(404, ['error' => 'order_not_found']);
        }
        if ((int) round((float) $grossAmount) !== (int) $order['total_amount']) {
            respond(422, ['error' => 'payment_amount_mismatch']);
        }
        $transactionStatus = (string) ($notification['transaction_status'] ?? '');
        $fraudStatus = (string) ($notification['fraud_status'] ?? 'accept');
        if (in_array($transactionStatus, ['settlement', 'capture'], true) && ($transactionStatus === 'settlement' || $fraudStatus === 'accept')) {
            if ($order['status'] === 'cancelled') {
                $update = $db->prepare("UPDATE orders SET payment_status = 'paid_review', payment_method = 'midtrans' WHERE id = ? AND payment_status != 'paid'");
                $update->execute([$order['id']]);
            } else {
                $update = $db->prepare("UPDATE orders SET payment_status = 'paid', status = 'confirmed', payment_method = 'midtrans' WHERE id = ? AND payment_status != 'paid'");
                $update->execute([$order['id']]);
            }
        } elseif (in_array($transactionStatus, ['expire', 'cancel', 'deny'], true) && $order['payment_status'] !== 'paid' && $order['status'] !== 'cancelled') {
            $db->beginTransaction();
            restore_order_stock($db, (string) $order['id']);
            $update = $db->prepare("UPDATE orders SET payment_status = ?, status = 'cancelled' WHERE id = ? AND payment_status != 'paid'");
            $update->execute([$transactionStatus, $order['id']]);
            $db->commit();
        } elseif ($transactionStatus === 'pending' && $order['payment_status'] !== 'paid') {
            $update = $db->prepare("UPDATE orders SET payment_status = 'pending' WHERE id = ?");
            $update->execute([$order['id']]);
        }
        respond(200, ['status' => 'ok']);
    }

    if ($method === 'GET' && count($parts) === 2 && $parts[0] === 'orders') {
        $statement = $db->prepare('SELECT order_number, status, payment_status, total_amount, shipping_amount, shipping_company, shipping_service, shipping_area_name, created_at FROM orders WHERE order_number = ?');
        $statement->execute([$parts[1]]);
        $order = $statement->fetch();
        if ($order === false) {
            respond(404, ['error' => 'order_not_found']);
        }
        header('Cache-Control: no-store');
        respond(200, ['data' => $order]);
    }

    if ($method === 'POST' && $resource === 'orders') {
        $input = read_json_body();
        $name = trim((string) ($input['customer_name'] ?? ''));
        $phone = trim((string) ($input['phone'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $address = trim((string) ($input['shipping_address'] ?? ''));
        $notes = trim((string) ($input['notes'] ?? ''));
        $items = $input['items'] ?? null;
        if ($name === '' || strlen($name) > 120 || !preg_match('/^[+0-9()\-\s]{8,24}$/', $phone) || strlen($address) < 8 || strlen($address) > 1000 || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) || !is_array($items) || count($items) < 1 || count($items) > 30) {
            respond(422, ['error' => 'invalid_order_details']);
        }
        $paymentMethod = (string) ($input['payment_method'] ?? 'midtrans');
        if (!in_array($paymentMethod, ['midtrans', 'whatsapp'], true)) {
            respond(422, ['error' => 'invalid_payment_method']);
        }
        $destinationAreaId = trim((string) ($input['destination_area_id'] ?? ''));
        $destinationAreaName = trim((string) ($input['destination_area_name'] ?? ''));
        $midtrans = midtrans_settings($config);
        if ($paymentMethod === 'midtrans' && !$midtrans['enabled']) {
            respond(503, ['error' => 'payment_not_configured']);
        }
        if ($destinationAreaId === '' || !biteship_settings($config)['enabled']) {
            respond(503, ['error' => 'shipping_not_configured']);
        }

        $db->beginTransaction();
        $orderItems = [];
        $total = 0;
        $byId = $db->prepare("SELECT source_json FROM products WHERE id = ? AND status = 'active'");
        $bySlug = $db->prepare("SELECT source_json FROM products WHERE slug = ? AND status = 'active'");
        foreach ($items as $item) {
            if (!is_array($item)) {
                $db->rollBack();
                respond(422, ['error' => 'invalid_order_item']);
            }
            $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($quantity === false || $quantity < 1 || $quantity > 99) {
                $db->rollBack();
                respond(422, ['error' => 'invalid_quantity']);
            }
            if (isset($item['product_id'])) {
                $byId->execute([(int) $item['product_id']]);
                $rawProduct = $byId->fetchColumn();
            } elseif (isset($item['slug'])) {
                $bySlug->execute([(string) $item['slug']]);
                $rawProduct = $bySlug->fetchColumn();
            } else {
                $db->rollBack();
                respond(422, ['error' => 'product_required']);
            }
            if ($rawProduct === false) {
                $db->rollBack();
                respond(404, ['error' => 'product_not_found']);
            }
            $product = json_decode($rawProduct, true, 512, JSON_THROW_ON_ERROR);
            $skus = $product['skus'] ?? [];
            $sku = null;
            foreach ($skus as $candidate) {
                $skuMatches = isset($item['sku_id'])
                    ? (string) $candidate['id'] === (string) $item['sku_id']
                    : count($skus) === 1;
                if (!empty($candidate['is_active']) && $skuMatches) {
                    $sku = $candidate;
                    break;
                }
            }
            if ($sku === null) {
                $db->rollBack();
                respond(422, ['error' => 'active_sku_required']);
            }
            $minimum = max(1, (int) ($sku['min_order_qty'] ?? 1));
            if ($quantity < $minimum) {
                $db->rollBack();
                respond(422, ['error' => 'below_minimum_quantity', 'minimum' => $minimum]);
            }
            if (empty($sku['is_unlimited_stock']) && (int) ($sku['stock'] ?? 0) < $quantity) {
                $db->rollBack();
                respond(409, ['error' => 'insufficient_stock']);
            }
            if (empty($sku['is_unlimited_stock'])) {
                foreach ($product['skus'] as &$storedSku) {
                    if ((string) $storedSku['id'] === (string) $sku['id']) {
                        $storedSku['stock'] = (int) ($storedSku['stock'] ?? 0) - $quantity;
                        break;
                    }
                }
                unset($storedSku);
                persist_product($db, $product);
            }
            $price = max(0, (int) ($sku['base_price'] ?? 0));
            $subtotal = $price * $quantity;
            $total += $subtotal;
            $orderItems[] = [
                'product_id' => (int) $product['id'], 'sku_id' => (int) $sku['id'],
                'sku_code' => (string) ($sku['sku_code'] ?? ''), 'product_name' => (string) $product['name'],
                'quantity' => $quantity, 'unit_price' => $price, 'subtotal' => $subtotal,
            ];
        }

        $selectedCompany = strtolower(trim((string) ($input['courier_company'] ?? '')));
        $selectedService = strtolower(trim((string) ($input['courier_service_code'] ?? '')));
        try {
            $availableRates = quote_shipping($config, $destinationAreaId, $orderItems);
        } catch (RuntimeException $error) {
            $db->rollBack();
            $code = $error->getMessage() === 'shipping_not_configured' ? 503 : 502;
            respond($code, ['error' => $error->getMessage()]);
        }
        $shippingRate = null;
        foreach ($availableRates as $availableRate) {
            if (strtolower($availableRate['company_code']) === $selectedCompany && strtolower($availableRate['service_code']) === $selectedService) {
                $shippingRate = $availableRate;
                break;
            }
        }
        if ($shippingRate === null) {
            $db->rollBack();
            respond(422, ['error' => 'shipping_rate_changed']);
        }
        $shippingAmount = (int) $shippingRate['price'];
        $total += $shippingAmount;

        $orderId = bin2hex(random_bytes(16));
        $orderNumber = 'KB-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $createdAt = gmdate('c');
        $insertOrder = $db->prepare('INSERT INTO orders (id, order_number, customer_name, phone, email, shipping_address, notes, payment_method, total_amount, created_at, shipping_amount, shipping_area_id, shipping_area_name, shipping_company, shipping_service, payment_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insertOrder->execute([$orderId, $orderNumber, $name, $phone, $email, $address, $notes, $paymentMethod, $total, $createdAt, $shippingAmount, $destinationAreaId, $destinationAreaName, $shippingRate['company'], $shippingRate['service'], 'unpaid']);
        $insertItem = $db->prepare('INSERT INTO order_items (order_id, product_id, sku_id, sku_code, product_name, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($orderItems as $orderItem) {
            $insertItem->execute([$orderId, ...array_values($orderItem)]);
        }
        $db->commit();

        if ($paymentMethod === 'midtrans') {
            try {
                $payment = create_midtrans_transaction($config, [
                    'order_number' => $orderNumber, 'total_amount' => $total,
                    'shipping_amount' => $shippingAmount, 'shipping_service' => $shippingRate['service'],
                    'customer_name' => $name, 'email' => $email, 'phone' => $phone,
                    'shipping_address' => $address,
                ], $orderItems);
            } catch (RuntimeException $error) {
                error_log('Payment setup failed for ' . $orderNumber . ': ' . $error->getMessage());
                respond(201, ['data' => [
                    'order_number' => $orderNumber, 'status' => 'pending', 'payment_status' => 'unpaid',
                    'total_amount' => $total, 'shipping_amount' => $shippingAmount,
                    'confirmation_url' => 'https://wa.me/6281998315468?text=' . rawurlencode('Mohon bantu konfirmasi pesanan ' . $orderNumber),
                    'payment_error' => true,
                ]]);
            }
            $savePayment = $db->prepare('UPDATE orders SET payment_token = ?, payment_url = ? WHERE id = ?');
            $savePayment->execute([$payment['token'], $payment['redirect_url'], $orderId]);
            respond(201, ['data' => [
                'order_number' => $orderNumber, 'status' => 'pending', 'payment_status' => 'unpaid',
                'total_amount' => $total, 'shipping_amount' => $shippingAmount,
                'payment_token' => $payment['token'], 'payment_url' => $payment['redirect_url'],
            ]]);
        }

        $whatsapp = (string) (getenv('STORE_WHATSAPP_NUMBER') ?: ($config['whatsapp_number'] ?? '6281998315468'));
        $message = rawurlencode('Pesanan ' . $orderNumber . ' dibuat. Total: Rp ' . number_format($total, 0, ',', '.') . '. Mohon konfirmasi pembayaran.');
        respond(201, ['data' => [
            'order_number' => $orderNumber, 'status' => 'pending', 'total_amount' => $total,
            'currency' => 'IDR', 'created_at' => $createdAt,
            'confirmation_url' => "https://wa.me/$whatsapp?text=$message",
        ]]);
    }

    if (count($parts) === 3 && $parts[0] === 'admin' && $parts[1] === 'products' && $method === 'PATCH') {
        require_admin($config);
        $lookup = $db->prepare('SELECT source_json FROM products WHERE slug = ?');
        $lookup->execute([$parts[2]]);
        $rawProduct = $lookup->fetchColumn();
        if ($rawProduct === false) {
            respond(404, ['error' => 'product_not_found']);
        }
        $product = json_decode($rawProduct, true, 512, JSON_THROW_ON_ERROR);
        $updates = read_json_body()['sku_updates'] ?? null;
        if (!is_array($updates) || count($updates) < 1 || count($updates) > 100) {
            respond(422, ['error' => 'invalid_sku_updates']);
        }
        foreach ($updates as $update) {
            if (!is_array($update)) {
                respond(422, ['error' => 'invalid_sku_update']);
            }
            if ((array_key_exists('base_price', $update) || array_key_exists('is_active', $update)) && !has_permission('products.manage_price')) {
                respond(403, ['error' => 'forbidden', 'required_permission' => 'products.manage_price']);
            }
            if ((array_key_exists('stock', $update) || array_key_exists('is_unlimited_stock', $update)) && !has_permission('inventory.manage')) {
                respond(403, ['error' => 'forbidden', 'required_permission' => 'inventory.manage']);
            }
        }
        $updatedIds = [];
        foreach ($updates as $update) {
            if (!is_array($update)) {
                respond(422, ['error' => 'invalid_sku_update']);
            }
            $skuId = (string) ($update['sku_id'] ?? '');
            $found = false;
            foreach ($product['skus'] as &$sku) {
                if ((string) $sku['id'] !== $skuId) {
                    continue;
                }
                $found = true;
                if (isset($update['base_price'])) {
                    $price = filter_var($update['base_price'], FILTER_VALIDATE_INT);
                    if ($price === false || $price < 1 || $price > 1000000000) {
                        unset($sku);
                        respond(422, ['error' => 'invalid_price']);
                    }
                    $sku['base_price'] = $price;
                }
                if (isset($update['stock'])) {
                    $stock = filter_var($update['stock'], FILTER_VALIDATE_INT);
                    if ($stock === false || $stock < 0 || $stock > 10000000) {
                        unset($sku);
                        respond(422, ['error' => 'invalid_stock']);
                    }
                    $sku['stock'] = $stock;
                }
                foreach (['is_active', 'is_unlimited_stock'] as $field) {
                    if (array_key_exists($field, $update)) {
                        if (!is_bool($update[$field])) {
                            unset($sku);
                            respond(422, ['error' => 'invalid_sku_flag']);
                        }
                        $sku[$field] = $update[$field];
                    }
                }
                $updatedIds[] = $skuId;
                break;
            }
            unset($sku);
            if (!$found) {
                respond(404, ['error' => 'sku_not_found']);
            }
        }
        $prices = array_map(static fn (array $sku): int => (int) ($sku['base_price'] ?? 0), array_filter($product['skus'], static fn (array $sku): bool => !empty($sku['is_active'])));
        $product['min_price'] = $prices === [] ? 0 : min($prices);
        $product['max_price'] = $prices === [] ? 0 : max($prices);
        persist_product($db, $product);
        respond(200, ['data' => ['slug' => $product['slug'], 'updated_sku_ids' => $updatedIds]]);
    }

    if ($resource === 'admin/me' && $method === 'GET') {
        require_admin($config);
        respond(200, ['data' => $GLOBALS['current_admin']]);
    }

    if ($resource === 'admin/users' && $method === 'GET') {
        require_permission($config, 'users.manage');
        $users = $db->query('SELECT id, username, display_name, role, permissions_json, is_active, created_at, last_login_at FROM users ORDER BY created_at DESC')->fetchAll();
        foreach ($users as &$user) {
            $user['permissions'] = json_decode($user['permissions_json'], true) ?: [];
            unset($user['permissions_json']);
            $user['is_active'] = (bool) $user['is_active'];
        }
        unset($user);
        respond(200, ['data' => $users, 'roles' => ['administrator', 'manager_toko', 'admin_toko', 'staff_gudang'], 'permissions' => all_permissions()]);
    }

    if ($resource === 'admin/users' && $method === 'POST') {
        require_permission($config, 'users.manage');
        $input = read_json_body();
        $username = strtolower(trim((string) ($input['username'] ?? '')));
        $displayName = trim((string) ($input['display_name'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $role = (string) ($input['role'] ?? '');
        if (!preg_match('/^[a-z0-9._-]{3,40}$/', $username) || $displayName === '' || strlen($displayName) > 100 || strlen($password) < 12 || strlen($password) > 200 || !in_array($role, ['administrator', 'manager_toko', 'admin_toko', 'staff_gudang'], true)) {
            respond(422, ['error' => 'invalid_user_details']);
        }
        $exists = $db->prepare('SELECT 1 FROM users WHERE username = ?');
        $exists->execute([$username]);
        if ($exists->fetchColumn()) {
            respond(409, ['error' => 'username_exists']);
        }
        $permissions = $input['permissions'] ?? permissions_for_role($role);
        if (!valid_permission_list($permissions)) {
            respond(422, ['error' => 'invalid_permissions']);
        }
        $permissions = array_values(array_unique($permissions));
        $userId = bin2hex(random_bytes(16));
        $insert = $db->prepare('INSERT INTO users (id, username, display_name, password_hash, role, permissions_json, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?)');
        $insert->execute([$userId, $username, $displayName, password_hash($password, PASSWORD_DEFAULT), $role, json_encode($permissions), gmdate('c')]);
        respond(201, ['data' => ['id' => $userId, 'username' => $username, 'display_name' => $displayName, 'role' => $role, 'permissions' => $permissions, 'is_active' => true]]);
    }

    if (count($parts) === 3 && $parts[0] === 'admin' && $parts[1] === 'users' && $method === 'PATCH') {
        require_permission($config, 'users.manage');
        $input = read_json_body();
        $findUser = $db->prepare('SELECT id, role, permissions_json, is_active FROM users WHERE id = ?');
        $findUser->execute([$parts[2]]);
        $existing = $findUser->fetch();
        if ($existing === false) {
            respond(404, ['error' => 'user_not_found']);
        }
        $fields = [];
        $values = [];
        $role = (string) ($input['role'] ?? $existing['role']);
        if (!in_array($role, ['administrator', 'manager_toko', 'admin_toko', 'staff_gudang'], true)) {
            respond(422, ['error' => 'invalid_role']);
        }
        if (isset($input['role'])) {
            $fields[] = 'role = ?';
            $values[] = $role;
        }
        $permissions = $input['permissions'] ?? (isset($input['role']) ? permissions_for_role($role) : (json_decode($existing['permissions_json'], true) ?: []));
        if (!valid_permission_list($permissions)) {
            respond(422, ['error' => 'invalid_permissions']);
        }
        if (isset($input['role']) || array_key_exists('permissions', $input)) {
            $fields[] = 'permissions_json = ?';
            $values[] = json_encode(array_values(array_unique($permissions)));
        }
        if (isset($input['display_name'])) {
            $displayName = trim((string) $input['display_name']);
            if ($displayName === '' || strlen($displayName) > 100) {
                respond(422, ['error' => 'invalid_display_name']);
            }
            $fields[] = 'display_name = ?';
            $values[] = $displayName;
        }
        if (isset($input['password'])) {
            if (!is_string($input['password']) || strlen($input['password']) < 12 || strlen($input['password']) > 200) {
                respond(422, ['error' => 'password_too_short']);
            }
            $fields[] = 'password_hash = ?';
            $values[] = password_hash($input['password'], PASSWORD_DEFAULT);
        }
        if (array_key_exists('is_active', $input)) {
            if (!is_bool($input['is_active'])) {
                respond(422, ['error' => 'invalid_active_flag']);
            }
            $fields[] = 'is_active = ?';
            $values[] = $input['is_active'] ? 1 : 0;
            if (!$input['is_active']) {
                $revoke = $db->prepare('DELETE FROM api_tokens WHERE user_id = ?');
                $revoke->execute([$parts[2]]);
            }
        }
        if ($fields !== []) {
            $values[] = $parts[2];
            $update = $db->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?');
            $update->execute($values);
        }
        if (isset($input['password'])) {
            $revoke = $db->prepare('DELETE FROM api_tokens WHERE user_id = ?');
            $revoke->execute([$parts[2]]);
        }
        respond(200, ['data' => ['id' => $parts[2], 'role' => $role, 'permissions' => array_values(array_unique($permissions)), 'is_active' => (bool) ($input['is_active'] ?? $existing['is_active'])]]);
    }

    if ($resource === 'admin/integrations' && $method === 'GET') {
        require_permission($config, 'integrations.view');
        $midtrans = midtrans_settings($config);
        $biteship = biteship_settings($config);
        respond(200, ['data' => [
            'midtrans' => [
                'enabled' => $midtrans['enabled'],
                'environment' => $midtrans['environment'],
                'client_key_configured' => $midtrans['client_key'] !== '',
                'server_key_configured' => $midtrans['server_key'] !== '',
            ],
            'biteship' => [
                'enabled' => $biteship['enabled'],
                'api_key_configured' => $biteship['api_key'] !== '',
                'warehouse_name' => $biteship['warehouse_name'],
                'origin_area_id' => $biteship['origin_area_id'],
                'couriers' => $biteship['couriers'],
                'weight_grams' => $biteship['weight_grams'],
                'package_length_cm' => $biteship['package_length_cm'],
                'package_width_cm' => $biteship['package_width_cm'],
                'package_height_cm' => $biteship['package_height_cm'],
            ],
        ]]);
    }

    if ($resource === 'admin/integrations' && $method === 'PATCH') {
        require_permission($config, 'integrations.manage');
        $input = read_json_body();
        $updated = $config;
        if (isset($input['midtrans_environment'])) {
            if (!in_array($input['midtrans_environment'], ['sandbox', 'production'], true)) {
                respond(422, ['error' => 'invalid_midtrans_environment']);
            }
            $updated['midtrans_environment'] = $input['midtrans_environment'];
        }
        foreach (['midtrans_client_key' => 200, 'midtrans_server_key' => 255, 'biteship_api_key' => 255] as $key => $maximumLength) {
            if (isset($input[$key]) && (!is_string($input[$key]) || strlen($input[$key]) > $maximumLength)) {
                respond(422, ['error' => 'invalid_provider_credential']);
            }
            if (isset($input[$key]) && $input[$key] !== '') {
                $updated[$key] = $input[$key];
            }
        }
        foreach (['clear_midtrans' => ['midtrans_client_key', 'midtrans_server_key'], 'clear_biteship' => ['biteship_api_key']] as $flag => $keys) {
            if (isset($input[$flag]) && !is_bool($input[$flag])) {
                respond(422, ['error' => 'invalid_provider_action']);
            }
            if (!empty($input[$flag])) {
                foreach ($keys as $key) {
                    $updated[$key] = '';
                }
            }
        }
        if (isset($input['warehouse_name'])) {
            $warehouseName = trim((string) $input['warehouse_name']);
            if ($warehouseName === '' || strlen($warehouseName) > 80) {
                respond(422, ['error' => 'invalid_warehouse_name']);
            }
            $updated['warehouse_name'] = $warehouseName;
        }
        if (isset($input['biteship_origin_area_id'])) {
            if (!is_string($input['biteship_origin_area_id']) || strlen($input['biteship_origin_area_id']) > 128 || !preg_match('/^[a-zA-Z0-9_-]*$/', $input['biteship_origin_area_id'])) {
                respond(422, ['error' => 'invalid_origin_area_id']);
            }
            $updated['biteship_origin_area_id'] = trim($input['biteship_origin_area_id']);
        }
        if (isset($input['biteship_couriers'])) {
            if (!is_string($input['biteship_couriers']) || !preg_match('/^[a-z0-9,]{1,160}$/', $input['biteship_couriers'])) {
                respond(422, ['error' => 'invalid_courier_list']);
            }
            $updated['biteship_couriers'] = $input['biteship_couriers'];
        }
        foreach (['default_weight_grams' => [100, 30000], 'default_package_length_cm' => [1, 200], 'default_package_width_cm' => [1, 200], 'default_package_height_cm' => [1, 200]] as $field => [$minimum, $maximum]) {
            if (isset($input[$field])) {
                $value = filter_var($input[$field], FILTER_VALIDATE_INT);
                if ($value === false || $value < $minimum || $value > $maximum) {
                    respond(422, ['error' => 'invalid_package_setting']);
                }
                $updated[$field] = $value;
            }
        }
        try {
            save_local_config($updated);
        } catch (RuntimeException $error) {
            error_log('Provider configuration update failed: ' . $error->getMessage());
            respond(500, ['error' => $error->getMessage()]);
        }
        $midtrans = midtrans_settings($updated);
        $biteship = biteship_settings($updated);
        respond(200, ['data' => [
            'midtrans' => ['enabled' => $midtrans['enabled'], 'environment' => $midtrans['environment'], 'client_key_configured' => $midtrans['client_key'] !== '', 'server_key_configured' => $midtrans['server_key'] !== ''],
            'biteship' => ['enabled' => $biteship['enabled'], 'api_key_configured' => $biteship['api_key'] !== '', 'warehouse_name' => $biteship['warehouse_name'], 'origin_area_id' => $biteship['origin_area_id'], 'couriers' => $biteship['couriers'], 'weight_grams' => $biteship['weight_grams'], 'package_length_cm' => $biteship['package_length_cm'], 'package_width_cm' => $biteship['package_width_cm'], 'package_height_cm' => $biteship['package_height_cm']],
        ]]);
    }

    if ($method === 'GET' && $resource === 'admin/summary') {
        require_permission($config, 'dashboard.view');
        $orderCounts = $db->query('SELECT COUNT(*) AS total, SUM(CASE WHEN status = \'pending\' THEN 1 ELSE 0 END) AS pending, SUM(CASE WHEN payment_status = \'paid\' THEN 1 ELSE 0 END) AS paid, SUM(CASE WHEN status IN (\'processing\', \'shipped\') THEN 1 ELSE 0 END) AS fulfilling, SUM(CASE WHEN status = \'cancelled\' THEN 1 ELSE 0 END) AS cancelled, COALESCE(SUM(CASE WHEN payment_status = \'paid\' THEN total_amount ELSE 0 END), 0) AS revenue FROM orders')->fetch();
        $lowStock = 0;
        foreach ($db->query("SELECT source_json FROM products WHERE status = 'active'")->fetchAll() as $row) {
            $product = json_decode($row['source_json'], true, 512, JSON_THROW_ON_ERROR);
            foreach (($product['skus'] ?? []) as $sku) {
                if (!empty($sku['is_active']) && empty($sku['is_unlimited_stock']) && (int) ($sku['stock'] ?? 0) <= 5) {
                    $lowStock++;
                }
            }
        }
        respond(200, ['data' => [
            'orders' => [
                'total' => (int) ($orderCounts['total'] ?? 0),
                'pending' => (int) ($orderCounts['pending'] ?? 0),
                'paid' => (int) ($orderCounts['paid'] ?? 0),
                'fulfilling' => (int) ($orderCounts['fulfilling'] ?? 0),
                'cancelled' => (int) ($orderCounts['cancelled'] ?? 0),
            ],
            'revenue' => (int) ($orderCounts['revenue'] ?? 0),
            'active_products' => (int) $db->query("SELECT COUNT(*) FROM products WHERE status = 'active'")->fetchColumn(),
            'low_stock_skus' => $lowStock,
        ]]);
    }

    if (count($parts) >= 2 && $parts[0] === 'admin' && $parts[1] === 'orders') {
        if ($method === 'GET' && count($parts) === 2) {
            require_permission($config, 'orders.view');
            $orders = $db->query('SELECT id, order_number, customer_name, phone, email, shipping_address, payment_method, status, payment_status, shipping_amount, shipping_area_name, shipping_company, shipping_service, total_amount, created_at FROM orders ORDER BY created_at DESC LIMIT 100')->fetchAll();
            $items = $db->query('SELECT order_id, sku_code, product_name, quantity, unit_price, subtotal FROM order_items ORDER BY id')->fetchAll();
            $itemsByOrder = [];
            foreach ($items as $item) {
                $itemsByOrder[$item['order_id']][] = array_diff_key($item, ['order_id' => true]);
            }
            foreach ($orders as &$order) {
                $order['items'] = $itemsByOrder[$order['id']] ?? [];
                unset($order['id']);
            }
            unset($order);
            respond(200, ['data' => $orders]);
        }
        if ($method === 'PATCH' && count($parts) === 3) {
            require_admin($config);
            $input = read_json_body();
            $status = (string) ($input['status'] ?? '');
            $canManage = has_permission('orders.manage');
            $canShip = has_permission('orders.ship') && in_array($status, ['processing', 'shipped', 'completed'], true);
            if (!$canManage && !$canShip) {
                respond(403, ['error' => 'forbidden', 'required_permission' => 'orders.manage']);
            }
            $transitions = [
                'pending' => ['confirmed', 'cancelled'],
                'confirmed' => ['processing', 'cancelled'],
                'processing' => ['shipped', 'cancelled'],
                'shipped' => ['completed'],
                'completed' => [],
                'cancelled' => [],
            ];
            if (!in_array($status, ['confirmed', 'processing', 'shipped', 'completed', 'cancelled'], true)) {
                respond(422, ['error' => 'invalid_order_status']);
            }
            $db->beginTransaction();
            $orderQuery = $db->prepare('SELECT id, status FROM orders WHERE order_number = ?');
            $orderQuery->execute([$parts[2]]);
            $order = $orderQuery->fetch();
            if ($order === false) {
                $db->rollBack();
                respond(404, ['error' => 'order_not_found']);
            }
            if ($order['status'] !== $status && !in_array($status, $transitions[$order['status']] ?? [], true)) {
                $db->rollBack();
                respond(409, ['error' => 'invalid_status_transition']);
            }
            if ($status === 'cancelled' && $order['status'] !== 'cancelled') {
                $orderItemQuery = $db->prepare('SELECT product_id, sku_id, quantity FROM order_items WHERE order_id = ?');
                $orderItemQuery->execute([$order['id']]);
                $productQuery = $db->prepare('SELECT source_json FROM products WHERE id = ?');
                foreach ($orderItemQuery->fetchAll() as $orderItem) {
                    $productQuery->execute([(int) $orderItem['product_id']]);
                    $rawProduct = $productQuery->fetchColumn();
                    if ($rawProduct === false) {
                        continue;
                    }
                    $product = json_decode($rawProduct, true, 512, JSON_THROW_ON_ERROR);
                    foreach ($product['skus'] as &$sku) {
                        if ((string) $sku['id'] === (string) $orderItem['sku_id'] && empty($sku['is_unlimited_stock'])) {
                            $sku['stock'] = (int) ($sku['stock'] ?? 0) + (int) $orderItem['quantity'];
                            break;
                        }
                    }
                    unset($sku);
                    persist_product($db, $product);
                }
            }
            $statement = $db->prepare('UPDATE orders SET status = ? WHERE id = ?');
            $statement->execute([$status, $order['id']]);
            $db->commit();
            respond(200, ['data' => ['order_number' => $parts[2], 'status' => $status]]);
        }
    }

    respond(404, ['error' => 'not_found']);
} catch (Throwable $error) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log((string) $error);
    respond(500, ['error' => 'internal_server_error']);
}