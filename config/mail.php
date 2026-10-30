<?php
// config/mail.php — sends the password-reset code by email (SMTP, no Composer).
//
// Defaults are Gmail: smtp.gmail.com:587 STARTTLS. Put your own credentials in
// config/mail.local.php (never commit it) or set env vars:
//   MAIL_HOST, MAIL_PORT, MAIL_SECURE (tls|ssl|none), MAIL_USER, MAIL_PASS, MAIL_FROM_NAME
// Gmail needs a 16-character App Password (Google Account → Security →
// 2-Step Verification → App passwords), not the normal account password.
//
// If nothing is configured (or the send fails) the message is written to
// storage/mail-outbox.log so the flow can still be tested on a local PC.

function mail_config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $cfg = [
        'host'      => getenv('MAIL_HOST') ?: 'smtp.gmail.com',
        'port'      => (int)(getenv('MAIL_PORT') ?: 587),
        'secure'    => strtolower(getenv('MAIL_SECURE') ?: 'tls'), // tls | ssl | none
        'user'      => (string)(getenv('MAIL_USER') ?: ''),
        'pass'      => (string)(getenv('MAIL_PASS') ?: ''),
        'from_name' => (string)(getenv('MAIL_FROM_NAME') ?: 'Sufian Store GMS'),
    ];
    $local = __DIR__ . '/mail.local.php';
    if (is_file($local)) {
        $override = @include $local;
        if (is_array($override)) $cfg = array_merge($cfg, $override);
    }
    return $cfg;
}

function mail_is_configured(): bool {
    $cfg = mail_config();
    return $cfg['user'] !== '' && $cfg['pass'] !== '';
}

function mail_outbox_path(): string {
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir . DIRECTORY_SEPARATOR . 'mail-outbox.log';
}

// Local fallback: keep the message on disk instead of silently losing it.
function mail_write_outbox(string $to, string $subject, string $body, string $reason): void {
    $line = '==== ' . date('Y-m-d H:i:s') . ' | to: ' . $to . ' | ' . $reason . " ====\n"
        . 'Subject: ' . $subject . "\n\n" . $body . "\n\n";
    @file_put_contents(mail_outbox_path(), $line, FILE_APPEND | LOCK_EX);
}

// One SMTP reply (handles multi-line "250-..." answers).
function smtp_read($fp): array {
    $raw = '';
    while (($line = fgets($fp, 1024)) !== false) {
        $raw .= $line;
        if (strlen($line) >= 4 && ($line[3] === ' ' || $line[3] === "\n")) break;
    }
    return [(int)substr($raw, 0, 3), trim($raw)];
}

function smtp_command($fp, string $command): array {
    fwrite($fp, $command . "\r\n");
    return smtp_read($fp);
}

function smtp_send(array $cfg, string $to, string $subject, string $body): bool {
    $host = $cfg['host'];
    $port = (int)$cfg['port'];
    $secure = $cfg['secure'];
    $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;

    $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);
    if (!$fp) {
        error_log('[mail] connect to ' . $remote . ' failed: ' . $errstr);
        return false;
    }
    stream_set_timeout($fp, 20);

    $abort = function (string $why) use ($fp) {
        error_log('[mail] ' . $why);
        @fclose($fp);
        return false;
    };

    [$code] = smtp_read($fp);
    if ($code !== 220) return $abort('bad SMTP greeting (' . $code . ')');

    [$code] = smtp_command($fp, 'EHLO localhost');
    if ($code !== 250) return $abort('EHLO rejected (' . $code . ')');

    if ($secure === 'tls') {
        [$code] = smtp_command($fp, 'STARTTLS');
        if ($code !== 220) return $abort('STARTTLS rejected (' . $code . ')');
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            return $abort('TLS handshake failed');
        }
        [$code] = smtp_command($fp, 'EHLO localhost');
        if ($code !== 250) return $abort('EHLO after TLS rejected (' . $code . ')');
    }

    [$code] = smtp_command($fp, 'AUTH LOGIN');
    if ($code !== 334) return $abort('AUTH LOGIN rejected (' . $code . ')');
    [$code] = smtp_command($fp, base64_encode($cfg['user']));
    if ($code !== 334) return $abort('SMTP username rejected (' . $code . ')');
    [$code] = smtp_command($fp, base64_encode($cfg['pass']));
    if ($code !== 235) return $abort('SMTP authentication failed (' . $code . ')');

    [$code] = smtp_command($fp, 'MAIL FROM:<' . $cfg['user'] . '>');
    if ($code !== 250) return $abort('MAIL FROM rejected (' . $code . ')');
    [$code] = smtp_command($fp, 'RCPT TO:<' . $to . '>');
    if ($code !== 250 && $code !== 251) return $abort('RCPT TO rejected (' . $code . ')');
    [$code] = smtp_command($fp, 'DATA');
    if ($code !== 354) return $abort('DATA rejected (' . $code . ')');

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encodedName    = '=?UTF-8?B?' . base64_encode($cfg['from_name']) . '?=';
    $headers = [
        'From: ' . $encodedName . ' <' . $cfg['user'] . '>',
        'To: <' . $to . '>',
        'Subject: ' . $encodedSubject,
        'Date: ' . date('r'),
        'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . ($host ?: 'localhost') . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
    ];
    $payload = implode("\r\n", $headers) . "\r\n\r\n"
        . chunk_split(base64_encode($body), 76, "\r\n")
        . ".\r\n";
    fwrite($fp, $payload);

    [$code] = smtp_read($fp);
    if ($code !== 250) return $abort('message rejected (' . $code . ')');

    smtp_command($fp, 'QUIT');
    @fclose($fp);
    return true;
}

/* Send one plain-text email. Returns true only when the server accepted it;
   otherwise the message is written to storage/mail-outbox.log. */
function send_mail(string $to, string $subject, string $body): bool {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log('[mail] invalid recipient: ' . $to);
        return false;
    }
    if (!mail_is_configured()) {
        mail_write_outbox($to, $subject, $body, 'SMTP not configured');
        return false;
    }
    if (smtp_send(mail_config(), $to, $subject, $body)) return true;
    mail_write_outbox($to, $subject, $body, 'SMTP send failed');
    return false;
}
