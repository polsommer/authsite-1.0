<?php
declare(strict_types=1);

/**
 * Send an email using either the configured SMTP relay or PHP's native mail() as a fallback.
 */
function sendEmail(array $config, string $toEmail, string $subject, string $body, ?string $toName = null): bool
{
    $fromEmail = trim((string) ($config['mail_from'] ?? ''));
    $fromName = trim((string) ($config['mail_from_name'] ?? ($config['site_name'] ?? '')));

    if ($fromEmail === '' || !filter_var(extractEmailAddress($fromEmail), FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $smtpConfig = $config['smtp'] ?? [];

    if (!empty($smtpConfig['host']) && !empty($smtpConfig['username']) && !empty($smtpConfig['password'])) {
        return sendEmailViaSmtp($smtpConfig, $fromEmail, $fromName, $toEmail, $subject, $body, $toName);
    }

    return sendEmailWithMailFunction($fromEmail, $fromName, $toEmail, $subject, $body, $toName);
}

function sendEmailWithMailFunction(string $fromEmail, string $fromName, string $toEmail, string $subject, string $body, ?string $toName = null): bool
{
    $fromHeader = formatAddress($fromName, $fromEmail);
    $toHeader = formatAddress($toName, $toEmail);

    $headers = [
        'From: ' . $fromHeader,
        'Reply-To: ' . $fromHeader,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];

    $normalizedBody = normalizeEmailBody($body);
    $sanitizedSubject = sanitizeHeaderValue($subject);

    return mail($toEmail, $sanitizedSubject, $normalizedBody, implode("\r\n", $headers));
}

function sendEmailViaSmtp(array $smtpConfig, string $fromEmail, string $fromName, string $toEmail, string $subject, string $body, ?string $toName = null): bool
{
    $host = trim((string) ($smtpConfig['host'] ?? ''));
    $port = (int) ($smtpConfig['port'] ?? 587);
    $username = trim((string) ($smtpConfig['username'] ?? ''));
    $password = (string) ($smtpConfig['password'] ?? '');
    $encryption = strtolower(trim((string) ($smtpConfig['encryption'] ?? 'starttls')));
    $timeout = max(5, (int) ($smtpConfig['timeout'] ?? 30));

    if ($host === '' || $port <= 0 || $username === '' || $password === '') {
        return false;
    }

    $clientName = trim((string) ($smtpConfig['client_name'] ?? ''));
    if ($clientName === '') {
        $clientName = deriveDefaultClientName($fromEmail);
    }

    $transport = 'tcp://';
    if ($encryption === 'tls' || $encryption === 'ssl') {
        $transport = 'tls://';
    }

    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
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
        if (!smtpExpectCodes($socket, [220])) {
            $cleanup();
            return false;
        }

        if (!smtpCommand($socket, 'EHLO ' . $clientName, [250])) {
            $cleanup();
            return false;
        }

        if ($encryption === 'starttls') {
            if (!smtpCommand($socket, 'STARTTLS', [220])) {
                $cleanup();
                return false;
            }

            $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                    $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                }
            }

            if (!@stream_socket_enable_crypto($socket, true, $cryptoMethod)) {
                $cleanup();
                return false;
            }

            if (!smtpCommand($socket, 'EHLO ' . $clientName, [250])) {
                $cleanup();
                return false;
            }
        }

        if (!smtpCommand($socket, 'AUTH LOGIN', [334])) {
            $cleanup();
            return false;
        }

        if (!smtpCommand($socket, base64_encode($username), [334])) {
            $cleanup();
            return false;
        }

        if (!smtpCommand($socket, base64_encode($password), [235])) {
            $cleanup();
            return false;
        }

        $fromAddress = extractEmailAddress($fromEmail);
        $toAddress = extractEmailAddress($toEmail);

        if (!smtpCommand($socket, 'MAIL FROM:<' . $fromAddress . '>', [250])) {
            $cleanup();
            return false;
        }

        if (!smtpCommand($socket, 'RCPT TO:<' . $toAddress . '>', [250, 251])) {
            $cleanup();
            return false;
        }

        if (!smtpCommand($socket, 'DATA', [354])) {
            $cleanup();
            return false;
        }

        $message = buildSmtpMessage($fromEmail, $fromName, $toEmail, $toName, $subject, $body, $clientName);

        $writeResult = @fwrite($socket, $message . "\r\n.\r\n");
        if ($writeResult === false) {
            $cleanup();
            return false;
        }

        if (!smtpExpectCodes($socket, [250])) {
            $cleanup();
            return false;
        }

        smtpCommand($socket, 'QUIT', [221]);
        fclose($socket);

        return true;
    } catch (Throwable $e) {
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
    $toHeader = formatAddress($toName, $toEmail);

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
    $emailAddress = extractEmailAddress($email);
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

function smtpCommand($socket, string $command, array $expectedCodes): bool
{
    if (@fwrite($socket, $command . "\r\n") === false) {
        return false;
    }

    return smtpExpectCodes($socket, $expectedCodes);
}

function smtpExpectCodes($socket, array $expectedCodes): bool
{
    $response = smtpReadResponse($socket);
    if ($response === '') {
        return false;
    }

    $code = (int) substr($response, 0, 3);
    return in_array($code, $expectedCodes, true);
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
