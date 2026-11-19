<?php
declare(strict_types=1);
// Cloud sync for structured JSON only (§6.3). Files never arrive here (§6.4 enforced in bootstrap input()).
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/entities.php';

$u = auth(['user']);

switch (action()) {
    case 'status': ok(['sync' => sync_active($u['id']) ? true : false, 'server_time' => gmdate('c')]);
    case 'push':   push($u);
    case 'pull':   pull($u);
    default:       fail('NOT_FOUND', 404);
}

function iso_to_mysql($v): ?string {
    if ($v === null || $v === '') return null;
    $t = strtotime((string)$v); if ($t === false) fail('INVALID_INPUT', 422, ['field' => 'updated_at']);
    $ms = preg_match('/\.(\d{1,3})/', (string)$v, $m) ? str_pad($m[1], 3, '0') : '000';
    return gmdate('Y-m-d H:i:s', $t) . '.' . $ms;
}
function mysql_to_iso(?string $v): ?string { return $v ? str_replace(' ', 'T', $v) . 'Z' : null; }
function next_rev(): int { q('UPDATE sync_counter SET v = LAST_INSERT_ID(v + 1) WHERE id = 1'); return (int)db()->lastInsertId(); }

function push(array $u): never {
    global $CFG;
    rate_limit('sync:' . $u['id'], 120, 3600);
    // Entitlement gating: writes need an active Cloud Sync add-on (§6.2)
    if (!sync_active($u['id'])) fail('SYNC_INACTIVE', 402);
    $changes = req('changes');
    if (!is_array($changes) || count($changes) > ($CFG['sync_max_records'] ?? 500)) fail('BAD_REQUEST', 422);

    $results = []; $pdo = db();
    $pdo->beginTransaction();
    foreach ($changes as $c) {
        $entity = $c['entity'] ?? ''; $id = $c['id'] ?? '';
        if (!isset(SYNC_ENTITIES[$entity])) { $pdo->rollBack(); fail('ENTITY_NOT_ALLOWED', 422, ['entity' => (string)$entity]); }
        if (!is_uuid($id) || !is_array($c['payload'] ?? null)) { $pdo->rollBack(); fail('BAD_REQUEST', 422); }
        $payload = filter_payload($entity, $c['payload']);
        $payload['id'] = $id; $payload['owner_user_id'] = $u['id'];
        $updated = iso_to_mysql($c['updated_at'] ?? null) ?? fail('INVALID_INPUT', 422, ['field' => 'updated_at']);
        $deleted = iso_to_mysql($c['deleted_at'] ?? null);
        $payload['updated_at'] = mysql_to_iso($updated); $payload['deleted_at'] = mysql_to_iso($deleted);

        $cur = one('SELECT * FROM sync_records WHERE entity=? AND id=? FOR UPDATE', [$entity, $id]);
        if ($cur && $cur['owner_user_id'] !== $u['id']) { $results[] = ['entity' => $entity, 'id' => $id, 'status' => 'forbidden']; continue; }
        if ($cur) {
            if ($cur['updated_at'] === $updated) { $results[] = ['entity' => $entity, 'id' => $id, 'status' => 'ok', 'rev' => (int)$cur['rev']]; continue; } // idempotent retry
            if ($cur['updated_at'] > $updated) {
                // Server copy is newer: server wins, both versions logged for audit
                q('INSERT INTO sync_conflicts (owner_user_id, entity, record_id, winner, server_version, client_version) VALUES (?,?,?,?,?,?)',
                    [$u['id'], $entity, $id, 'server', $cur['payload'], json_encode($payload, JSON_UNESCAPED_UNICODE)]);
                $results[] = ['entity' => $entity, 'id' => $id, 'status' => 'stale', 'server' => json_decode($cur['payload'], true)];
                continue;
            }
            q('INSERT INTO sync_conflicts (owner_user_id, entity, record_id, winner, server_version, client_version) VALUES (?,?,?,?,?,?)',
                [$u['id'], $entity, $id, 'client', $cur['payload'], json_encode($payload, JSON_UNESCAPED_UNICODE)]);
        }
        $rev = next_rev();
        q('INSERT INTO sync_records (entity, id, owner_user_id, updated_at, deleted_at, payload, rev) VALUES (?,?,?,?,?,?,?)
           ON DUPLICATE KEY UPDATE updated_at=VALUES(updated_at), deleted_at=VALUES(deleted_at), payload=VALUES(payload), rev=VALUES(rev)',
            [$entity, $id, $u['id'], $updated, $deleted, json_encode($payload, JSON_UNESCAPED_UNICODE), $rev]);
        $results[] = ['entity' => $entity, 'id' => $id, 'status' => 'ok', 'rev' => $rev];
    }
    $pdo->commit();
    ok(['results' => $results, 'server_time' => gmdate('c')]);
}

function pull(array $u): never {
    // Read-only pull stays available even when the add-on has lapsed.
    rate_limit('pull:' . $u['id'], 600, 3600);
    $cursor = max(0, (int)req('cursor', 0)); $limit = min(1000, max(1, (int)req('limit', 500)));
    $rows = all('SELECT entity, payload, rev FROM sync_records WHERE owner_user_id=? AND rev > ? ORDER BY rev ASC LIMIT ' . $limit, [$u['id'], $cursor]);
    $changes = []; $last = $cursor;
    foreach ($rows as $r) { $changes[] = ['entity' => $r['entity'], 'record' => json_decode($r['payload'], true)]; $last = (int)$r['rev']; }
    ok(['changes' => $changes, 'cursor' => $last, 'more' => count($rows) === $limit, 'server_time' => gmdate('c'), 'writable' => (bool)sync_active($u['id'])]);
}
