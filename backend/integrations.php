<?php
declare(strict_types=1);

function provider_request(string $url, string $apiKey, string $method = 'GET', ?array $payload = null): array
{
    $headers = ['Accept: application/json', 'Authorization: Bearer ' . $apiKey];
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($payload !== null) {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        $headers[] = 'Content-Type: application/json';
        curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
    }
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);
    if (!is_string($response) || $status < 200 || $status >= 300) {
        error_log('Shipping/payment provider request failed: ' . ($error !== '' ? $error : 'HTTP ' . $status));
        throw new RuntimeException('provider_unavailable');
    }
    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('provider_invalid_response');
    }
    return $decoded;
}

function midtrans_settings(array $config): array
{
    $environment = (getenv('MIDTRANS_ENVIRONMENT') ?: ($config['midtrans_environment'] ?? 'sandbox')) === 'production' ? 'production' : 'sandbox';
    $serverKey = (string) (getenv('MIDTRANS_SERVER_KEY') ?: ($config['midtrans_server_key'] ?? ''));
    $clientKey = (string) (getenv('MIDTRANS_CLIENT_KEY') ?: ($config['midtrans_client_key'] ?? ''));
    return [
        'enabled' => $serverKey !== '' && $clientKey !== '',
        'client_key' => $clientKey,
        'server_key' => $serverKey,
        'environment' => $environment,
        'snap_url' => $environment === 'production' ? 'https://app.midtrans.com/snap/snap.js' : 'https://app.sandbox.midtrans.com/snap/snap.js',
        'transaction_url' => $environment === 'production' ? 'https://app.midtrans.com/snap/v1/transactions' : 'https://app.sandbox.midtrans.com/snap/v1/transactions',
    ];
}

function biteship_settings(array $config): array
{
    $apiKey = (string) (getenv('BITESHIP_API_KEY') ?: ($config['biteship_api_key'] ?? ''));
    $originAreaId = (string) (getenv('BITESHIP_ORIGIN_AREA_ID') ?: ($config['biteship_origin_area_id'] ?? ''));
    return [
        'enabled' => $apiKey !== '' && $originAreaId !== '',
        'api_key' => $apiKey,
        'warehouse_name' => (string) ($config['warehouse_name'] ?? 'Prepedan'),
        'origin_area_id' => $originAreaId,
        'couriers' => (string) ($config['biteship_couriers'] ?? 'jne,jnt,sicepat,anteraja,pos,tiki'),
        'weight_grams' => max(100, (int) ($config['default_weight_grams'] ?? 500)),
        'package_length_cm' => max(1, (int) ($config['default_package_length_cm'] ?? 10)),
        'package_width_cm' => max(1, (int) ($config['default_package_width_cm'] ?? 10)),
        'package_height_cm' => max(1, (int) ($config['default_package_height_cm'] ?? 5)),
    ];
}

function shipping_areas(array $config, string $query): array
{
    $settings = biteship_settings($config);
    if (!$settings['enabled']) {
        throw new RuntimeException('shipping_not_configured');
    }
    $url = 'https://api.biteship.com/v1/maps/areas?' . http_build_query([
        'countries' => 'ID', 'input' => $query, 'type' => 'single',
    ]);
    $response = provider_request($url, $settings['api_key']);
    $areas = $response['areas'] ?? $response['data'] ?? [];
    return array_values(array_filter(array_map(static function (array $area): ?array {
        $id = $area['id'] ?? $area['area_id'] ?? null;
        if (!is_string($id) || $id === '') {
            return null;
        }
        $name = array_filter([
            $area['administrative_division_level_3_name'] ?? null,
            $area['administrative_division_level_2_name'] ?? null,
            $area['administrative_division_level_1_name'] ?? null,
        ]);
        return [
            'id' => $id,
            'name' => implode(', ', $name) ?: (string) ($area['name'] ?? $area['label'] ?? $id),
            'postal_code' => (string) ($area['postal_code'] ?? ''),
        ];
    }, is_array($areas) ? $areas : [])));
}

function quote_shipping(array $config, string $destinationAreaId, array $items): array
{
    $settings = biteship_settings($config);
    if (!$settings['enabled']) {
        throw new RuntimeException('shipping_not_configured');
    }
    $biteshipItems = [];
    foreach ($items as $item) {
        $quantity = max(1, (int) ($item['quantity'] ?? 1));
        $biteshipItems[] = [
            'name' => (string) ($item['product_name'] ?? $item['name'] ?? 'Produk titanium'),
            'description' => (string) ($item['sku_code'] ?? 'K-Titanium Bolt'),
            'value' => max(1, (int) ($item['unit_price'] ?? $item['price'] ?? 1)),
            'length' => max(1, (int) ($config['default_package_length_cm'] ?? 10)),
            'width' => max(1, (int) ($config['default_package_width_cm'] ?? 10)),
            'height' => max(1, (int) ($config['default_package_height_cm'] ?? 5)),
            'weight' => $settings['weight_grams'],
            'quantity' => $quantity,
        ];
    }
    $response = provider_request('https://api.biteship.com/v1/rates/couriers', $settings['api_key'], 'POST', [
        'origin_area_id' => $settings['origin_area_id'],
        'destination_area_id' => $destinationAreaId,
        'couriers' => $settings['couriers'],
        'items' => $biteshipItems,
    ]);
    $pricing = $response['pricing'] ?? $response['data'] ?? [];
    return array_values(array_filter(array_map(static function (array $rate): ?array {
        $companyCode = (string) ($rate['courier_code'] ?? $rate['company_code'] ?? '');
        $serviceCode = (string) ($rate['courier_service_code'] ?? $rate['service_code'] ?? '');
        $price = filter_var($rate['price'] ?? null, FILTER_VALIDATE_INT);
        if ($companyCode === '' || $serviceCode === '' || $price === false || $price < 0) {
            return null;
        }
        return [
            'company_code' => $companyCode,
            'company' => (string) ($rate['courier_name'] ?? $rate['courier_company'] ?? strtoupper($companyCode)),
            'service_code' => $serviceCode,
            'service' => (string) ($rate['courier_service_name'] ?? $rate['service_name'] ?? $serviceCode),
            'price' => $price,
            'duration' => (string) ($rate['duration'] ?? $rate['shipment_duration_range'] ?? ''),
        ];
    }, is_array($pricing) ? $pricing : [])));
}

function create_midtrans_transaction(array $config, array $order, array $items): array
{
    $settings = midtrans_settings($config);
    if (!$settings['enabled']) {
        throw new RuntimeException('payment_not_configured');
    }
    $lineItems = array_map(static fn (array $item): array => [
        'id' => (string) ($item['sku_code'] ?: $item['sku_id']),
        'price' => (int) $item['unit_price'],
        'quantity' => (int) $item['quantity'],
        'name' => mb_substr((string) $item['product_name'], 0, 50),
    ], $items);
    if ((int) $order['shipping_amount'] > 0) {
        $lineItems[] = [
            'id' => 'SHIPPING', 'price' => (int) $order['shipping_amount'],
            'quantity' => 1, 'name' => mb_substr('Ongkir ' . $order['shipping_service'], 0, 50),
        ];
    }
    $payload = [
        'transaction_details' => ['order_id' => $order['order_number'], 'gross_amount' => (int) $order['total_amount']],
        'item_details' => $lineItems,
        'customer_details' => [
            'first_name' => mb_substr((string) $order['customer_name'], 0, 40),
            'email' => (string) $order['email'], 'phone' => (string) $order['phone'],
            'billing_address' => ['address' => mb_substr((string) $order['shipping_address'], 0, 200)],
            'shipping_address' => ['address' => mb_substr((string) $order['shipping_address'], 0, 200)],
        ],
        'callbacks' => ['finish' => 'https://v4.indoatlmart.com/pesanan?order=' . rawurlencode((string) $order['order_number'])],
    ];
    $handle = curl_init($settings['transaction_url']);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => [
            'Accept: application/json', 'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode($settings['server_key'] . ':'),
        ],
        CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);
    $decoded = is_string($response) ? json_decode($response, true) : null;
    if ($status < 200 || $status >= 300 || !is_array($decoded) || empty($decoded['token'])) {
        error_log('Midtrans Snap request failed: ' . ($error !== '' ? $error : 'HTTP ' . $status));
        throw new RuntimeException('payment_provider_unavailable');
    }
    return ['token' => (string) $decoded['token'], 'redirect_url' => (string) ($decoded['redirect_url'] ?? '')];
}
