<?php
require_once __DIR__.'/../includes/auth.php'; require_admin();
require_once __DIR__.'/_order_actions.php';
if ($_SERVER['REQUEST_METHOD']==='POST') handle_order_post('admin/payments.php');
$rows = db()->query("SELECT p.*, o.contact_name, o.contact_phone, o.status AS order_status, o.deleted_at FROM payments p JOIN orders o USING(order_id) ORDER BY (p.method='mpesa' AND p.status='pending' AND p.mpesa_receipt IS NOT NULL) DESC, p.payment_id DESC LIMIT 200")->fetchAll();
$sum  = db()->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='paid'")->fetchColumn();
$wait = db()->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE method='mpesa' AND status='pending' AND mpesa_receipt IS NOT NULL")->fetchColumn();
$layout='admin'; $active='payments'; $section='Payments'; $title='Payments'; include __DIR__.'/../includes/header.php'; ?>
<div class="tbl-card pad no-print" style="margin-bottom:24px">
  <b>Verify M-Pesa payments</b><br>
  <span class="muted">Compare the customer's transaction code and the amount with the M-Pesa confirmation message on the shop's phone. Press <b>Approve</b> if they match, or <b>Decline</b> if they do not. The customer sees the result in Order Tracking, and the order can only be processed after you approve it.<br>For <b>Pay on Pickup</b> orders, press <b>Mark as Paid</b> once the customer has collected the order and paid.</span>
</div>
<div class="stats" style="grid-template-columns:repeat(2,1fr)"><div class="stat grad"><span>Approved</span><b><?= money($sum) ?></b></div><div class="stat"><span>Awaiting verification</span><b><?= money($wait) ?></b></div></div>
<div class="tbl-card scroll"><table class="tbl"><thead><tr><th>Order</th><th>Customer</th><th>Method</th><th>Amount</th><th>Transaction code</th><th>Status</th><th>Submitted / Paid</th><th class="no-print"></th></tr></thead><tbody>
<?php foreach ($rows as $r): $has = !empty($r['mpesa_receipt']); $cash = $r['method']==='cash_on_pickup' && $r['status']==='pending' && $r['order_status']!=='cancelled' && !$r['deleted_at']; $dec = $r['method']==='mpesa' && $r['status']==='pending' && $has && $r['order_status']!=='cancelled' && !$r['deleted_at']; ?>
<tr><td class="id"><a href="<?= url('admin/order_view.php?id='.$r['order_id']) ?>" style="text-decoration:none">#<?= $r['order_id'] ?></a></td>
  <td><?= e($r['contact_name']) ?><?= $r['deleted_at'] ? ' <small class="muted">(order deleted)</small>' : '' ?></td>
  <td><?= pay_method_label($r['method']) ?></td><td><?= money($r['amount']) ?></td>
  <td><b><?= e($r['mpesa_receipt'] ?: '—') ?></b></td><td><?= pay_badge($r['status'], $r['method'], $has) ?><?= $r['status']==='failed' && $r['decline_reason'] ? '<br><small class="muted">'.e($r['decline_reason']).'</small>' : '' ?></td>
  <td><?= $r['submitted_at'] ? date('d M, H:i', strtotime($r['submitted_at'])) : ($r['method']==='cash_on_pickup' && $r['paid_at'] ? 'Paid '.date('d M, H:i', strtotime($r['paid_at'])) : '—') ?></td>
  <td class="no-print"><?php if ($cash): ?>
    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="pay_cash_paid"><input type="hidden" name="order_id" value="<?= $r['order_id'] ?>"><button class="pill-btn pill-edit">Mark as Paid</button></form>
  <?php endif; ?><?php if ($dec): ?>
    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="pay_approve"><input type="hidden" name="order_id" value="<?= $r['order_id'] ?>"><button class="pill-btn pill-edit">Approve</button></form>
    <button class="pill-btn pill-del" type="button" data-decline="<?= $r['order_id'] ?>">Decline</button>
  <?php endif; ?></td></tr>
<?php endforeach;
if (!$rows): ?><tr><td colspan="8" class="muted" style="text-align:center;padding:36px">No payments yet.</td></tr><?php endif; ?></tbody></table></div>
<dialog id="dlg-decline"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="pay_decline"><input type="hidden" name="order_id" id="decline-id">
  <h3>Decline payment for order <span id="decline-label"></span>?</h3>
  <p class="muted" style="font-size:.9rem">The customer will see <b>Declined</b> in Order Tracking and can submit a corrected code.</p>
  <input class="field" name="reason" maxlength="250" placeholder="Reason (optional), e.g. code not found in M-Pesa messages">
  <div class="acts"><button type="button" class="btn-ghost" data-close>Close</button><button class="btn">Decline payment</button></div></form></dialog>
<script>document.querySelectorAll('[data-decline]').forEach(function(b){b.addEventListener('click',function(){document.getElementById('decline-id').value=b.dataset.decline;document.getElementById('decline-label').textContent='#'+b.dataset.decline;document.getElementById('dlg-decline').showModal();});});</script>
<?php include __DIR__.'/../includes/footer.php';
