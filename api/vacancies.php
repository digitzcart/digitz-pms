<?php
declare(strict_types=1);
// Inter-agency vacancy sharing (§3.6). Text metadata only — images are never shared through the cloud.
require __DIR__ . '/lib/bootstrap.php';

$u = auth(['user', 'client_admin']);

const META_FIELDS = ['title', 'unit_type', 'emirate', 'community', 'location', 'size_sqft', 'rent_fils', 'rent_period', 'available_from', 'amenities', 'contact_name', 'contact_mobile', 'description'];

switch (action()) {
    case 'share':   share($u);
    case 'revoke':  revoke($u);
    case 'unit_status': unit_status($u);
    case 'mine':    mine($u);
    case 'browse':  browse($u);
    default:        fail('NOT_FOUND', 404);
}

function can_share(array $u): bool {
    if ($u['role'] !== 'user' || !$u['can_share_vacancies']) return false;
    $ca = one('SELECT * FROM client_admins WHERE id=?', [$u['client_admin_id']]);
    return client_admin_usable($ca) && $ca['sharing_enabled'] && in_array('sharing', effective_modules($u), true);
}

function share(array $u): never {
    if (!can_share($u)) fail('SHARING_DISABLED', 403);
    $entity = str_in('unit_entity', 32); if (!in_array($entity, ['properties', 'rooms', 'bed_spaces'], true)) fail('INVALID_INPUT', 422);
    $unitId = str_in('unit_id', 36); if (!is_uuid($unitId)) fail('INVALID_INPUT', 422);
    $metaIn = (array)req('meta', []); $meta = [];
    foreach (META_FIELDS as $k) if (isset($metaIn[$k]) && (is_scalar($metaIn[$k]) || is_array($metaIn[$k]))) $meta[$k] = is_string($metaIn[$k]) ? mb_substr(trim($metaIn[$k]), 0, 1000) : $metaIn[$k];
    if (empty($meta['title']) || !isset($meta['rent_fils'])) fail('INVALID_INPUT', 422);
    $split = (float)req('commission_split_pct', 50); if ($split < 0 || $split > 100) fail('INVALID_INPUT', 422, ['field' => 'commission_split_pct']);
    $exp = str_in('expires_at', 10, false); if ($exp && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp)) fail('INVALID_INPUT', 422);
    $existing = one('SELECT id, owner_user_id FROM vacancy_shares WHERE unit_entity=? AND unit_id=?', [$entity, $unitId]);
    if ($existing && $existing['owner_user_id'] !== $u['id']) fail('FORBIDDEN', 403);
    $id = $existing['id'] ?? uuid4();
    q('INSERT INTO vacancy_shares (id, client_admin_id, owner_user_id, unit_entity, unit_id, meta, commission_split_pct, status, expires_at) VALUES (?,?,?,?,?,?,?, "active", ?)
       ON DUPLICATE KEY UPDATE meta=VALUES(meta), commission_split_pct=VALUES(commission_split_pct), status="active", expires_at=VALUES(expires_at)',
        [$id, $u['client_admin_id'], $u['id'], $entity, $unitId, json_encode($meta, JSON_UNESCAPED_UNICODE), $split, $exp]);
    audit($u, 'vacancy.shared', ['id' => $id]);
    ok(['id' => $id]);
}

function revoke(array $u): never {
    $id = str_in('id', 36);
    $s = one('SELECT * FROM vacancy_shares WHERE id=?', [$id]); if (!$s) fail('NOT_FOUND', 404);
    $mayRevoke = ($u['role'] === 'user' && $s['owner_user_id'] === $u['id']) || ($u['role'] === 'client_admin' && $s['client_admin_id'] === $u['id']);
    if (!$mayRevoke) fail('NOT_FOUND', 404);
    q('UPDATE vacancy_shares SET status="revoked" WHERE id=?', [$id]);
    audit($u, 'vacancy.revoked', ['id' => $id]);
    ok(['ok' => true]);
}

/** Client reports unit status changes; any non-Vacant status expires the listing automatically. */
function unit_status(array $u): never {
    $unitId = str_in('unit_id', 36); $status = str_in('status', 30);
    if ($status !== 'Vacant') q('UPDATE vacancy_shares SET status="expired" WHERE unit_id=? AND owner_user_id=? AND status="active"', [$unitId, $u['id']]);
    ok(['ok' => true]);
}

function decode_rows(array $rows): array { foreach ($rows as &$r) { $r['meta'] = json_decode($r['meta'], true); $r['commission_split_pct'] = (float)$r['commission_split_pct']; } return $rows; }

function mine(array $u): never {
    if ($u['role'] === 'user') $rows = all('SELECT * FROM vacancy_shares WHERE owner_user_id=? ORDER BY updated_at DESC', [$u['id']]);
    else $rows = all('SELECT vs.*, us.name broker_name FROM vacancy_shares vs JOIN users us ON us.id=vs.owner_user_id WHERE vs.client_admin_id=? ORDER BY vs.updated_at DESC', [$u['id']]);
    ok(['shares' => decode_rows($rows), 'can_share' => can_share($u)]);
}

function browse(array $u): never {
    $myCa = tenant_of($u);
    $ca = one('SELECT * FROM client_admins WHERE id=?', [$myCa]);
    if (!client_admin_usable($ca) || !$ca['sharing_enabled']) fail('SHARING_DISABLED', 403);
    if ($u['role'] === 'user' && (!$ca['users_see_shared'] || !in_array('sharing', effective_modules($u), true))) fail('SHARING_DISABLED', 403);
    $rows = all('SELECT vs.id, vs.meta, vs.commission_split_pct, vs.expires_at, vs.updated_at, ca.company agency
                 FROM vacancy_shares vs JOIN client_admins ca ON ca.id=vs.client_admin_id
                 WHERE vs.status="active" AND vs.client_admin_id<>? AND ca.sharing_enabled=1 AND ca.status<>"Expired"
                   AND (ca.expires_at IS NULL OR ca.expires_at >= UTC_DATE()) AND (vs.expires_at IS NULL OR vs.expires_at >= UTC_DATE())
                 ORDER BY vs.updated_at DESC LIMIT 500', [$myCa]);
    ok(['listings' => decode_rows($rows)]);
}
