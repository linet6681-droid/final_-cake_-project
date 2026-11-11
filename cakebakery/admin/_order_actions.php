<?php
// Shared by orders.php and order_view.php. Every action: admin only + CSRF token + server-side rules in OrderService.
require_once __DIR__.'/../services/OrderService.php';
require_once __DIR__.'/../services/PaymentService.php';
function handle_order_post(string $back): void {
    require_admin(); csrf_check();
    $oid = (int)($_POST['order_id'] ?? 0); $act = $_POST['action'] ?? '';
    try {
        if ($act === 'status')      { $n = OrderService::updateStatus($oid, $_POST['status'] ?? ''); flash("Order #$oid status updated.".$n); }
        elseif ($act === 'cancel')  { $n = OrderService::updateStatus($oid, 'cancelled'); flash("Order #$oid cancelled. Stock was returned to the shop.".$n); }
        elseif ($act === 'delete')  { OrderService::deleteDelivered($oid); flash("Delivered order #$oid deleted."); $back = 'admin/orders.php'; }
        elseif ($act === 'pay_cash_paid') { PaymentService::markCashPaid($oid, (int)current_user()['id']); flash("Order #$oid marked as PAID (Pay on Pickup). The customer sees it in Order Tracking."); }
        elseif ($act === 'pay_approve') { PaymentService::decide($oid, (int)current_user()['id'], true);  flash("Order #$oid payment APPROVED. The customer sees it in Order Tracking and the order can now be processed."); }
        elseif ($act === 'pay_decline') { PaymentService::decide($oid, (int)current_user()['id'], false, (string)($_POST['reason'] ?? '')); flash("Order #$oid payment DECLINED. The customer sees it in Order Tracking and can submit a corrected code."); }
    } catch (InvalidArgumentException $ex) { flash($ex->getMessage()); }
    redirect($back);
}
