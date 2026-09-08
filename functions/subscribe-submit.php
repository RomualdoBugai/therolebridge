<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Method not allowed.', [], 405);
}

// ===============
// Turnstile CAPTCHA
// ===============
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

// ===============
// Sanitize input
// ===============
$firstName  = trim((string)($_POST['first_name']  ?? ''));
$lastName   = trim((string)($_POST['last_name']   ?? ''));
$email      = trim((string)($_POST['email']       ?? ''));
$jobKeyword = trim((string)($_POST['job_keyword'] ?? ''));
$city       = trim((string)($_POST['city']        ?? ''));
$state      = trim((string)($_POST['state']       ?? ''));
$zip        = trim((string)($_POST['zip']         ?? ''));
$consent    = (string)($_POST['consent'] ?? '') === '1';
$source     = trim((string)($_POST['source'] ?? 'website')); // opcional, só se você for usar depois

$errors = [];

// ===============
// Required fields
// ===============
if ($firstName === '') {
    $errors[] = 'First name is required';
}

if ($lastName === '') {
    $errors[] = 'Last name is required';
}

if ($email === '') {
    $errors[] = 'Email is required';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Invalid email address';
}

if ($jobKeyword === '') {
    $errors[] = 'Job keyword is required';
}

if ($city === '') {
    $errors[] = 'City is required';
}

if ($state === '') {
    $errors[] = 'State is required';
} elseif (!preg_match('/^[A-Za-z]{2}$/', $state)) {
    $errors[] = 'Enter a valid 2-letter state code (e.g., SC)';
}

if ($zip === '') {
    $errors[] = 'ZIP is required';
} elseif (!preg_match('/^\d{5}(-\d{4})?$/', $zip)) {
    $errors[] = 'Enter a valid ZIP (e.g., 29201 or 29201-1234)';
}

if (!$consent) {
    $errors[] = 'You must accept consent to subscribe';
}

// Se tiver qualquer erro de validação, retorna tudo de uma vez
if (!empty($errors)) {
    respond(false, 'Validation failed.', ['errors' => $errors], 422);
}

// Normaliza state pra maiúsculo
$state = strtoupper($state);

try {
    $pdo = getPdoConnection();

    // Optional: prevent duplicates by email
    $stmt = $pdo->prepare("SELECT id FROM record_leads WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    $existingId = $stmt->fetchColumn();

    if (!$existingId) {
        $ins = $pdo->prepare("
            INSERT INTO record_leads (
                provider_data_id,
                email,
                first_name,
                last_name,
                job_keyword,
                city,
                state,
                zip,
                created_at,
                updated_at
            ) VALUES (
                :provider_data_id,
                :email,
                :first_name,
                :last_name,
                :job_keyword,
                :city,
                :state,
                :zip,
                :created_at,
                :updated_at
            )
        ");
        $ins->execute([
            ':provider_data_id' => 1,
            ':email'            => $email,
            ':first_name'       => $firstName,
            ':last_name'        => $lastName,
            ':job_keyword'      => $jobKeyword,
            ':city'             => $city,
            ':state'            => $state,
            ':zip'              => $zip,
            ':created_at'       => date('Y-m-d H:i:s'),
            ':updated_at'       => date('Y-m-d H:i:s'),
        ]);
    } else {
        $upd = $pdo->prepare("
            UPDATE record_leads
            SET
                first_name  = :first_name,
                last_name   = :last_name,
                job_keyword = :job_keyword,
                city        = :city,
                state       = :state,
                zip         = :zip,
                updated_at  = :updated_at
            WHERE id = :id
        ");
        $upd->execute([
            ':id'          => (int)$existingId,
            ':first_name'  => $firstName,
            ':last_name'   => $lastName,
            ':job_keyword' => $jobKeyword,
            ':city'        => $city,
            ':state'       => $state,
            ':zip'         => $zip,
            ':updated_at'  => date('Y-m-d H:i:s'),
        ]);
    }
} catch (Throwable $e) {
    // Não vaza erro pro usuário, só loga
    error_log('subscribe-submit error: ' . $e->getMessage());
    respond(false, 'Database error. Please try again later.', [], 500);
}

respond(true, 'Thanks! You’re subscribed. Check your inbox soon.');
