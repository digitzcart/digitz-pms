<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/whatsapp.php';

const OTP_TTL = 300, OTP_MAX_ATTEMPTS = 5, OTP_COOLDOWN = 60;

switch (action()) {
    case 'signup_start':  signup_start();
    case 'signup_verify': signup_verify();
    case 'otp_resend':    otp_resend();
    case 'login':         login();
    case 'refresh':       refresh();
    case 'logout':        logout();
    case 'reset_start':   reset_start();
    case 'reset_verify':  reset_verify();
    case 'me':            me();
    case 'change_secret': change_secret();
    default:              fail('NOT_FOUND', 404);
}

function pick_client_admin(): array {
    $ca = one('SELECT * FROM client_admins WHERE is_default=1 LIMIT 1');
    if (!$ca || !client_admin_usable($ca)) {
        $active = array_values(array_filter(all('SELECT * FROM client_admins'), 'client_admin_usable'));
        if ($ca && !client_admin_usable($ca)) fail('CLIENT_EXPIRED', 403);
        if (count($active) !== 1) fail('NO_CLIENT_ADMIN', 503);
        $ca = $active[0];
    }
    $count = (int)one('SELECT COUNT(*) c FROM users WHERE client_admin_id=? AND role="user" AND deleted_at IS NULL', [$ca['id']])['c'];
    if ($count >= (int)$ca['max_users']) fail('USER_LIMIT', 403);
    return $ca;
}

function create_otp(string $mobile, string $purpose, array $payload): array {
    global $CFG;
    $prev = one('SELECT last_sent_at FROM otps WHERE mobile=? AND purpose=? AND consumed_at IS NULL ORDER BY last_sent_at DESC LIMIT 1', [$mobile, $purpose]);
    if ($prev && strtotime($prev['last_sent_at'] . ' UTC') > time() - OTP_COOLDOWN) fail('OTP_COOLDOWN', 429, ['retry_after' => OTP_COOLDOWN - (time() - strtotime($prev['last_sent_at'] . ' UTC'))]);
    q('UPDATE otps SET consumed_at=UTC_TIMESTAMP() WHERE mobile=? AND purpose=? AND consumed_at IS NULL', [$mobile, $purpose]);
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $id = uuid4();
    q('INSERT INTO otps (id, mobile, purpose, code_hash, payload, expires_at, last_sent_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())',
        [$id, $mobile, $purpose, password_hash($code, PASSWORD_BCRYPT), json_encode($payload), gmdate('Y-m-d H:i:s', time() + OTP_TTL)]);
    if (!send_whatsapp_otp($mobile, $code)) fail('OTP_SEND_FAILED', 502);
    $out = ['otp_id' => $id, 'expires_in' => OTP_TTL, 'resend_in' => OTP_COOLDOWN];
    if (!empty($CFG['otp_dev_mode'])) $out['dev_code'] = $code;
    return $out;
}

/** Validates an OTP and returns its row. Consumes it on success. */
function check_otp(string $id, string $code, string $purpose): array {
    $o = one('SELECT * FROM otps WHERE id=? AND purpose=?', [$id, $purpose]);
    if (!$o || $o['consumed_at']) fail('OTP_EXPIRED', 410);
    if (strtotime($o['expires_at'] . ' UTC') < time()) fail('OTP_EXPIRED', 410);
    if ((int)$o['attempts'] >= OTP_MAX_ATTEMPTS) fail('OTP_LOCKED', 429);
    q('UPDATE otps SET attempts = attempts + 1 WHERE id=?', [$id]);
    if (!preg_match('/^\d{6}$/', $code) || !password_verify($code, $o['code_hash'])) {
        $left = OTP_MAX_ATTEMPTS - (int)$o['attempts'] - 1;
        if ($left <= 0) fail('OTP_LOCKED', 429);
        fail('OTP_INVALID', 422, ['attempts_left' => $left]);
    }
    q('UPDATE otps SET consumed_at=UTC_TIMESTAMP() WHERE id=?', [$id]);
    return $o;
}

function session_payload(array $u, bool $withRecovery = false): array {
    $out = ['user' => public_user($u), 'modules' => effective_modules($u), 'sync' => null, 'agency' => null];
    if ($u['role'] !== 'super_admin') {
        $ca = tenant_of($u) ? one('SELECT company, status, expires_at, sharing_enabled, users_see_shared FROM client_admins WHERE id=?', [tenant_of($u)]) : null;
        $out['agency'] = $ca;
    }
    if ($u['role'] === 'user') { $s = sync_active($u['id']); $out['sync'] = $s ? ['active' => true, 'until' => $s['period_end']] : ['active' => false]; }
    if ($withRecovery && $u['role'] === 'user') $out['recovery_secret'] = $u['recovery_secret'];
    return $out;
}

function signup_start(): never {
    rate_limit('signup:' . client_ip(), 10, 3600);
    $name = str_in('name', 150); $mobile = str_in('mobile', 20); $email = str_in('email', 190, false); $dob = str_in('dob', 10); $pin = (string)req('pin', '');
    if (!valid_e164($mobile)) fail('INVALID_MOBILE', 422, ['field' => 'mobile']);
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('INVALID_INPUT', 422, ['field' => 'email']);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob) || !strtotime($dob) || $dob > gmdate('Y-m-d')) fail('INVALID_INPUT', 422, ['field' => 'dob']);
    if (!valid_pin($pin)) fail('INVALID_PIN', 422, ['field' => 'pin']);
    rate_limit('otp:' . $mobile, 5, 3600);
    if (one('SELECT id FROM users WHERE mobile=?', [$mobile])) fail('MOBILE_EXISTS', 409);
    $ca = pick_client_admin();
    ok(create_otp($mobile, 'signup', ['name' => $name, 'email' => $email, 'dob' => $dob, 'pin_hash' => hash_secret($pin), 'ca' => $ca['id']]));
}

function otp_resend(): never {
    $id = str_in('otp_id', 36);
    $o = one('SELECT * FROM otps WHERE id=?', [$id]); if (!$o) fail('OTP_EXPIRED', 410);
    rate_limit('otp:' . $o['mobile'], 5, 3600);
    ok(create_otp($o['mobile'], $o['purpose'], json_decode($o['payload'] ?? '{}', true) ?: []));
}

function signup_verify(): never {
    rate_limit('otpv:' . client_ip(), 30, 3600);
    $o = check_otp(str_in('otp_id', 36), str_in('code', 6), 'signup');
    $p = json_decode($o['payload'], true);
    db()->beginTransaction();
    if (one('SELECT id FROM users WHERE mobile=? FOR UPDATE', [$o['mobile']])) { db()->rollBack(); fail('MOBILE_EXISTS', 409); }
    $ca = one('SELECT * FROM client_admins WHERE id=? FOR UPDATE', [$p['ca']]);
    if (!client_admin_usable($ca)) { db()->rollBack(); fail('CLIENT_EXPIRED', 403); }
    $count = (int)one('SELECT COUNT(*) c FROM users WHERE client_admin_id=? AND role="user" AND deleted_at IS NULL', [$ca['id']])['c'];
    if ($count >= (int)$ca['max_users']) { db()->rollBack(); fail('USER_LIMIT', 403); }
    $id = uuid4();
    q('INSERT INTO users (id, role, name, mobile, email, dob, secret_hash, client_admin_id, status, recovery_secret) VALUES (?,?,?,?,?,?,?,?, "active", ?)',
        [$id, 'user', $p['name'], $o['mobile'], $p['email'], $p['dob'], $p['pin_hash'], $ca['id'], bin2hex(random_bytes(32))]);
    db()->commit();
    $u = one('SELECT * FROM users WHERE id=?', [$id]);
    audit($u, 'user.signup', ['mobile' => $o['mobile']]);
    ok(array_merge(session_payload($u, true), issue_tokens($u)), 201);
}

function login(): never {
    rate_limit('login:' . client_ip(), 30, 900);
    $username = req('username'); $mobile = req('mobile');
    if ($username) { $u = one('SELECT * FROM users WHERE username=? AND role<>"user" AND deleted_at IS NULL', [strtoupper(trim((string)$username))]); $secret = (string)req('password', ''); }
    else { $m = (string)$mobile; if (!valid_e164($m)) fail('INVALID_MOBILE', 422); $u = one('SELECT * FROM users WHERE mobile=? AND role="user" AND deleted_at IS NULL', [$m]); $secret = (string)req('pin', ''); }
    rate_limit('login-acct:' . ($username ?: $mobile), 20, 3600);
    if (!$u || $u['status'] !== 'active') { password_verify($secret, '$2y$10$usesomesillystringforsaltDUMMYhashxxxxxxxxxxxxxxxxxxx'); fail('BAD_CREDENTIALS', 401); }
    if ($u['locked_until'] && strtotime($u['locked_until'] . ' UTC') > time()) fail('ACCOUNT_LOCKED', 423, ['retry_after' => strtotime($u['locked_until'] . ' UTC') - time()]);
    if (!password_verify($secret, (string)$u['secret_hash'])) {
        $fails = (int)$u['failed_logins'] + 1;
        if ($fails >= 5) {
            $mins = 5 * (2 ** min((int)$u['lockouts'], 6));     // 5, 10, 20 … minutes
            q('UPDATE users SET failed_logins=0, lockouts=lockouts+1, locked_until=? WHERE id=?', [gmdate('Y-m-d H:i:s', time() + $mins * 60), $u['id']]);
            fail('ACCOUNT_LOCKED', 423, ['retry_after' => $mins * 60]);
        }
        q('UPDATE users SET failed_logins=? WHERE id=?', [$fails, $u['id']]);
        fail('BAD_CREDENTIALS', 401, ['attempts_left' => 5 - $fails]);
    }
    if ($u['role'] !== 'super_admin' && $u['role'] !== 'user') {
        $ca = one('SELECT * FROM client_admins WHERE id=?', [tenant_of($u)]);
        if (!client_admin_usable($ca)) fail('CLIENT_EXPIRED', 403);
    }
    if (password_needs_rehash($u['secret_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) q('UPDATE users SET secret_hash=? WHERE id=?', [hash_secret($secret), $u['id']]);
    q('UPDATE users SET failed_logins=0, lockouts=0, locked_until=NULL WHERE id=?', [$u['id']]);
    audit($u, 'auth.login');
    ok(array_merge(session_payload($u, $u['role'] === 'user'), issue_tokens($u)));
}

function refresh(): never {
    rate_limit('refresh:' . client_ip(), 120, 3600);
    $t = str_in('refresh_token', 128);
    $r = one('SELECT * FROM refresh_tokens WHERE token_hash=?', [hash('sha256', $t)]);
    if (!$r) fail('UNAUTHORIZED', 401);
    if ($r['revoked_at']) { q('UPDATE refresh_tokens SET revoked_at=UTC_TIMESTAMP() WHERE user_id=? AND revoked_at IS NULL', [$r['user_id']]); fail('UNAUTHORIZED', 401); } // reuse detected
    if (strtotime($r['expires_at'] . ' UTC') < time()) fail('UNAUTHORIZED', 401);
    $u = one('SELECT * FROM users WHERE id=? AND status="active" AND deleted_at IS NULL', [$r['user_id']]); if (!$u) fail('UNAUTHORIZED', 401);
    q('UPDATE refresh_tokens SET revoked_at=UTC_TIMESTAMP() WHERE id=?', [$r['id']]);
    ok(array_merge(session_payload($u), issue_tokens($u)));
}

function logout(): never {
    $t = (string)req('refresh_token', '');
    if ($t) q('UPDATE refresh_tokens SET revoked_at=UTC_TIMESTAMP() WHERE token_hash=?', [hash('sha256', $t)]);
    ok(['ok' => true]);
}

function reset_start(): never {
    rate_limit('reset:' . client_ip(), 10, 3600);
    $mobile = str_in('mobile', 20); $dob = str_in('dob', 10);
    if (!valid_e164($mobile)) fail('INVALID_MOBILE', 422);
    rate_limit('otp:' . $mobile, 5, 3600);
    $u = one('SELECT id, dob FROM users WHERE mobile=? AND role="user" AND deleted_at IS NULL AND status="active"', [$mobile]);
    if (!$u || $u['dob'] !== $dob) fail('DOB_MISMATCH', 422);
    ok(create_otp($mobile, 'reset', ['user_id' => $u['id']]));
}

function reset_verify(): never {
    rate_limit('otpv:' . client_ip(), 30, 3600);
    $pin = (string)req('new_pin', '');
    if (!valid_pin($pin)) fail('INVALID_PIN', 422, ['field' => 'new_pin']);
    $o = check_otp(str_in('otp_id', 36), str_in('code', 6), 'reset');
    $p = json_decode($o['payload'], true);
    // Credentials only: no data is touched. All sessions are revoked.
    q('UPDATE users SET secret_hash=?, failed_logins=0, lockouts=0, locked_until=NULL WHERE id=?', [hash_secret($pin), $p['user_id']]);
    q('UPDATE refresh_tokens SET revoked_at=UTC_TIMESTAMP() WHERE user_id=? AND revoked_at IS NULL', [$p['user_id']]);
    $u = one('SELECT * FROM users WHERE id=?', [$p['user_id']]);
    audit($u, 'auth.pin_reset');
    ok(array_merge(session_payload($u, true), issue_tokens($u)));
}

function me(): never { $u = auth([], true); ok(session_payload($u)); }

function change_secret(): never {
    $u = auth([], true);
    rate_limit('chg:' . $u['id'], 10, 3600);
    $cur = (string)req('current', ''); $new = (string)req('new', '');
    if (!password_verify($cur, (string)$u['secret_hash'])) fail('BAD_CREDENTIALS', 401);
    if ($u['role'] === 'user' ? !valid_pin($new) : !valid_password($new)) fail($u['role'] === 'user' ? 'INVALID_PIN' : 'PASSWORD_WEAK', 422);
    q('UPDATE users SET secret_hash=?, must_change_secret=0 WHERE id=?', [hash_secret($new), $u['id']]);
    audit($u, 'auth.secret_changed');
    ok(['ok' => true]);
}
