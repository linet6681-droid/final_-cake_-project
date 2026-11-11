<?php
require_once 'includes/auth.php';
$err = null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_check();
    $err = login_user(trim($_POST['email']??''), $_POST['password']??'', !empty($_POST['remember']));
    if (!$err) redirect(current_user()['role']==='admin' ? 'admin/index.php' : 'index.php');
}
$layout='auth'; $active='login'; $title='Login'; include 'includes/header.php'; ?>
<section class="auth">
  <div>
    <p class="eyebrow">Welcome back</p>
    <h1>Sign in to your<br><span class="nw"><em>Cake &amp; Bakery</em> account</span></h1>
    <p class="lead">Order fresh cakes, cupcakes and bread online, pay with M-Pesa and track your order.</p>
   
    <img class="photo" src="assets\img\uploads\wedding-cake.jpg" alt="wedding">
  </div>
  <form method="post" class="card auth-card" autocomplete="on">
    <h2>Login</h2>
    <p class="sub">Customers and administrators use the same form</p>
    <?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>
    <?= csrf_field() ?>
    <div class="row"><label class="f" for="email">Email</label>
      <input class="field" id="email" type="email" name="email" placeholder="example@gmail.com" required value="<?= e($_POST['email'] ?? '') ?>"></div>
    <div class="row"><label class="f" for="pw">Password</label>
      <div class="pw-wrap"><input class="field" id="pw" type="password" name="password" placeholder="••••••••••" required><button type="button" class="pw-toggle" aria-label="Show password" aria-pressed="false" aria-controls="pw"><svg class="i-eye" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg><svg class="i-eye-off" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.9 10.9 0 0 1 12 19C5 19 1 12 1 12a18.5 18.5 0 0 1 5.06-5.94M9.9 4.24A9.1 9.1 0 0 1 12 5c7 0 11 7 11 7a18.5 18.5 0 0 1-2.16 3.19M14.12 14.12a3 3 0 1 1-4.24-4.24"/><path d="M1 1l22 22"/></svg></button></div></div>
    <div class="meta"><label><input type="checkbox" name="remember" value="1"> Remember me</label><a href="<?= url('forgot_password.php') ?>">Forgot password?</a></div>
    <button class="btn btn-block">Login</button>
    <p class="alt">New customer? <a href="<?= url('register.php') ?>">Register here</a></p>
  </form>
</section>
<?php include 'includes/footer.php';
