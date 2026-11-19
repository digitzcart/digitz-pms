<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/push.php';

switch (action()) {
    case 'public_key':
        ok(['public_key' => setting('vapid_public')]);
    case 'subscribe': {
        $u = auth(['user']);
        $ep = str_in('endpoint', 600);
        if (!preg_match('#^https://#', $ep)) fail('INVALID_INPUT', 422);
        q('INSERT INTO push_subscriptions (id, user_id, endpoint, endpoint_hash) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id)',
            [uuid4(), $u['id'], $ep, hash('sha256', $ep)]);
        ok(['ok' => true]);
    }
    case 'unsubscribe': {
        $u = auth(['user']);
        q('DELETE FROM push_subscriptions WHERE user_id=? AND endpoint_hash=?', [$u['id'], hash('sha256', str_in('endpoint', 600))]);
        ok(['ok' => true]);
    }
    case 'pending': {
        // Called by the service worker on a payload-less push. The endpoint URL is an unguessable
        // capability held only by the browser, so it identifies the subscriber.
        rate_limit('pushpend:' . client_ip(), 120, 3600);
        $s = one('SELECT user_id FROM push_subscriptions WHERE endpoint_hash=?', [hash('sha256', str_in('endpoint', 600))]);
        if (!$s) ok(['messages' => []]);
        ok(['messages' => all('SELECT title, body FROM push_messages WHERE user_id=? AND created_at > UTC_TIMESTAMP() - INTERVAL 1 DAY ORDER BY id DESC LIMIT 3', [$s['user_id']])]);
    }
    default: fail('NOT_FOUND', 404);
}
