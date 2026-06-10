<?php
declare(strict_types=1);

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function normalizeLineEndings(string $value): string
{
    return preg_replace("/\r\n|\r|\n/", "\r\n", $value) ?? $value;
}

function sanitizeHeaderValue(string $value): string
{
    return trim(str_replace(["\r", "\n"], '', $value));
}

function sanitizeReturnPath(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return 'iletisim.html';
    }

    $allowed = [
        'iletisim.html',
        'en/contact.html',
        'fr/communication.html',
    ];

    return in_array($value, $allowed, true) ? $value : 'iletisim.html';
}

function wantsJsonResponse(): bool
{
    $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    if ($requestedWith === 'xmlhttprequest') {
        return true;
    }

    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    return strpos($accept, 'application/json') !== false;
}

function sendJsonResponse(string $title, string $message, bool $success, string $returnPath = 'iletisim.html', int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => $success,
        'title' => $title,
        'message' => $message,
        'returnPath' => sanitizeReturnPath($returnPath),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function loadSmtpConfig(): array
{
    $configPath = __DIR__ . DIRECTORY_SEPARATOR . 'smtp-config.php';
    if (!is_file($configPath)) {
        throw new RuntimeException('SMTP konfigurasyon dosyasi bulunamadi.');
    }

    $config = require $configPath;
    if (!is_array($config)) {
        throw new RuntimeException('SMTP konfigurasyon dosyasi gecersiz.');
    }

    $passwordEnv = (string) ($config['password_env'] ?? '');
    $envPassword = $passwordEnv !== '' ? (string) getenv($passwordEnv) : '';
    if ($envPassword !== '') {
        $config['password'] = $envPassword;
    }

    return $config;
}

function getSmtpServerConfigs(array $config): array
{
    if (isset($config['servers']) && is_array($config['servers']) && $config['servers'] !== []) {
        return $config['servers'];
    }

    return [[
        'host' => (string) ($config['host'] ?? ''),
        'port' => (int) ($config['port'] ?? 465),
        'encryption' => (string) ($config['encryption'] ?? 'ssl'),
    ]];
}

function buildSocketContext(array $smtpConfig)
{
    $verifyPeer = (bool) ($smtpConfig['verify_peer'] ?? false);
    $verifyPeerName = (bool) ($smtpConfig['verify_peer_name'] ?? false);
    $allowSelfSigned = (bool) ($smtpConfig['allow_self_signed'] ?? true);

    return stream_context_create([
        'ssl' => [
            'verify_peer' => $verifyPeer,
            'verify_peer_name' => $verifyPeerName,
            'allow_self_signed' => $allowSelfSigned,
            'SNI_enabled' => true,
            'peer_name' => (string) ($smtpConfig['host'] ?? ''),
        ],
    ]);
}

function smtpReadResponse($socket): string
{
    $response = '';

    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (strlen($line) < 4) {
            continue;
        }
        if ($line[3] === ' ') {
            break;
        }
    }

    if ($response === '') {
        throw new RuntimeException('SMTP sunucusundan yanit alinamadi.');
    }

    return $response;
}

function smtpExpect($socket, array $expectedCodes): string
{
    $response = smtpReadResponse($socket);
    $code = (int) substr($response, 0, 3);

    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('SMTP hatasi: ' . trim($response));
    }

    return $response;
}

function smtpCommand($socket, string $command, array $expectedCodes): string
{
    fwrite($socket, $command . "\r\n");
    return smtpExpect($socket, $expectedCodes);
}

function smtpSendMailWithServer(array $smtpConfig, array $serverConfig, string $replyName, string $replyEmail, string $subject, string $plainBody): void
{
    $host = (string) ($serverConfig['host'] ?? '');
    $port = (int) ($serverConfig['port'] ?? 465);
    $encryption = strtolower((string) ($serverConfig['encryption'] ?? 'ssl'));
    $username = (string) ($smtpConfig['username'] ?? '');
    $password = (string) ($smtpConfig['password'] ?? '');
    $fromEmail = (string) ($smtpConfig['from_email'] ?? $username);
    $fromName = (string) ($smtpConfig['from_name'] ?? 'Dogu Bati Proje');
    $toEmail = (string) ($smtpConfig['to_email'] ?? $username);
    $timeout = (int) ($smtpConfig['timeout'] ?? 20);

    if ($host === '' || $username === '' || $fromEmail === '' || $toEmail === '') {
        throw new RuntimeException('SMTP ayarlari eksik.');
    }

    if ($password === '') {
        throw new RuntimeException('SMTP sifresi henuz girilmemis.');
    }

    $remoteHost = $encryption === 'ssl' ? 'ssl://' . $host : $host;
    $context = buildSocketContext(array_merge($smtpConfig, ['host' => $host]));
    $socket = @stream_socket_client(
        $remoteHost . ':' . $port,
        $errorNumber,
        $errorMessage,
        $timeout,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$socket) {
        $lastError = error_get_last();
        $details = trim((string) ($lastError['message'] ?? ''));
        if ($details !== '' && stripos($details, $errorMessage) === false) {
            $errorMessage = trim($errorMessage . ' | ' . $details, ' |');
        }
        throw new RuntimeException('SMTP baglantisi kurulamadi: ' . $errorMessage . ' (' . $errorNumber . ')');
    }

    stream_set_timeout($socket, $timeout);

    try {
        smtpExpect($socket, [220]);

        $helloHost = preg_replace('/[^a-z0-9.\-]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
        smtpCommand($socket, 'EHLO ' . $helloHost, [250]);

        if ($encryption === 'tls') {
            smtpCommand($socket, 'STARTTLS', [220]);
            @stream_context_set_option($socket, 'ssl', 'verify_peer', (bool) ($smtpConfig['verify_peer'] ?? false));
            @stream_context_set_option($socket, 'ssl', 'verify_peer_name', (bool) ($smtpConfig['verify_peer_name'] ?? false));
            @stream_context_set_option($socket, 'ssl', 'allow_self_signed', (bool) ($smtpConfig['allow_self_signed'] ?? true));
            $cryptoEnabled = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($cryptoEnabled !== true) {
                throw new RuntimeException('STARTTLS baslatilamadi.');
            }
            smtpCommand($socket, 'EHLO ' . $helloHost, [250]);
        }

        smtpCommand($socket, 'AUTH LOGIN', [334]);
        smtpCommand($socket, base64_encode($username), [334]);
        smtpCommand($socket, base64_encode($password), [235]);
        smtpCommand($socket, 'MAIL FROM:<' . sanitizeHeaderValue($fromEmail) . '>', [250]);
        smtpCommand($socket, 'RCPT TO:<' . sanitizeHeaderValue($toEmail) . '>', [250, 251]);
        smtpCommand($socket, 'DATA', [354]);

        $headers = [
            'Date: ' . date('r'),
            'From: ' . sanitizeHeaderValue($fromName) . ' <' . sanitizeHeaderValue($fromEmail) . '>',
            'To: <' . sanitizeHeaderValue($toEmail) . '>',
            'Reply-To: ' . sanitizeHeaderValue($replyName) . ' <' . sanitizeHeaderValue($replyEmail) . '>',
            'Subject: ' . $subject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'X-Mailer: PHP SMTP',
        ];

        $message = implode("\r\n", $headers) . "\r\n\r\n" . normalizeLineEndings($plainBody);
        $message = str_replace("\r\n.", "\r\n..", $message);
        fwrite($socket, $message . "\r\n.\r\n");
        smtpExpect($socket, [250]);
        smtpCommand($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

function smtpSendMail(array $smtpConfig, string $replyName, string $replyEmail, string $subject, string $plainBody): void
{
    $attemptErrors = [];

    foreach (getSmtpServerConfigs($smtpConfig) as $serverConfig) {
        $host = (string) ($serverConfig['host'] ?? '');
        $port = (int) ($serverConfig['port'] ?? 0);
        $encryption = strtolower((string) ($serverConfig['encryption'] ?? 'plain'));
        $label = $host . ':' . $port . ' [' . $encryption . ']';

        try {
            smtpSendMailWithServer($smtpConfig, $serverConfig, $replyName, $replyEmail, $subject, $plainBody);
            return;
        } catch (RuntimeException $exception) {
            $attemptErrors[] = $label . ' => ' . $exception->getMessage();
        }
    }

    throw new RuntimeException("Tum SMTP denemeleri basarisiz oldu:\n" . implode("\n", $attemptErrors));
}

function renderResponse(string $title, string $message, bool $success, string $returnPath = 'iletisim.html'): void
{
    $accent = $success ? '#5cb85c' : '#c09a3c';
    $status = $success ? 'Mesaj gonderildi' : 'Mesaj gonderilemedi';
    $returnPath = sanitizeReturnPath($returnPath);

    if (wantsJsonResponse()) {
        sendJsonResponse($title, $message, $success, $returnPath, $success ? 200 : 400);
        return;
    }

    echo '<!DOCTYPE html>';
    echo '<html lang="tr">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title) . '</title>';
    echo '<style>';
    echo 'body{margin:0;font-family:Arial,sans-serif;background:#0b0b0b;color:#f4f4f4;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px;box-sizing:border-box;}';
    echo '.card{max-width:720px;width:100%;background:#151515;border:1px solid rgba(255,255,255,.08);border-top:4px solid ' . $accent . ';padding:32px;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.35);}';
    echo 'h1{margin:0 0 12px;font-size:28px;color:#fff;}';
    echo 'p{margin:0 0 12px;line-height:1.7;color:#ddd;}';
    echo '.status{display:inline-block;margin-bottom:18px;padding:8px 12px;border-radius:999px;background:rgba(255,255,255,.06);color:#fff;font-size:13px;letter-spacing:.04em;text-transform:uppercase;}';
    echo '.actions{margin-top:24px;}';
    echo '.button{display:inline-block;padding:12px 18px;border-radius:999px;background:' . $accent . ';color:#111;text-decoration:none;font-weight:bold;}';
    echo '.button:hover{filter:brightness(1.05);}';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    echo '<div class="card">';
    echo '<div class="status">' . h($status) . '</div>';
    echo '<h1>' . h($title) . '</h1>';
    echo '<p>' . nl2br(h($message)) . '</p>';
    echo '<div class="actions"><a class="button" href="' . h($returnPath) . '">Iletisim sayfasina don</a></div>';
    echo '</div>';
    echo '</body>';
    echo '</html>';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (wantsJsonResponse()) {
        sendJsonResponse('Geçersiz istek', 'Bu işlem sadece form gönderimi ile kullanılabilir.', false, 'iletisim.html', 405);
        exit;
    }

    header('Location: iletisim.html');
    exit;
}

$name = trim((string) ($_POST['name'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$subject = trim((string) ($_POST['subject'] ?? ''));
$message = trim((string) ($_POST['message'] ?? ''));
$returnPath = sanitizeReturnPath((string) ($_POST['return_path'] ?? 'iletisim.html'));

$errors = [];

if ($name === '') {
    $errors[] = 'Lutfen ad soyad alanini doldurun.';
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Lutfen gecerli bir e-posta adresi girin.';
}

if ($subject === '') {
    $errors[] = 'Lutfen konu alanini doldurun.';
}

if ($message === '') {
    $errors[] = 'Lutfen mesaj alanini doldurun.';
}

if ($errors !== []) {
    renderResponse(
        'Formda eksik bilgi var',
        implode("\n", $errors),
        false,
        $returnPath
    );
    exit;
}

$formSubject = trim((string) ($_POST['form_subject'] ?? "Dogu Bati Proje'ye gelen mesaj"));
$siteName = trim((string) ($_POST['project_name'] ?? 'Doğu Batı Proje'));
$mailBody = implode("\n", [
    'Yeni iletisim mesaji alindi.',
    '',
    'Ad Soyad: ' . $name,
    'E-posta: ' . $email,
    'Konu: ' . $subject,
    '',
    'Mesaj:',
    $message,
    '',
    'Gonderim Kaynagi: ' . $siteName,
    'IP: ' . (string) ($_SERVER['REMOTE_ADDR'] ?? '-'),
]);
$encodedSubject = '=?UTF-8?B?' . base64_encode($formSubject . ' | ' . $subject) . '?=';

try {
    $smtpConfig = loadSmtpConfig();
    smtpSendMail($smtpConfig, $name, $email, $encodedSubject, $mailBody);

    renderResponse(
        'Mesajınız başarıyla gönderildi',
        'Mesajınız tarafımıza ulaştı. En kısa sürede size geri dönüş yapılacak.',
        true,
        $returnPath
    );
    exit;
} catch (RuntimeException $exception) {
    renderResponse(
        'Mail sunucusu şu anda yanıt vermiyor',
        "Form doğrulaması başarılı oldu ancak SMTP gönderimi tamamlanamadı.\n" . $exception->getMessage(),
        false,
        $returnPath
    );
}
