<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

// partner-submit.php - AJAX handler for The Role Bridge partner form

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
$companyName   = trim((string)($_POST['company_name']   ?? ''));
$website       = trim((string)($_POST['website']        ?? ''));
$contactEmail  = trim((string)($_POST['contact_email']  ?? ''));
$monthlyBudget = trim((string)($_POST['monthly_budget'] ?? ''));
$message       = trim((string)($_POST['message']        ?? ''));

$errors = [];

// 3. Validation (coerente com o JS)
if ($companyName === '') {
    $errors[] = 'Company name is required';
}

if ($website === '') {
    $errors[] = 'Website is required';
} else {
    // validação simples de URL
    if (!preg_match('~^https?://~i', $website)) {
        $errors[] = 'Website must start with http:// or https://';
    }
}

if ($contactEmail === '') {
    $errors[] = 'Contact email is required';
} elseif (!filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Enter a valid contact email';
}

// monthlyBudget e message são opcionais — não forço nada

if (!empty($errors)) {
    respond(false, 'Validation failed', ['errors' => $errors], 422);
}

// 4. Email config
$toEmail   = 'jeferson.martins@therolebridge.com';          // destino: você
$fromEmail = 'partners@therolebridge.com';       // remetente do domínio (ajusta se precisar)
$fromName  = $siteTitle . ' Partners';

$mailSubject = 'New partner request - ' . $siteTitle;

// 5. Build body
$body  = "You received a new PARTNER request from {$siteTitle}:\r\n\r\n";
$body .= "Company name:   {$companyName}\r\n";
$body .= "Website:        {$website}\r\n";
$body .= "Contact email:  {$contactEmail}\r\n";
$body .= "Monthly budget: " . ($monthlyBudget !== '' ? $monthlyBudget : 'Not provided') . "\r\n";
$body .= "Date:           " . date('Y-m-d H:i:s') . " (server time)\r\n";
$body .= "----------------------------------------\r\n";
$body .= "Message / Details:\r\n" . ($message !== '' ? $message : '[No additional message]') . "\r\n";

// 6. Headers
$headers   = "From: {$fromName} <{$fromEmail}>\r\n";
$headers  .= "Reply-To: Partner Contact <{$contactEmail}>\r\n";
$headers  .= "MIME-Version: 1.0\r\n";
$headers  .= "Content-Type: text/plain; charset=UTF-8\r\n";

// 7. Send
$sent = mail($toEmail, $mailSubject, $body, $headers);

if ($sent) {
    respond(true, 'Your message has been sent successfully. We will get back to you soon.');
}

$error = error_get_last();
error_log('Partner form: mail() failed. Last PHP error: ' . json_encode($error));

respond(false, 'We could not send your message. Please try again later.', [], 500);