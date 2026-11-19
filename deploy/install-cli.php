<?php
// First-time, non-interactive install for a server (used by setup-server.sh).
// Usage: php install-cli.php  (reads settings from environment variables)
//   DB_NAME DB_USER DB_PASS APP_URL COMPANY OTP_DEV(0|1) WA_PHONE_ID WA_TOKEN MAIL_FROM
//   CA_COMPANY CA_USERNAME CA_PASSWORD CA_MAX   (optional default agency)
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$root = dirname(__DIR__);
$e = fn($k, $d = '') => getenv($k) !== false ? getenv($k) : $d;
$dsn = sprintf('mysql:host=localhost;dbname=%s;charset=utf8mb4', $e('DB_NAME'));
$pdo = new PDO($dsn, $e('DB_USER'), $e('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("SET time_zone = '+00:00'");
foreach (array_filter(array_map('trim', explode(";\n", file_get_contents("$root/schema.sql")))) as $s) { if (!preg_match('/^\s*(--.*\n?)*\s*$/', $s)) $pdo->exec($s); }
$uuid = function () { $b = random_bytes(16); $b[6] = chr((ord($b[6]) & 0x0f) | 0x40); $b[8] = chr((ord($b[8]) & 0x3f) | 0x80); return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4)); };
$algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
if (!$pdo->query("SELECT id FROM users WHERE role='super_admin'")->fetch())
    $pdo->prepare("INSERT INTO users (id, role, username, name, secret_hash, must_change_secret, status) VALUES (?, 'super_admin', 'SUPERADMIN', 'Super Admin', ?, 1, 'active')")->execute([$uuid(), password_hash('Superadmin@123', $algo)]);
$set = $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v=VALUES(v)');
if (!$pdo->query("SELECT k FROM settings WHERE k='vapid_public'")->fetch()) {
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    openssl_pkey_export($key, $pem); $d = openssl_pkey_get_details($key)['ec'];
    $pub = rtrim(strtr(base64_encode("\x04" . str_pad($d['x'], 32, "\x00", STR_PAD_LEFT) . str_pad($d['y'], 32, "\x00", STR_PAD_LEFT)), '+/', '-_'), '=');
    $set->execute(['vapid_private_pem', $pem]); $set->execute(['vapid_public', $pub]);
}
$set->execute(['company_name', $e('COMPANY', 'Digitz PMS')]);
if (!$pdo->query('SELECT id FROM plans LIMIT 1')->fetch()) {
    $ins = $pdo->prepare('INSERT INTO plans (id, name, base_monthly_fils, base_yearly_fils, sync_monthly_fils, sync_yearly_fils, modules) VALUES (?,?,?,?,?,?,?)');
    $ins->execute([$uuid(), 'Starter', 2900, 29000, 1500, 15000, '[]']);
    $ins->execute([$uuid(), 'Professional', 7900, 79000, 2500, 25000, '["sharing"]']);
    $ins->execute([$uuid(), 'Family+', 9900, 99000, 2500, 25000, '["sharing","health","family"]']);
}
if ($e('CA_USERNAME') !== '' && !$pdo->query("SELECT id FROM client_admins LIMIT 1")->fetch()) {
    $id = $uuid(); $plan = $pdo->query("SELECT id FROM plans WHERE name='Family+'")->fetchColumn() ?: null;
    $pdo->prepare("INSERT INTO users (id, role, username, name, secret_hash, must_change_secret, status) VALUES (?, 'client_admin', ?, ?, ?, 1, 'active')")->execute([$id, strtoupper($e('CA_USERNAME')), $e('CA_COMPANY', 'Default Agency'), password_hash($e('CA_PASSWORD'), $algo)]);
    $pdo->prepare("INSERT INTO client_admins (id, company, status, max_users, is_default, plan_id, sharing_enabled) VALUES (?, ?, 'Active', ?, 1, ?, 1)")->execute([$id, $e('CA_COMPANY', 'Default Agency'), (int)$e('CA_MAX', '100'), $plan]);
}
if (!is_file("$root/config.php")) {
    $cfg = strtr(file_get_contents("$root/config.sample.php"), [
        "'https://pms.example.ae'" => var_export(rtrim($e('APP_URL'), '/'), true),
        "'mysql:host=localhost;dbname=digitz_pms;charset=utf8mb4'" => var_export($dsn, true),
        "'user' => 'digitz'" => "'user' => " . var_export($e('DB_USER'), true),
        "'pass' => 'change-me'" => "'pass' => " . var_export($e('DB_PASS'), true),
        "'CHANGE_ME'" => var_export(bin2hex(random_bytes(48)), true),
        "'otp_dev_mode' => false" => "'otp_dev_mode' => " . ($e('OTP_DEV') === '1' ? 'true' : 'false'),
        "'phone_number_id' => ''" => "'phone_number_id' => " . var_export($e('WA_PHONE_ID'), true),
        "'access_token'    => ''" => "'access_token'    => " . var_export($e('WA_TOKEN'), true),
        "'mailto:admin@example.ae'" => var_export('mailto:' . $e('MAIL_ADMIN', 'admin@example.ae'), true),
        "'Digitz PMS <no-reply@example.ae>'" => var_export($e('MAIL_FROM', 'Digitz PMS <no-reply@example.ae>'), true),
    ]);
    file_put_contents("$root/config.php", $cfg); chmod("$root/config.php", 0640);
}
file_put_contents("$root/install.lock", gmdate('c'));
echo "Installed. Super Admin: SUPERADMIN / Superadmin@123 (change it at first login)\n";
