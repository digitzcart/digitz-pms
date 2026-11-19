<?php
declare(strict_types=1);
// WhatsApp Business Cloud API — sends an authentication-template OTP.

function send_whatsapp_otp(string $e164, string $code): bool {
    global $CFG;
    $w = $CFG['whatsapp'];
    if (!empty($CFG['otp_dev_mode'])) { error_log("[digitz][DEV OTP] $e164 => $code"); if (empty($w['access_token'])) return true; }
    if (empty($w['phone_number_id']) || empty($w['access_token'])) { error_log('[digitz] WhatsApp not configured'); return false; }

    $components = [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $code]]]];
    if (!empty($w['button_param'])) {
        $components[] = ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $code]]];
    }
    $body = [
        'messaging_product' => 'whatsapp',
        'to' => ltrim($e164, '+'),
        'type' => 'template',
        'template' => ['name' => $w['template_name'], 'language' => ['code' => $w['template_lang']], 'components' => $components],
    ];
    $ch = curl_init("https://graph.facebook.com/{$w['graph_version']}/{$w['phone_number_id']}/messages");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $w['access_token'], 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($body),
    ]);
    $res = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($status < 200 || $status >= 300) { error_log("[digitz] WhatsApp send failed ($status): " . substr((string)$res, 0, 500)); return false; }
    return true;
}
