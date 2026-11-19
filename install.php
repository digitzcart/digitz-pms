<?php
declare(strict_types=1);
// Digitz PMS installer. Creates config.php, the database schema, the Super Admin,
// VAPID keys, sample plans and (optionally) the default Client Admin.
// Delete this file (or keep install.lock in place) after installation.

$root = __DIR__;
$lock = "$root/install.lock";
header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: DENY');
if (is_file($lock)) { http_response_code(403); exit('<p style="font-family:sans-serif">Digitz PMS is already installed. Remove <code>install.lock</code> to reinstall.</p>'); }

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$err = ''; $done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $p = fn($k, $d = '') => trim((string)($_POST[$k] ?? $d));
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $p('db_host', 'localhost'), (int)$p('db_port', '3306'), $p('db_name'));
        $pdo = new PDO($dsn, $p('db_user'), $_POST['db_pass'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("SET time_zone = '+00:00'");
        foreach (array_filter(array_map('trim', explode(";\n", file_get_contents("$root/schema.sql")))) as $stmt) {
            if (preg_match('/^\s*(--.*\n?)*\s*$/', $stmt)) continue;
            $pdo->exec($stmt);
        }
        $uuid = function () { $b = random_bytes(16); $b[6] = chr((ord($b[6]) & 0x0f) | 0x40); $b[8] = chr((ord($b[8]) & 0x3f) | 0x80); return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4)); };
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;

        // Super Admin — default credentials per spec; a new password is required on first login.
        if (!$pdo->query("SELECT id FROM users WHERE role='super_admin' LIMIT 1")->fetch()) {
            $pdo->prepare("INSERT INTO users (id, role, username, name, secret_hash, must_change_secret, status) VALUES (?, 'super_admin', 'SUPERADMIN', 'Super Admin', ?, 1, 'active')")
                ->execute([$uuid(), password_hash('Superadmin@123', $algo)]);
        }

        // VAPID keys for Web Push
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($key, $pem); $d = openssl_pkey_get_details($key)['ec'];
        $pub = rtrim(strtr(base64_encode("\x04" . str_pad($d['x'], 32, "\x00", STR_PAD_LEFT) . str_pad($d['y'], 32, "\x00", STR_PAD_LEFT)), '+/', '-_'), '=');
        $set = $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v=VALUES(v)');
        if (!$pdo->query("SELECT k FROM settings WHERE k='vapid_public'")->fetch()) { $set->execute(['vapid_private_pem', $pem]); $set->execute(['vapid_public', $pub]); }
        $set->execute(['company_name', $p('company', 'Digitz PMS')]);

        // Sample plans (amounts in fils)
        if (!$pdo->query('SELECT id FROM plans LIMIT 1')->fetch()) {
            $ins = $pdo->prepare('INSERT INTO plans (id, name, base_monthly_fils, base_yearly_fils, sync_monthly_fils, sync_yearly_fils, modules) VALUES (?,?,?,?,?,?,?)');
            $ins->execute([$uuid(), 'Starter', 2900, 29000, 1500, 15000, '[]']);
            $ins->execute([$uuid(), 'Professional', 7900, 79000, 2500, 25000, '["sharing"]']);
            $ins->execute([$uuid(), 'Family+', 9900, 99000, 2500, 25000, '["sharing","health","family"]']);
        }

        // Optional default Client Admin (needed for self sign-up)
        if ($p('ca_username') !== '') {
            $pw = $_POST['ca_password'] ?? '';
            if (strlen($pw) < 10 || !preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) throw new RuntimeException('Client Admin password must be at least 10 characters with letters and numbers.');
            $id = $uuid(); $plan = $pdo->query("SELECT id FROM plans WHERE name='Family+' LIMIT 1")->fetchColumn() ?: null;
            $pdo->prepare("INSERT INTO users (id, role, username, name, secret_hash, must_change_secret, status) VALUES (?, 'client_admin', ?, ?, ?, 0, 'active')")
                ->execute([$id, strtoupper($p('ca_username')), $p('ca_company', 'Default Agency'), password_hash($pw, $algo)]);
            $pdo->prepare("INSERT INTO client_admins (id, company, status, expires_at, max_users, is_default, plan_id, sharing_enabled) VALUES (?, ?, 'Active', NULL, ?, 1, ?, 1)")
                ->execute([$id, $p('ca_company', 'Default Agency'), max(1, (int)$p('ca_max', '100')), $plan]);
        }

        $cfg = file_get_contents("$root/config.sample.php");
        $repl = [
            "'https://pms.example.ae'" => var_export(rtrim($p('app_url'), '/'), true),
            "'mysql:host=localhost;dbname=digitz_pms;charset=utf8mb4'" => var_export($dsn, true),
            "'user' => 'digitz'" => "'user' => " . var_export($p('db_user'), true),
            "'pass' => 'change-me'" => "'pass' => " . var_export($_POST['db_pass'] ?? '', true),
            "'CHANGE_ME'" => var_export(bin2hex(random_bytes(48)), true),
            "'otp_dev_mode' => false" => "'otp_dev_mode' => " . (!empty($_POST['dev_otp']) ? 'true' : 'false'),
            "'phone_number_id' => ''" => "'phone_number_id' => " . var_export($p('wa_phone_id'), true),
            "'access_token'    => ''" => "'access_token'    => " . var_export($p('wa_token'), true),
        ];
        $cfg = strtr($cfg, $repl);
        if (file_put_contents("$root/config.php", $cfg) === false) throw new RuntimeException('Cannot write config.php — check folder permissions.');
        @chmod("$root/config.php", 0640);
        file_put_contents($lock, gmdate('c'));
        $done = true;
    } catch (Throwable $e) { $err = $e->getMessage(); }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Install Digitz PMS</title>
<style>body{font-family:system-ui,sans-serif;background:#1c1a17;color:#f4f1ea;max-width:640px;margin:40px auto;padding:0 16px}h1{font-weight:900;text-transform:uppercase;letter-spacing:-.02em}h1 span{color:#ffe94d}fieldset{border:1px solid #3a362f;border-radius:14px;margin:16px 0;padding:16px}legend{color:#ffe94d;font-weight:800;padding:0 6px}label{display:block;margin:10px 0 4px;font-size:14px;color:#c9c3b6}input{width:100%;box-sizing:border-box;padding:11px;border-radius:10px;border:1px solid #3a362f;background:#26231f;color:#fff;font-size:15px}button{background:#ffe94d;color:#1c1a17;border:0;border-radius:999px;padding:14px 26px;font-weight:900;font-size:16px;cursor:pointer}.err{background:#5a1f1f;padding:12px;border-radius:10px}.ok{background:#1f3d2a;padding:16px;border-radius:12px}code{background:#26231f;padding:2px 6px;border-radius:6px}small{color:#9a9384}</style></head><body>
<h1>Install <span>Digitz PMS</span></h1>
<?php if ($done): ?>
<div class="ok"><p><strong>Installation complete.</strong></p>
<p>Super Admin login: <code>SUPERADMIN</code> / <code>Superadmin@123</code>. You will be asked to choose a new password on first login.</p>
<p>Next: add a cron job, e.g. <code>*/30 * * * * php <?= h($root) ?>/cron/reminders.php</code>, then delete <code>install.php</code>.</p>
<p><a style="color:#ffe94d" href="./">Open the app →</a></p></div>
<?php else: ?>
<?php if ($err): ?><p class="err"><?= h($err) ?></p><?php endif; ?>
<?php if (empty($_SERVER['HTTPS']) && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'])): ?><p class="err">Warning: you are not on HTTPS. The API refuses plain HTTP.</p><?php endif; ?>
<form method="post" autocomplete="off">
<fieldset><legend>Database (MySQL 8 / MariaDB 10.6+)</legend>
<label>Host</label><input name="db_host" value="localhost" required>
<label>Port</label><input name="db_port" value="3306" inputmode="numeric">
<label>Database name</label><input name="db_name" required>
<label>User</label><input name="db_user" required>
<label>Password</label><input name="db_pass" type="password"></fieldset>
<fieldset><legend>Application</legend>
<label>Public URL (https://…)</label><input name="app_url" type="url" required placeholder="https://pms.example.ae">
<label>Company name (on invoices)</label><input name="company" value="Digitz PMS"></fieldset>
<fieldset><legend>WhatsApp Business Cloud API</legend>
<label>Phone number ID</label><input name="wa_phone_id">
<label>Access token</label><input name="wa_token" type="password">
<label><input type="checkbox" name="dev_otp" value="1" style="width:auto"> Development OTP mode (returns codes in API responses — never in production)</label></fieldset>
<fieldset><legend>Default Client Admin (optional, enables self sign-up)</legend>
<label>Agency / company</label><input name="ca_company" value="Default Agency">
<label>Username</label><input name="ca_username" placeholder="AGENCY1">
<label>Password <small>(10+ chars, letters and numbers)</small></label><input name="ca_password" type="password">
<label>Maximum users</label><input name="ca_max" value="100" inputmode="numeric"></fieldset>
<button type="submit">Install</button>
</form>
<?php endif; ?>
</body></html>
