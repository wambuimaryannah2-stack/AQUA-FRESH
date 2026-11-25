<?php
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function body_json(): array {
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}
function b64url(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}
function make_token(array $user): string {
    $header = b64url(json_encode(['typ'=>'JWT','alg'=>'HS256']));
    $payload = b64url(json_encode([
        'sub'=>(string)$user['id'], 'role'=>$user['role'],
        'name'=>$user['name'], 'iat'=>time(), 'exp'=>time()+86400
    ]));
    $signature = b64url(hash_hmac('sha256', "$header.$payload", AQUAR_JWT_SECRET, true));
    return "$header.$payload.$signature";
}
function current_user(): array {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!$header && function_exists('getallheaders')) {
        $headers = getallheaders();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    if (!preg_match('/Bearer\s+(.+)/i', $header, $m)) respond(['message'=>'Please log in again.'], 401);
    $parts = explode('.', trim($m[1]));
    if (count($parts) !== 3) respond(['message'=>'Invalid login token.'], 401);
    [$h,$p,$s] = $parts;
    $expected = b64url(hash_hmac('sha256', "$h.$p", AQUAR_JWT_SECRET, true));
    if (!hash_equals($expected, $s)) respond(['message'=>'Invalid login token.'], 401);
    $payload = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
    if (!is_array($payload) || empty($payload['sub']) || ($payload['exp'] ?? 0) < time()) {
        respond(['message'=>'Your session has expired. Please log in again.'], 401);
    }
    return ['id'=>(int)$payload['sub'], 'role'=>$payload['role'] ?? 'customer', 'name'=>$payload['name'] ?? ''];
}
function require_roles(array $roles): array {
    $user = current_user();
    if (!in_array($user['role'], $roles, true)) respond(['message'=>'You do not have permission to perform this action.'], 403);
    return $user;
}
function get_orders(string $where = '', array $params = []): array {
    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT o.id,o.order_number,o.customer_id,o.customer_name,o.phone,o.address,
                o.payment_method,o.payment_status,o.status,o.total,o.mpesa_receipt_number,o.created_at
         FROM orders o $where ORDER BY o.created_at DESC"
    );
    $stmt->execute($params);
    $orders = $stmt->fetchAll();
    $itemsStmt = $pdo->prepare(
        "SELECT product_id,product_name,quantity,unit_price FROM order_items WHERE order_id=?"
    );
    foreach ($orders as &$order) {
        $order['id'] = (int)$order['id'];
        $order['total'] = (float)$order['total'];
        if (!empty($order['created_at'])) $order['created_at'] = date('c', strtotime($order['created_at']));
        $itemsStmt->execute([$order['id']]);
        $order['items'] = $itemsStmt->fetchAll();
    }
    return $orders;
}
function daraja_base(): string {
    return strtolower(DARAJA_ENV) === 'production'
        ? 'https://api.safaricom.co.ke' : 'https://sandbox.safaricom.co.ke';
}
function daraja_token(): string {
    if (!DARAJA_CONSUMER_KEY || !DARAJA_CONSUMER_SECRET) {
        throw new RuntimeException('Configure the Daraja consumer key and secret in the PHP server environment first.');
    }
    $ch = curl_init(daraja_base() . '/oauth/v1/generate?grant_type=client_credentials');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_USERPWD=>DARAJA_CONSUMER_KEY . ':' . DARAJA_CONSUMER_SECRET,
        CURLOPT_HTTPAUTH=>CURLAUTH_BASIC, CURLOPT_TIMEOUT=>30
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) throw new RuntimeException('Daraja connection failed: ' . curl_error($ch));
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($raw, true);
    if ($status >= 400 || empty($data['access_token'])) throw new RuntimeException('Could not obtain a Daraja access token.');
    return $data['access_token'];
}
function normalize_phone(string $phone): string {
    $phone = preg_replace('/[\s\-+]/', '', $phone);
    if (strlen($phone) === 10 && $phone[0] === '0') $phone = '254' . substr($phone, 1);
    if (strlen($phone) === 9 && $phone[0] === '7') $phone = '254' . $phone;
    if (!preg_match('/^254\d{9}$/', $phone)) throw new RuntimeException('Enter a valid Kenyan M-Pesa number.');
    return $phone;
}

$route = trim($_GET['route'] ?? '', '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$data = body_json();

try {
    if ($route === '' || $route === 'health') {
        db()->query('SELECT 1');
        respond(['message'=>'Welcome to AQUAR Fresh PHP API','status'=>'running','database'=>'connected']);
    }

    if ($route === 'register' && $method === 'POST') {
        $name = trim($data['name'] ?? '');
        $email = strtolower(trim($data['email'] ?? ''));
        $phone = trim($data['phone'] ?? '');
        $password = $data['password'] ?? '';
        if (!$name || !$email || !$phone || !$password) respond(['message'=>'All fields are required.'], 400);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['message'=>'Enter a valid email address.'], 400);
        if (strlen($password) < 8) respond(['message'=>'Password must be at least 8 characters.'], 400);
        $pdo = db();
        $check = $pdo->prepare('SELECT id FROM users WHERE email=?');
        $check->execute([$email]);
        if ($check->fetch()) respond(['message'=>'An account with this email already exists.'], 409);
        $insert = $pdo->prepare("INSERT INTO users (name,email,phone,password_hash,role) VALUES (?,?,?,?, 'customer')");
        $insert->execute([$name,$email,$phone,password_hash($password, PASSWORD_BCRYPT)]);
        respond(['message'=>'Account created successfully.'], 201);
    }

    if ($route === 'login' && $method === 'POST') {
        $email = strtolower(trim($data['email'] ?? ''));
        $password = $data['password'] ?? '';
        if (!$email || !$password) respond(['message'=>'Email and password are required.'], 400);
        $stmt = db()->prepare('SELECT id,name,email,phone,password_hash,role FROM users WHERE email=? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            respond(['message'=>'Invalid email or password.'], 401);
        }
        $userData = [
            'id'=>(int)$user['id'], 'name'=>$user['name'], 'email'=>$user['email'],
            'phone'=>$user['phone'], 'role'=>$user['role'] ?: 'customer'
        ];
        $token = make_token($userData);
        respond(['message'=>'Login successful','token'=>$token,'access_token'=>$token,'user'=>$userData]);
    }

    if ($route === 'products' && $method === 'GET') {
        $rows = db()->query('SELECT id,name,size,price,description FROM products WHERE active=1 ORDER BY id')->fetchAll();
        foreach ($rows as &$r) { $r['id']=(int)$r['id']; $r['price']=(float)$r['price']; }
        respond($rows);
    }

    if ($route === 'account' && $method === 'GET') {
        $user = current_user();
        $stmt = db()->prepare('SELECT id,name,email,phone,role,created_at FROM users WHERE id=?');
        $stmt->execute([$user['id']]);
        $record = $stmt->fetch();
        if (!$record) respond(['message'=>'User account not found.'], 404);
        respond(['user'=>$record]);
    }

    if ($route === 'orders' && $method === 'POST') {
        $user = current_user();
        $items = $data['items'] ?? [];
        $address = trim($data['address'] ?? '');
        $payment = strtolower(trim($data['payment_method'] ?? 'cash'));
        if (!is_array($items) || !$items) respond(['message'=>'Your cart is empty.'], 400);
        if (!$address) respond(['message'=>'Delivery address is required.'], 400);
        if (!in_array($payment, ['cash','m-pesa','mpesa'], true)) respond(['message'=>'Choose Cash or M-Pesa.'], 400);
        $pdo = db();
        $customerStmt = $pdo->prepare('SELECT name,phone FROM users WHERE id=?');
        $customerStmt->execute([$user['id']]);
        $customer = $customerStmt->fetch();
        if (!$customer) respond(['message'=>'Customer account not found.'], 404);
        if (!$customer['phone']) respond(['message'=>'Add a phone number to your account before ordering.'], 400);

        $pdo->beginTransaction();
        $clean = []; $total = 0.0;
        $productStmt = $pdo->prepare('SELECT id,name,price FROM products WHERE id=? AND active=1');
        foreach ($items as $item) {
            $pid = (int)($item['product_id'] ?? 0);
            $qty = (int)($item['quantity'] ?? 0);
            if ($pid < 1 || $qty < 1) { $pdo->rollBack(); respond(['message'=>'Each item needs a valid product and quantity.'], 400); }
            $productStmt->execute([$pid]);
            $product = $productStmt->fetch();
            if (!$product) { $pdo->rollBack(); respond(['message'=>"Product $pid is unavailable."], 400); }
            $price = (float)$product['price']; $total += $price * $qty;
            $clean[] = ['product_id'=>(int)$product['id'],'product_name'=>$product['name'],'quantity'=>$qty,'unit_price'=>$price];
        }
        $orderNo = 'AQ-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $paymentLabel = in_array($payment,['m-pesa','mpesa'],true) ? 'M-Pesa' : 'Cash';
        $ins = $pdo->prepare("INSERT INTO orders (order_number,customer_id,customer_name,phone,address,payment_method,payment_status,status,total) VALUES (?,?,?,?,?,?, 'Pending','Pending',?)");
        $ins->execute([$orderNo,$user['id'],$customer['name'],$customer['phone'],$address,$paymentLabel,round($total,2)]);
        $orderId = (int)$pdo->lastInsertId();
        $itemIns = $pdo->prepare('INSERT INTO order_items (order_id,product_id,product_name,quantity,unit_price) VALUES (?,?,?,?,?)');
        foreach ($clean as $item) $itemIns->execute([$orderId,$item['product_id'],$item['product_name'],$item['quantity'],$item['unit_price']]);
        $pdo->commit();
        respond(['message'=>'Order placed successfully','id'=>$orderId,'order_number'=>$orderNo,'total'=>round($total,2),'payment_method'=>$paymentLabel,'items'=>$clean], 201);
    }

    if ($route === 'my-orders' && $method === 'GET') {
        $user = current_user();
        respond(get_orders('WHERE o.customer_id=?', [$user['id']]));
    }

    if ($route === 'admin/orders' && $method === 'GET') {
        require_roles(['admin']);
        respond(get_orders());
    }

    if ($route === 'admin/dashboard-stats' && $method === 'GET') {
        require_roles(['admin']);
        $pdo = db();
        $total = (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
        $completed = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status='Paid'")->fetchColumn();
        $active = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status NOT IN ('Delivered','Cancelled')")->fetchColumn();
        $delivered = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='Delivered'")->fetchColumn();
        respond(['total_orders'=>$total,'completed_sales'=>$completed,'active_orders'=>$active,'delivered_orders'=>$delivered]);
    }

    if (preg_match('#^admin/orders/(\d+)/status$#', $route, $m) && $method === 'PUT') {
        require_roles(['admin']);
        $status = trim($data['status'] ?? '');
        $allowed = ['Pending','Confirmed','Preparing','Processing','Out for Delivery','Delivered','Cancelled'];
        if (!in_array($status,$allowed,true)) respond(['message'=>'Invalid order status.'], 400);
        $stmt = db()->prepare('UPDATE orders SET status=? WHERE id=?');
        $stmt->execute([$status,(int)$m[1]]);
        if (!$stmt->rowCount()) {
            $check = db()->prepare('SELECT id FROM orders WHERE id=?'); $check->execute([(int)$m[1]]);
            if (!$check->fetch()) respond(['message'=>'Order not found.'], 404);
        }
        respond(['message'=>'Order status updated.','status'=>$status]);
    }

    if ($route === 'delivery/orders' && $method === 'GET') {
        require_roles(['delivery','admin']);
        respond(get_orders("WHERE o.status IN ('Confirmed','Preparing','Processing','Out for Delivery')"));
    }

    if (preg_match('#^delivery/orders/(\d+)/status$#', $route, $m) && $method === 'PUT') {
        require_roles(['delivery','admin']);
        $status = trim($data['status'] ?? '');
        if (!in_array($status,['Out for Delivery','Delivered'],true)) respond(['message'=>'Delivery staff can set Out for Delivery or Delivered.'], 400);
        $stmt = db()->prepare('UPDATE orders SET status=? WHERE id=?');
        $stmt->execute([$status,(int)$m[1]]);
        if (!$stmt->rowCount()) {
            $check = db()->prepare('SELECT id FROM orders WHERE id=?'); $check->execute([(int)$m[1]]);
            if (!$check->fetch()) respond(['message'=>'Order not found.'], 404);
        }
        respond(['message'=>'Delivery status updated.','status'=>$status]);
    }

    if (preg_match('#^mpesa/stkpush/([A-Za-z0-9-]+)$#', $route, $m) && $method === 'POST') {
        $user = current_user();
        if (!DARAJA_CONSUMER_KEY || !DARAJA_CONSUMER_SECRET || !DARAJA_PASSKEY || !DARAJA_CALLBACK_URL) {
            respond(['message'=>'M-Pesa is not configured yet. Add the Daraja credentials and a public HTTPS callback URL to the PHP server environment.'], 400);
        }
        $pdo = db();
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE order_number=?');
        $stmt->execute([$m[1]]); $order = $stmt->fetch();
        if (!$order) respond(['message'=>'Order not found.'], 404);
        if ((int)$order['customer_id'] !== $user['id'] && $user['role'] !== 'admin') respond(['message'=>'You cannot pay for this order.'], 403);
        if ($order['payment_method'] !== 'M-Pesa') respond(['message'=>'This order is not set up for M-Pesa payment.'], 400);
        $phone = normalize_phone($order['phone']);
        $shortcode = DARAJA_SHORTCODE; $timestamp = date('YmdHis');
        $password = base64_encode($shortcode . DARAJA_PASSKEY . $timestamp);
        $access = daraja_token();
        $payload = [
            'BusinessShortCode'=>$shortcode,'Password'=>$password,'Timestamp'=>$timestamp,
            'TransactionType'=>'CustomerPayBillOnline','Amount'=>max(1,(int)round((float)$order['total'])),
            'PartyA'=>$phone,'PartyB'=>$shortcode,'PhoneNumber'=>$phone,'CallBackURL'=>DARAJA_CALLBACK_URL,
            'AccountReference'=>$order['order_number'],'TransactionDesc'=>'AQUAR Fresh water order'
        ];
        $ch = curl_init(daraja_base().'/mpesa/stkpush/v1/processrequest');
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload),
            CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$access,'Content-Type: application/json'],CURLOPT_TIMEOUT=>40]);
        $raw = curl_exec($ch); $http = curl_getinfo($ch,CURLINFO_HTTP_CODE);
        if ($raw === false) { $err=curl_error($ch); curl_close($ch); respond(['message'=>'M-Pesa request failed: '.$err],502); }
        curl_close($ch); $result=json_decode($raw,true) ?: [];
        if ($http >= 400) respond(['message'=>$result['errorMessage'] ?? 'M-Pesa request failed.'],502);
        $upd=$pdo->prepare('UPDATE orders SET mpesa_checkout_request_id=?,mpesa_merchant_request_id=? WHERE id=?');
        $upd->execute([$result['CheckoutRequestID'] ?? null,$result['MerchantRequestID'] ?? null,$order['id']]);
        respond(['message'=>$result['CustomerMessage'] ?? 'STK Push sent.','response'=>$result]);
    }

    if ($route === 'mpesa/callback' && $method === 'POST') {
        $callback = $data['Body']['stkCallback'] ?? [];
        $checkoutId = $callback['CheckoutRequestID'] ?? '';
        if ($checkoutId !== '') {
            $pdo = db();
            if ((string)($callback['ResultCode'] ?? '') === '0') {
                $receipt = null;
                foreach (($callback['CallbackMetadata']['Item'] ?? []) as $item) {
                    if (($item['Name'] ?? '') === 'MpesaReceiptNumber') $receipt = $item['Value'] ?? null;
                }
                $stmt=$pdo->prepare("UPDATE orders SET payment_status='Paid',mpesa_receipt_number=? WHERE mpesa_checkout_request_id=?");
                $stmt->execute([$receipt,$checkoutId]);
            } else {
                $stmt=$pdo->prepare("UPDATE orders SET payment_status='Failed' WHERE mpesa_checkout_request_id=?");
                $stmt->execute([$checkoutId]);
            }
        }
        respond(['ResultCode'=>0,'ResultDesc'=>'Callback processed']);
    }

    respond(['message'=>'The requested API endpoint was not found.','route'=>$route], 404);
} catch (Throwable $e) {
    error_log('AQUAR API error: ' . $e->getMessage());
    respond(['message'=>'A server or database error occurred. Check that Apache, MySQL and the aquar_fresh tables are available.'], 500);
}
