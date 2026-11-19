<?php
// Applies schema.sql idempotently (CREATE TABLE IF NOT EXISTS / INSERT IGNORE).
// Runs on the server after every deploy. CLI only.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$root = dirname(__DIR__);
// First deploy on a fresh server: run the one-time installer with the settings setup-server.sh saved.
if (!is_file($root . '/config.php')) {
    $envFile = '/etc/digitz/install.env';
    if (!is_file($envFile)) { fwrite(STDERR, "config.php missing and no $envFile — run deploy/setup-server.sh first\n"); exit(1); }
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) if (str_contains($line, '=')) putenv(trim($line));
    require __DIR__ . '/install-cli.php';
    @unlink($envFile);
    exit(0);
}
$cfg = require $root . '/config.php';
$pdo = new PDO($cfg['db']['dsn'], $cfg['db']['user'], $cfg['db']['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$n = 0;
foreach (array_filter(array_map('trim', explode(";\n", file_get_contents($root . '/schema.sql')))) as $stmt) {
    if (preg_match('/^\s*(--.*\n?)*\s*$/', $stmt)) continue;
    $pdo->exec($stmt); $n++;
}
echo "migrate: $n statements applied\n";
