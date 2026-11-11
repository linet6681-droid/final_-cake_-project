<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';

/**
 * Manual M-Pesa payment verification.
 *   customer pays the shop's Till/Paybill, then submits the M-Pesa transaction code  -> "Pending Verification"  (payments.status = pending)
 *   admin compares the code with the M-Pesa confirmation message and decides:
 *        Approve -> "Approved"  (payments.status = paid)    and the order may now be processed
 *        Decline -> "Declined"  (payments.status = failed)  and the customer may submit a corrected code
 * Every update is shown to the customer in Order Tracking. No SMS / phone notification is sent.
 */
class PaymentService {
    /** Customer submits (or re-submits after a decline) the M-Pesa transaction code for one of THEIR orders. */
    public static function submitCode(int $orderId, int $userId, string $rawCode): void {
        $code = normalize_mpesa_code($rawCode);
        if (!$code) throw new InvalidArgumentException('Enter the 10-character M-Pesa transaction code from your M-Pesa confirmation message (for example QGH7K2LMNP).');
        $pdo = db(); $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT o.status AS order_status, o.contact_name, p.* FROM orders o JOIN payments p USING(order_id) WHERE o.order_id=? AND o.user_id=? AND o.deleted_at IS NULL FOR UPDATE');
            $st->execute([$orderId, $userId]); $p = $st->fetch();
            if (!$p || $p['method'] !== 'mpesa') throw new InvalidArgumentException('This order is not paid by M-Pesa.');
            if ($p['order_status'] === 'cancelled') throw new InvalidArgumentException('This order was cancelled, so no payment is needed.');
            if ($p['status'] === 'paid') throw new InvalidArgumentException('This payment is already approved.');
            if ($p['status'] === 'pending' && !empty($p['mpesa_receipt'])) throw new InvalidArgumentException('Your code is already waiting for verification.');
            // a transaction code can pay for ONE order only
            $dup = $pdo->prepare("SELECT 1 FROM payments WHERE mpesa_receipt=? AND order_id<>? AND status IN ('pending','paid') LIMIT 1");
            $dup->execute([$code, $orderId]);
            if ($dup->fetchColumn()) throw new InvalidArgumentException('That transaction code has already been used for another order. Check it and try again.');
            $pdo->prepare("UPDATE payments SET mpesa_receipt=?, status='pending', submitted_at=?, verified_at=NULL, verified_by=NULL, decline_reason=NULL, paid_at=NULL, paid_amount=NULL, result_desc='Pending Verification' WHERE order_id=?")
                ->execute([$code, date('Y-m-d H:i:s'), $orderId]);
            $pdo->commit();
        } catch (Throwable $t) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $t; }
        self::alertAdmin($orderId, 'PAYMENT ALERT: Awaiting verification. '.$p['contact_name'].' submitted M-Pesa code '.$code.' for order #'.$orderId.' (Ksh '.number_format((float)$p['amount']).').');
    }

    /** Admin decision on a submitted code. $approve=true -> Approved (paid), false -> Declined (failed). */
    public static function decide(int $orderId, int $adminId, bool $approve, string $reason = ''): void {
        $reason = mb_substr(trim($reason), 0, 250);
        $pdo = db(); $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT o.status AS order_status, p.* FROM orders o JOIN payments p USING(order_id) WHERE o.order_id=? AND o.deleted_at IS NULL FOR UPDATE');
            $st->execute([$orderId]); $p = $st->fetch();
            if (!$p) throw new InvalidArgumentException('Order not found.');
            if ($p['method'] !== 'mpesa') throw new InvalidArgumentException('Only M-Pesa payments need verification.');
            if ($p['order_status'] === 'cancelled') throw new InvalidArgumentException('Order #'.$orderId.' is cancelled, so its payment cannot be verified.');
            if ($p['status'] !== 'pending' || empty($p['mpesa_receipt'])) throw new InvalidArgumentException('Order #'.$orderId.' has no code waiting for verification (it may already have been decided).');
            $now = date('Y-m-d H:i:s');
            if ($approve) $pdo->prepare("UPDATE payments SET status='paid', paid_at=?, paid_amount=amount, verified_at=?, verified_by=?, decline_reason=NULL, result_code=0, result_desc='Approved by admin' WHERE order_id=?")->execute([$now, $now, $adminId, $orderId]);
            else          $pdo->prepare("UPDATE payments SET status='failed', paid_at=NULL, paid_amount=NULL, verified_at=?, verified_by=?, decline_reason=?, result_desc='Declined by admin' WHERE order_id=?")->execute([$now, $adminId, $reason !== '' ? $reason : null, $orderId]);
            $pdo->commit();
        } catch (Throwable $t) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $t; }
    }

    /** Admin confirms that a PAY ON PICKUP customer paid at collection: Unpaid -> Paid, with the date and time. Separate from M-Pesa code verification. */
    public static function markCashPaid(int $orderId, int $adminId): void {
        $pdo = db();
        $r = $pdo->prepare("SELECT role FROM users WHERE user_id=?"); $r->execute([$adminId]);
        if ($r->fetchColumn() !== 'admin') throw new InvalidArgumentException('Only an administrator can mark a payment as paid.');
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT o.status AS order_status, p.* FROM orders o JOIN payments p USING(order_id) WHERE o.order_id=? AND o.deleted_at IS NULL FOR UPDATE');
            $st->execute([$orderId]); $p = $st->fetch();
            if (!$p) throw new InvalidArgumentException('Order not found.');
            if ($p['method'] !== 'cash_on_pickup') throw new InvalidArgumentException('Order #'.$orderId.' is paid by M-Pesa: verify its transaction code instead.');
            if ($p['order_status'] === 'cancelled') throw new InvalidArgumentException('Order #'.$orderId.' is cancelled, so it cannot be marked as paid.');
            if ($p['status'] !== 'pending') throw new InvalidArgumentException('Order #'.$orderId.' is already marked as paid.');
            $now = date('Y-m-d H:i:s');
            $pdo->prepare("UPDATE payments SET status='paid', paid_at=?, paid_amount=amount, verified_at=?, verified_by=?, result_desc='Paid on pickup (confirmed by admin)' WHERE order_id=?")->execute([$now, $now, $adminId, $orderId]);
            $pdo->commit();
        } catch (Throwable $t) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $t; }
    }

    /** True when an M-Pesa order may be processed (baked / ready / delivered): the admin approved the payment. Pay-on-pickup orders are not held back. */
    public static function allowsProcessing(int $orderId): bool {
        $st = db()->prepare('SELECT method, status FROM payments WHERE order_id=?'); $st->execute([$orderId]); $p = $st->fetch();
        return !$p || $p['method'] !== 'mpesa' || $p['status'] === 'paid';
    }

    /** Customer wording for a payment row: [state, headline, detail]. state = pending | paid | failed | awaiting (no code yet). */
    public static function describe(array $p): array {
        $code = (string)($p['mpesa_receipt'] ?? '');
        if (($p['method'] ?? 'mpesa') === 'cash_on_pickup') {      // pay on pickup: Unpaid -> Paid (admin confirms)
            switch ($p['status'] ?? 'pending') {
                case 'paid':   return ['paid', 'Paid', 'The shop confirmed your payment of '.money($p['paid_amount'] ?? $p['amount']).($p['paid_at'] ? ' on '.date('d M Y, g:i A', strtotime($p['paid_at'])) : '').'. Thank you!'];
                case 'failed': return ['failed', 'Order cancelled', 'This order was cancelled, so no payment is needed.'];
                default:       return ['unpaid', 'Unpaid', 'Pay '.money($p['amount']).' when you collect your order. This changes to Paid once the shop confirms it received your payment.'];
            }
        }
        switch ($p['status'] ?? 'pending') {
            case 'paid':
                return ['paid', 'Approved', 'The shop confirmed your M-Pesa payment of '.money($p['paid_amount'] ?? $p['amount']).' (code '.$code.'). Your order can now be prepared.'];
            case 'failed':
                $why = trim((string)($p['decline_reason'] ?? ''));
                if (($p['result_desc'] ?? '') === 'Order cancelled') return ['failed', 'Order cancelled', 'This order was cancelled, so no payment is needed.'];
                if ($code === '') return ['failed', 'Declined', 'No matching M-Pesa payment of '.money($p['amount']).' was found for this order. Pay with M-Pesa, then submit the transaction code below.'];
                return ['failed', 'Declined', 'The shop could not match code '.$code.' with an M-Pesa payment of '.money($p['amount']).'.'.($why !== '' ? ' Reason: '.$why : '').' Please check your M-Pesa confirmation message and submit the correct code below.'];
            default:
                if ($code === '') return ['awaiting', 'Awaiting transaction code', 'Pay '.money($p['amount']).' with M-Pesa, then enter the transaction code from your M-Pesa confirmation message below.'];
                return ['pending', 'Pending Verification', 'You submitted code '.$code.'. The shop will check it against the M-Pesa payment of '.money($p['amount']).'. This page updates by itself and you can come back any time to see the result. Your order is processed after the payment is approved.'];
        }
    }

    /** Dashboard-only message for the admin (shown on Admin > Dashboard). Never a text message. */
    private static function alertAdmin(int $orderId, string $msg): void {
        db()->prepare("INSERT INTO notifications(order_id,phone,message,delivery_status) VALUES(?,?,?,'sent')")->execute([$orderId, 'ADMIN', mb_substr($msg, 0, 315)]);
    }
}
