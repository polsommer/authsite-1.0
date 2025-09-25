<?php
declare(strict_types=1);

/**
 * Lightweight mailer with STARTTLS/SSL handling that works on Simply.com.
 * - Uses plain TCP + STARTTLS for 'tls'/'starttls' on port 587 (recommended).
 * - Uses implicit SSL for 'ssl' on port 465.
 * - Adds EHLO→HELO fallback.
 * - Optional debug logging to PHP error_log when SWG_SMTP_DEBUG=1 (or "true").
 *
 * Public API unchanged:
 *   sendEmail(array $config, string $toEmail, string $subject, string $body, ?string $toName = null): bool
 */

//////////////////////////////////////////////////////
// Debug helpers (no API changes to your code paths)
//////////////////////////////////////////////////////

/** Enable by setting SWG_SMTP_DEBUG=1 (or "true") in your env */
function mailer_debug_enabled(): bool {
    $v = getenv('SWG_SMTP_DEBUG');
    return $v === '1' || strtolower((string)$v) === 'true';
}

function mailer_debug(string $msg): void {
    if (mailer_debug_enabled()) {
        error_log('[MAILER] ' . $msg);
    }
}

/** Last error message captured inside this file (for log context) */
function mailer_set_last_error(string $msg): void {
    $GLOBALS['MAILER_LAST_ERROR'] = $msg;
    mailer_debug($msg);
}
function mailer_get_last_error(): ?string {
    return $GLOBALS['MAILER_LAST_ERROR'] ?? null;
}

//////////////////////////////////////////////////////
// Public entry
//////////////////////////////////////////////////////

/**
 * Send an email using either the configured SMTP relay or PHP's native mail() as a fallback.
 */
function sendEmail(array $config, string $toEmail, string $subject, string $body, ?string $toName = null): bool
{
    $fromEmail = trim((string) ($config['mail_from'] ?? ''));
    $fromName = trim((string) ($config['mail_from_name'] ?? ($config['site_name'] ?? '')));

    if ($fromEmail === '' || !filter_var(extractEmailAddress($fromEmail), FILTER_VALIDATE_EMAIL)) {
        mailer_set_last_error('Invalid "from" address.');
        return false;
    }

    $smtpConfig = $config['smtp'] ?? [];

    if (!empty($smtpConfig['host']) && !empty($smtpConfig['username']) && !empty($smtpConfig['password'])) {
        $ok = sendEmailViaSmtp($smtpConfig, $fromEmail, $fromName, $toEmail, $subject, $body, $toName);
        if (!$ok) {
            $le = mailer_get_last_error();
            if ($le) mailer_debug('SMTP failed: ' . $le);
        }
        return $ok;
    }

    $ok = sendEmailWithMailFunction($fromEmail, $fromName, $toEmail, $subject, $body, $toName);
    if (!$ok) {
        mailer_set_last_error('PHP mail() returned false.');
    }
    return $ok;
}

function sendEmailWithMailFunction(string $fromEmail, string $fromName, string $toEmail, string $subject, string $body, ?string $toName = null): bool
{
    $fromHeader = formatAddress($fromName, $fromEmail);
    $toHeader   = formatAddress($toName, $toEmail);

    $headers = [
        'From: ' . $fromHeader,
        'Reply-To: ' . $fromHeader,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];

    $normalizedBody   = normalizeEmailBody($body);
    $sanitizedSubject = sanitizeHeaderValue($subject);

    $ok = @mail($toEmail, $sanitizedSubject, $normalizedBody, implode("\r\n", $headers));
    if (!$ok) {
        mailer_set_last_error('mail() transport failed.');
    }
    return $ok;
}

function sendEmailViaSmtp(array $smtpConfig, string $fromEmail, string $fromName, string $toEmail, string $subject, string $body, ?string $toName = null): bool
{
    $host       = trim((string) ($smtpConfig['host'] ?? ''));
    $port       = (int) ($smtpConfig['port'] ?? 587);
    $username   = trim((string) ($smtpConfig['username'] ?? ''));
    $password   = (string) ($smtpConfig['password'] ?? '');
    $encryption = strtolower(trim((string) ($smtpConfig['encryption'] ?? 'starttls')));
    $timeout    = max(5, (int) ($smtpConfig['timeout'] ?? 30));

    if ($host === '' || $port <= 0 || $username === '' || $password === '') {
        mailer_set_last_error('Missing SMTP settings (host/port/username/password).');
        return false;
    }

    // Normalize encryption labels
    // 'tls' is commonly used in configs to mean STARTTLS on 587
    if ($encryption === 'tls') {
        $encryption = 'starttls';
    }
    if (!in_array($encryption, ['starttls', 'ssl', 'none'], true)) {
        $encryption = 'starttls';
    }

    $clientName = trim((string) ($smtpConfig['client_name'] ?? ''));
    if ($clientName === '') {
        $clientName = deriveDefaultClientName($fromEmail);
    }

    // Transport:
    // - STARTTLS must start as clear TCP and then upgrade
    // - Implicit SSL uses ssl:// at connect time
    $transport = 'tcp://';
    if ($encryption === 'ssl') {
        $transport = 'ssl://';
    }

    $context = stream_context_create([
        'ssl' => [
            'verify_peer'       => true,
            'verify_peer_name'  => true,
            'allow_self_signed' => false,
            // Sane peer name matching
            'SNI_enabled'       => true,
            'peer_name'         => $host,
        ],
    ]);

    $socket = @stream_socket_client(
        $transport . $host . ':' . $port,
        $errorNumber,
        $errorMessage,
        $timeout,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!is_resource($socket)) {
        mailer_set_last_error(sprintf('Socket connect failed: [%s] %s', (string)$errorNumber, (string)$errorMessage));
        return false;
    }

    stream_set_timeout($socket, $timeout);

    $cleanup = function () use ($socket): void {
        if (is_resource($socket)) {
            @fwrite($socket, "QUIT\r\n");
            @smtpReadResponse($socket);
            fclose($socket);
        }
    };

    try {
        if (!smtpExpectCodes($socket, [220], 'banner')) {
            $cleanup();
            return false;
        }

        // EHLO with HELO fallback
        if (!smtpCommand($socket, 'EHLO ' . $clientName, [250], 'ehlo-1')) {
            if (!smtpCommand($socket, 'HELO ' . $clientName, [250], 'helo-1')) {
                $cleanup();
                return false;
            }
        }

        // STARTTLS upgrade when requested
        if ($encryption === 'starttls') {
            if (!smtpCommand($socket, 'STARTTLS', [220], 'starttls')) {
                $cleanup();
                return false;
            }

            // Prefer TLS 1.2/1.3 if available
            $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                    $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                }
            }

            if (!@stream_socket_enable_crypto($socket, true, $cryptoMethod)) {
                mailer_set_last_error('TLS negotiation failed after STARTTLS.');
                $cleanup();
                return false;
            }

            // Re-greet after TLS; again with fallback
            if (!smtpCommand($socket, 'EHLO ' . $clientName, [250], 'ehlo-2')) {
                if (!smtpCommand($socket, 'HELO ' . $clientName, [250], 'helo-2')) {
                    $cleanup();
                    return false;
                }
            }
        }

        // AUTH LOGIN sequence
        if (!smtpCommand($socket, 'AUTH LOGIN', [334], 'auth-login')) {
            $cleanup();
            return false;
        }
        if (!smtpCommand($socket, base64_encode($username), [334], 'auth-user')) {
            $cleanup();
            return false;
        }
        if (!smtpCommand($socket, base64_encode($password), [235], 'auth-pass')) {
            $cleanup();
            return false;
        }

        $fromAddress = extractEmailAddress($fromEmail);
        $toAddress   = extractEmailAddress($toEmail);

        if (!smtpCommand($socket, 'MAIL FROM:<' . $fromAddress . '>', [250], 'mail-from')) {
            $cleanup();
            return false;
        }

        if (!smtpCommand($socket, 'RCPT TO:<' . $toAddress . '>', [250, 251], 'rcpt-to')) {
            $cleanup();
            return false;
        }

        if (!smtpCommand($socket, 'DATA', [354], 'data-cmd')) {
            $cleanup();
            return false;
        }

        $message = buildSmtpMessage($fromEmail, $fromName, $toEmail, $toName, $subject, $body, $clientName);

        $writeResult = @fwrite($socket, $message . "\r\n.\r\n");
        if ($writeResult === false) {
            mailer_set_last_error('Failed to write message body.');
            $cleanup();
            return false;
        }

        if (!smtpExpectCodes($socket, [250], 'data-commit')) {
            $cleanup();
            return false;
        }

        smtpCommand($socket, 'QUIT', [221], 'quit');
        fclose($socket);

        return true;
    } catch (Throwable $e) {
        mailer_set_last_error('Exception: ' . $e->getMessage());
        $cleanup();
        return false;
    }
}

function deriveDefaultClientName(string $fromEmail): string
{
    $address = extractEmailAddress($fromEmail);
    if (strpos($address, '@') !== false) {
        return substr($address, strpos($address, '@') + 1);
    }
    return 'localhost';
}

function buildSmtpMessage(string $fromEmail, string $fromName, string $toEmail, ?string $toName, string $subject, string $body, string $clientName): string
{
    $fromHeader = formatAddress($fromName, $fromEmail);
    $toHeader   = formatAddress($toName, $toEmail);

    $sanitizedSubject = sanitizeHeaderValue($subject);
    if (function_exists('mb_encode_mimeheader')) {
        $encoded = @mb_encode_mimeheader($sanitizedSubject, 'UTF-8', 'B', "\r\n");
        if ($encoded !== false) {
            $sanitizedSubject = $encoded;
        }
    }

    $messageIdDomain = $clientName !== '' ? $clientName : deriveDefaultClientName($fromEmail);
    $messageId = sprintf('<%s@%s>', bin2hex(random_bytes(16)), $messageIdDomain);

    $headers = [
        'From: ' . $fromHeader,
        'To: ' . $toHeader,
        'Subject: ' . $sanitizedSubject,
        'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
        'Message-ID: ' . $messageId,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];

    $normalizedBody = normalizeEmailBody($body);
    // Escape leading dots, per RFC 5321 .-stuffing
    $escapedBody = preg_replace('/(^|\r\n)\./', '$1..', $normalizedBody);

    return implode("\r\n", $headers) . "\r\n\r\n" . $escapedBody;
}

function normalizeEmailBody(string $body): string
{
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $body = preg_replace('/\n/u', "\r\n", $body) ?? '';
    return $body;
}

function sanitizeHeaderValue(string $value): string
{
    $value = str_replace(["\r", "\n"], ' ', $value);
    return trim($value);
}

function formatAddress(?string $name, string $email): string
{
    $emailAddress  = extractEmailAddress($email);
    $sanitizedName = $name !== null ? trim(str_replace(['"', "\r", "\n"], '', $name)) : '';

    if ($sanitizedName === '') {
        return $emailAddress;
    }

    if (function_exists('mb_encode_mimeheader')) {
        $encoded = @mb_encode_mimeheader($sanitizedName, 'UTF-8', 'B', "\r\n");
        if ($encoded !== false) {
            $sanitizedName = $encoded;
        }
    }

    return sprintf('"%s" <%s>', $sanitizedName, $emailAddress);
}

function extractEmailAddress(string $value): string
{
    if (preg_match('/<([^>]+)>/', $value, $matches)) {
        $value = $matches[1];
    }
    return trim($value);
}

function smtpCommand($socket, string $command, array $expectedCodes, string $tag = ''): bool
{
    if (@fwrite($socket, $command . "\r\n") === false) {
        mailer_set_last_error("Write failed for command [$tag]: $command");
        return false;
    }
    return smtpExpectCodes($socket, $expectedCodes, $tag);
}

function smtpExpectCodes($socket, array $expectedCodes, string $tag = ''): bool
{
    $response = smtpReadResponse($socket);
    if ($response === '') {
        mailer_set_last_error("Empty response after [$tag].");
        return false;
    }

    $code = (int) substr($response, 0, 3);
    $ok   = in_array($code, $expectedCodes, true);

    mailer_debug(sprintf(
        'SMTP[%s]: got code %d, expected %s. Full response: %s',
        $tag,
        $code,
        implode('/', $expectedCodes),
        rtrim($response)
    ));

    if (!$ok) {
        mailer_set_last_error(sprintf('Unexpected SMTP code after [%s]: %d. Response: %s', $tag, $code, rtrim($response)));
    }

    return $ok;
}

function smtpReadResponse($socket): string
{
    $response = '';
    while (true) {
        $line = @fgets($socket, 515);
        if ($line === false) {
            break;
        }
        $response .= $line;
        if (strlen($line) < 4) {
            break;
        }
        if ($line[3] === ' ') {
            break;
        }
    }
    return $response;
}
