<?php

declare(strict_types=1);

// RFC 8058 one-click: Gmail/ESPs send POST ?m=ID with body List-Unsubscribe=One-Click.
// These automated requests have no browser UA/headers, so they must bypass bot_check.
$isOneClickPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && isset($_GET['m'])
    && trim((string) ($_POST['List-Unsubscribe'] ?? '')) === 'One-Click';

if (!$isOneClickPost) {
    require_once __DIR__ . '/../includes/bot_check_ses.php';
}

/**
 * Email unsubscribe endpoint.
 *
 * Usage:
 *   /track/unsubscribe.php?m=EMAIL_MESSAGE_ID
 */

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function renderPage(string $title, string $message, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    $titleEsc   = h($title);
    $messageEsc = h($message);

    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$titleEsc}</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            background: #f7f7f7;
            color: #111827;
            font-family: Arial, Helvetica, sans-serif;
        }
        .wrap {
            max-width: 640px;
            margin: 60px auto;
            padding: 24px;
        }
        .card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 32px 24px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04);
        }
        h1 {
            margin: 0 0 12px;
            font-size: 28px;
            line-height: 1.2;
        }
        p {
            margin: 0;
            font-size: 16px;
            line-height: 1.6;
            color: #374151;
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <h1>{$titleEsc}</h1>
            <p>{$messageEsc}</p>
        </div>
    </div>
</body>
</html>
HTML;
    exit;
}

// =========================
// BASIC VALIDATION
// =========================

if (!isset($_GET['m'])) {
    renderPage('Invalid request', 'Missing message id.', 400);
}

$emailMessageId = (int) $_GET['m'];
if ($emailMessageId <= 0) {
    renderPage('Invalid request', 'Invalid message id.', 400);
}

// =========================
// BOOTSTRAP
// =========================

require_once __DIR__ . '/../includes/config.php';

try {
    $pdo = getPdoConnection();
} catch (Throwable $e) {
    error_log('unsubscribe: DB connection error: ' . $e->getMessage());
    renderPage(
        'Request could not be completed',
        'We could not process your unsubscribe request right now. Please try again later.',
        500
    );
}

$ip        = $_SERVER['REMOTE_ADDR']     ?? null;
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

if (!is_string($userAgent) || $userAgent === '') {
    $userAgent = null;
}

if ($userAgent !== null) {
    $userAgent = mb_substr($userAgent, 0, 512);
}

// =========================
// MESSAGE CHECK
// =========================

try {
    $stmt = $pdo->prepare("
        SELECT id, email
        FROM email_messages
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([
        ':id' => $emailMessageId,
    ]);

    $message = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$message) {
        renderPage('Invalid request', 'Message not found.', 404);
    }
} catch (Throwable $e) {
    error_log('unsubscribe: message lookup error: ' . $e->getMessage());
    renderPage(
        'Request could not be completed',
        'We could not validate your unsubscribe request right now. Please try again later.',
        500
    );
}

$email = strtolower(trim((string)($message['email'] ?? '')));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    error_log('unsubscribe: invalid email for message id=' . $emailMessageId);
    renderPage(
        'Request could not be completed',
        'We could not validate your unsubscribe request right now. Please try again later.',
        500
    );
}

// =========================
// RECORD UNSUBSCRIBE
// =========================

try {
    $stmt = $pdo->prepare("
        INSERT INTO email_unsubs (
            email_message_id,
            email,
            unsub_at,
            ip,
            user_agent
        ) VALUES (
            :email_message_id,
            :email,
            :unsub_at,
            :ip,
            :user_agent
        )
        ON DUPLICATE KEY UPDATE
            email = VALUES(email)
    ");

    $stmt->execute([
        ':email_message_id' => $emailMessageId,
        ':email'            => $email,
        ':unsub_at'         => date('Y-m-d H:i:s'),
        ':ip'               => $ip,
        ':user_agent'       => $userAgent,
    ]);

    if ($isOneClickPost) {
        http_response_code(200);
        exit;
    }

    renderPage(
        'You have been unsubscribed',
        'Your request has been recorded successfully.'
    );
} catch (Throwable $e) {
    error_log('unsubscribe: insert error: ' . $e->getMessage());

    if ($isOneClickPost) {
        http_response_code(500);
        exit;
    }

    renderPage(
        'Request could not be completed',
        'We could not process your unsubscribe request right now. Please try again later.',
        500
    );
}
