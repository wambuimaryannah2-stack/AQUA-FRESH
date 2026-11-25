# AQUAR Fresh — PHP + MySQL version

## Run with XAMPP

1. Copy the `AQUAR-Fresh-Website` folder into `C:\\xampp\\htdocs`.
2. Start **Apache** and **MySQL** in XAMPP.
3. Keep your existing MySQL database named `aquar_fresh` and its `users`, `products`, `orders`, and `order_items` tables.
4. Open `http://localhost/AQUAR-Fresh-Website/`.
5. Test the PHP-to-MySQL connection at `http://localhost/AQUAR-Fresh-Website/API/test.php`.
6. Open `http://localhost/AQUAR-Fresh-Website/pages/login.html` to log in.

**Do not run `backend/app.py` or use port 5000.** This version uses PHP through Apache.

## API routes

The browser pages now call `API/index.php` using the `route` query parameter. It supports registration, login, products, account, customer orders, admin orders and statistics, delivery order updates, and the Daraja STK/callback endpoints.

## Database assumptions

The PHP API uses the existing schema:
- `users`: `id`, `name`, `email`, `phone`, `password_hash`, `role`, `created_at`
- `products`: `id`, `name`, `size`, `price`, `description`, `active`
- `orders`: `id`, `order_number`, `customer_id`, `customer_name`, `phone`, `address`, `payment_method`, `payment_status`, `status`, `total`, `mpesa_checkout_request_id`, `mpesa_merchant_request_id`, `mpesa_receipt_number`, `created_at`
- `order_items`: `id`, `order_id`, `product_id`, `product_name`, `quantity`, `unit_price`

New registrations are saved with role `customer`. Existing admin and delivery accounts keep their database roles. Existing Flask/Bcrypt password hashes are verified with PHP's bcrypt password verification.

## M-Pesa setup

The STK Push and callback PHP routes are included, but they need Daraja credentials and a publicly reachable HTTPS callback URL. Set these server environment variables before testing payments:
- `DARAJA_ENV` (`sandbox` or `production`)
- `DARAJA_CONSUMER_KEY`
- `DARAJA_CONSUMER_SECRET`
- `DARAJA_SHORTCODE`
- `DARAJA_PASSKEY`
- `DARAJA_CALLBACK_URL`

The PHP cURL extension must also be enabled in XAMPP. Do not put live credentials in a public repository.

## Security note

This is a local development build. Before public deployment, set a private `AQUAR_JWT_SECRET`, use HTTPS, configure a non-root MySQL user, and apply production security hardening.
