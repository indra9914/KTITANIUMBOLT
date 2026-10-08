# KTITANIUMBOLT

Static snapshot of the public website at [ktitaniumbolt.com](https://ktitaniumbolt.com/).

## Run locally

From this directory, start a static file server:

```sh
python3 -m http.server 8000
```

Then open <http://localhost:8000>.

The snapshot includes the 105 public pages listed in the source sitemap, along with their downloaded images, stylesheets, and scripts. The homepage, `/katalog`, `/checkout`, `/pesanan`, and `/admin` now use local routes. Product images and the WhatsApp contact link still use the original public host.

## Backend API

The PHP 8 API uses SQLite and seeds its catalog with the 96 public products in `backend/data/products.json` on the first request. PHP must have PDO SQLite and cURL enabled.

Copy `backend/config.example.php` to `backend/config.local.php` and replace the placeholder with a long random `admin_token`. Keep that token private. The local config and SQLite database are excluded from Git. On Hostinger, make sure the PHP process can write to `backend/storage/`.

Run locally with:

```sh
php -S localhost:8001 -t . backend/dev-router.php
```

Endpoints:

- `GET /api/v1/health`
- `GET /api/v1/config` reports whether payment and shipping providers are configured; secret keys are never returned.
- `GET /api/v1/products?q=bolt&sort=sold_desc&per_page=24&page=1`
- `GET /api/v1/products/{slug}`
- `GET /api/v1/shipping/areas?q=Bogor` and `POST /api/v1/shipping/rates` proxy Biteship area lookup and courier quotes.
- `POST /api/v1/orders` creates a pending payment order. Send customer details, selected destination area/courier, and `items` containing `product_id`, `sku_id`, and `quantity`. The API re-quotes shipping and calculates prices from the server-side catalog.
- `POST /api/v1/payments/midtrans/notification` is the Midtrans payment webhook. It validates the notification signature and amount.
- `PATCH /api/v1/admin/products/{slug}` with the admin token and `{"sku_updates":[{"sku_id":223,"base_price":25000,"stock":12,"is_unlimited_stock":false}]}` to update SKU price/stock.
- `GET /api/v1/admin/orders` with `Authorization: Bearer <admin_token>`
- `PATCH /api/v1/admin/orders/{order_number}` with `{"status":"confirmed"}` and the admin token. Allowed transitions are pending → confirmed → processing → shipped → completed; pending, confirmed, or processing orders can also be cancelled, which restores reserved stock.
- `POST /api/v1/auth/login` accepts either the bootstrap `admin_token` or an individual `username`/`password`; user sessions expire after 12 hours.
- `GET /api/v1/admin/users`, `POST /api/v1/admin/users`, and `PATCH /api/v1/admin/users/{id}` manage accounts and permission sets. All require `users.manage`.

## Admin and provider setup

Open `https://v4.indoatlmart.com/admin`. In the Hostinger SSH terminal, retrieve the existing admin token directly there, then paste it into the login form. Do not send the token in chat or commit `backend/config.local.php`:

```sh
php -r '$c=require "/home/u851033464/domains/v4.indoatlmart.com/public_html/backend/config.local.php"; echo $c["admin_token"], PHP_EOL;'
```

Use that bootstrap token to create named accounts in **Pengguna & Hak Akses**. Available role templates are Administrator, Manager Toko, Admin Toko, and Staff Gudang; the Administrator can customize each account's permission checkboxes. Manager Toko handles orders, prices, and inventory; Admin Toko handles day-to-day orders/inventory; Staff Gudang can view orders and update stock/shipping, but cannot change prices, payment settings, or users. Passwords are stored as hashes, API sessions expire after 12 hours, and permissions are enforced by the API. Deactivating an account revokes its active sessions.

Automatic checkout stays disabled until merchant credentials are added to `backend/config.local.php` on the server. Add Midtrans sandbox `midtrans_client_key`, `midtrans_server_key`, and `midtrans_environment => 'sandbox'`; set the Midtrans payment notification URL to `https://v4.indoatlmart.com/api/v1/payments/midtrans/notification`. After testing, replace the keys and environment with production credentials.

For shipping, the admin settings name the warehouse **Prepedan**; enter its actual Biteship Warehouse Origin Area ID and `biteship_api_key` in the **Pengiriman** tab. The ID is account-specific and is not present in the current configuration, so the field is deliberately blank until the real Biteship value is supplied. Optionally adjust `biteship_couriers`. Catalog products do not include parcel weights, so `default_weight_grams` (500 by default) and package dimensions are estimates; adjust them for the actual packed products. Until valid keys and the warehouse area ID are entered, payment and courier requests remain disabled.

The product catalog is a one-time public snapshot; it does not sync inventory changes from `ktitaniumbolt.com`. Customer accounts, shipment tracking, and automatic inventory sync are not included.
