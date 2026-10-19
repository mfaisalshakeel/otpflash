<?php
/**
 * Sending mail.
 *
 * There is no Composer here and no guarantee of a working sendmail binary on
 * a shared host, so the SMTP conversation is written out by hand. It is a
 * small protocol and the alternative is a dependency we cannot install.
 *
 * Every send is recorded in `email_log`, success or failure, because mail
 * that silently does not arrive is the hardest kind of fault to be told
 * about: the admin asks "did it send?" and only the log can answer.
 */

/** Settings that describe the mailer, read in one place. */
function mail_settings(): array
{
    return [
        'driver'    => (string) setting('mail_driver', 'off'),      // off | mail | smtp
        'host'      => (string) setting('smtp_host', ''),
        'port'      => (int) setting('smtp_port', 587),
        'user'      => (string) setting('smtp_user', ''),
        'pass'      => (string) setting('smtp_pass', ''),
        'secure'    => (string) setting('smtp_secure', 'tls'),      // none | ssl | tls
        'from'      => (string) setting('mail_from', ''),
        'from_name' => (string) setting('mail_from_name', (string) setting('site_name', 'OTP Flash')),
    ];
}

/** Is the mailer configured enough to try a send? */
function mail_ready(): bool
{
    $config = mail_settings();
    if ($config['driver'] === 'off' || $config['from'] === '') {
        return false;
    }
    return $config['driver'] !== 'smtp' || $config['host'] !== '';
}

/**
 * Send one message.
 *
 * @param  string $to       a single address
 * @param  string $html     the body; a plain-text part is derived from it
 * @return array            ['ok' => bool, 'error' => string]
 */
function send_mail(string $to, string $subject, string $html, array $options = []): array
{
    $config = mail_settings();
    $event  = (string) ($options['event'] ?? '');

    $record = static function (bool $ok, string $error) use ($to, $subject, $event): array {
        // A log row is written even when the mailer is switched off, so
        // "nothing happened" is still visible rather than being silence.
        insert_row('email_log', [
            'event'      => $event,
            'recipient'  => mb_substr($to, 0, 190),
            'subject'    => mb_substr($subject, 0, 190),
            'status'     => $ok ? 'sent' : 'failed',
            'error'      => mb_substr($error, 0, 500),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => $ok, 'error' => $error];
    };

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return $record(false, 'Not a valid address: ' . $to);
    }
    if ($config['driver'] === 'off') {
        return $record(false, 'Mail is switched off in Settings.');
    }
    if ($config['from'] === '') {
        return $record(false, 'No "from" address is set in Settings.');
    }

    $message = mail_build($config, $to, $subject, $html);

    try {
        if ($config['driver'] === 'smtp') {
            smtp_send($config, $to, $message['headers'], $message['body']);
        } else {
            $headerLines = [];
            foreach ($message['headers'] as $name => $value) {
                if ($name !== 'To' && $name !== 'Subject') {
                    $headerLines[] = $name . ': ' . $value;
                }
            }
            $sent = @mail($to, $message['encoded_subject'], $message['body'],
                implode("\r\n", $headerLines));
            if (!$sent) {
                throw new RuntimeException('PHP mail() refused the message. '
                    . 'Most shared hosts need SMTP instead.');
            }
        }
    } catch (Throwable $e) {
        return $record(false, $e->getMessage());
    }

    return $record(true, '');
}

/**
 * Headers and body for one message.
 *
 * Multipart, because a mail client that will not render HTML should still
 * show the words rather than an empty window.
 */
function mail_build(array $config, string $to, string $subject, string $html): array
{
    $boundary = 'b' . bin2hex(random_bytes(12));
    $plain    = mail_plain_text($html);

    $headers = [
        'Date'         => date('r'),
        'From'         => mail_address($config['from'], $config['from_name']),
        'To'           => $to,
        'Subject'      => mail_encode_header($subject),
        'Message-ID'   => '<' . bin2hex(random_bytes(10)) . '@' . mail_hostname($config) . '>',
        'MIME-Version' => '1.0',
        'Content-Type' => 'multipart/alternative; boundary="' . $boundary . '"',
    ];
    if (filter_var((string) setting('support_email', ''), FILTER_VALIDATE_EMAIL)) {
        $headers['Reply-To'] = (string) setting('support_email');
    }

    $body = "--$boundary\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
        . quoted_printable_encode($plain) . "\r\n"
        . "--$boundary\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
        . quoted_printable_encode($html) . "\r\n"
        . "--$boundary--\r\n";

    return ['headers' => $headers, 'body' => $body,
            'encoded_subject' => $headers['Subject']];
}

/** "Name <address>", with the name encoded only when it needs to be. */
function mail_address(string $address, string $name = ''): string
{
    $name = trim($name);
    return $name === '' ? $address : mail_encode_header($name) . ' <' . $address . '>';
}

/**
 * RFC 2047 for anything outside ASCII.
 *
 * A raw UTF-8 subject reaches some clients as mojibake and some spam filters
 * as a reason to score the message up, and the encoding costs nothing when
 * the text is plain ASCII because it is skipped entirely.
 */
function mail_encode_header(string $text): string
{
    $text = preg_replace('/[\r\n]+/', ' ', $text);
    if (preg_match('/^[\x20-\x7E]*$/', $text)) {
        return $text;
    }
    return '=?UTF-8?B?' . base64_encode($text) . '?=';
}

/** A readable plain-text version of an HTML body. */
function mail_plain_text(string $html): string
{
    $text = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', $html);
    // A link is worth keeping as "words (url)" - a bare anchor loses the
    // destination, which on an order mail is the whole point of the message.
    $text = preg_replace_callback(
        '~<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>~is',
        static function ($m) {
            $label = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES, 'UTF-8'));
            return $label === '' || $label === $m[1] ? $m[1] : $label . ' (' . $m[1] . ')';
        },
        $text
    );
    $text = preg_replace('~<br\s*/?>~i', "\n", $text);
    $text = preg_replace('~</(p|div|tr|h[1-6]|li)>~i', "\n", $text);
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
    $text = preg_replace("/[ \t]+/", ' ', $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text);

    return trim($text);
}

/** The host we claim to be in EHLO and in a Message-ID. */
function mail_hostname(array $config): string
{
    $host = parse_url((string) cfg('base_url', ''), PHP_URL_HOST);
    if (!$host) {
        $host = $_SERVER['SERVER_NAME'] ?? '';
    }
    if (!$host && str_contains($config['from'], '@')) {
        $host = substr(strrchr($config['from'], '@'), 1);
    }
    return $host ?: 'localhost';
}

// =========================================================== SMTP client ====

/**
 * One SMTP conversation, start to finish.
 *
 * Throws rather than returning false: every failure here has something
 * specific to say and the caller stores the words, so an admin reading the
 * log sees "535 authentication failed" instead of "could not send".
 */
function smtp_send(array $config, string $to, array $headers, string $body): void
{
    $secure  = $config['secure'];
    $host    = $config['host'];
    $port    = $config['port'] ?: ($secure === 'ssl' ? 465 : 587);
    $address = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;

    $context = stream_context_create(['ssl' => [
        'verify_peer'       => true,
        'verify_peer_name'  => true,
        'SNI_enabled'       => true,
    ]]);

    $socket = @stream_socket_client($address, $errorNumber, $errorText, 20,
        STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        throw new RuntimeException('Could not reach ' . $host . ':' . $port
            . ' - ' . ($errorText ?: 'no answer') . '.');
    }
    stream_set_timeout($socket, 20);

    try {
        smtp_expect($socket, 220);
        $capabilities = smtp_hello($socket, mail_hostname($config));

        if ($secure === 'tls') {
            smtp_command($socket, 'STARTTLS', 220);
            $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (!@stream_socket_enable_crypto($socket, true, $crypto)) {
                throw new RuntimeException('The server would not start TLS. '
                    . 'Try "SSL" on port 465, or "None" if the server has no encryption.');
            }
            // Everything the server said before the handshake is untrusted,
            // so ask again now that the channel is protected.
            $capabilities = smtp_hello($socket, mail_hostname($config));
        }

        if ($config['user'] !== '') {
            smtp_authenticate($socket, $config, $capabilities);
        }

        smtp_command($socket, 'MAIL FROM:<' . $config['from'] . '>', 250);
        smtp_command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
        smtp_command($socket, 'DATA', 354);

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $data = implode("\r\n", $lines) . "\r\n\r\n" . $body;
        // A lone dot on its own line ends the message, so any real one in the
        // body has to be doubled or the mail is truncated there.
        $data = preg_replace('/^\./m', '..', str_replace("\n", "\r\n",
            str_replace("\r\n", "\n", $data)));

        fwrite($socket, $data . "\r\n.\r\n");
        smtp_expect($socket, 250);
        smtp_command($socket, 'QUIT', [221, 250]);
    } finally {
        @fclose($socket);
    }
}

/** EHLO, falling back to HELO for a server too old to know it. */
function smtp_hello($socket, string $hostname): string
{
    try {
        return smtp_command($socket, 'EHLO ' . $hostname, 250);
    } catch (RuntimeException $e) {
        return smtp_command($socket, 'HELO ' . $hostname, 250);
    }
}

/**
 * AUTH, picking a mechanism the server actually offered.
 *
 * LOGIN before PLAIN because more of the hosts this runs against advertise
 * it; CRAM-MD5 is not attempted, since every server that supports it also
 * supports one of these two over a channel that is already encrypted.
 */
function smtp_authenticate($socket, array $config, string $capabilities): void
{
    $offered = strtoupper($capabilities);

    if (str_contains($offered, 'LOGIN')) {
        smtp_command($socket, 'AUTH LOGIN', 334);
        smtp_command($socket, base64_encode($config['user']), 334);
        smtp_command($socket, base64_encode($config['pass']), 235);
        return;
    }
    if (str_contains($offered, 'PLAIN')) {
        smtp_command($socket, 'AUTH PLAIN ' . base64_encode(
            "\0" . $config['user'] . "\0" . $config['pass']), 235);
        return;
    }

    throw new RuntimeException('The server offers no password mechanism we can use'
        . ' (it advertised: ' . trim(preg_replace('/\s+/', ' ', $capabilities)) . ').');
}

/** Send one line and check the reply code. */
function smtp_command($socket, string $line, $expected): string
{
    fwrite($socket, $line . "\r\n");
    return smtp_expect($socket, $expected, $line);
}

/**
 * Read a reply and insist on a code.
 *
 * The server's own words are carried into the exception, because "550 5.7.1
 * relay denied" tells the admin what to change and "send failed" does not.
 */
function smtp_expect($socket, $expected, string $sent = ''): string
{
    $reply = '';
    while (!feof($socket)) {
        $line = fgets($socket, 1024);
        if ($line === false) {
            break;
        }
        $reply .= $line;
        // A continuation line is "250-TEXT"; the last one is "250 TEXT".
        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }

    if ($reply === '') {
        $info = stream_get_meta_data($socket);
        throw new RuntimeException(!empty($info['timed_out'])
            ? 'The server stopped answering (timed out).'
            : 'The server closed the connection.');
    }

    $code = (int) substr($reply, 0, 3);
    foreach ((array) $expected as $wanted) {
        if ($code === (int) $wanted) {
            return $reply;
        }
    }

    // Never echo the AUTH line back: it carries the password.
    $context = $sent !== '' && !str_starts_with($sent, 'AUTH')
        && strlen($sent) < 60 && !preg_match('~^[A-Za-z0-9+/=]{16,}$~', $sent)
        ? ' after ' . $sent : '';

    throw new RuntimeException('SMTP' . $context . ': ' . trim($reply));
}
