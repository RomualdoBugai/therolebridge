<?php

declare(strict_types=1);

// Valida se é bot antes de qualquer coisa (proteção básica)
require_once __DIR__ . '/../includes/bot_check_v2.php';

/**
 * Email click tracking with redirect.
 *
 * Usage:
 *   <a href="https://therolebridge.com/track/email_click.php?m=EMAIL_MESSAGE_ID&u=URL_ENCODED_DEST">
 *       Link text
 *   </a>
 *
 * - m = email_messages.id
 * - u = final URL (urlencoded)
 */

// Basic param check
if (!isset($_GET['m'], $_GET['u'])) {
    http_response_code(400);
    echo 'Bad request';
    exit;
}

$emailMessageId = (int) $_GET['m'];
if ($emailMessageId <= 0) {
    http_response_code(400);
    echo 'Invalid message id';
    exit;
}

// Decode target URL
$targetParam = (string) $_GET['u'];

// IMPORTANTE:
// - Você já fez rawurlencode no envio do e-mail
// - O PHP já decodificou uma vez quando criou $_GET['u']
//   => NÃO chame urldecode de novo aqui.
$targetUrl = $targetParam;

// ===== Validação com parse_url em vez de filter_var =====
$parts = parse_url($targetUrl);
if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
    http_response_code(400);
    echo 'Invalid URL';
    exit;
}

$scheme = strtolower($parts['scheme']);
if (!in_array($scheme, ['http', 'https'], true)) {
    http_response_code(400);
    echo 'Invalid URL';
    exit;
}

// OPCIONAL: se quiser travar só nos teus domínios pra não virar open redirect:
// if (!preg_match('/(therolebridge\.com)$/i', $parts['host'])) {
//     http_response_code(400);
//     echo 'Invalid redirect host';
//     exit;
// }

$targetUrlValidated = $targetUrl;

require_once __DIR__ . '/../includes/config.php';

$pdo = null;

try {
    $pdo = getPdoConnection();
} catch (Throwable $e) {
    error_log('email_click: DB connection error: ' . $e->getMessage());
    // Mesmo com erro de DB, redireciona para não quebrar fluxo do usuário
    header('Location: ' . $targetUrlValidated, true, 302);
    exit;
}

$ip        = $_SERVER['REMOTE_ADDR']     ?? null;
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

try {
    $pdo->beginTransaction();

    // Set first_click_at if null
    $stmt = $pdo->prepare("
        UPDATE email_messages
        SET first_click_at = COALESCE(first_click_at, :first_click_at)
        WHERE id = :id
    ");
    $stmt->execute([
        ':first_click_at' => date('Y-m-d H:i:s'),
        ':id' => $emailMessageId,
    ]);

    // Insert click event
    $stmt = $pdo->prepare("
        INSERT INTO email_clicks (email_message_id, clicked_at, url, ip, user_agent)
        VALUES (:id, :clicked_at, :url, :ip, :ua)
    ");
    $stmt->execute([
        ':id'  => $emailMessageId,
        ':clicked_at' => date('Y-m-d H:i:s'),
        ':url' => $targetUrlValidated,
        ':ip'  => $ip,
        ':ua'  => $userAgent,
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('email_click error: ' . $e->getMessage());
}

// Always redirect to final URL
header('Location: ' . $targetUrlValidated, true, 302);
exit;
