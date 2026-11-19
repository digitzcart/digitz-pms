<?php
declare(strict_types=1);
// Plans, subscriptions, invoices and receipts (§6.2).
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/billing.php';

switch (action()) {
    case 'plans':        plans();
    case 'my':           my();
    case 'request':      request_sub();
    case 'invoice':      invoice();
    case 'list':         list_invoices();
    case 'mark':         mark();
    case 'plan_save':    plan_save();
    case 'plan_delete':  plan_delete();
    default:             fail('NOT_FOUND', 404);
}

function plan_out(array $p): array { $p['modules'] = json_decode($p['modules'], true) ?: []; foreach (['base_monthly_fils','base_yearly_fils','sync_monthly_fils','sync_yearly_fils'] as $k) $p[$k] = (int)$p[$k]; $p['active'] = (bool)$p['active']; return $p; }

function plans(): never {
    $u = auth();
    $sql = $u['role'] === 'super_admin' ? 'SELECT * FROM plans ORDER BY base_monthly_fils' : 'SELECT * FROM plans WHERE active=1 ORDER BY base_monthly_fils';
    ok(['plans' => array_map('plan_out', all($sql))]);
}

function my(): never {
    $u = auth(['user']);
    ok([
        'subscriptions' => all('SELECT s.*, p.name plan_name FROM subscriptions s JOIN plans p ON p.id=s.plan_id WHERE s.user_id=? ORDER BY s.created_at DESC', [$u['id']]),
        'invoices' => array_map(fn($i) => $i + ['items' => json_decode($i['items'], true)], all('SELECT * FROM invoices WHERE user_id=? ORDER BY seq DESC', [$u['id']])),
        'sync' => sync_active($u['id']),
    ]);
}

function request_sub(): never {
    $u = auth(['user']);
    rate_limit('subreq:' . $u['id'], 10, 3600);
    $plan = one('SELECT * FROM plans WHERE id=? AND active=1', [str_in('plan_id', 36)]); if (!$plan) fail('NOT_FOUND', 404);
    $cycle = req('cycle') === 'yearly' ? 'yearly' : 'monthly';
    $withSync = (bool)req('includes_sync', false);
    if (one('SELECT id FROM invoices WHERE user_id=? AND payment_status="Pending"', [$u['id']])) fail('CONFLICT', 409);
    $last = one('SELECT MAX(period_end) e FROM subscriptions WHERE user_id=? AND status="active"', [$u['id']]);
    $start = ($last['e'] ?? null) && $last['e'] >= gmdate('Y-m-d') ? date('Y-m-d', strtotime($last['e'] . ' +1 day')) : gmdate('Y-m-d');
    $end = date('Y-m-d', strtotime($start . ($cycle === 'yearly' ? ' +1 year' : ' +1 month') . ' -1 day'));
    $items = [['label' => $plan['name'] . ' — ' . ($cycle === 'yearly' ? 'Yearly' : 'Monthly'), 'amount_fils' => (int)$plan[$cycle === 'yearly' ? 'base_yearly_fils' : 'base_monthly_fils']]];
    if ($withSync) $items[] = ['label' => 'Cloud Sync add-on', 'amount_fils' => (int)$plan[$cycle === 'yearly' ? 'sync_yearly_fils' : 'sync_monthly_fils']];
    $total = array_sum(array_column($items, 'amount_fils'));

    db()->beginTransaction();
    $sid = uuid4(); $iid = uuid4(); $seq = next_seq('invoice');
    q('INSERT INTO subscriptions (id, user_id, plan_id, cycle, includes_sync, period_start, period_end, status) VALUES (?,?,?,?,?,?,?, "pending")', [$sid, $u['id'], $plan['id'], $cycle, $withSync ? 1 : 0, $start, $end]);
    q('INSERT INTO invoices (id, number, seq, subscription_id, user_id, items, total_fils) VALUES (?,?,?,?,?,?,?)', [$iid, sprintf('INV-%s-%06d', gmdate('Y'), $seq), $seq, $sid, $u['id'], json_encode($items, JSON_UNESCAPED_UNICODE), $total]);
    db()->commit();
    audit($u, 'subscription.requested', ['invoice' => $iid]);
    ok(['invoice_id' => $iid], 201);
}

/** Returns invoice if actor may see it (owner, its client admin, super admin). */
function scoped_invoice(array $actor, string $id): array {
    $inv = one('SELECT i.*, u.name user_name, u.mobile user_mobile, u.email user_email, u.client_admin_id FROM invoices i JOIN users u ON u.id=i.user_id WHERE i.id=?', [$id]);
    if (!$inv) fail('NOT_FOUND', 404);
    $okScope = $actor['role'] === 'super_admin' || ($actor['role'] === 'user' && $inv['user_id'] === $actor['id']) || ($actor['role'] === 'client_admin' && $inv['client_admin_id'] === $actor['id']);
    if (!$okScope) fail('NOT_FOUND', 404);
    return $inv;
}

function invoice(): never {
    $u = auth();
    $inv = scoped_invoice($u, str_in('id', 36));
    $inv['items'] = json_decode($inv['items'], true);
    $sub = one('SELECT s.*, p.name plan_name FROM subscriptions s JOIN plans p ON p.id=s.plan_id WHERE s.id=?', [$inv['subscription_id']]);
    $ca = $inv['client_admin_id'] ? one('SELECT company FROM client_admins WHERE id=?', [$inv['client_admin_id']]) : null;
    ok(['invoice' => $inv, 'subscription' => $sub, 'issuer' => ['name' => setting('company_name', 'Digitz PMS'), 'address' => setting('company_address', ''), 'trn' => setting('company_trn', '')], 'agency' => $ca]);
}

function list_invoices(): never {
    $u = auth(['super_admin', 'client_admin']);
    $where = ['1=1']; $p = [];
    if ($u['role'] === 'client_admin') { $where[] = 'u.client_admin_id=?'; $p[] = $u['id']; }
    if (in_array(req('status'), ['Pending', 'Paid', 'Failed', 'Refunded'], true)) { $where[] = 'i.payment_status=?'; $p[] = req('status'); }
    $rows = all('SELECT i.id, i.number, i.receipt_number, i.total_fils, i.payment_status, i.paid_at, i.created_at, u.name user_name, u.mobile user_mobile, s.period_start, s.period_end, s.includes_sync, p.name plan_name
                 FROM invoices i JOIN users u ON u.id=i.user_id AND u.role="user" JOIN subscriptions s ON s.id=i.subscription_id JOIN plans p ON p.id=s.plan_id
                 WHERE ' . implode(' AND ', $where) . ' ORDER BY i.seq DESC LIMIT 500', $p);
    ok(['invoices' => $rows]);
}

function mark(): never {
    $u = auth(['super_admin', 'client_admin']);
    $inv = scoped_invoice($u, str_in('id', 36));
    $status = str_in('status', 10);
    if (!in_array($status, ['Paid', 'Failed', 'Refunded', 'Pending'], true)) fail('INVALID_INPUT', 422, ['field' => 'status']);
    if ($status === 'Refunded' && $inv['payment_status'] !== 'Paid') fail('INVALID_INPUT', 422, ['field' => 'status']);
    db()->beginTransaction();
    if ($status === 'Paid' && $inv['payment_status'] !== 'Paid') {
        $rseq = next_seq('receipt');
        q('UPDATE invoices SET payment_status="Paid", paid_at=UTC_TIMESTAMP(), method=?, reference=?, receipt_number=? WHERE id=?',
            [str_in('method', 40, false) ?? 'Cash', str_in('reference', 120, false), sprintf('RCT-%s-%06d', gmdate('Y'), $rseq), $inv['id']]);
        q('UPDATE subscriptions SET status="active" WHERE id=?', [$inv['subscription_id']]);
        create_commission_for_invoice($inv);
    } elseif ($status === 'Refunded') {
        q('UPDATE invoices SET payment_status="Refunded" WHERE id=?', [$inv['id']]);
        q('UPDATE subscriptions SET status="cancelled" WHERE id=?', [$inv['subscription_id']]);
        reverse_commission_for_invoice($inv);
    } else {
        q('UPDATE invoices SET payment_status=? WHERE id=?', [$status, $inv['id']]);
        if ($status === 'Failed') q('UPDATE subscriptions SET status="cancelled" WHERE id=?', [$inv['subscription_id']]);
    }
    db()->commit();
    audit($u, 'invoice.' . strtolower($status), ['invoice' => $inv['number']]);
    ok(['ok' => true]);
}

function plan_save(): never {
    $u = auth(['super_admin']);
    $id = req('id') ?: uuid4(); if (!is_uuid($id)) fail('INVALID_INPUT', 422);
    $mods = array_values(array_intersect((array)req('modules', []), MODULES));
    $f = [str_in('name', 120)];
    foreach (['base_monthly_fils', 'base_yearly_fils', 'sync_monthly_fils', 'sync_yearly_fils'] as $k) { $v = int_in($k, 0); if ($v < 0) fail('INVALID_INPUT', 422, ['field' => $k]); $f[] = $v; }
    q('INSERT INTO plans (id, name, base_monthly_fils, base_yearly_fils, sync_monthly_fils, sync_yearly_fils, modules, active) VALUES (?,?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE name=VALUES(name), base_monthly_fils=VALUES(base_monthly_fils), base_yearly_fils=VALUES(base_yearly_fils), sync_monthly_fils=VALUES(sync_monthly_fils), sync_yearly_fils=VALUES(sync_yearly_fils), modules=VALUES(modules), active=VALUES(active)',
        array_merge([$id], $f, [json_encode($mods), req('active', true) ? 1 : 0]));
    audit($u, 'plan.saved', ['id' => $id]);
    ok(['id' => $id]);
}

function plan_delete(): never {
    $u = auth(['super_admin']);
    q('UPDATE plans SET active=0 WHERE id=?', [str_in('id', 36)]);  // plans are never hard-deleted (invoices reference them)
    audit($u, 'plan.deactivated');
    ok(['ok' => true]);
}
