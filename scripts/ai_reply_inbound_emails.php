#!/usr/bin/env php
<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

// =======================================
// CLI ONLY
// =======================================
if (PHP_SAPI !== 'cli') {
    echo "This script must be run from CLI.\n";
    return;
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

// =======================================
// CONFIG
// =======================================

$openAiModel = getenv('OPENAI_MODEL') ?: 'gpt-4.1-mini';
$openAiApiKey = getenv('OPENAI_API_KEY') ?: '';

// ChatGPT desligado. Para religar: AI_REPLY_USE_OPENAI=1
$useOpenAi = getenv('AI_REPLY_USE_OPENAI') === '1';

// Mantém baixo por padrão. Se precisar, ajuste por env: AI_REPLY_BATCH_LIMIT=20
$batchLimit = (int)(getenv('AI_REPLY_BATCH_LIMIT') ?: 350);
$batchLimit = max(1, min($batchLimit, 350));

// Limite duro do corpo enviado para IA. Isso é o que mais economiza token.
$maxBodyCharsForAi = (int)(getenv('AI_REPLY_MAX_BODY_CHARS') ?: 1200);
$maxBodyCharsForAi = max(500, min($maxBodyCharsForAi, 1200));

// Resposta curta. 600 era desperdício para email simples.
$maxOutputTokens = (int)(getenv('AI_REPLY_MAX_OUTPUT_TOKENS') ?: 220);
$maxOutputTokens = max(80, min($maxOutputTokens, 400));

if ($useOpenAi && $openAiApiKey === '') {
    fwrite(STDERR, "ERROR: OPENAI_API_KEY not set in environment.\n");
    return;
}

// =======================================
// HELPERS
// =======================================

function safeTrim(?string $value): string
{
    return trim((string)$value);
}

function normalizeWhitespace(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
    return trim($text);
}

function stripHtmlToText(string $html): string
{
    $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html) ?? $html;
    $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $html) ?? $html;
    $html = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
    $html = preg_replace('/<\/p\s*>/i', "\n", $html) ?? $html;
    $html = preg_replace('/<\/div\s*>/i', "\n", $html) ?? $html;
    $text = strip_tags($html);
    return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function cutQuotedThread(string $body): string
{
    $body = str_replace(["\r\n", "\r"], "\n", $body);

    $patterns = [
        '/\nOn\s.+?wrote:\s*/is',
        '/\nEm\s.+?escreveu:\s*/is',
        '/\nEl\s.+?escribi[oó]:\s*/is',
        '/\nFrom:\s.+/is',
        '/\nDe:\s.+/is',
        '/\nSent:\s.+/is',
        '/\nEnviado:\s.+/is',
        '/\nTo:\s.+/is',
        '/\nPara:\s.+/is',
        '/\nSubject:\s.+/is',
        '/\nAssunto:\s.+/is',
        '/\n-----Original Message-----/is',
        '/\n_{5,}.*/s',
        '/\n-{5,}\s*Forwarded message\s*-{5,}.*/is',
    ];

    foreach ($patterns as $pattern) {
        $parts = preg_split($pattern, $body, 2);
        if (is_array($parts) && trim($parts[0]) !== '') {
            $body = $parts[0];
        }
    }

    $lines = explode("\n", $body);
    $clean = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if ($trimmed === '') {
            $clean[] = '';
            continue;
        }

        // Remove linhas quoteadas e headers repetidos.
        if (str_starts_with($trimmed, '>')) {
            continue;
        }

        if (preg_match('/^(from|to|sent|subject|de|para|assunto|enviado):\s/i', $trimmed)) {
            continue;
        }

        $clean[] = $trimmed;
    }

    return normalizeWhitespace(implode("\n", $clean));
}

function cleanInboundEmailBody(array $emailRow, int $maxChars): string
{
    $bodyText = safeTrim($emailRow['body_text'] ?? '');

    // Detectar confirmações curtas rápidas
    if (in_array(trim(mb_strtolower($bodyText)), ['yes', 'si', 'correct', 'ok', 'é', 'sim', 'yes it is', 'that is correct'], true)) {
        return "Thank you for confirming."; // será tratado pelas regras
    }

    if ($bodyText === '') {
        $bodyText = stripHtmlToText((string)($emailRow['body_html'] ?? ''));
    } elseif (preg_match('/<\/?[a-z][\s\S]*>/i', $bodyText)) {
        // Proteção caso body_text venha com HTML por erro de parser.
        $bodyText = stripHtmlToText($bodyText);
    }

    $bodyText = html_entity_decode($bodyText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $bodyText = cutQuotedThread($bodyText);

    // Remove lixo comum de footer/tracking. Não precisa ir para IA.
    $footerPatterns = [
        '/\n.*unsubscribe.*$/is',
        '/\n.*manage preferences.*$/is',
        '/\n.*update preferences.*$/is',
        '/\n.*privacy policy.*$/is',
        '/\n.*view this email in your browser.*$/is',
    ];

    foreach ($footerPatterns as $pattern) {
        $parts = preg_split($pattern, $bodyText, 2);
        if (is_array($parts) && trim($parts[0]) !== '') {
            $bodyText = $parts[0];
        }
    }

    $bodyText = normalizeWhitespace($bodyText);

    if (mb_strlen($bodyText) > $maxChars) {
        $bodyText = mb_substr($bodyText, 0, $maxChars) . "\n[message truncated]";
    }

    return $bodyText;
}

function detectLanguage(string $text): string
{
    $lower = mb_strtolower($text);

    if (preg_match('/\b(cancelar|remover|parar|descadastrar|não quero|nao quero|obrigado|emprego|vaga)\b/u', $lower)) {
        return 'pt';
    }

    if (preg_match('/\b(cancelar|quitar|eliminar|baja|no quiero|gracias|trabajo|empleo)\b/u', $lower)) {
        return 'es';
    }

    return 'en';
}

function buildRuleBasedReplyIfPossible(array $emailRow, string $cleanBody): ?string
{
    $subject   = safeTrim($emailRow['subject'] ?? '');
    $fullText  = mb_strtolower($subject . "\n" . $cleanBody);
    $lang      = detectLanguage($fullText);
    $brandName = getBrandName($emailRow);     // ← Dinâmico agora

    $bodyLower = mb_strtolower($cleanBody);

    // ========================
    // 1. UNSUBSCRIBE / CANCELAR
    // ========================
    if (preg_match('/\b(unsubscribe|stop|cancel|remove|opt out|parar|cancelar|descadastrar|dar de baja|no quiero|unsub)\b/u', $fullText)) {
        return match ($lang) {
            'pt' => "Você pode parar de receber nossos emails clicando no link **Unsubscribe** que aparece no final de qualquer email nosso.\n\nEssa é a forma mais rápida e segura.\n\nAtenciosamente,\n{$brandName}",
            'es' => "Puede dejar de recibir nuestros correos haciendo clic en el enlace **Unsubscribe** al final de cualquier email nuestro.\n\nEsa es la forma más rápida.\n\nSaludos,\n{$brandName}",
            default => "You can stop receiving our emails by clicking the **Unsubscribe** link at the bottom of any email from us.\n\nThis is the fastest way.\n\nBest regards,\n{$brandName}",
        };
    }

    // ========================
    // 2. CONFIRMAÇÃO
    // ========================
    if (preg_match('/\b(yes|sim|si|correct|é meu|this is|confirm|confirmed|my number)\b/u', $fullText)) {
        return match ($lang) {
            'pt' => "Obrigado por confirmar seus dados!\n\nContinuaremos enviando vagas relevantes para você.\n\nPara atualizar preferências acesse:\nhttps://therolebridge.com/subscribe.php\n\nAtenciosamente,\n{$brandName}",
            'es' => "¡Gracias por confirmar tus datos!\n\nSeguiremos enviando oportunidades relevantes.\n\nPara actualizar preferencias:\nhttps://therolebridge.com/subscribe.php\n\nSaludos,\n{$brandName}",
            default => "Thank you for confirming your information!\n\nWe'll continue sending you relevant job leads.\n\nTo update your preferences:\nhttps://therolebridge.com/subscribe.php\n\nBest regards,\n{$brandName}",
        };
    }

    // ========================
    // 3. MAIS INFORMAÇÃO SOBRE A VAGA
    // ========================
    if (preg_match('/\b(more info|details|information|send me|what is the job|detalhes|mais informações?|más información)\b/u', $fullText)) {
        return match ($lang) {
            'pt' => "{$brandName} apenas envia leads de emprego baseados no seu perfil. Não temos detalhes adicionais além do que foi enviado no email.\n\nPor favor, clique no link da vaga para se candidatar diretamente.\n\nAtenciosamente,\n{$brandName}",
            'es' => "{$brandName} solo envía leads basados en tu perfil. No tenemos más detalles.\n\nHaz clic en el enlace de la oferta para aplicar directamente.\n\nSaludos,\n{$brandName}",
            default => "{$brandName} only sends job leads based on your profile. We don’t have additional details beyond the email.\n\nPlease click the job link to apply directly.\n\nBest regards,\n{$brandName}",
        };
    }

    // ========================
    // 4. STATUS / ENTREVISTA
    // ========================
    if (preg_match('/\b(status|application|interview|entrevista|candidatura|they called|they contacted)\b/u', $fullText)) {
        return match ($lang) {
            'pt' => "Não somos o empregador nem recrutador. Não temos acesso ao status das candidaturas.\n\nEntre em contato diretamente com o empregador através do link da vaga.\n\nAtenciosamente,\n{$brandName}",
            'es' => "No somos el empleador ni el reclutador. No tenemos acceso al estado de las solicitudes.\n\nContacta directamente al empleador.\n\nSaludos,\n{$brandName}",
            default => "We are not the employer or recruiter and do not have access to application status.\n\nPlease contact the employer directly through the job link.\n\nBest regards,\n{$brandName}",
        };
    }

    // ========================
    // 5. WHO ARE YOU / WHY RECEIVING
    // ========================
    if (preg_match('/\b(who are you|what is this|why did i|por que recebi|quem são|por qué recibí)\b/u', $fullText)) {
        return match ($lang) {
            'pt' => "{$brandName} envia leads de emprego por email com base no perfil dos assinantes.\n\nPara gerenciar preferências: https://therolebridge.com/subscribe.php\n\nAtenciosamente,\n{$brandName}",
            'es' => "{$brandName} envía oportunidades según tu perfil.\n\nPara gestionar preferencias: https://therolebridge.com/subscribe.php\n\nSaludos,\n{$brandName}",
            default => "{$brandName} sends job leads based on subscriber profiles.\n\nTo manage preferences: https://therolebridge.com/subscribe.php\n\nBest regards,\n{$brandName}",
        };
    }

    // ========================
    // 6. QUALIFICAÇÕES
    // ========================
    if (preg_match('/\b(licensed|lpn|rn|nclex|nurse|license|qualifications)\b/u', $bodyLower) && mb_strlen($cleanBody) < 400) {
        return match ($lang) {
            'pt' => "Obrigado por compartilhar suas qualificações!\n\nContinuaremos enviando vagas compatíveis com seu perfil.\n\nAtenciosamente,\n{$brandName}",
            default => "Thank you for sharing your qualifications!\n\nWe'll continue sending relevant job leads.\n\nBest regards,\n{$brandName}",
        };
    }

    // ========================
    // 7. RESPOSTAS CURTAS
    // ========================
    $shortReplies = ['sim', 'yes', 'ok', 'obrigado', 'thanks', 'thank you', 'gracias', 'certo'];
    if (mb_strlen($cleanBody) < 80 && preg_match('/\b(' . implode('|', $shortReplies) . ')\b/u', $bodyLower)) {
        return match ($lang) {
            'pt' => "Obrigado! Continuaremos enviando oportunidades relevantes.\n\nAtenciosamente,\n{$brandName}",
            'es' => "¡Gracias! Seguiremos enviando oportunidades relevantes.\n\nSaludos,\n{$brandName}",
            default => "Thank you! We'll keep sending relevant opportunities.\n\nBest regards,\n{$brandName}",
        };
    }

    return null; // cai para GPT
}

function buildPromptForReply(array $emailRow, string $cleanBody): string
{
    $fromEmail = safeTrim($emailRow['from_email'] ?? '');
    $fromName  = safeTrim($emailRow['from_name'] ?? '');
    $subject   = safeTrim($emailRow['subject'] ?? '');

    $fromDisplay = $fromName !== '' ? "{$fromName} <{$fromEmail}>" : $fromEmail;

    return <<<PROMPT
Reply as The Role Bridge support.

Facts:
- The Role Bridge sends job lead emails to subscribers.
- We are not an employer, recruiter, staffing agency, or hiring manager.
- We do not control applications, interviews, hiring decisions, or employer systems.
- Users can update preferences here: https://therolebridge.com/subscribe.php
- For unsubscribe requests, tell them to use the unsubscribe link at the bottom of the last The Role Bridge email.

Rules:
- Reply in the same language as the sender.
- Answer only the newest message.
- Do not mention or repeat email addresses.
- Do not invent access, guarantees, services, or manual deletion.
- Be concise, practical, calm, and professional.
- Body only. No subject.
- End with "The Role Bridge" as signature.

From: {$fromDisplay}
Subject: {$subject}

Newest message:
{$cleanBody}
PROMPT;
}

function generateAiReply(string $apiKey, string $model, string $prompt, int $maxOutputTokens): string
{
    $payload = [
        'model' => $model,
        'messages' => [
            [
                'role' => 'system',
                'content' => 'You write short customer-support email replies for The Role Bridge.',
            ],
            [
                'role' => 'user',
                'content' => $prompt,
            ],
        ],
        'temperature' => 0.2,
        'max_tokens' => $maxOutputTokens,
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("cURL error calling OpenAI: {$err}");
    }

    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode < 200 || $httpCode >= 300) {
        file_put_contents(__DIR__ . '/openai_error.log', date('c') . " HTTP {$httpCode} {$response}\n\n", FILE_APPEND);
        throw new RuntimeException("OpenAI HTTP {$httpCode} response");
    }

    $json = json_decode($response, true);

    if (!isset($json['choices'][0]['message']['content'])) {
        file_put_contents(__DIR__ . '/openai_bad_schema.log', date('c') . " {$response}\n\n", FILE_APPEND);
        throw new RuntimeException('Unexpected OpenAI response schema');
    }

    $content = trim((string)$json['choices'][0]['message']['content']);

    if ($content === '') {
        file_put_contents(__DIR__ . '/openai_empty_content.log', date('c') . " {$response}\n\n", FILE_APPEND);
        throw new RuntimeException('OpenAI returned empty content');
    }

    return $content;
}

function sendEmailReply(
    string $fromEmail,
    string $fromName,
    string $toEmail,
    string $subject,
    string $htmlBody,
    ?string $inReplyTo,
    string $smtpHost,
    string $smtpUser,
    string $smtpPass,
    int $smtpPort
): void {
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = $smtpHost;
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtpUser;
        $mail->Password   = $smtpPass;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $smtpPort;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom($fromEmail, $fromName);
        $mail->addReplyTo($fromEmail, $fromName);
        $mail->addAddress($toEmail);

        if (!empty($inReplyTo)) {
            $mail->addCustomHeader('In-Reply-To', $inReplyTo);
            $mail->addCustomHeader('References', $inReplyTo);
        }

        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body    = $htmlBody;
        $mail->AltBody = trim(strip_tags($htmlBody));

        $mail->send();
    } catch (Exception $e) {
        throw new RuntimeException("PHPMailer error sending to {$toEmail}: " . $mail->ErrorInfo);
    }
}

function buildReplySubject(string $subject): string
{
    $subject = trim($subject);

    if ($subject === '') {
        return 'Re: (no subject)';
    }

    if (preg_match('/^\s*re\s*:/i', $subject)) {
        return $subject;
    }

    return 'Re: ' . $subject;
}

function fetchPendingInboundEmails(int $limit): array
{
    $limit = max(1, min($limit, 200));

    $sql = "
        SELECT
            ie.*,
            e.domain_name,
            e.name,
            e.smtp_host,
            e.smtp_user,
            e.smtp_pass,
            e.smtp_port
        FROM inbound_emails AS ie
        JOIN esp AS e
          ON e.id = ie.esp_id
        WHERE ie.processed_status = :processed_status
          AND ie.from_email IS NOT NULL
          AND ie.from_email <> ''
        ORDER BY ie.id ASC
        LIMIT {$limit}
    ";

    return pdoFetchAll($sql, [
        ':processed_status' => 'pending',
    ]);
}

function markInboundEmailProcessed(
    int $id,
    string $model,
    ?string $replyHtml,
    ?string $replyText,
    ?string $error,
    string $status
): void {
    pdoExecute(
        "
        UPDATE inbound_emails
        SET
            ai_reply_html    = :ai_reply_html,
            ai_reply_text    = :ai_reply_text,
            ai_model         = :ai_model,
            ai_sent_at       = CASE WHEN :set_ai_sent_at = 1 THEN NOW() ELSE ai_sent_at END,
            ai_error         = :ai_error,
            processed_status = :processed_status
        WHERE id = :id
        ",
        [
            ':ai_reply_html'    => $replyHtml,
            ':ai_reply_text'    => $replyText,
            ':ai_model'         => $model,
            ':set_ai_sent_at'   => $status === 'replied' ? 1 : 0,
            ':ai_error'         => $error,
            ':processed_status' => $status,
            ':id'               => $id,
        ]
    );
}

function markOtherPendingEmailsFromSender(
    string $fromEmail,
    int $currentId,
    string $model,
    string $note
): int {
    return pdoExecute(
        "
        UPDATE inbound_emails
        SET
            processed_status = :processed_status,
            ai_reply_html    = NULL,
            ai_reply_text    = NULL,
            ai_model         = :ai_model,
            ai_error         = :ai_error
        WHERE LOWER(TRIM(from_email)) = LOWER(TRIM(:from_email))
          AND processed_status = 'pending'
          AND id <> :id
        ",
        [
            ':processed_status' => 'replied',
            ':ai_model'         => $model,
            ':ai_error'         => $note,
            ':from_email'       => $fromEmail,
            ':id'               => $currentId,
        ]
    );
}

function shouldSkipSender(string $email): bool
{
    $email = strtolower(trim($email));

    return $email === ''
        || preg_match('/^(no-reply|noreply|donotreply|mailer-daemon|postmaster)@/i', $email) === 1;
}

function textToHtml(string $text): string
{
    return nl2br(htmlspecialchars(trim($text), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
}

/**
 * Retorna o nome correto da marca/empresa dinamicamente da tabela esp
 */
function getBrandName(array $emailRow): string
{
    $domainName = safeTrim($emailRow['domain_name'] ?? '');
    if ($domainName !== '') {
        // Remove .com, .net, etc e capitaliza
        $clean = preg_replace('/\.(com|net|org|io|co|app)$/i', '', $domainName);
        return ucwords(str_replace(['-', '_', '.'], ' ', $clean));
    }

    return 'Support Team'; // fallback seguro
}

// =======================================
// MAIN FLOW
// =======================================

$rows = fetchPendingInboundEmails($batchLimit);

if (empty($rows)) {
    echo "No pending inbound emails to reply.\n";
    return;
}

$seenEmails = [];

foreach ($rows as $row) {
    $id = (int)$row['id'];
    $fromEmail = safeTrim($row['from_email'] ?? '');
    $fromEmailNorm = strtolower($fromEmail);

    if (shouldSkipSender($fromEmailNorm)) {
        markInboundEmailProcessed(
            $id,
            'rule-based',
            null,
            null,
            'Skipped automated/no-reply sender.',
            'error'
        );
        echo "SKIP #{$id}: automated/no-reply sender {$fromEmail}.\n";
        continue;
    }

    if (isset($seenEmails[$fromEmailNorm])) {
        echo "Skipping #{$id}: duplicate for {$fromEmailNorm}, already replied in #{$seenEmails[$fromEmailNorm]}.\n";
        continue;
    }

    $domainName = safeTrim($row['domain_name'] ?? '');
    $smtpHost = safeTrim($row['smtp_host'] ?? '');
    $smtpUser = safeTrim($row['smtp_user'] ?? '');
    $smtpPass = safeTrim($row['smtp_pass'] ?? '');
    $smtpPort = (int)($row['smtp_port'] ?? 587);

    $replyFromEmail = $smtpUser;

    if ($replyFromEmail === '' || $smtpHost === '' || $smtpPass === '') {
        $errMsg = 'Missing SMTP config for esp_id=' . ($row['esp_id'] ?? 'null');

        markInboundEmailProcessed($id, $openAiModel, null, null, $errMsg, 'error');
        fwrite(STDERR, "SKIP #{$id}: {$errMsg}\n");
        continue;
    }

    $subject = safeTrim($row['subject'] ?? '');
    $replySubject = buildReplySubject($subject);
    $inReplyTo = !empty($row['message_id']) ? (string)$row['message_id'] : null;

    echo "Processing inbound #{$id} from {$fromEmail} (esp_id={$row['esp_id']})...\n";

    try {
        $cleanBody = cleanInboundEmailBody($row, $maxBodyCharsForAi);

        if ($cleanBody === '') {
            $cleanBody = '(empty message)';
        }

        $ruleReply = buildRuleBasedReplyIfPossible($row, $cleanBody);

        if ($ruleReply !== null) {
            $replyText = $ruleReply;
            $modelUsed = 'rule-based';
            echo "Using rule-based reply for #{$id}. No OpenAI call.\n";
        } elseif (!$useOpenAi) {
            markInboundEmailProcessed(
                $id,
                'rule-based',
                null,
                null,
                'OpenAI disabled: no rule matched, needs manual review.',
                'error'
            );
            echo "SKIP #{$id}: no rule matched and OpenAI is disabled. Marked for manual review.\n";
            continue;
        } else {
            $prompt = buildPromptForReply($row, $cleanBody);
            echo "OpenAI call for #{$id}. body_chars=" . mb_strlen($cleanBody) . ", prompt_chars=" . mb_strlen($prompt) . "\n";
            $replyText = generateAiReply($openAiApiKey, $openAiModel, $prompt, $maxOutputTokens);
            $modelUsed = $openAiModel;
        }

        $replyHtml = textToHtml($replyText);

        sendEmailReply(
            $replyFromEmail,
            $domainName !== '' ? $domainName : $replyFromEmail,
            $fromEmail,
            $replySubject,
            $replyHtml,
            $inReplyTo,
            $smtpHost,
            $smtpUser,
            $smtpPass,
            $smtpPort
        );

        markInboundEmailProcessed($id, $modelUsed, $replyHtml, $replyText, null, 'replied');

        $seenEmails[$fromEmailNorm] = $id;

        $affectedOthers = markOtherPendingEmailsFromSender(
            $fromEmailNorm,
            $id,
            $modelUsed,
            'Auto-processed: consolidated reply sent in id ' . $id
        );

        echo "OK: replied to {$fromEmail} (id {$id}) and marked {$affectedOthers} other pending email(s) from this sender as processed.\n";
    } catch (Throwable $e) {
        $errMsg = mb_substr($e->getMessage(), 0, 1000);

        markInboundEmailProcessed($id, $openAiModel, null, null, $errMsg, 'error');
        fwrite(STDERR, "ERROR replying to #{$id} ({$fromEmail}): {$errMsg}\n");
    }
}

echo "Done.\n";
