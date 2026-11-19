<?php
declare(strict_types=1);
// Shared bootstrap for every API endpoint: config, DB, JSON I/O, binary rejection,
// tokens, rate limiting, tenant scoping and bilingual errors.

if (!defined('DIGITZ_CLI')) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
}

$cfgFile = dirname(__DIR__, 2) . '/config.php';
if (!is_file($cfgFile)) { http_response_code(503); echo json_encode(['error' => ['code' => 'NOT_INSTALLED', 'message_en' => 'Run install.php first.', 'message_ml' => 'ആദ്യം install.php പ്രവർത്തിപ്പിക്കുക.']]); exit; }
$CFG = require $cfgFile;
date_default_timezone_set('UTC');

// ---------- HTTPS only ----------
if (!defined('DIGITZ_CLI')) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    if (!$https && !$local) fail('HTTPS_REQUIRED', 403);
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin && in_array($origin, $CFG['allowed_origins'] ?? [], true)) {
        header("Access-Control-Allow-Origin: $origin");
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Vary: Origin');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
}

// ---------- Bilingual error catalogue ----------
const ERRORS = [
    'HTTPS_REQUIRED'   => ['HTTPS is required.', 'HTTPS ആവശ്യമാണ്.'],
    'BAD_REQUEST'      => ['Invalid request.', 'അസാധുവായ അഭ്യർത്ഥന.'],
    'UNSUPPORTED_TYPE' => ['Only JSON requests are accepted. Files are never uploaded.', 'JSON അഭ്യർത്ഥനകൾ മാത്രമേ സ്വീകരിക്കൂ. ഫയലുകൾ ഒരിക്കലും അപ്‌ലോഡ് ചെയ്യില്ല.'],
    'PAYLOAD_TOO_LARGE'=> ['The request is too large.', 'അഭ്യർത്ഥന വളരെ വലുതാണ്.'],
    'BINARY_REJECTED'  => ['File or binary data is not allowed on the server.', 'ഫയലുകളോ ബൈനറി ഡാറ്റയോ സെർവറിൽ അനുവദനീയമല്ല.'],
    'UNAUTHORIZED'     => ['Please sign in again.', 'ദയവായി വീണ്ടും സൈൻ ഇൻ ചെയ്യുക.'],
    'FORBIDDEN'        => ['You do not have permission for this action.', 'ഈ പ്രവർത്തനത്തിന് നിങ്ങൾക്ക് അനുമതിയില്ല.'],
    'NOT_FOUND'        => ['Not found.', 'കണ്ടെത്തിയില്ല.'],
    'RATE_LIMITED'     => ['Too many attempts. Please wait and try again.', 'വളരെയധികം ശ്രമങ്ങൾ. അൽപസമയം കാത്തിരുന്ന് വീണ്ടും ശ്രമിക്കുക.'],
    'INVALID_MOBILE'   => ['Enter a valid mobile number in international format.', 'അന്താരാഷ്ട്ര ഫോർമാറ്റിൽ സാധുവായ മൊബൈൽ നമ്പർ നൽകുക.'],
    'INVALID_PIN'      => ['PIN must be 4–6 digits and not too simple.', 'PIN 4–6 അക്കങ്ങളായിരിക്കണം, വളരെ ലളിതമാകരുത്.'],
    'INVALID_INPUT'    => ['Please check the highlighted fields.', 'അടയാളപ്പെടുത്തിയ ഫീൽഡുകൾ പരിശോധിക്കുക.'],
    'MOBILE_EXISTS'    => ['This mobile number is already registered.', 'ഈ മൊബൈൽ നമ്പർ ഇതിനകം രജിസ്റ്റർ ചെയ്തിട്ടുണ്ട്.'],
    'OTP_COOLDOWN'     => ['Please wait before requesting a new code.', 'പുതിയ കോഡ് ആവശ്യപ്പെടുന്നതിന് മുമ്പ് കാത്തിരിക്കുക.'],
    'OTP_SEND_FAILED'  => ['Could not send the WhatsApp code. Try again later.', 'WhatsApp കോഡ് അയയ്ക്കാനായില്ല. പിന്നീട് ശ്രമിക്കുക.'],
    'OTP_INVALID'      => ['The code is incorrect.', 'കോഡ് തെറ്റാണ്.'],
    'OTP_EXPIRED'      => ['The code has expired. Request a new one.', 'കോഡിന്റെ കാലാവധി കഴിഞ്ഞു. പുതിയത് ആവശ്യപ്പെടുക.'],
    'OTP_LOCKED'       => ['Too many wrong codes. Request a new one.', 'വളരെയധികം തെറ്റായ കോഡുകൾ. പുതിയത് ആവശ്യപ്പെടുക.'],
    'NO_CLIENT_ADMIN'  => ['Sign-up is not available right now. No active agency is configured.', 'ഇപ്പോൾ സൈൻ അപ്പ് ലഭ്യമല്ല. സജീവ ഏജൻസി ഒന്നും ക്രമീകരിച്ചിട്ടില്ല.'],
    'CLIENT_EXPIRED'   => ['Your agency account has expired. Please contact your agency.', 'നിങ്ങളുടെ ഏജൻസി അക്കൗണ്ടിന്റെ കാലാവധി കഴിഞ്ഞു. ഏജൻസിയെ ബന്ധപ്പെടുക.'],
    'USER_LIMIT'       => ['Your agency has reached its maximum number of users.', 'നിങ്ങളുടെ ഏജൻസി പരമാവധി ഉപയോക്തൃ പരിധിയിൽ എത്തി.'],
    'BAD_CREDENTIALS'  => ['Incorrect login details.', 'ലോഗിൻ വിവരങ്ങൾ തെറ്റാണ്.'],
    'ACCOUNT_LOCKED'   => ['Account temporarily locked. Try again later.', 'അക്കൗണ്ട് താൽക്കാലികമായി ലോക്ക് ചെയ്തു. പിന്നീട് ശ്രമിക്കുക.'],
    'DOB_MISMATCH'     => ['Date of birth does not match our records.', 'ജനനത്തീയതി ഞങ്ങളുടെ രേഖകളുമായി പൊരുത്തപ്പെടുന്നില്ല.'],
    'SYNC_INACTIVE'    => ['Cloud Sync is not active. Your data is safe on this device; renew to sync again.', 'ക്ലൗഡ് സിങ്ക് സജീവമല്ല. ഡാറ്റ ഈ ഉപകരണത്തിൽ സുരക്ഷിതമാണ്; വീണ്ടും സിങ്ക് ചെയ്യാൻ പുതുക്കുക.'],
    'ENTITY_NOT_ALLOWED'=>['This data type cannot be synced.', 'ഈ ഡാറ്റ തരം സിങ്ക് ചെയ്യാനാവില്ല.'],
    'SHARING_DISABLED' => ['Vacancy sharing is not enabled for your account.', 'നിങ്ങളുടെ അക്കൗണ്ടിന് ഒഴിവ് പങ്കിടൽ പ്രവർത്തനക്ഷമമല്ല.'],
    'PASSWORD_WEAK'    => ['Password must be at least 10 characters with letters and numbers.', 'പാസ്‌വേഡിൽ കുറഞ്ഞത് 10 അക്ഷരങ്ങളും അക്കങ്ങളും വേണം.'],
    'CHANGE_PASSWORD'  => ['Please set a new password to continue.', 'തുടരാൻ പുതിയ പാസ്‌വേഡ് സജ്ജമാക്കുക.'],
    'CONFLICT'         => ['This record already exists.', 'ഈ രേഖ ഇതിനകം നിലവിലുണ്ട്.'],
    'SERVER_ERROR'     => ['Something went wrong on the server.', 'സെർവറിൽ എന്തോ പിശക് സംഭവിച്ചു.'],
];

function fail(string $code, int $status = 400, array $extra = []): never {
    http_response_code($status);
    [$en, $ml] = ERRORS[$code] ?? ERRORS['SERVER_ERROR'];
    echo json_encode(['error' => array_merge(['code' => $code, 'message_en' => $en, 'message_ml' => $ml], $extra)], JSON_UNESCAPED_UNICODE);
    exit;
}
function ok(array $data = [], int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
set_exception_handler(function (Throwable $e) {
    error_log('[digitz] ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) fail('SERVER_ERROR', 500);
    exit;
});

// ---------- DB ----------
function db(): PDO {
    static $pdo = null; global $CFG;
    if ($pdo) return $pdo;
    $pdo = new PDO($CFG['db']['dsn'], $CFG['db']['user'], $CFG['db']['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}
function q(string $sql, array $p = []): PDOStatement { $s = db()->prepare($sql); $s->execute($p); return $s; }
function one(string $sql, array $p = []): ?array { $r = q($sql, $p)->fetch(); return $r ?: null; }
function all(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }
function setting(string $k, ?string $default = null): ?string { $r = one('SELECT v FROM settings WHERE k=?', [$k]); return $r['v'] ?? $default; }
function set_setting(string $k, string $v): void { q('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)', [$k, $v]); }

function uuid4(): string {
    $b = random_bytes(16); $b[6] = chr((ord($b[6]) & 0x0f) | 0x40); $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}
function is_uuid($s): bool { return is_string($s) && (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $s); }
function now_utc(): string { return gmdate('Y-m-d H:i:s'); }
function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? 'cli'; }

// ---------- Input + binary rejection (§6.4) ----------
function looks_binary(string $s): bool {
    if (preg_match('#^\s*data:[\w.+-]+/[\w.+-]+;base64,#i', $s)) return true;              // data URI
    if (preg_match('/^(JVBERi0|iVBORw0KGgo|\/9j\/|R0lGOD|UEsDB|AAAA[GH]GZ0eX|UklGR|Qk[0-9A-Za-z]{2}AAAA)/', $s)) return true; // base64 file signatures
    if (strncmp($s, '%PDF', 4) === 0 || strncmp($s, "\x89PNG", 4) === 0 || strncmp($s, "\xFF\xD8\xFF", 3) === 0
        || strncmp($s, 'GIF8', 4) === 0 || strncmp($s, "PK\x03\x04", 4) === 0) return true; // raw signatures
    $compact = preg_replace('/\s+/', '', $s);
    if (strlen($compact) >= 256 && preg_match('/^[A-Za-z0-9+\/_-]+={0,2}$/', $compact) && !preg_match('/\s/', trim($s))) return true; // long base64 run
    if (preg_match('/[\x00-\x08\x0E-\x1F]/', $s)) return true;                               // control bytes
    return false;
}
function scan_binary($v, int $depth = 0): void {
    if ($depth > 8) fail('BAD_REQUEST', 422);
    if (is_string($v)) { if (looks_binary($v)) fail('BINARY_REJECTED', 422); return; }
    if (is_array($v)) foreach ($v as $k => $x) { if (is_string($k) && looks_binary($k)) fail('BINARY_REJECTED', 422); scan_binary($x, $depth + 1); }
}
function input(): array {
    global $CFG;
    static $cache = null; if ($cache !== null) return $cache;
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') { $cache = $_GET; scan_binary($cache); return $cache; }
    $ct = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
    if (str_contains($ct, 'multipart/') || !str_starts_with($ct, 'application/json') || !empty($_FILES)) fail('UNSUPPORTED_TYPE', 415);
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $max = (int)($CFG['sync_max_bytes'] ?? 1048576);
    if ($len > $max) fail('PAYLOAD_TOO_LARGE', 413);
    $raw = file_get_contents('php://input', false, null, 0, $max + 1);
    if (strlen($raw) > $max) fail('PAYLOAD_TOO_LARGE', 413);
    $data = json_decode($raw ?: '{}', true);
    if (!is_array($data)) fail('BAD_REQUEST', 400);
    scan_binary($data);
    $cache = array_merge($_GET, $data);
    return $cache;
}
function req(string $k, $default = null) { $i = input(); return $i[$k] ?? $default; }
function str_in(string $k, int $max = 190, bool $required = true): ?string {
    $v = req($k); if ($v === null || $v === '') { if ($required) fail('INVALID_INPUT', 422, ['field' => $k]); return null; }
    if (!is_string($v) && !is_numeric($v)) fail('INVALID_INPUT', 422, ['field' => $k]);
    $v = trim((string)$v); if (mb_strlen($v) > $max) fail('INVALID_INPUT', 422, ['field' => $k]); return $v;
}
function int_in(string $k, ?int $default = null): int {
    $v = req($k, $default); if (!is_numeric($v) || (int)$v != $v) fail('INVALID_INPUT', 422, ['field' => $k]); return (int)$v;
}

// ---------- Validation helpers ----------
function valid_e164(string $m): bool { return (bool)preg_match('/^\+[1-9]\d{7,14}$/', $m) && (!str_starts_with($m, '+971') || preg_match('/^\+9715\d{8}$|^\+971[2-9]\d{7}$/', $m)); }
function valid_pin(string $p): bool {
    if (!preg_match('/^\d{4,6}$/', $p)) return false;
    if (count(array_unique(str_split($p))) === 1) return false;                // 0000, 111111
    if (str_contains('0123456789', $p) || str_contains('9876543210', $p)) return false; // 1234, 4321, 123456
    if (in_array($p, ['1212', '2580', '0852', '1004', '2000', '6969', '121212', '112233', '159753', '147258', '852456'], true)) return false;
    return true;
}
function valid_password(string $p): bool { return strlen($p) >= 10 && preg_match('/[A-Za-z]/', $p) && preg_match('/\d/', $p); }
function hash_secret(string $s): string { return password_hash($s, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT); }

// ---------- Rate limiting ----------
function rate_limit(string $key, int $max, int $window): void {
    $now = time(); $k = substr($key, 0, 190);
    $r = one('SELECT window_start, hits FROM rate_limits WHERE rk=?', [$k]);
    if (!$r || $r['window_start'] + $window <= $now) { q('REPLACE INTO rate_limits (rk, window_start, hits) VALUES (?,?,1)', [$k, $now]); return; }
    if ($r['hits'] >= $max) { header('Retry-After: ' . ($r['window_start'] + $window - $now)); fail('RATE_LIMITED', 429, ['retry_after' => $r['window_start'] + $window - $now]); }
    q('UPDATE rate_limits SET hits = hits + 1 WHERE rk=?', [$k]);
}

// ---------- Tokens (HS256 JWT access + rotating opaque refresh) ----------
function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64u_dec(string $s): string { return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }
function jwt_sign(array $claims): string {
    global $CFG;
    $h = b64u(json_encode(['alg' => 'HS256', 'typ' => 'JWT'])); $p = b64u(json_encode($claims));
    return "$h.$p." . b64u(hash_hmac('sha256', "$h.$p", $CFG['jwt_secret'], true));
}
function jwt_verify(string $t): ?array {
    global $CFG;
    $parts = explode('.', $t); if (count($parts) !== 3) return null;
    [$h, $p, $s] = $parts;
    if (!hash_equals(b64u(hash_hmac('sha256', "$h.$p", $CFG['jwt_secret'], true)), $s)) return null;
    $c = json_decode(b64u_dec($p), true); if (!is_array($c) || ($c['exp'] ?? 0) < time()) return null;
    return $c;
}
function issue_tokens(array $user): array {
    global $CFG;
    $access = jwt_sign(['sub' => $user['id'], 'role' => $user['role'], 'ca' => $user['client_admin_id'] ?? null, 'iat' => time(), 'exp' => time() + $CFG['access_ttl']]);
    $refresh = bin2hex(random_bytes(32));
    q('INSERT INTO refresh_tokens (id, user_id, token_hash, expires_at) VALUES (?,?,?,?)', [uuid4(), $user['id'], hash('sha256', $refresh), gmdate('Y-m-d H:i:s', time() + $CFG['refresh_ttl'])]);
    return ['access_token' => $access, 'refresh_token' => $refresh, 'expires_in' => $CFG['access_ttl']];
}

/** Returns the authenticated user row (fresh from DB). */
function auth(array $roles = [], bool $allowMustChange = false): array {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $h, $m)) fail('UNAUTHORIZED', 401);
    $c = jwt_verify(trim($m[1])); if (!$c) fail('UNAUTHORIZED', 401);
    $u = one('SELECT * FROM users WHERE id=? AND deleted_at IS NULL AND status="active"', [$c['sub']]);
    if (!$u) fail('UNAUTHORIZED', 401);
    if ($roles && !in_array($u['role'], $roles, true)) fail('FORBIDDEN', 403);
    if ($u['must_change_secret'] && !$allowMustChange) fail('CHANGE_PASSWORD', 403);
    return $u;
}
function public_user(array $u): array {
    return array_intersect_key($u, array_flip(['id', 'role', 'username', 'name', 'mobile', 'email', 'dob', 'client_admin_id', 'vendor_id', 'must_change_secret', 'can_share_vacancies']));
}

// ---------- Tenant scope ----------
/** The client_admin id that scopes a caller (null for super admin = global). */
function tenant_of(array $u): ?string { return $u['role'] === 'super_admin' ? null : ($u['role'] === 'client_admin' ? $u['id'] : $u['client_admin_id']); }

function audit(?array $actor, string $action, array $details = []): void {
    q('INSERT INTO audit_logs (actor_id, actor_role, client_admin_id, action, details, ip) VALUES (?,?,?,?,?,?)',
        [$actor['id'] ?? null, $actor['role'] ?? null, $actor ? tenant_of($actor) : null, $action, json_encode($details, JSON_UNESCAPED_UNICODE), client_ip()]);
}

// ---------- Entitlements ----------
const MODULES = ['health', 'family', 'sharing'];
function effective_modules(array $u): array {
    $ca = tenant_of($u); if (!$ca) return MODULES;
    $caRow = one('SELECT * FROM client_admins WHERE id=?', [$ca]);
    $planId = $u['plan_id'] ?: ($caRow['plan_id'] ?? null);
    $plan = $planId ? one('SELECT modules FROM plans WHERE id=?', [$planId]) : null;
    $mods = $plan ? (json_decode($plan['modules'], true) ?: []) : [];
    foreach (all('SELECT module, enabled FROM module_flags WHERE client_admin_id=?', [$ca]) as $f) {
        if ($f['enabled']) $mods[] = $f['module']; else $mods = array_diff($mods, [$f['module']]);
    }
    if (empty($caRow['sharing_enabled'])) $mods = array_diff($mods, ['sharing']);
    return array_values(array_unique(array_intersect($mods, MODULES)));
}
function sync_active(string $userId): ?array {
    return one('SELECT * FROM subscriptions WHERE user_id=? AND includes_sync=1 AND status="active" AND period_end >= UTC_DATE() ORDER BY period_end DESC LIMIT 1', [$userId]);
}
function client_admin_usable(?array $ca): bool {
    if (!$ca || $ca['status'] === 'Expired') return false;
    if ($ca['expires_at'] && $ca['expires_at'] < gmdate('Y-m-d')) return false;
    return true;
}
function action(): string { return (string)($_GET['action'] ?? req('action', '')); }

// Enforce JSON-only / size / binary rules (§6.4) on every request before any endpoint logic runs.
if (!defined('DIGITZ_CLI')) input();
