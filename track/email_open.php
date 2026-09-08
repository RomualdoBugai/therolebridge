<?php

declare(strict_types=1);

// Valida se é bot antes de qualquer coisa (proteção básica)
require_once __DIR__ . '/../includes/bot_check.php';

/**
 * Email open tracking pixel.
 * Usage in HTML:
 *   <img src="https://therolebridge.com/track/email_open.php?m=EMAIL_MESSAGE_ID"
 *        width="1" height="1" style="display:none;" alt="" />
 */

// 1x1 transparent GIF
const PIXEL_DATA = "\x47\x49\x46\x38\x39\x61\x01\x00\x01\x00\x80\x00\x00"
    . "\x00\x00\x00\xff\xff\xff\x21\xf9\x04\x01\x00\x00\x00\x00"
    . "\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x4c\x01\x00\x3b";

/**
 * Always output the pixel, regardless of DB errors.
 */
function outputPixel(): void
{
    header('Content-Type: image/gif');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo PIXEL_DATA;
    exit;
}

// Basic param check
if (!isset($_GET['m'])) {
    outputPixel();
}

$emailMessageId = (int) $_GET['m'];
if ($emailMessageId <= 0) {
    outputPixel();
}

require_once __DIR__ . '/../includes/config.php';

$pdo = null;

try {
    $pdo = getPdoConnection();
} catch (Throwable $e) {
    error_log('email_open: DB connection error: ' . $e->getMessage());
    outputPixel();
}

$ip        = $_SERVER['REMOTE_ADDR']       ?? null;
$userAgent = $_SERVER['HTTP_USER_AGENT']   ?? null;

try {
    $pdo->beginTransaction();

    // Set first_open_at if null
    $stmt = $pdo->prepare("
        UPDATE email_messages
        SET first_open_at = COALESCE(first_open_at, :first_open_at)
        WHERE id = :id
    ");
    $stmt->execute([
        ':first_open_at' => date('Y-m-d H:i:s'),
        ':id' => $emailMessageId,
    ]);

    // Insert open event
    $stmt = $pdo->prepare("
        INSERT INTO email_opens (email_message_id, opened_at, ip, user_agent)
        VALUES (:id, :opened_at, :ip, :ua)
    ");
    $stmt->execute([
        ':id' => $emailMessageId,
        ':opened_at' => date('Y-m-d H:i:s'),
        ':ip' => $ip,
        ':ua' => $userAgent,
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('email_open error: ' . $e->getMessage());
}

// Always output pixel, even on error
outputPixel();
