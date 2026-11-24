<?php
declare(strict_types=1);

/**
 * Minimal dependency-free mail delivery.
 *  - SMTP_HOST empty  -> the message is appended to data/logs/mail.log (self-hosted / dev mode).
 *  - SMTP_HOST set    -> sent over SMTP (STARTTLS on 587, implicit TLS on 465, or plain), AUTH LOGIN if SMTP_USER set.
 * Every message is also logged (without the body when delivered) so admins can trace resets.
 */

function smtp_configured(): bool
{
    return config('SMTP_HOST') !== null;
}

/** @return bool true if handed to an SMTP server, false if only logged */
function send_mail(string $to, string $subject, string $body): bool
{
    if (!smtp_configured()) {
        app_log('info', "To: $to\nSubject: $subject\n\n$body\n" . str_repeat('-', 60), 'mail.log');
        return false;
    }
    try {
        smtp_send($to, $subject, $body);
        app_log('info', "Delivered via SMTP to $to: $subject", 'mail.log');
        return true;
    } catch (Throwable $e) {
        app_log('error', 'SMTP delivery failed: ' . $e->getMessage());
        app_log('info', "SMTP FAILED (" . $e->getMessage() . ")\nTo: $to\nSubject: $subject\n\n$body\n" . str_repeat('-', 60), 'mail.log');
        return false;
    }
}

function mime_header(string $text): string
{
    return preg_match('/[^\x20-\x7E]/', $text) ? '=?UTF-8?B?' . base64_encode($text) . '?=' : $text;
}

function build_mail_message(string $to, string $subject, string $body): string
{
    $from = (string)config('MAIL_FROM', 'no-reply@buildtrack.local');
    $fromName = (string)config('MAIL_FROM_NAME', 'BuildTrack');
    $domain = substr(strrchr($from, '@') ?: '@buildtrack.local', 1);
    $headers = [
        'Date: ' . date(DATE_RFC2822),
        'From: ' . mime_header($fromName) . " <$from>",
        "To: <$to>",
        'Subject: ' . mime_header($subject),
        'Message-ID: <' . bin2hex(random_bytes(12)) . "@$domain>",
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: quoted-printable',
    ];
    // quoted_printable_encode() only treats CRLF as a line break, so normalise to CRLF first.
    $crlf = str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $body));
    $encoded = quoted_printable_encode($crlf);
    // Dot-stuffing: lines starting with "." must be doubled inside DATA.
    $encoded = preg_replace('/^\./m', '..', $encoded);
    return implode("\r\n", $headers) . "\r\n\r\n" . $encoded;
}

function smtp_send(string $to, string $subject, string $body): void
{
    if (preg_match('/[\r\n<>]/', $to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Invalid recipient address.');
    }
    $host = (string)config('SMTP_HOST');
    $port = config_int('SMTP_PORT', 587);
    $enc = strtolower((string)config('SMTP_ENCRYPTION', 'tls'));
    $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;

    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
    $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        throw new RuntimeException("Cannot connect to $remote: $errstr ($errno)");
    }
    stream_set_timeout($fp, 15);

    $read = static function () use ($fp): array {
        $data = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return [(int)substr($data, 0, 3), trim($data)];
    };
    $cmd = static function (string $line, array $expect) use ($fp, $read): string {
        fwrite($fp, $line . "\r\n");
        [$code, $text] = $read();
        if (!in_array($code, $expect, true)) {
            $shown = str_starts_with($line, 'AUTH') || strlen($line) > 60 ? strtok($line, ' ') . ' …' : $line;
            throw new RuntimeException("SMTP: '$shown' answered $text");
        }
        return $text;
    };

    try {
        [$code, $greet] = $read();
        if ($code !== 220) {
            throw new RuntimeException("SMTP greeting: $greet");
        }
        $ehloHost = gethostname() ?: 'localhost';
        $cmd("EHLO $ehloHost", [250]);
        if ($enc === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new RuntimeException('STARTTLS negotiation failed.');
            }
            $cmd("EHLO $ehloHost", [250]);
        }
        if (($user = config('SMTP_USER')) !== null) {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode($user), [334]);
            $cmd(base64_encode((string)config('SMTP_PASS', '')), [235]);
        }
        $cmd('MAIL FROM:<' . config('MAIL_FROM', 'no-reply@buildtrack.local') . '>', [250]);
        $cmd("RCPT TO:<$to>", [250, 251]);
        $cmd('DATA', [354]);
        $cmd(build_mail_message($to, $subject, $body) . "\r\n.", [250]);
        $cmd('QUIT', [221]);
    } finally {
        fclose($fp);
    }
}
