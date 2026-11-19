<?php
declare(strict_types=1);
// Staff console API: Super Admin, Client Admin, Vendor (§5).
// Visibility rule: super_admin rows are excluded from every non-super-admin query.
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/billing.php';

$u = auth(['super_admin', 'client_admin', 'vendor']);
$act = action();

$routes = [
    'stats'        => ['super_admin', 'client_admin', 'vendor'],
    'ca_list'      => ['super_admin'], 'ca_save' => ['super_admin'], 'ca_modules' => ['super_admin'],
    'settings'     => ['super_admin'], 'settings_save' => ['super_admin'],
    'logs'         => ['super_admin', 'client_admin'],
    'users'        => ['super_admin', 'client_admin', 'vendor'], 'user_save' => ['client_admin'],
    'vendor_save'  => ['client_admin'],
    'sharing_save' => ['client_admin'],
];
if (!isset($routes[$act])) fail('NOT_FOUND', 404);
if (!in_array($u['role'], $routes[$act], true)) fail('FORBIDDEN', 403);
$act($u);

function stats(array $u): never {
    if ($u['role'] === 'super_admin') ok([
        'client_admins' => (int)one('SELECT COUNT(*) c FROM client_admins')['c'],
        'users' => (int)one('SELECT COUNT(*) c FROM users WHERE role="user" AND deleted_at IS NULL')['c'],
        'vendors' => (int)one('SELECT COUNT(*) c FROM users WHERE role="vendor" AND deleted_at IS NULL')['c'],
        'paid_fils_30d' => (int)one('SELECT COALESCE(SUM(total_fils),0) s FROM invoices WHERE payment_status="Paid" AND paid_at > UTC_TIMESTAMP() - INTERVAL 30 DAY')['s'],
        'pending_invoices' => (int)one('SELECT COUNT(*) c FROM invoices WHERE payment_status="Pending"')['c'],
        'active_sync' => (int)one('SELECT COUNT(DISTINCT user_id) c FROM subscriptions WHERE includes_sync=1 AND status="active" AND period_end >= UTC_DATE()')['c'],
    ]);
    if ($u['role'] === 'client_admin') {
        $ca = one('SELECT company, status, expires_at, max_users, sharing_enabled, users_see_shared FROM client_admins WHERE id=?', [$u['id']]);
        ok(['agency' => $ca,
            'users' => (int)one('SELECT COUNT(*) c FROM users WHERE role="user" AND client_admin_id=? AND deleted_at IS NULL', [$u['id']])['c'],
            'vendors' => (int)one('SELECT COUNT(*) c FROM users WHERE role="vendor" AND client_admin_id=? AND deleted_at IS NULL', [$u['id']])['c'],
            'pending_invoices' => (int)one('SELECT COUNT(*) c FROM invoices i JOIN users us ON us.id=i.user_id WHERE us.client_admin_id=? AND i.payment_status="Pending"', [$u['id']])['c'],
            'modules' => effective_modules($u)]);
    }
    ok(['linked_users' => (int)one('SELECT COUNT(*) c FROM users WHERE vendor_id=? AND deleted_at IS NULL', [$u['id']])['c'], 'balance_fils' => vendor_balance($u['id'])]);
}

// ----- Super Admin: Client Admin lifecycle -----
function ca_list(array $u): never {
    $rows = all('SELECT ca.*, us.username, us.name, us.mobile, us.email, us.status login_status,
                 (SELECT COUNT(*) FROM users x WHERE x.client_admin_id=ca.id AND x.role="user" AND x.deleted_at IS NULL) user_count
                 FROM client_admins ca JOIN users us ON us.id=ca.id ORDER BY ca.company');
    foreach ($rows as &$r) $r['module_flags'] = all('SELECT module, enabled FROM module_flags WHERE client_admin_id=?', [$r['id']]);
    ok(['client_admins' => $rows]);
}

function ca_save(array $u): never {
    $id = req('id'); $isNew = !$id;
    $company = str_in('company', 150); $name = str_in('name', 150);
    $status = str_in('status', 10); if (!in_array($status, ['Active', 'Trial', 'Expired'], true)) fail('INVALID_INPUT', 422, ['field' => 'status']);
    $expires = str_in('expires_at', 10, false); if ($expires && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires)) fail('INVALID_INPUT', 422, ['field' => 'expires_at']);
    $max = int_in('max_users', 10); if ($max < 0) fail('INVALID_INPUT', 422, ['field' => 'max_users']);
    $plan = req('plan_id') ?: null; if ($plan && !one('SELECT id FROM plans WHERE id=?', [$plan])) fail('INVALID_INPUT', 422, ['field' => 'plan_id']);
    $isDefault = req('is_default') ? 1 : 0; $sharing = req('sharing_enabled') ? 1 : 0;
    $email = str_in('email', 190, false); $mobile = str_in('mobile', 20, false);
    db()->beginTransaction();
    if ($isNew) {
        $username = strtoupper(str_in('username', 64));
        if (!preg_match('/^[A-Z0-9_.-]{3,64}$/', $username)) fail('INVALID_INPUT', 422, ['field' => 'username']);
        if (one('SELECT id FROM users WHERE username=?', [$username])) fail('CONFLICT', 409, ['field' => 'username']);
        $pw = (string)req('password', ''); if (!valid_password($pw)) fail('PASSWORD_WEAK', 422, ['field' => 'password']);
        $id = uuid4();
        q('INSERT INTO users (id, role, username, name, email, secret_hash, must_change_secret, status) VALUES (?,?,?,?,?,?,1,"active")', [$id, 'client_admin', $username, $name, $email, hash_secret($pw)]);
        q('INSERT INTO client_admins (id, company, status, expires_at, max_users, is_default, plan_id, sharing_enabled) VALUES (?,?,?,?,?,?,?,?)', [$id, $company, $status, $expires, $max, $isDefault, $plan, $sharing]);
    } else {
        if (!one('SELECT id FROM client_admins WHERE id=?', [$id])) fail('NOT_FOUND', 404);
        q('UPDATE users SET name=?, email=?, status=? WHERE id=?', [$name, $email, req('login_status') === 'disabled' ? 'disabled' : 'active', $id]);
        q('UPDATE client_admins SET company=?, status=?, expires_at=?, max_users=?, is_default=?, plan_id=?, sharing_enabled=? WHERE id=?', [$company, $status, $expires, $max, $isDefault, $plan, $sharing, $id]);
        if (($pw = (string)req('password', '')) !== '') { if (!valid_password($pw)) fail('PASSWORD_WEAK', 422); q('UPDATE users SET secret_hash=?, must_change_secret=1 WHERE id=?', [hash_secret($pw), $id]); }
    }
    if ($isDefault) q('UPDATE client_admins SET is_default=0 WHERE id<>?', [$id]);
    db()->commit();
    audit($u, $isNew ? 'client_admin.created' : 'client_admin.updated', ['id' => $id]);
    ok(['id' => $id]);
}

function ca_modules(array $u): never {
    $id = str_in('id', 36); if (!one('SELECT id FROM client_admins WHERE id=?', [$id])) fail('NOT_FOUND', 404);
    q('DELETE FROM module_flags WHERE client_admin_id=?', [$id]);
    foreach ((array)req('flags', []) as $m => $v) {
        if (!in_array($m, MODULES, true) || $v === null || $v === 'plan') continue;
        q('INSERT INTO module_flags (client_admin_id, module, enabled) VALUES (?,?,?)', [$id, $m, $v ? 1 : 0]);
    }
    audit($u, 'client_admin.modules', ['id' => $id, 'flags' => req('flags')]);
    ok(['ok' => true]);
}

function settings(array $u): never {
    $keys = ['company_name', 'company_address', 'company_trn', 'company_email', 'support_whatsapp'];
    $out = []; foreach ($keys as $k) $out[$k] = setting($k, '');
    ok(['settings' => $out, 'vapid_public' => setting('vapid_public')]);
}
function settings_save(array $u): never {
    foreach (['company_name', 'company_address', 'company_trn', 'company_email', 'support_whatsapp'] as $k) { $v = str_in($k, 500, false); set_setting($k, $v ?? ''); }
    audit($u, 'settings.saved');
    ok(['ok' => true]);
}

function logs(array $u): never {
    if ($u['role'] === 'super_admin') $rows = all('SELECT l.*, us.name actor_name FROM audit_logs l LEFT JOIN users us ON us.id=l.actor_id ORDER BY l.id DESC LIMIT 300');
    else $rows = all('SELECT l.id, l.action, l.details, l.created_at, l.actor_role, us.name actor_name FROM audit_logs l LEFT JOIN users us ON us.id=l.actor_id
                      WHERE l.client_admin_id=? AND (l.actor_role IS NULL OR l.actor_role<>"super_admin") ORDER BY l.id DESC LIMIT 300', [$u['id']]);
    ok(['logs' => $rows]);
}

// ----- Users (end users) -----
function users(array $u): never {
    $base = 'SELECT us.id, us.name, us.mobile, us.email, us.dob, us.status, us.vendor_id, us.plan_id, us.can_share_vacancies, us.created_at, us.client_admin_id,
             (SELECT MAX(period_end) FROM subscriptions s WHERE s.user_id=us.id AND s.status="active") sub_until,
             (SELECT MAX(period_end) FROM subscriptions s WHERE s.user_id=us.id AND s.status="active" AND s.includes_sync=1) sync_until
             FROM users us WHERE us.role="user" AND us.deleted_at IS NULL';
    if ($u['role'] === 'super_admin') $rows = all($base . ' ORDER BY us.created_at DESC LIMIT 1000');
    elseif ($u['role'] === 'client_admin') $rows = all($base . ' AND us.client_admin_id=? ORDER BY us.created_at DESC', [$u['id']]);
    else $rows = all($base . ' AND us.vendor_id=? ORDER BY us.name', [$u['id']]);
    $vendors = $u['role'] === 'client_admin' ? all('SELECT us.id, us.name, us.username, us.mobile, us.email, us.status, v.commission_pct FROM users us JOIN vendors v ON v.id=us.id WHERE us.role="vendor" AND v.client_admin_id=? AND us.deleted_at IS NULL ORDER BY us.name', [$u['id']]) : [];
    ok(['users' => $rows, 'vendors' => $vendors]);
}

function user_save(array $u): never {
    $id = req('id');
    $vendor = req('vendor_id') ?: null;
    if ($vendor && !one('SELECT id FROM vendors WHERE id=? AND client_admin_id=?', [$vendor, $u['id']])) fail('INVALID_INPUT', 422, ['field' => 'vendor_id']);
    $plan = req('plan_id') ?: null; if ($plan && !one('SELECT id FROM plans WHERE id=? AND active=1', [$plan])) fail('INVALID_INPUT', 422, ['field' => 'plan_id']);
    $status = req('status') === 'disabled' ? 'disabled' : 'active';
    $share = req('can_share_vacancies') ? 1 : 0;
    if (!$id) {
        $ca = one('SELECT * FROM client_admins WHERE id=?', [$u['id']]);
        if (!client_admin_usable($ca)) fail('CLIENT_EXPIRED', 403);
        $count = (int)one('SELECT COUNT(*) c FROM users WHERE client_admin_id=? AND role="user" AND deleted_at IS NULL', [$u['id']])['c'];
        if ($count >= (int)$ca['max_users']) fail('USER_LIMIT', 403);
        $mobile = str_in('mobile', 20); if (!valid_e164($mobile)) fail('INVALID_MOBILE', 422, ['field' => 'mobile']);
        if (one('SELECT id FROM users WHERE mobile=?', [$mobile])) fail('MOBILE_EXISTS', 409);
        $dob = str_in('dob', 10); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) fail('INVALID_INPUT', 422, ['field' => 'dob']);
        $pin = (string)req('pin', ''); if (!valid_pin($pin)) fail('INVALID_PIN', 422, ['field' => 'pin']);
        $id = uuid4();
        q('INSERT INTO users (id, role, name, mobile, email, dob, secret_hash, client_admin_id, vendor_id, plan_id, status, can_share_vacancies, recovery_secret) VALUES (?, "user", ?,?,?,?,?,?,?,?,?,?,?)',
            [$id, str_in('name', 150), $mobile, str_in('email', 190, false), $dob, hash_secret($pin), $u['id'], $vendor, $plan, $status, $share, bin2hex(random_bytes(32))]);
        audit($u, 'user.created', ['id' => $id]);
    } else {
        if (!one('SELECT id FROM users WHERE id=? AND role="user" AND client_admin_id=?', [$id, $u['id']])) fail('NOT_FOUND', 404);
        q('UPDATE users SET name=?, email=?, vendor_id=?, plan_id=?, status=?, can_share_vacancies=? WHERE id=?', [str_in('name', 150), str_in('email', 190, false), $vendor, $plan, $status, $share, $id]);
        if ($status === 'disabled') q('UPDATE refresh_tokens SET revoked_at=UTC_TIMESTAMP() WHERE user_id=? AND revoked_at IS NULL', [$id]);
        audit($u, 'user.updated', ['id' => $id, 'vendor' => $vendor]);
    }
    ok(['id' => $id]);
}

function vendor_save(array $u): never {
    $id = req('id'); $pct = (float)req('commission_pct', 10); if ($pct < 0 || $pct > 100) fail('INVALID_INPUT', 422, ['field' => 'commission_pct']);
    $name = str_in('name', 150); $email = str_in('email', 190, false); $mobile = str_in('mobile', 20, false);
    if ($mobile && !valid_e164($mobile)) fail('INVALID_MOBILE', 422, ['field' => 'mobile']);
    $status = req('status') === 'disabled' ? 'disabled' : 'active';
    db()->beginTransaction();
    if (!$id) {
        $username = strtoupper(str_in('username', 64)); if (!preg_match('/^[A-Z0-9_.-]{3,64}$/', $username)) fail('INVALID_INPUT', 422, ['field' => 'username']);
        if (one('SELECT id FROM users WHERE username=?', [$username])) fail('CONFLICT', 409, ['field' => 'username']);
        $pw = (string)req('password', ''); if (!valid_password($pw)) fail('PASSWORD_WEAK', 422, ['field' => 'password']);
        $id = uuid4();
        q('INSERT INTO users (id, role, username, name, email, secret_hash, must_change_secret, client_admin_id, status) VALUES (?, "vendor", ?,?,?,?,1,?,?)', [$id, $username, $name, $email, hash_secret($pw), $u['id'], $status]);
        q('INSERT INTO vendors (id, client_admin_id, commission_pct) VALUES (?,?,?)', [$id, $u['id'], $pct]);
    } else {
        if (!one('SELECT id FROM vendors WHERE id=? AND client_admin_id=?', [$id, $u['id']])) fail('NOT_FOUND', 404);
        q('UPDATE users SET name=?, email=?, status=? WHERE id=?', [$name, $email, $status, $id]);
        q('UPDATE vendors SET commission_pct=? WHERE id=?', [$pct, $id]);
        if (($pw = (string)req('password', '')) !== '') { if (!valid_password($pw)) fail('PASSWORD_WEAK', 422); q('UPDATE users SET secret_hash=?, must_change_secret=1 WHERE id=?', [hash_secret($pw), $id]); }
    }
    db()->commit();
    audit($u, 'vendor.saved', ['id' => $id]);
    ok(['id' => $id]);
}

function sharing_save(array $u): never {
    q('UPDATE client_admins SET users_see_shared=? WHERE id=?', [req('users_see_shared') ? 1 : 0, $u['id']]);
    audit($u, 'sharing.settings');
    ok(['ok' => true]);
}
