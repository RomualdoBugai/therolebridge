<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

// contact-submit.php - AJAX handler for The Role Bridge contact form

header('Content-Type: application/json; charset=utf-8');

function respond(bool $success, string $message, array $extra = [], int $code = 200): void
{
    http_response_code($code);
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $extra));
    exit;
}

// 1. Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Method not allowed', [], 405);
}

// 2. Turnstile CAPTCHA
$turnstileToken = trim((string)($_POST['cf-turnstile-response'] ?? ''));
$turnstileSecret = getenv('TURNSTILE_SECRET') ?: '';

if ($turnstileToken === '') {
    respond(false, 'CAPTCHA verification required.', [], 422);
}

if ($turnstileSecret !== '' && $turnstileSecret !== 'your_secret_key_here') {
    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'secret'   => $turnstileSecret,
            'response' => $turnstileToken,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]),
        CURLOPT_TIMEOUT        => 5,
    ]);
    $tsResult = curl_exec($ch);
    curl_close($ch);
    $tsData = $tsResult ? json_decode($tsResult, true) : null;
    if (!($tsData['success'] ?? false)) {
        respond(false, 'CAPTCHA verification failed. Please try again.', [], 422);
    }
}

// 3. Get and sanitize input
$name    = trim((string)($_POST['name']     ?? ''));
$email   = trim((string)($_POST['email']    ?? ''));
$subject = trim((string)($_POST['subject']  ?? ''));
$message = trim((string)($_POST['comments'] ?? ''));

$errors = [];

// 3. Validation (mesma lógica do JS)
if ($name === '') {
    $errors[] = 'Name is required';
}

if ($email === '') {
    $errors[] = 'Email is required';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Enter a valid email';
}

if ($subject === '') {
    $errors[] = 'Subject is required';
}

if ($message === '') {
    $errors[] = 'Message is required';
}

if (!empty($errors)) {
    respond(false, 'Validation failed', ['errors' => $errors], 422);
}
$siteTitle = $siteTitle ?? 'The Role Bridge';

// 4. Email config
$toEmail   = 'jeferson.martins@therolebridge.com';      // destino
$fromEmail = 'contact@therolebridge.com';    // remetente do domínio
$fromName  = $siteTitle;

$mailSubject = 'New contact form message - ' . $siteTitle;

// 5. Build body
$body  = "You received a new message from the {$siteTitle} contact form:\r\n\r\n";
$body .= "Name:    {$name}\r\n";
$body .= "Email:   {$email}\r\n";
$body .= "Subject: {$subject}\r\n";
$body .= "Date:    " . date('Y-m-d H:i:s') . " (server time)\r\n";
$body .= "----------------------------------------\r\n";
$body .= "Message:\r\n{$message}\r\n";

// 6. Headers
$headers   = "From: {$fromName} <{$fromEmail}>\r\n";
$headers  .= "Reply-To: {$name} <{$email}>\r\n";
$headers  .= "MIME-Version: 1.0\r\n";
$headers  .= "Content-Type: text/plain; charset=UTF-8\r\n";

// 7. Send
$sent = mail($toEmail, $mailSubject, $body, $headers);

if ($sent) {
    respond(true, 'Your message has been sent successfully. We will get back to you soon.');
}

$error = error_get_last();
error_log('Contact form: mail() failed. Last PHP error: ' . json_encode($error));

respond(false, 'We could not send your message. Please try again later.', [], 500);