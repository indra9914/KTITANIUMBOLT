<?php
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($path === '/api/v1' || str_starts_with($path, '/api/v1/')) {
    require __DIR__ . '/index.php';
    return true;
}
if (preg_match('#^/backend/(?:data|storage)(?:/|$)|^/backend/config\.local\.php$#', $path)) {
    http_response_code(404);
    return true;
}
if ($path === '/admin' || $path === '/admin/') {
    require __DIR__ . '/../admin/index.html';
    return true;
}
if ($path === '/katalog' || $path === '/katalog/' || $path === '/katalog.html') {
    require __DIR__ . '/../storefront/catalog.html';
    return true;
}
if (str_starts_with($path, '/katalog/')) {
    $_GET['slug'] = preg_replace('/\.html$/', '', rawurldecode(substr($path, strlen('/katalog/'))));
    require __DIR__ . '/../storefront/product.html';
    return true;
}
if ($path === '/checkout' || $path === '/checkout/') {
    require __DIR__ . '/../storefront/checkout.html';
    return true;
}
if ($path === '/pesanan' || $path === '/pesanan/') {
    require __DIR__ . '/../storefront/order.html';
    return true;
}
if ($path === '/') {
    return false;
}

$file = realpath(__DIR__ . '/..' . $path);
$root = realpath(__DIR__ . '/..');
if ($file !== false && $root !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}
http_response_code(404);
return true;