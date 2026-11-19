<?php
declare(strict_types=1);

function next_seq(string $name): int {
    // Caller must be inside a transaction
    $r = one('SELECT v FROM sequences WHERE name=? FOR UPDATE', [$name]);
    $v = (int)$r['v'] + 1;
    q('UPDATE sequences SET v=? WHERE name=?', [$v, $name]);
    return $v;
}

/** Vendor commission % for a user's plan: per-plan override, else vendor default. */
function vendor_rate(string $vendorId, ?string $planId): ?float {
    if ($planId) { $o = one('SELECT commission_pct FROM vendor_plan_rates WHERE vendor_id=? AND plan_id=?', [$vendorId, $planId]); if ($o) return (float)$o['commission_pct']; }
    $v = one('SELECT commission_pct FROM vendors WHERE id=?', [$vendorId]);
    return $v ? (float)$v['commission_pct'] : null;
}

/** Called when an invoice becomes Paid: creates an Unpaid commission entry for the user's vendor. */
function create_commission_for_invoice(array $inv): void {
    $user = one('SELECT vendor_id FROM users WHERE id=?', [$inv['user_id']]);
    if (empty($user['vendor_id'])) return;
    if (one('SELECT id FROM commissions WHERE invoice_id=? AND amount_fils > 0', [$inv['id']])) return;
    $sub = one('SELECT plan_id FROM subscriptions WHERE id=?', [$inv['subscription_id']]);
    $pct = vendor_rate($user['vendor_id'], $sub['plan_id'] ?? null);
    if ($pct === null || $pct <= 0) return;
    $amount = (int)round($inv['total_fils'] * $pct / 100);
    q('INSERT INTO commissions (id, vendor_id, invoice_id, user_id, base_fils, pct, amount_fils) VALUES (?,?,?,?,?,?,?)',
        [uuid4(), $user['vendor_id'], $inv['id'], $inv['user_id'], $inv['total_fils'], $pct, $amount]);
}

/** On refund: cancel the unpaid part, and post a negative entry for whatever was already paid out. */
function reverse_commission_for_invoice(array $inv): void {
    foreach (all('SELECT * FROM commissions WHERE invoice_id=? AND amount_fils > 0 AND status <> "Reversed"', [$inv['id']]) as $c) {
        if ((int)$c['paid_fils'] === 0) { q('UPDATE commissions SET status="Reversed" WHERE id=?', [$c['id']]); continue; }
        q('UPDATE commissions SET amount_fils=paid_fils, status="Paid" WHERE id=?', [$c['id']]);
        q('INSERT INTO commissions (id, vendor_id, invoice_id, user_id, base_fils, pct, amount_fils, status) VALUES (?,?,?,?,?,?,?, "Unpaid")',
            [uuid4(), $c['vendor_id'], $inv['id'], $c['user_id'], -$inv['total_fils'], $c['pct'], -(int)$c['paid_fils']]);
    }
}

/** Live payable balance for a vendor = earned (non-reversed) − paid out. */
function vendor_balance(string $vendorId): int {
    $earned = (int)(one('SELECT COALESCE(SUM(amount_fils),0) s FROM commissions WHERE vendor_id=? AND status <> "Reversed"', [$vendorId])['s']);
    $paid = (int)(one('SELECT COALESCE(SUM(amount_fils),0) s FROM commission_payouts WHERE vendor_id=?', [$vendorId])['s']);
    return $earned - $paid;
}

/** Can $actor see/manage $vendorId? */
function vendor_in_scope(array $actor, string $vendorId): ?array {
    $v = one('SELECT v.*, u.name, u.mobile, u.email FROM vendors v JOIN users u ON u.id=v.id WHERE v.id=? AND u.deleted_at IS NULL', [$vendorId]);
    if (!$v) return null;
    if ($actor['role'] === 'super_admin') return $v;
    if ($actor['role'] === 'client_admin' && $v['client_admin_id'] === $actor['id']) return $v;
    if ($actor['role'] === 'vendor' && $v['id'] === $actor['id']) return $v;
    return null;
}
