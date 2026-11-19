<?php
declare(strict_types=1);
// Cron job: PDC reminders (due today / in 3 days / in 7 days) by email and Web Push,
// lease-expiry notices, subscription and agency expiry, vacancy auto-expiry, housekeeping.
// Run from the CLI only, e.g.:  */30 * * * * php /path/to/cron/reminders.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
define('DIGITZ_CLI', true);
require dirname(__DIR__) . '/api/lib/bootstrap.php';
require dirname(__DIR__) . '/api/lib/push.php';

$tz = new DateTimeZone('Asia/Dubai');
$today = new DateTimeImmutable('now', $tz);
$todayStr = $today->format('Y-m-d');

function dubai_date(?string $iso, DateTimeZone $tz): ?string { if (!$iso) return null; try { return (new DateTimeImmutable($iso))->setTimezone($tz)->format('Y-m-d'); } catch (Throwable) { return null; } }
function fmt_aed(int $fils): string { return 'AED ' . number_format($fils / 100, 2); }
function dmy(string $ymd): string { [$y, $m, $d] = explode('-', $ymd); return "$d/$m/$y"; }
function once(string $key): bool { try { q('INSERT INTO reminder_log (rkey) VALUES (?)', [substr($key, 0, 190)]); return true; } catch (PDOException) { return false; } }

$sent = 0;

// ---- 1. PDC reminders (only cloud-synced data is visible to the server) ----
$rows = all("SELECT r.id, r.owner_user_id, r.payload, u.email, u.name FROM sync_records r JOIN users u ON u.id=r.owner_user_id
             WHERE r.entity='pdcs' AND r.deleted_at IS NULL AND u.status='active' AND u.deleted_at IS NULL
               AND JSON_UNQUOTE(JSON_EXTRACT(r.payload, '$.status')) = 'Pending'");
$byUser = [];
foreach ($rows as $r) {
    $p = json_decode($r['payload'], true);
    $due = dubai_date($p['due_date'] ?? null, $tz); if (!$due) continue;
    $days = (int)$today->setTime(0, 0)->diff(new DateTimeImmutable($due, $tz))->format('%r%a');
    if (!in_array($days, [0, 3, 7], true)) continue;
    if (!once("pdc:{$r['id']}:$due:$days")) continue;
    $byUser[$r['owner_user_id']]['user'] = $r;
    $byUser[$r['owner_user_id']]['items'][] = ['days' => $days, 'due' => $due, 'p' => $p];
}
foreach ($byUser as $uid => $g) {
    $lines = [];
    foreach ($g['items'] as $it) {
        $when = $it['days'] === 0 ? 'TODAY / ഇന്ന്' : "in {$it['days']} days / {$it['days']} ദിവസത്തിനുള്ളിൽ";
        $dir = ($it['p']['direction'] ?? 'receivable') === 'payable' ? 'Payable / നൽകേണ്ടത്' : 'Receivable / ലഭിക്കേണ്ടത്';
        $lines[] = sprintf('• Cheque #%s (%s) — %s — %s — due %s, %s', $it['p']['cheque_no'] ?? '-', $it['p']['bank'] ?? '-', fmt_aed((int)($it['p']['amount_fils'] ?? 0)), $dir, dmy($it['due']), $when);
    }
    $count = count($g['items']);
    notify_user($uid, "Cheque reminder / ചെക്ക് ഓർമ്മപ്പെടുത്തൽ ($count)", implode("\n", array_slice($lines, 0, 3)));
    if (!empty($g['user']['email'])) {
        $body = "Hello {$g['user']['name']},\n\nThe following post-dated cheques need your attention:\n"
              . "താഴെ പറയുന്ന പോസ്റ്റ്-ഡേറ്റഡ് ചെക്കുകൾ ശ്രദ്ധിക്കുക:\n\n" . implode("\n", $lines) . "\n\n— Digitz PMS";
        $headers = 'From: ' . $CFG['mail_from'] . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit";
        @mail($g['user']['email'], '=?UTF-8?B?' . base64_encode("Cheque reminders — $count due soon") . '?=', $body, $headers);
    }
    $sent++;
}

// ---- 2. Lease expiry notices (30 / 60 / 90 days) via push ----
$rows = all("SELECT r.id, r.owner_user_id, r.payload FROM sync_records r WHERE r.entity='leases' AND r.deleted_at IS NULL
             AND JSON_UNQUOTE(JSON_EXTRACT(r.payload, '$.status')) = 'Active'");
foreach ($rows as $r) {
    $p = json_decode($r['payload'], true); $end = dubai_date($p['end_date'] ?? null, $tz); if (!$end) continue;
    $days = (int)$today->setTime(0, 0)->diff(new DateTimeImmutable($end, $tz))->format('%r%a');
    if (!in_array($days, [30, 60, 90], true) || !once("lease:{$r['id']}:$days")) continue;
    notify_user($r['owner_user_id'], 'Lease expiring / ലീസ് കാലാവധി തീരുന്നു', "A lease ends on " . dmy($end) . " ($days days). / " . dmy($end) . " ന് ഒരു ലീസ് അവസാനിക്കുന്നു.");
}

// ---- 3. Subscriptions and agencies past expiry ----
q("UPDATE subscriptions SET status='expired' WHERE status='active' AND period_end < ?", [$todayStr]);
q("UPDATE client_admins SET status='Expired' WHERE status<>'Expired' AND expires_at IS NOT NULL AND expires_at < ?", [$todayStr]);

// ---- 4. Vacancy listings: expire by date, or when the synced unit is no longer Vacant ----
q("UPDATE vacancy_shares SET status='expired' WHERE status='active' AND expires_at IS NOT NULL AND expires_at < ?", [$todayStr]);
q("UPDATE vacancy_shares vs JOIN sync_records r ON r.entity=vs.unit_entity AND r.id=vs.unit_id
   SET vs.status='expired'
   WHERE vs.status='active' AND (r.deleted_at IS NOT NULL OR JSON_UNQUOTE(JSON_EXTRACT(r.payload, '$.status')) <> 'Vacant')");

// ---- 5. Housekeeping ----
q("DELETE FROM otps WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 1 DAY");
q("DELETE FROM refresh_tokens WHERE expires_at < UTC_TIMESTAMP() OR revoked_at < UTC_TIMESTAMP() - INTERVAL 7 DAY");
q("DELETE FROM rate_limits WHERE window_start < ?", [time() - 86400]);
q("DELETE FROM push_messages WHERE created_at < UTC_TIMESTAMP() - INTERVAL 7 DAY");
q("DELETE FROM reminder_log WHERE sent_at < UTC_TIMESTAMP() - INTERVAL 120 DAY");

echo gmdate('c') . " reminders: $sent user batches\n";
