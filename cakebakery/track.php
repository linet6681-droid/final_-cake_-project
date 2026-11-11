<?php
require_once 'includes/auth.php'; require_once 'services/PaymentService.php'; require_login();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$st = db()->prepare('SELECT o.*, p.method, p.status AS pay_status, p.mpesa_receipt, p.amount, p.paid_amount, p.payer_phone, p.result_desc, p.decline_reason, p.submitted_at, p.verified_at, p.paid_at FROM orders o LEFT JOIN payments p USING(order_id) WHERE o.order_id=? AND o.deleted_at IS NULL'); $st->execute([$id]);
$o = $st->fetch();
if (!$o || ($o['user_id'] != current_user()['id'] && current_user()['role'] !== 'admin')) { http_response_code(404); include '404.php'; exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {   // submit / re-submit the M-Pesa transaction code (same order, never a duplicate order)
    csrf_check();
    if ($o['user_id'] != current_user()['id']) { http_response_code(403); exit('Only the customer who placed this order can pay for it.'); }
    try { PaymentService::submitCode($id, (int)current_user()['id'], (string)($_POST['mpesa_code'] ?? '')); flash('Code received. Your payment is Pending Verification.'); }
    catch (InvalidArgumentException $ex) { flash($ex->getMessage()); }
    redirect('track.php?id='.$id);
}
$it = db()->prepare('SELECT oi.*, p.name, p.pack_size, p.image FROM order_items oi JOIN products p USING(product_id) WHERE oi.order_id=?'); $it->execute([$id]); $items = $it->fetchAll();
$steps = ['new'=>'Order received','in_progress'=>'Baking','ready'=>'Ready','delivered'=>$o['fulfilment']==='delivery' ? 'Delivered' : 'Collected'];
$reached = array_search($o['status'], array_keys($steps), true);
$layout='simple'; $active='orders'; $title='Order #'.$id; include 'includes/header.php'; ?>
<h1 class="page-title">Order #<?= $id ?> <?= status_badge($o['status']) ?></h1>
<div class="co">
  <div class="stack">
    <div class="panel">
      <h3>Order tracking</h3>
      <?php if ($o['status'] === 'cancelled'): ?><p class="err" style="margin:0">This order was cancelled.</p>
      <?php else: ?><ol class="steps"><?php $i = 0; foreach ($steps as $k=>$l): ?><li class="<?= $i < $reached ? 'done' : ($i === $reached ? 'now' : '') ?>"><span><?= $i < $reached ? '✓' : $i+1 ?></span><?= e($l) ?></li><?php $i++; endforeach; ?></ol>
      <?php if ($o['method']==='mpesa' && $o['pay_status']!=='paid'): ?><p class="muted" style="margin:16px 0 0;font-size:.9rem">Your order is processed once the shop approves your M-Pesa payment.</p><?php endif; ?><?php endif; ?>
    </div>
    <?php if ($o['method']==='mpesa' || $o['method']==='cash_on_pickup'): [$pstate, $phead, $pdetail] = PaymentService::describe(['status'=>$o['pay_status']] + $o);
          $canSubmit = $o['method']==='mpesa' && $o['status']!=='cancelled' && $o['user_id']==current_user()['id'] && in_array($pstate, ['failed','awaiting'], true); ?>
    <div class="panel paybox pay-<?= $pstate==='unpaid' ? 'pending' : e($pstate) ?>" id="paybox" data-id="<?= $id ?>" data-state="<?= e($pstate) ?>" data-url="<?= e(url('payment_status.php?id='.$id)) ?>">
      <h3><?= $o['method']==='mpesa' ? 'M-Pesa payment' : 'Pay on Pickup payment' ?> <span id="paypill"><?= pay_badge($o['pay_status'], $o['method'], $o['mpesa_receipt'] !== null && $o['mpesa_receipt'] !== '') ?></span></h3>
      <p class="phead" id="payhead"><b><?= e($phead) ?></b></p>
      <p class="muted" id="paydetail" style="margin-top:-6px"><?= e($pdetail) ?></p>
      <?php if ($o['method']==='cash_on_pickup'): ?><div class="r"><span>Amount to pay</span><b><?= money($o['amount']) ?></b></div><?php if ($o['pay_status']==='paid' && $o['paid_at']): ?><div class="r"><span>Paid on</span><b><?= date('d M Y, g:i A', strtotime($o['paid_at'])) ?></b></div><?php endif; endif; ?>
      <?php if ($o['mpesa_receipt']): ?><div class="r"><span>Transaction code</span><b><?= e($o['mpesa_receipt']) ?></b></div><?php endif; ?>
      <?php if ($o['submitted_at']): ?><div class="r"><span>Submitted</span><b><?= date('d M Y, g:i A', strtotime($o['submitted_at'])) ?></b></div><?php endif; ?>
      <?php if ($o['verified_at'] && $o['method']==='mpesa'): ?><div class="r"><span><?= $o['pay_status']==='paid' ? 'Approved' : 'Declined' ?> on</span><b><?= date('d M Y, g:i A', strtotime($o['verified_at'])) ?></b></div><?php endif; ?>
      <?php if ($canSubmit): ?>
      <form method="post" id="codeform" style="margin-top:14px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
        <label class="f">M-Pesa transaction code</label>
        <input class="field" name="mpesa_code" maxlength="10" required autocomplete="off" placeholder="e.g. QGH7K2LMNP" style="max-width:240px;text-transform:uppercase">
        <button class="btn" style="margin-left:8px"><?= $pstate==='failed' ? 'Submit corrected code' : 'Submit code' ?></button>
        <p class="muted" style="font-size:.82rem;margin:4px 0 0">Pay <?= money($o['amount']) ?> to <?= e(MPESA_PAY_KIND) ?> <?= MPESA_PAY_NUMBER !== '' ? e(MPESA_PAY_NUMBER) : '' ?> first, then type the code from your M-Pesa confirmation message.</p></form>
      <?php endif; ?>
    </div>
    <?php if ($pstate === 'pending' || $pstate === 'unpaid'): ?><script>
    (function(){var b=document.getElementById('paybox');if(!b)return;var n=0;
    function tick(){fetch(b.dataset.url,{credentials:'same-origin',cache:'no-store'}).then(function(r){return r.json()}).then(function(j){
      if(!j.state)return; if(j.state!==b.dataset.state){location.reload();return;}
      if(++n>450)return; setTimeout(tick,8000);}).catch(function(){setTimeout(tick,15000)});}
    setTimeout(tick,8000);})();</script><?php endif; ?>
    <?php endif; ?>
    <?php foreach ($items as $r): ?>
    <div class="item"><img class="contain" src="<?= img_url($r['image']) ?>" alt="">
      <div class="info"><h3><?= e($r['name']) ?><?= $r['pack_size'] ? ' x '.($r['quantity']*$r['pack_size']) : '' ?></h3>
        <div class="note"><?= $r['pack_size'] ? $r['quantity'].' box'.($r['quantity']>1?'es':'').' of '.$r['pack_size'] : 'Quantity: '.$r['quantity'] ?><?= $r['cake_message'] ? ' · Message on cake: <span style="color:var(--pink)">'.e($r['cake_message']).'</span>' : '' ?></div></div>
      <div class="line"><?= money($r['unit_price']*$r['quantity']) ?></div></div>
    <?php endforeach; ?>
  </div>
  <aside class="card summary">
    <h2>Details</h2>
    <div class="r"><span>Needed</span><b><?= date('d M Y, g:i A', strtotime($o['date_needed'])) ?></b></div>
    <div class="r"><span><?= $o['fulfilment']==='delivery' ? 'Destination' : 'Pickup' ?></span><b style="text-align:right"><?= $o['fulfilment']==='delivery' ? e($o['destination'] ?: 'Delivery').($o['delivery_address'] ? '<br><small style="font-weight:400">'.e($o['delivery_address']).'</small>' : '') : 'At the shop' ?></b></div>
    <div class="r"><span>Subtotal</span><b><?= money($o['total_amount'] - $o['delivery_fee']) ?></b></div>
    <div class="r"><span>Delivery fee</span><b><?= $o['delivery_fee'] > 0 ? money($o['delivery_fee']) : 'Free' ?></b></div>
    <div class="r"><span>Payment</span><b><?= pay_method_label($o['method']) ?> <?= pay_badge($o['pay_status'], $o['method'], !empty($o['mpesa_receipt'])) ?></b></div>
    <?php if ($o['mpesa_receipt']): ?><div class="r"><span>M-Pesa code</span><b><?= e($o['mpesa_receipt']) ?></b></div><?php endif; ?>
    <div class="total"><span>Total</span><b><?= money($o['total_amount']) ?></b></div>
  </aside>
</div>
<?php include 'includes/footer.php';
