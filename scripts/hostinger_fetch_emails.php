<?php

declare(strict_types=1);

// public_html % docker run --rm \
//   -v "$PWD":/app \
//   -w /app \
//   -e DB_HOST=host.docker.internal \
//   -e DB_NAME=bugai_mail \
//   -e DB_USER=root \
//   -e DB_PASS='' \
//   php-imap-jld \
//   php scripts/hostinger_fetch_emails.php

// =======================================
// CLI ONLY
// =======================================
if (PHP_SAPI !== 'cli') {
    echo "This script must be run from CLI.\n";
    return;
}

require_once __DIR__ . '/../includes/config.php';

/**
 * =============
 * FUNÇÕES AUX
 * =============
 */

function decodeHeader(?string $value): string
{
    if (!$value) {
        return '';
    }

    $decoded = imap_mime_header_decode($value);
    $out = '';

    foreach ($decoded as $part) {
        $charset = strtolower((string)($part->charset ?? ''));
        $text = (string)($part->text ?? '');

        if ($charset === 'default' || $charset === 'us-ascii' || $charset === 'utf-8') {
            $out .= $text;
            continue;
        }

        $converted = @mb_convert_encoding($text, 'UTF-8', $charset);
        $out .= $converted !== false ? $converted : $text;
    }

    return trim(normalizeStoredValue($out));
}

function parseAddress(string $raw): array
{
    $raw = trim($raw);
    $name = '';
    $email = '';

    if ($raw === '') {
        return [$name, $email];
    }

    $parsed = imap_rfc822_parse_adrlist($raw, '');

    if ($parsed && isset($parsed[0])) {
        $p = $parsed[0];
        $email = trim((string)($p->mailbox ?? '') . '@' . (string)($p->host ?? ''), '@');
        $name = decodeHeader((string)($p->personal ?? ''));

        return [$name, $email];
    }

    if (preg_match('/<([^>]+)>/', $raw, $m)) {
        $email = trim($m[1]);
        $name = trim(str_replace($m[0], '', $raw), "\" '");
    } else {
        $email = $raw;
    }

    return [normalizeStoredValue($name), normalizeStoredValue($email)];
}

function decodePart(string $text, int $encoding): string
{
    switch ($encoding) {
        case ENCBASE64:
            return base64_decode($text) ?: '';
        case ENCQUOTEDPRINTABLE:
            return quoted_printable_decode($text);
        case ENC8BIT:
        case ENC7BIT:
        case ENCBINARY:
        default:
            return $text;
    }
}

function normalizeCharset(?string $charset): ?string
{
    if ($charset === null) {
        return null;
    }

    $charset = trim($charset, " \t\n\r\0\x0B\"'");

    if ($charset === '') {
        return null;
    }

    $lower = strtolower($charset);

    $map = [
        'utf8'          => 'UTF-8',
        'utf-8'         => 'UTF-8',
        'us-ascii'      => 'ASCII',
        'ascii'         => 'ASCII',
        'latin1'        => 'ISO-8859-1',
        'latin-1'       => 'ISO-8859-1',
        'iso8859-1'     => 'ISO-8859-1',
        'iso-8859-1'    => 'ISO-8859-1',
        'iso8859-15'    => 'ISO-8859-15',
        'iso-8859-15'   => 'ISO-8859-15',
        'cp1252'        => 'Windows-1252',
        'windows-1252'  => 'Windows-1252',
        'win-1252'      => 'Windows-1252',
        'cp1256'        => 'Windows-1256',
        'windows-1256'  => 'Windows-1256',
        'win-1256'      => 'Windows-1256',
        'cp1251'        => 'Windows-1251',
        'windows-1251'  => 'Windows-1251',
        'cp1250'        => 'Windows-1250',
        'windows-1250'  => 'Windows-1250',
    ];

    return $map[$lower] ?? $charset;
}

function getPartCharset(object $part): ?string
{
    if (!empty($part->parameters) && is_array($part->parameters)) {
        foreach ($part->parameters as $param) {
            if (
                isset($param->attribute, $param->value) &&
                strtolower((string)$param->attribute) === 'charset'
            ) {
                return normalizeCharset((string)$param->value);
            }
        }
    }

    if (!empty($part->dparameters) && is_array($part->dparameters)) {
        foreach ($part->dparameters as $param) {
            if (
                isset($param->attribute, $param->value) &&
                strtolower((string)$param->attribute) === 'charset'
            ) {
                return normalizeCharset((string)$param->value);
            }
        }
    }

    return null;
}

function convertToUtf8(string $text, ?string $charset = null): string
{
    if ($text === '') {
        return '';
    }

    $tryCharsets = [];

    $normalized = normalizeCharset($charset);
    if ($normalized !== null) {
        $tryCharsets[] = $normalized;
    }

    if (mb_check_encoding($text, 'UTF-8')) {
        return $text;
    }

    $tryCharsets = array_merge($tryCharsets, [
        'UTF-8',
        'Windows-1252',
        'ISO-8859-1',
        'ISO-8859-15',
        'ASCII',
    ]);

    $tryCharsets = array_values(array_unique($tryCharsets));

    foreach ($tryCharsets as $enc) {
        try {
            $converted = @mb_convert_encoding($text, 'UTF-8', $enc);
        } catch (\ValueError $e) {
            continue;
        }

        if ($converted !== false && mb_check_encoding($converted, 'UTF-8')) {
            return $converted;
        }
    }

    $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $text);
    if ($converted !== false && $converted !== '') {
        return $converted;
    }

    $converted = @iconv('ISO-8859-1', 'UTF-8//IGNORE', $text);
    if ($converted !== false && $converted !== '') {
        return $converted;
    }

    return preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $text) ?? $text;
}

function normalizeStoredValue(?string $value): ?string
{
    if ($value === null || $value === '') {
        return $value;
    }

    $value = convertToUtf8($value);
    $value = str_replace(["\xC2\xA0", "\xEF\xBB\xBF"], ' ', $value);
    $value = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $value) ?? '';
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    $value = preg_replace("/\r\n|\r/", "\n", $value) ?? '';
    $value = preg_replace('/[ \t]{2,}/', ' ', $value) ?? '';

    return trim($value);
}

function normalizeBodyText(string $text): string
{
    if ($text === '') {
        return '';
    }

    $text = convertToUtf8($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $text = str_replace(
        ["\xC2\xA0", "­", "\xEF\xBB\xBF"],
        ' ',
        $text
    );

    $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $text) ?? '';
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
    $text = preg_replace("/\r\n|\r/", "\n", $text) ?? '';
    $text = preg_replace('/[ \t]{2,}/', ' ', $text) ?? '';
    $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? '';

    return trim($text);
}

function normalizeHtml(string $html): string
{
    if ($html === '') {
        return '';
    }

    $html = convertToUtf8($html);
    $html = str_replace(["\xC2\xA0", "\xEF\xBB\xBF"], ' ', $html);
    $html = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $html) ?? '';
    $html = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $html) ?? '';
    $html = preg_replace("/\r\n|\r/", "\n", $html) ?? '';

    return trim($html);
}

function findTextPart($inbox, int $msgNo, $structure, string $wantedSubtype, string $partNumber = ''): string
{
    if (!isset($structure->parts) || !is_array($structure->parts) || count($structure->parts) === 0) {
        if (
            isset($structure->subtype, $structure->type) &&
            strtoupper((string)$structure->subtype) === $wantedSubtype &&
            (int)$structure->type === TYPETEXT
        ) {
            $body = imap_body($inbox, $msgNo, FT_PEEK);
            $decoded = decodePart((string)$body, (int)($structure->encoding ?? ENC7BIT));
            $charset = getPartCharset($structure);

            return trim(convertToUtf8($decoded, $charset));
        }

        return '';
    }

    foreach ($structure->parts as $index => $part) {
        $partNum = $partNumber === '' ? (string)($index + 1) : $partNumber . '.' . ($index + 1);

        if (isset($part->parts) && is_array($part->parts) && count($part->parts) > 0) {
            $sub = findTextPart($inbox, $msgNo, $part, $wantedSubtype, $partNum);
            if ($sub !== '') {
                return $sub;
            }
            continue;
        }

        if (isset($part->type, $part->subtype) && (int)$part->type === TYPETEXT) {
            if (strtoupper((string)$part->subtype) === $wantedSubtype) {
                $text = imap_fetchbody($inbox, $msgNo, $partNum, FT_PEEK);
                $decoded = decodePart((string)$text, (int)($part->encoding ?? ENC7BIT));
                $charset = getPartCharset($part);

                return trim(convertToUtf8($decoded, $charset));
            }
        }
    }

    return '';
}

function getBodyParts($inbox, int $msgNo): array
{
    $structure = imap_fetchstructure($inbox, $msgNo);

    if (!$structure) {
        $body = convertToUtf8(trim((string)imap_body($inbox, $msgNo, FT_PEEK)));

        return [
            'html' => normalizeHtml($body),
            'text' => normalizeBodyText(strip_tags($body)),
        ];
    }

    $html = findTextPart($inbox, $msgNo, $structure, 'HTML');
    $plain = findTextPart($inbox, $msgNo, $structure, 'PLAIN');

    if ($html === '' && $plain === '') {
        $body = convertToUtf8(trim((string)imap_body($inbox, $msgNo, FT_PEEK)));

        return [
            'html' => normalizeHtml($body),
            'text' => normalizeBodyText(strip_tags($body)),
        ];
    }

    if ($html === '') {
        $html = nl2br(htmlspecialchars($plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    $html = normalizeHtml($html);
    $text = $plain !== ''
        ? normalizeBodyText($plain)
        : normalizeBodyText(strip_tags($html));

    return [
        'html' => $html,
        'text' => $text,
    ];
}

function parseHeaderField(string $rawHeaders, string $name): ?string
{
    $pattern = '/^' . preg_quote($name, '/') . ':\s*(.+)$/im';

    if (preg_match($pattern, $rawHeaders, $m)) {
        return trim((string)normalizeStoredValue(trim($m[1], " \t\r\n<>")));
    }

    return null;
}

function extractHeaderInfo(string $rawHeaders): array
{
    $messageId = parseHeaderField($rawHeaders, 'Message-ID');
    $inReplyTo = parseHeaderField($rawHeaders, 'In-Reply-To');
    $returnPath = parseHeaderField($rawHeaders, 'Return-Path');

    $providerCampaignId =
        parseHeaderField($rawHeaders, 'X-Mailin-Campaign') ??
        parseHeaderField($rawHeaders, 'X-Mailin-MessageId') ??
        parseHeaderField($rawHeaders, 'X-Mailin-Tag') ??
        null;

    return [
        'message_id'           => $messageId,
        'in_reply_to'          => $inReplyTo,
        'return_path'          => $returnPath,
        'provider_campaign_id' => $providerCampaignId,
    ];
}

function isLikelyHuman(?string $fromEmail, string $rawHeaders): bool
{
    $fromEmail = strtolower(trim((string)$fromEmail));

    if ($fromEmail === '') {
        return false;
    }

    $blockedLocalParts = ['no-reply', 'noreply', 'mailer-daemon', 'postmaster'];
    [$localPart, $domain] = array_pad(explode('@', $fromEmail, 2), 2, '');

    foreach ($blockedLocalParts as $lp) {
        if (str_contains($localPart, $lp)) {
            return false;
        }
    }

    $blockedDomainFragments = [
        'brevo.',
        'sendinblue.',
        'hostinger.',
        'therolebridge.com',
        'mailer.',
        'talent.',
        'amazonses.com',
    ];

    foreach ($blockedDomainFragments as $frag) {
        if ($domain !== '' && str_contains($domain, $frag)) {
            return false;
        }
    }

    $raw = strtolower($rawHeaders);

    $autoSignals = [
        'auto-submitted: auto-',
        'auto-submitted: auto-replied',
        'precedence: bulk',
        'precedence: list',
        'precedence: junk',
        'x-auto-response-suppress: all',
        'x-autoreply:',
    ];

    foreach ($autoSignals as $sig) {
        if (str_contains($raw, strtolower($sig))) {
            return false;
        }
    }

    return true;
}

function getEspsWithImap(): array
{
    return pdoFetchAll(
        "
        SELECT *
        FROM esp
        WHERE imap_host IS NOT NULL
          AND imap_user IS NOT NULL
        "
    );
}

function inboundEmailExists(string $mailbox, string $msgUid): bool
{
    $existingId = pdoFetchValue(
        "
        SELECT id
        FROM inbound_emails
        WHERE mailbox = :mailbox
          AND msg_uid = :msg_uid
        LIMIT 1
        ",
        [
            ':mailbox' => $mailbox,
            ':msg_uid' => $msgUid,
        ]
    );

    return !empty($existingId);
}

function insertInboundEmail(array $data): int
{
    $subject = normalizeStoredValue((string)($data['subject'] ?? ''));
    $fromName = normalizeStoredValue(isset($data['from_name']) ? (string)$data['from_name'] : '');
    $fromEmail = normalizeStoredValue(isset($data['from_email']) ? (string)$data['from_email'] : '');
    $toEmail = normalizeStoredValue(isset($data['to_email']) ? (string)$data['to_email'] : '');
    $bodyHtml = normalizeHtml((string)($data['body_html'] ?? ''));
    $bodyText = normalizeBodyText((string)($data['body_text'] ?? ''));
    $messageId = normalizeStoredValue(isset($data['message_id']) ? (string)$data['message_id'] : '');
    $inReplyTo = normalizeStoredValue(isset($data['in_reply_to']) ? (string)$data['in_reply_to'] : '');
    $returnPath = normalizeStoredValue(isset($data['return_path']) ? (string)$data['return_path'] : '');
    $providerCampaignId = normalizeStoredValue(isset($data['provider_campaign_id']) ? (string)$data['provider_campaign_id'] : '');
    $rawHeaders = normalizeStoredValue((string)($data['raw_headers'] ?? ''));

    return pdoInsertGetId(
        "
        INSERT INTO inbound_emails (
            esp_id,
            mailbox,
            msg_uid,
            message_id,
            in_reply_to,
            msg_date,
            from_email,
            from_name,
            to_email,
            subject,
            body_html,
            body_text,
            return_path,
            provider_campaign_id,
            raw_headers
        ) VALUES (
            :esp_id,
            :mailbox,
            :msg_uid,
            :message_id,
            :in_reply_to,
            :msg_date,
            :from_email,
            :from_name,
            :to_email,
            :subject,
            :body_html,
            :body_text,
            :return_path,
            :provider_campaign_id,
            :raw_headers
        )
        ",
        [
            ':esp_id'               => $data['esp_id'],
            ':mailbox'              => $data['mailbox'],
            ':msg_uid'              => $data['msg_uid'],
            ':message_id'           => $messageId !== '' ? $messageId : null,
            ':in_reply_to'          => $inReplyTo !== '' ? $inReplyTo : null,
            ':msg_date'             => $data['msg_date'],
            ':from_email'           => $fromEmail !== '' ? $fromEmail : null,
            ':from_name'            => $fromName !== '' ? $fromName : null,
            ':to_email'             => $toEmail !== '' ? $toEmail : null,
            ':subject'              => $subject,
            ':body_html'            => $bodyHtml,
            ':body_text'            => $bodyText,
            ':return_path'          => $returnPath !== '' ? $returnPath : null,
            ':provider_campaign_id' => $providerCampaignId !== '' ? $providerCampaignId : null,
            ':raw_headers'          => $rawHeaders,
        ]
    );
}

/**
 * ============================
 * BUSCA TODOS OS ESPs COM IMAP
 * ============================
 */

$esps = getEspsWithImap();

if (empty($esps)) {
    echo "Nenhum ESP com IMAP configurado na tabela esp.\n";
    return;
}

/**
 * =========================
 * LOOP POR ESP / DOMÍNIO
 * =========================
 */

foreach ($esps as $esp) {
    $espId = (int)$esp['id'];
    $espName = (string)($esp['name'] ?? ('ESP #' . $espId));
    $domain = (string)($esp['domain'] ?? '');

    $imapHost = (string)($esp['imap_host'] ?: 'imap.hostinger.com');
    $imapPort = (int)($esp['imap_port'] ?: 993);
    $imapFlags = (string)($esp['imap_flags'] ?: '/imap/ssl');
    $imapUser = (string)($esp['imap_user'] ?? '');
    $imapPass = (string)($esp['imap_pass'] ?? '');

    if ($imapUser === '' || $imapPass === '') {
        echo "[ESP {$espId} - {$espName}] IMAP sem user/pass, pulando...\n";
        continue;
    }

    $imapMailbox = sprintf('{%s:%d%s}INBOX', $imapHost, $imapPort, $imapFlags);

    echo "[ESP {$espId} - {$espName}" . ($domain !== '' ? " ({$domain})" : "") . "] Conectando em {$imapMailbox} com {$imapUser}\n";

    $inbox = @imap_open($imapMailbox, $imapUser, $imapPass);

    if ($inbox === false) {
        echo "[ESP {$espId}] Erro ao conectar IMAP: " . imap_last_error() . "\n";
        continue;
    }

    $emails = imap_search($inbox, 'UNSEEN');

    if (!$emails) {
        echo "[ESP {$espId}] Nenhum e-mail novo.\n";
        imap_close($inbox);
        continue;
    }

    sort($emails);

    foreach ($emails as $msgNo) {
        $uid = imap_uid($inbox, $msgNo);

        if (!$uid) {
            imap_setflag_full($inbox, (string)$msgNo, "\\Seen");
            continue;
        }

        $overviewArr = imap_fetch_overview($inbox, (string)$msgNo, 0);
        if (!$overviewArr || !isset($overviewArr[0])) {
            imap_setflag_full($inbox, (string)$msgNo, "\\Seen");
            continue;
        }

        $overview = $overviewArr[0];

        $fromRaw = (string)($overview->from ?? '');
        $subjectRaw = (string)($overview->subject ?? '');
        $dateStr = (string)($overview->date ?? '');

        [$fromName, $fromEmail] = parseAddress($fromRaw);

        $fromName = $fromName !== '' ? $fromName : null;
        $fromEmail = $fromEmail !== '' ? $fromEmail : null;

        $subject = decodeHeader($subjectRaw);

        $msgDate = null;
        if ($dateStr !== '') {
            $ts = strtotime($dateStr);
            if ($ts !== false) {
                $msgDate = date('Y-m-d H:i:s', $ts);
            }
        }

        $rawHeaders = imap_fetchheader($inbox, $msgNo, FT_PREFETCHTEXT) ?: '';
        $bodyParts = getBodyParts($inbox, $msgNo);
        $hdrInfo = extractHeaderInfo($rawHeaders);

        // Trata somente replies humanos
        if (!isLikelyHuman($fromEmail, $rawHeaders)) {
            imap_setflag_full($inbox, (string)$msgNo, "\\Seen");
            continue;
        }

        if (inboundEmailExists($imapUser, (string)$uid)) {
            imap_setflag_full($inbox, (string)$msgNo, "\\Seen");
            continue;
        }

        insertInboundEmail([
            'esp_id'               => $espId,
            'mailbox'              => $imapUser,
            'msg_uid'              => (string)$uid,
            'message_id'           => $hdrInfo['message_id'],
            'in_reply_to'          => $hdrInfo['in_reply_to'],
            'msg_date'             => $msgDate,
            'from_email'           => $fromEmail,
            'from_name'            => $fromName,
            'to_email'             => $imapUser,
            'subject'              => $subject,
            'body_html'            => $bodyParts['html'],
            'body_text'            => $bodyParts['text'],
            'return_path'          => $hdrInfo['return_path'],
            'provider_campaign_id' => $hdrInfo['provider_campaign_id'],
            'raw_headers'          => $rawHeaders,
        ]);

        imap_setflag_full($inbox, (string)$msgNo, "\\Seen");
    }

    imap_close($inbox);

    echo "[ESP {$espId}] Importação concluída.\n";
}

echo "Processo finalizado para todos os ESPs com IMAP.\n";
