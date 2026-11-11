<?php
/**
 * Automated checks for Milestone 4 (Chapter 4 test cases).
 * Run from the project folder on a DEV database:   php tests/run_tests.php
 * It creates its own test users/products (prefixed ZZ) and soft-deletes the products afterwards.
 */
chdir(__DIR__ . '/..');
require_once 'includes/auth.php';
require_once 'services/OrderService.php';
require_once 'services/PaymentService.php';
$pass = 0; $fail = 0;
function t(string $id, string $name, callable $fn): void {
    global $pass, $fail;
    try { $r = $fn(); $ok = $r === true; $msg = $ok ? '' : (is_string($r) ? $r : 'returned false'); }
    catch (Throwable $e) { $ok = false; $msg = get_class($e).': '.$e->getMessage(); }
    $ok ? $pass++ : $fail++;
    printf("%-6s %-4s %s%s\n", $id, $ok ? 'PASS' : 'FAIL', $name, $ok ? '' : "  -> $msg");
}
function throws(callable $f, string $contains): bool {
    try { $f(); } catch (InvalidArgumentException $e) { return stripos($e->getMessage(), $contains) !== false; }
    return false;
}
$pdo = db(); $u = uniqid();
$email = "zz$u@test.local";
$cake = ['name'=>"ZZ Cake $u", 'cat'=>1, 'price'=>1000, 'stock'=>5];
$bread = ['name'=>"ZZ Bread $u", 'cat'=>3, 'price'=>70, 'stock'=>50];
foreach ([&$cake, &$bread] as &$p) {
    $pdo->prepare('INSERT INTO products(category_id,name,price,stock_qty) VALUES(?,?,?,?)')->execute([$p['cat'],$p['name'],$p['price'],$p['stock']]);
    $p['id'] = (int)$pdo->lastInsertId();
}
unset($p);
$stock = fn($id) => (int)$pdo->query("SELECT stock_qty FROM products WHERE product_id=$id")->fetchColumn();
$tomorrow2 = date('Y-m-d', strtotime('+2 days')); $today = date('Y-m-d');
$base = ['name'=>'Test User','phone'=>'0712345678','location'=>'','instructions'=>'','method'=>'cash_on_pickup','date'=>$tomorrow2,'time'=>'10:00'];
$cartCake = fn($q=1,$msg='') => [$cake['id']=>['qty'=>$q,'message'=>$msg]];
$cartBread = fn($q=1) => [$bread['id']=>['qty'=>$q,'message'=>'']];

echo "== Registration & login ==\n";
t('T01','Register with valid details', fn() => register_user('Test User',$email,'0712345678','password123') === []);
$uid = (int)$pdo->query("SELECT user_id FROM users WHERE email='$email'")->fetchColumn();
t('T02','Password is stored hashed, not plain text', fn() => (bool)preg_match('/^\$2y\$|^\$argon/', $pdo->query("SELECT password_hash FROM users WHERE user_id=$uid")->fetchColumn()));
t('T03','Duplicate email rejected', fn() => in_array('Email already registered.', register_user('Test Two',$email,'0712345678','password123')));
t('T04','Short password rejected', fn() => count(register_user('Test',"b$u@t.local",'0712345678','123')) >= 1);
t('T05','Invalid phone rejected', fn() => count(register_user('Test',"c$u@t.local",'12345','password123')) >= 1);
t('T06','Invalid email rejected', fn() => count(register_user('Test','not-an-email','0712345678','password123')) >= 1);
t('T07','SQL injection in login is rejected', fn() => login_user("' OR '1'='1' -- ", "' OR '1'='1") === 'Invalid email or password.');
t('T08','Wrong password gives generic error', fn() => login_user($email,'wrongpass') === 'Invalid email or password.');
t('T09','Account locks after 3 failed attempts', function() use ($email) { login_user($email,'bad'); $r = login_user($email,'bad'); return stripos((string)$r,'locked') !== false; });
t('T10','Locked account rejects even the correct password', fn() => stripos((string)login_user($email,'password123'),'locked') !== false);
$pdo->prepare('UPDATE users SET locked_until=NULL, failed_attempts=0 WHERE user_id=?')->execute([$uid]);
t('T11','Correct login succeeds and sets role session', fn() => login_user($email,'password123') === null && ($_SESSION['user']['role'] ?? '') === 'customer');

echo "== Ordering rules ==\n";
t('T12','Empty cart cannot be ordered', fn() => throws(fn() => OrderService::placeOrder($uid, [], $base), 'empty'));
t('T13','Invalid phone at checkout rejected', fn() => throws(fn() => OrderService::placeOrder($uid, $cartBread(), ['phone'=>'999']+$base), 'phone'));
t('T14','Missing date rejected', fn() => throws(fn() => OrderService::placeOrder($uid, $cartBread(), ['date'=>'']+$base), 'date'));
t('T15','Date in the past rejected', fn() => throws(fn() => OrderService::placeOrder($uid, $cartBread(), ['date'=>date('Y-m-d',strtotime('-1 day'))]+$base), 'hour'));
t('T16','Cake needs 24 hours notice', fn() => throws(fn() => OrderService::placeOrder($uid, $cartCake(), ['date'=>$today,'time'=>'18:59']+$base), '24 hours') || throws(fn() => OrderService::placeOrder($uid, $cartCake(), ['date'=>$today,'time'=>'18:59']+$base), 'hour'));
t('T17','Outside opening hours rejected', fn() => throws(fn() => OrderService::placeOrder($uid, $cartBread(), ['time'=>'23:30']+$base), 'open'));
t('T18','Quantity above stock rejected, stock unchanged', function() use ($uid,$cartCake,$base,$cake,$stock) {
    $ok = throws(fn() => OrderService::placeOrder($uid, $cartCake(99), $base), 'stock'); return $ok && $stock($cake['id']) === 5; });
$orderId = 0;
t('T19','Valid cake order is placed (pickup, pay on pickup)', function() use (&$orderId,$uid,$cartCake,$base) { $orderId = OrderService::placeOrder($uid, $cartCake(2,'Happy Birthday Amina'), $base); return $orderId > 0; });
t('T20','Stock decreases by ordered quantity', fn() => $stock($cake['id']) === 3);
t('T21','Total = price x qty with no delivery fee for pickup', fn() => (float)$pdo->query("SELECT total_amount FROM orders WHERE order_id=$orderId")->fetchColumn() === 2000.0);
t('T22','Cake message saved on the order item', fn() => $pdo->query("SELECT cake_message FROM order_items WHERE order_id=$orderId")->fetchColumn() === 'Happy Birthday Amina');
t('T23','Payment row created as pending', fn() => $pdo->query("SELECT status FROM payments WHERE order_id=$orderId")->fetchColumn() === 'pending');
t('T24','No SMS / phone notification is created for the customer', fn() => (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE order_id=$orderId AND phone<>'ADMIN'")->fetchColumn() === 0);
$zoneId = fn($like) => (int)$pdo->query("SELECT zone_id FROM delivery_zones WHERE name LIKE '$like%' ORDER BY zone_id LIMIT 1")->fetchColumn();
delivery_zones();   // creates + seeds the zones table on older databases
t('T25','Delivery adds the fee of the chosen destination (Between Allsops and Juja = Ksh 100)', function() use ($uid,$cartBread,$base,$zoneId) {
    $id = OrderService::placeOrder($uid, $cartBread(2), ['zone'=>$zoneId('Between Allsops'),'location'=>'Mwatu wa Ngoma Rd']+$base);
    return (float)db()->query("SELECT total_amount FROM orders WHERE order_id=$id")->fetchColumn() === 240.0; });
t('T26','Cake message HTML is escaped on output (XSS)', fn() => e('<script>alert(1)</script>') === '&lt;script&gt;alert(1)&lt;/script&gt;');

echo "== Admin order handling ==\n";
t('T31','Invalid status rejected', fn() => throws(fn() => OrderService::updateStatus($orderId,'flying'), 'invalid'));
t('T32','Status change to Baking saved, no SMS sent (pay on pickup is not held back)', function() use ($orderId,$pdo) {
    $n = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE order_id=$orderId")->fetchColumn();
    OrderService::updateStatus($orderId,'in_progress');
    return $pdo->query("SELECT status FROM orders WHERE order_id=$orderId")->fetchColumn() === 'in_progress'
        && (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE order_id=$orderId")->fetchColumn() === $n; });
t('T33','Cancelling restores stock', function() use ($orderId,$stock,$cake) { OrderService::updateStatus($orderId,'cancelled'); return $stock($cake['id']) === 5; });
t('T34','Cancelled order cannot be re-opened', fn() => throws(fn() => OrderService::updateStatus($orderId,'ready'), 'cancelled'));
t('T35','Cancelling twice does not restore stock twice', function() use ($orderId,$stock,$cake) { OrderService::updateStatus($orderId,'cancelled'); return $stock($cake['id']) === 5; });


echo "== Delivery areas =============================\n";
t('T36','A farther area costs more (Mombasa = Ksh 500)', function() use ($uid,$cartBread,$base,$zoneId) {
    $id = OrderService::placeOrder($uid, $cartBread(2), ['zone'=>$zoneId('Mombasa'),'location'=>'Nyali']+$base);
    return (float)db()->query("SELECT delivery_fee FROM orders WHERE order_id=$id")->fetchColumn() === 500.0; });
t('T37','Delivery area without a street/landmark is rejected', fn() => throws(fn() => OrderService::placeOrder($uid, $cartBread(), ['zone'=>$zoneId('Mombasa'),'location'=>'']+$base), 'street'));
t('T38','Location typed but no area chosen is rejected', fn() => throws(fn() => OrderService::placeOrder($uid, $cartBread(), ['zone'=>'','location'=>'Somewhere']+$base), 'delivery destination'));
t('T39','Unknown / tampered area id is rejected', fn() => throws(fn() => OrderService::placeOrder($uid, $cartBread(), ['zone'=>99999,'location'=>'Somewhere','fee'=>0]+$base), 'delivery destination'));
t('T40','Pickup order has no delivery fee', fn() => (float)db()->query("SELECT delivery_fee FROM orders WHERE order_id=$orderId")->fetchColumn() === 0.0);

echo "== M-Pesa transaction-code verification ==\n";
$adminId = (int)$pdo->query("SELECT user_id FROM users WHERE role='admin' LIMIT 1")->fetchColumn();
$mk = fn(string $tag) => 'ZZ'.strtoupper(substr(md5($tag.$u), 0, 8));   // 10 letters/digits
$mp = fn(string $c) => OrderService::placeOrder($uid, $cartBread(1), ['method'=>'mpesa','mpesa_code'=>$c]+$base);
$pst = fn($id) => $pdo->query("SELECT * FROM payments WHERE order_id=$id")->fetch();
t('T41','M-Pesa order without a transaction code is rejected', fn() => throws(fn() => OrderService::placeOrder($uid, $cartBread(), ['method'=>'mpesa']+$base), 'transaction code'));
t('T42','Malformed transaction code is rejected', fn() => throws(fn() => $mp('123'), 'transaction code'));
$c1 = $mk('a'); $id1 = $mp($c1);
t('T43','Valid code: payment saved as Pending Verification with the code and time', function() use ($id1,$pst,$c1) {
    $p = $pst($id1); [$st,$h] = PaymentService::describe($p);
    return $p['status']==='pending' && $p['mpesa_receipt']===$c1 && $p['submitted_at']!==null && $st==='pending' && $h==='Pending Verification' && pay_label('pending','mpesa',true)==='Pending Verification'; });
t('T44','Order cannot be processed before the payment is approved', fn() => throws(fn() => OrderService::updateStatus($id1,'in_progress'), 'approve'));
t('T45','The same code cannot pay for a second order', fn() => throws(fn() => $mp($c1), 'already been used'));
t('T46','Admin approves: payment Approved (paid), time saved, wording shown to the customer', function() use ($id1,$pst,$adminId) {
    PaymentService::decide($id1, $adminId, true); $p = $pst($id1); [$st,$h] = PaymentService::describe($p);
    return $p['status']==='paid' && $p['paid_at']!==null && $p['verified_at']!==null && $st==='paid' && $h==='Approved' && pay_label('paid','mpesa')==='Approved'; });
t('T47','After approval the order can be processed (Baking)', function() use ($id1,$pdo) { OrderService::updateStatus($id1,'in_progress'); return $pdo->query("SELECT status FROM orders WHERE order_id=$id1")->fetchColumn()==='in_progress'; });
t('T48','A decided payment cannot be decided again', fn() => throws(fn() => PaymentService::decide($id1, $adminId, false), 'already'));
$c2 = $mk('b'); $id2 = $mp($c2);
t('T49','Admin declines with a reason: payment Declined (failed), reason shown, order still blocked', function() use ($id2,$pst,$adminId) {
    PaymentService::decide($id2, $adminId, false, 'Code not found'); $p = $pst($id2); [$st,$h,$d] = PaymentService::describe($p);
    return $p['status']==='failed' && $st==='failed' && $h==='Declined' && str_contains($d,'Code not found') && pay_label('failed','mpesa')==='Declined'
        && throws(fn() => OrderService::updateStatus($id2,'in_progress'), 'approve'); });
t('T50','Customer submits a corrected code after a decline: back to Pending Verification', function() use ($id2,$pst,$uid,$mk) {
    $c = $mk('c'); PaymentService::submitCode($id2, $uid, strtolower($c)); $p = $pst($id2);
    return $p['status']==='pending' && $p['mpesa_receipt']===$c && $p['decline_reason']===null && $p['verified_at']===null; });
t('T51','A code that is already waiting for verification cannot be replaced', fn() => throws(fn() => PaymentService::submitCode($id2, $uid, $mk('d')), 'waiting'));
t('T54','Another customer cannot submit a code for this order', fn() => throws(fn() => PaymentService::submitCode($id2, $uid + 999999, $mk('e')), 'not paid'));
$id3 = $mp($mk('f'));
t('T55','Cancelling an unverified M-Pesa order closes its payment and returns stock', function() use ($id3,$pdo,$pst,$bread,$stock) {
    $s0 = $stock($bread['id']); OrderService::updateStatus($id3, 'cancelled'); return $pst($id3)['status']==='failed' && $stock($bread['id'])===$s0+1; });
t('T56','A cancelled order\'s payment cannot be verified', fn() => throws(fn() => PaymentService::decide($id3, $adminId, true), 'cancel'));
t('T57','No SMS / phone notification was created for the customer anywhere in this run', fn() => (int)$pdo->query("SELECT COUNT(*) FROM notifications n JOIN orders o USING(order_id) WHERE o.user_id=$uid AND n.phone<>'ADMIN'")->fetchColumn() === 0);

echo "== Pay on Pickup (admin marks as paid) ==\n";
$cid = OrderService::placeOrder($uid, $cartBread(1), ['method'=>'cash_on_pickup']+$base);
t('T60','Pay on Pickup order: method saved, status Unpaid, no code needed, not auto-paid', function() use ($cid,$pst) {
    $p = $pst($cid); [$st,$h] = PaymentService::describe($p);
    return $p['method']==='cash_on_pickup' && $p['status']==='pending' && $p['mpesa_receipt']===null && $p['paid_at']===null && $h==='Unpaid' && pay_label('pending','cash_on_pickup')==='Unpaid'; });
t('T61','Admin marks Pay on Pickup as Paid: status Paid with date/time recorded', function() use ($cid,$pst,$adminId) {
    PaymentService::markCashPaid($cid, $adminId); $p = $pst($cid); [$st,$h] = PaymentService::describe($p);
    return $p['status']==='paid' && $p['paid_at']!==null && $p['verified_by']==$adminId && $h==='Paid'; });
t('T62','A Paid pickup order cannot be marked paid twice', fn() => throws(fn() => PaymentService::markCashPaid($cid, $adminId), 'already'));
$cid2 = OrderService::placeOrder($uid, $cartBread(1), ['method'=>'cash_on_pickup']+$base);
t('T63','A customer (non-admin) cannot mark a payment as paid', fn() => throws(fn() => PaymentService::markCashPaid($cid2, $uid), 'administrator') && $pst($cid2)['status']==='pending');
t('T64','M-Pesa orders cannot be marked paid this way (kept separate)', fn() => throws(fn() => PaymentService::markCashPaid($id2, $adminId), 'M-Pesa'));
t('T65','Cancelled pickup order cannot be marked paid', function() use ($cid2,$adminId) { OrderService::updateStatus($cid2,'cancelled'); return throws(fn() => PaymentService::markCashPaid($cid2, $adminId), 'cancel'); });
t('T52','Delivered orders can be deleted (hidden, record kept); others cannot', function() use ($uid,$cartBread,$base,$pdo) {
    $id = OrderService::placeOrder($uid, $cartBread(1), $base);
    if (!throws(fn() => OrderService::deleteDelivered($id), 'delivered')) return 'new order was deletable';
    OrderService::updateStatus($id, 'delivered'); OrderService::deleteDelivered($id);
    return $pdo->query("SELECT deleted_at FROM orders WHERE order_id=$id")->fetchColumn() !== null && (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE order_id=$id")->fetchColumn()===1; });
t('T53','Delete customer: no orders = removed; with orders = erased but financial rows kept', function() use ($pdo,$u,$cartBread,$base) {
    require_once 'services/AdminService.php';
    register_user('ZZ Del', "del1$u@t.local", '0712345678', 'password123'); $x = (int)$pdo->query("SELECT user_id FROM users WHERE email='del1$u@t.local'")->fetchColumn();
    AdminService::deleteCustomer($x); if ((int)$pdo->query("SELECT COUNT(*) FROM users WHERE user_id=$x")->fetchColumn() !== 0) return 'row not removed';
    register_user('ZZ Del2', "del2$u@t.local", '0712345678', 'password123'); $y = (int)$pdo->query("SELECT user_id FROM users WHERE email='del2$u@t.local'")->fetchColumn();
    $id = OrderService::placeOrder($y, $cartBread(1), $base);
    if (!throws(fn() => AdminService::deleteCustomer($y), 'unfinished')) return 'deleted with an open order';
    OrderService::updateStatus($id, 'delivered'); AdminService::deleteCustomer($y);
    $r = $pdo->query("SELECT u.full_name, u.deleted_at, o.contact_name, o.total_amount FROM users u JOIN orders o ON o.user_id=u.user_id WHERE u.user_id=$y")->fetch();
    return $r && $r['deleted_at'] && $r['full_name']==='Deleted customer' && $r['contact_name']==='Deleted customer' && $r['total_amount'] > 0; });

// cleanup: hide test products (orders reference them so rows are kept)
$pdo->prepare('UPDATE products SET is_active=0 WHERE product_id IN (?,?)')->execute([$cake['id'],$bread['id']]);
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
