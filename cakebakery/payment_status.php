<?php
// Polled by the order page every few seconds: returns the payment verification state as JSON. Only the order's owner (or an admin) may ask.
require_once 'includes/auth.php'; require_once 'services/PaymentService.php';
no_store_headers(); header('Content-Type: application/json');
if (!current_user() || !session_user_is_valid()) { http_response_code(401); echo json_encode(['error'=>'login']); exit; }
$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT o.user_id, o.status order_status, p.* FROM orders o JOIN payments p USING(order_id) WHERE o.order_id=? AND o.deleted_at IS NULL'); $st->execute([$id]);
$p = $st->fetch();
if (!$p || ($p['user_id'] != current_user()['id'] && current_user()['role'] !== 'admin')) { http_response_code(404); echo json_encode(['error'=>'not found']); exit; }
[$state, $head, $detail] = PaymentService::describe($p);
echo json_encode(['state'=>$state, 'headline'=>$head, 'detail'=>$detail, 'code'=>$p['mpesa_receipt']]);
