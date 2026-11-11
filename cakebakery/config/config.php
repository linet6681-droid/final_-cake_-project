<?php
// Central settings. SECRETS (database password, M-Pesa keys) belong in config/secrets.php, which is NOT shared or committed.
// Copy config/secrets.example.php to config/secrets.php and fill it in. Anything defined there overrides the defaults below.
if (is_file(__DIR__ . '/secrets.php')) require_once __DIR__ . '/secrets.php';
/** Read a setting: environment variable first (hosting panels), else the default. */
function cfg_env(string $name, $default = '') { $v = getenv($name); return ($v !== false && $v !== '') ? $v : $default; }
function cfg_define(string $name, $default): void { if (!defined($name)) define($name, cfg_env($name, $default)); }

cfg_define('DB_HOST', 'localhost');
cfg_define('DB_NAME', 'cakeb');
cfg_define('DB_USER', 'root');
cfg_define('DB_PASS', '');
cfg_define('BASE_URL', '/cakebakery');
define('DEBUG', false);          // errors are always logged to logs/php_errors.log, never shown to visitors
// Delivery fees are set per destination in Admin > Delivery (table delivery_zones). The server always reads the fee from there.
define('MAX_FAILED_LOGINS', 3);
define('LOCK_MINUTES', 15);

// ---- M-Pesa (manual verification) ----
// Customers pay the shop's Till / Paybill from their own phone, then type the M-Pesa transaction code (e.g. QGH7K2LMNP) at checkout.
// The admin compares the code with the M-Pesa confirmation message and Approves or Declines it (Admin > Payments).
cfg_define('MPESA_PAY_KIND', 'Till number');    // shown to the customer: 'Till number' or 'Paybill'
cfg_define('MPESA_PAY_NUMBER', '');             // the shop's Till / Paybill number (put it in config/secrets.php)
cfg_define('MPESA_PAY_ACCOUNT', '');            // Paybill only: the account name customers must type (usually the order number is fine: leave empty)
cfg_define('MPESA_PAY_NAME', 'Cake & Bakery');  // business name the customer sees on the M-Pesa confirmation
// No SMS or phone notifications are sent to customers: every payment update is shown in Order Tracking.
