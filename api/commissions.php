<?php
declare(strict_types=1);
// Vendor commissions (§6.5)
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/billing.php';

$u = auth(['super_admin', 'client_admin', 'vendor']);

switch (action()) {
    case 'vendors':   vendors($u);
    case 'entries':   entries($u);
    case 'set_rate':  set_rate($u);
    case 'payout':    payout($u);
    case 'statement': statement($u);
    default:          fail('NOT_FOUND', 404);
}

function must_vendor(array $u, string $id): array { $v = vendor_in_scope($u, $id); if (!$v) fail('NOT_FOUND', 404); return $v; }

function vendors(array $u): never {
    if ($u['role'] === 'vendor') $rows = all('SELECT v.*, u.name, u.mobile, u.email FROM vendors v JOIN users u ON u.id=v.id WHERE v.id=?', [$u['id']]);
    elseif ($u['role'] === 'client_admin') $rows = all('SELECT v.*, u.name, u.mobile, u.email FROM vendors v JOIN users u ON u.id=v.id WHERE v.client_admin_id=? AND u.deleted_at IS NULL ORDER BY u.name', [$u['id']]);
    else $rows = all('SELECT v.*, u.name, u.mobile, u.email, ca.company FROM vendors v JOIN users u ON u.id=v.id JOIN client_admins ca ON ca.id=v.client_admin_id WHERE u.deleted_at IS NULL ORDER BY ca.company, u.name');
    foreach ($rows as &$r) {
        $r['balance_fils'] = vendor_balance($r['id']);
        $r['plan_rates'] = all('SELECT plan_id, commission_pct FROM vendor_plan_rates WHERE vendor_id=?', [$r['id']]);
        $r['linked_users'] = (int)one('SELECT COUNT(*) c FROM users WHERE vendor_id=? AND deleted_at IS NULL', [$r['id']])['c'];
    }
    ok(['vendors' => $rows]);
}

function entries(array $u): never {
    $v = must_vendor($u, str_in('vendor_id', 36));
    ok(['entries' => all('SELECT c.*, i.number invoice_number, us.name user_name FROM commissions c JOIN invoices i ON i.id=c.invoice_id JOIN users us ON us.id=c.user_id WHERE c.vendor_id=? ORDER BY c.created_at DESC LIMIT 500', [$v['id']]),
        'payouts' => all('SELECT * FROM commission_payouts WHERE vendor_id=? ORDER BY paid_on DESC, created_at DESC LIMIT 500', [$v['id']]),
        'balance_fils' => vendor_balance($v['id'])]);
}

function set_rate(array $u): never {
    if ($u['role'] === 'vendor') fail('FORBIDDEN', 403);
    $v = must_vendor($u, str_in('vendor_id', 36));
    $pct = (float)req('commission_pct'); if ($pct < 0 || $pct > 100) fail('INVALID_INPUT', 422, ['field' => 'commission_pct']);
    q('UPDATE vendors SET commission_pct=? WHERE id=?', [$pct, $v['id']]);
    q('DELETE FROM vendor_plan_rates WHERE vendor_id=?', [$v['id']]);
    foreach ((array)req('plan_rates', []) as $pr) {
        if (!is_array($pr) || !is_uuid($pr['plan_id'] ?? '') || ($pr['commission_pct'] ?? '') === '') continue;
        $p = (float)$pr['commission_pct']; if ($p < 0 || $p > 100) fail('INVALID_INPUT', 422);
        q('INSERT INTO vendor_plan_rates (vendor_id, plan_id, commission_pct) VALUES (?,?,?)', [$v['id'], $pr['plan_id'], $p]);
    }
    audit($u, 'commission.rate_set', ['vendor' => $v['id'], 'pct' => $pct]);
    ok(['ok' => true]);
}

function payout(array $u): never {
    if ($u['role'] === 'vendor') fail('FORBIDDEN', 403);
    $v = must_vendor($u, str_in('vendor_id', 36));
    $amount = int_in('amount_fils'); if ($amount <= 0) fail('INVALID_INPUT', 422, ['field' => 'amount_fils']);
    $paidOn = str_in('paid_on', 10); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidOn)) fail('INVALID_INPUT', 422, ['field' => 'paid_on']);
    $method = str_in('method', 40); $ref = str_in('reference', 120, false);

    db()->beginTransaction();
    q('INSERT INTO commission_payouts (id, vendor_id, amount_fils, paid_on, method, reference, created_by) VALUES (?,?,?,?,?,?,?)', [uuid4(), $v['id'], $amount, $paidOn, $method, $ref, $u['id']]);
    // Negative (refund) entries first offset what is owed, then pay oldest-first; partial payments allowed.
    $pool = $amount;
    foreach (all('SELECT id, amount_fils FROM commissions WHERE vendor_id=? AND amount_fils < 0 AND status="Unpaid" FOR UPDATE', [$v['id']]) as $neg) {
        $pool += -(int)$neg['amount_fils']; q('UPDATE commissions SET paid_fils=amount_fils, status="Paid" WHERE id=?', [$neg['id']]);
    }
    foreach (all('SELECT id, amount_fils, paid_fils FROM commissions WHERE vendor_id=? AND amount_fils > 0 AND status IN ("Unpaid","Partial") ORDER BY created_at ASC FOR UPDATE', [$v['id']]) as $c) {
        if ($pool <= 0) break;
        $due = (int)$c['amount_fils'] - (int)$c['paid_fils'];
        $pay = min($due, $pool); $pool -= $pay;
        $paid = (int)$c['paid_fils'] + $pay;
        q('UPDATE commissions SET paid_fils=?, status=? WHERE id=?', [$paid, $paid >= (int)$c['amount_fils'] ? 'Paid' : 'Partial', $c['id']]);
    }
    db()->commit();
    audit($u, 'commission.payout', ['vendor' => $v['id'], 'amount_fils' => $amount]);
    ok(['ok' => true, 'balance_fils' => vendor_balance($v['id'])]);
}

function statement(array $u): never {
    $v = must_vendor($u, str_in('vendor_id', 36));
    $from = str_in('from', 10); $to = str_in('to', 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to) fail('INVALID_INPUT', 422);
    $toEnd = $to . ' 23:59:59.999';
    $earnedBefore = (int)one('SELECT COALESCE(SUM(amount_fils),0) s FROM commissions WHERE vendor_id=? AND status<>"Reversed" AND created_at < ?', [$v['id'], $from])['s'];
    $paidBefore = (int)one('SELECT COALESCE(SUM(amount_fils),0) s FROM commission_payouts WHERE vendor_id=? AND paid_on < ?', [$v['id'], $from])['s'];
    $earned = all('SELECT c.created_at, c.amount_fils, c.pct, c.base_fils, c.status, i.number invoice_number, us.name user_name FROM commissions c JOIN invoices i ON i.id=c.invoice_id JOIN users us ON us.id=c.user_id
                   WHERE c.vendor_id=? AND c.status<>"Reversed" AND c.created_at BETWEEN ? AND ? ORDER BY c.created_at', [$v['id'], $from, $toEnd]);
    $payouts = all('SELECT paid_on, amount_fils, method, reference FROM commission_payouts WHERE vendor_id=? AND paid_on BETWEEN ? AND ? ORDER BY paid_on', [$v['id'], $from, $to]);
    $opening = $earnedBefore - $paidBefore;
    $closing = $opening + array_sum(array_column($earned, 'amount_fils')) - array_sum(array_column($payouts, 'amount_fils'));
    $ca = one('SELECT company FROM client_admins WHERE id=?', [$v['client_admin_id']]);
    ok(['vendor' => ['id' => $v['id'], 'name' => $v['name'], 'mobile' => $v['mobile'], 'email' => $v['email'], 'commission_pct' => $v['commission_pct']],
        'agency' => $ca['company'] ?? '', 'issuer' => setting('company_name', 'Digitz PMS'),
        'from' => $from, 'to' => $to, 'opening_fils' => $opening, 'earned' => $earned, 'payouts' => $payouts, 'closing_fils' => $closing]);
}
