<?php
declare(strict_types=1);
// Web Push with VAPID (RFC 8292), no third-party library.
// Pushes are sent WITHOUT a payload (no RFC 8291 encryption needed). The service worker
// wakes up and fetches the pending message text from api/push.php?action=pending.

function der_to_raw_sig(string $der): string {
    $off = 2; if (ord($der[1]) & 0x80) $off += ord($der[1]) & 0x7f;
    $rLen = ord($der[$off + 1]); $r = substr($der, $off + 2, $rLen); $off += 2 + $rLen;
    $sLen = ord($der[$off + 1]); $s = substr($der, $off + 2, $sLen);
    $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT); $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    return $r . $s;
}

function vapid_generate(): array {
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    openssl_pkey_export($key, $pem);
    $d = openssl_pkey_get_details($key)['ec'];
    $pub = "\x04" . str_pad($d['x'], 32, "\x00", STR_PAD_LEFT) . str_pad($d['y'], 32, "\x00", STR_PAD_LEFT);
    return ['private_pem' => $pem, 'public' => b64u($pub)];
}

function send_push(string $endpoint): int {
    global $CFG;
    $pem = setting('vapid_private_pem'); $pub = setting('vapid_public');
    if (!$pem || !$pub) return 0;
    $p = parse_url($endpoint); if (($p['scheme'] ?? '') !== 'https') return 0;
    $aud = 'https://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    $h = b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $c = b64u(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $CFG['vapid_subject']]));
    openssl_sign("$h.$c", $der, openssl_pkey_get_private($pem), OPENSSL_ALGO_SHA256);
    $jwt = "$h.$c." . b64u(der_to_raw_sig($der));
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POSTFIELDS => '',
        CURLOPT_HTTPHEADER => ["Authorization: vapid t=$jwt, k=$pub", 'TTL: 86400', 'Urgency: high', 'Content-Length: 0'],
    ]);
    curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($status === 404 || $status === 410) q('DELETE FROM push_subscriptions WHERE endpoint_hash=?', [hash('sha256', $endpoint)]);
    return $status;
}

function notify_user(string $userId, string $title, string $body): void {
    q('INSERT INTO push_messages (user_id, title, body) VALUES (?,?,?)', [$userId, mb_substr($title, 0, 190), mb_substr($body, 0, 500)]);
    foreach (all('SELECT endpoint FROM push_subscriptions WHERE user_id=?', [$userId]) as $s) send_push($s['endpoint']);
}
