<?php
// =============================================================
//  ARQUIVO: api/routes/email.php
//  FUNÇÃO:  Envio de emails via API do Brevo.
//
//  Usa a API REST do Brevo diretamente via cURL — sem precisar
//  instalar nenhuma biblioteca externa.
// =============================================================

// Chave de API do Brevo — lida do ambiente
function getBrevoKey(): string {
    return $_ENV['BREVO_KEY'] ?? '';
}

// Email e nome do remetente
function getBrevoSender(): array {
    return [
        'name'  => $_ENV['BREVO_SENDER_NAME']  ?? 'Sorriso Calories',
        'email' => $_ENV['BREVO_SENDER_EMAIL']  ?? '',
    ];
}

/**
 * Envia um email via API do Brevo.
 *
 * @param string $toEmail   Email do destinatário
 * @param string $toName    Nome do destinatário
 * @param string $subject   Assunto do email
 * @param string $htmlBody  Corpo do email em HTML
 * @return bool             true se enviou, false se falhou
 */
function sendEmail(string $toEmail, string $toName, string $subject, string $htmlBody): bool {
    $sender = getBrevoSender();

    $payload = json_encode([
        'sender'      => $sender,
        'to'          => [['email' => $toEmail, 'name' => $toName]],
        'subject'     => $subject,
        'htmlContent' => $htmlBody,
    ]);

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'api-key: ' . getBrevoKey(),
        ],
        CURLOPT_TIMEOUT        => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError= curl_error($ch);
    curl_close($ch);

    // Salva o resultado em log temporário para debug
    file_put_contents(
        __DIR__ . '/../../email_debug.log',
        date('Y-m-d H:i:s') . " | HTTP: $httpCode | cURL error: $curlError | Response: $response\n",
        FILE_APPEND
    );

    return $httpCode === 201;
}

/**
 * Envia o email de confirmação de cadastro com link de verificação.
 *
 * @param string $toEmail  Email do usuário
 * @param string $toName   Nome do usuário
 * @param string $token    Token de verificação gerado no cadastro
 */
function sendEmailVerificacao(string $toEmail, string $toName, string $token): bool {
    // Detecta se está em produção ou local para montar o link correto
    $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $isLocal = str_contains($host, 'localhost') || str_contains($host, '127.0.0.1');
    $base    = $isLocal
        ? 'http://localhost/sorriso-calories/public'
        : "https://{$host}/sorriso-calories/public";

    $link    = "{$base}/verificar-email.html?token={$token}";
    $subject = '✅ Confirme seu cadastro no Sorriso Calories';
    $html    = "
    <div style='font-family:sans-serif;max-width:480px;margin:0 auto;padding:32px 24px'>
      <div style='text-align:center;margin-bottom:28px'>
        <div style='font-size:48px'>🥗</div>
        <h1 style='font-family:Georgia,serif;font-size:24px;color:#2d6a4f;margin-top:8px'>
          Sorriso Calories
        </h1>
      </div>
      <h2 style='font-size:20px;color:#2e3a2f;margin-bottom:10px'>
        Olá, {$toName}! 👋
      </h2>
      <p style='color:#5a6b5c;line-height:1.6;margin-bottom:24px'>
        Obrigado por se cadastrar! Clica no botão abaixo para confirmar seu email
        e ativar sua conta.
      </p>
      <div style='text-align:center;margin-bottom:28px'>
        <a href='{$link}'
           style='background:#2d6a4f;color:#fff;padding:14px 32px;border-radius:8px;
                  text-decoration:none;font-size:15px;font-weight:600;display:inline-block'>
          Confirmar meu email
        </a>
      </div>
      <p style='color:#9aab9c;font-size:13px;text-align:center'>
        O link expira em 1 hora.<br>
        Se você não se cadastrou no Sorriso Calories, ignore este email.
      </p>
      <hr style='border:none;border-top:1px solid #e8ede9;margin:24px 0'>
      <p style='color:#9aab9c;font-size:12px;text-align:center'>
        Sorriso Calories — Nutrição inteligente 🥗
      </p>
    </div>";

    return sendEmail($toEmail, $toName, $subject, $html);
}

/**
 * Envia o email de recuperação de senha com link para redefinir.
 *
 * @param string $toEmail  Email do usuário
 * @param string $toName   Nome do usuário
 * @param string $token    Token de recuperação gerado
 */
function sendEmailRecuperacao(string $toEmail, string $toName, string $token): bool {
    $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $isLocal = str_contains($host, 'localhost') || str_contains($host, '127.0.0.1');
    $base    = $isLocal
        ? 'http://localhost/sorriso-calories/public'
        : "https://{$host}/sorriso-calories/public";

    $link    = "{$base}/redefinir-senha.html?token={$token}";
    $subject = '🔑 Redefinição de senha — Sorriso Calories';
    $html    = "
    <div style='font-family:sans-serif;max-width:480px;margin:0 auto;padding:32px 24px'>
      <div style='text-align:center;margin-bottom:28px'>
        <div style='font-size:48px'>🥗</div>
        <h1 style='font-family:Georgia,serif;font-size:24px;color:#2d6a4f;margin-top:8px'>
          Sorriso Calories
        </h1>
      </div>
      <h2 style='font-size:20px;color:#2e3a2f;margin-bottom:10px'>
        Redefinir senha
      </h2>
      <p style='color:#5a6b5c;line-height:1.6;margin-bottom:24px'>
        Recebemos uma solicitação para redefinir a senha da conta associada
        a este email. Clica no botão abaixo para criar uma nova senha.
      </p>
      <div style='text-align:center;margin-bottom:28px'>
        <a href='{$link}'
           style='background:#2d6a4f;color:#fff;padding:14px 32px;border-radius:8px;
                  text-decoration:none;font-size:15px;font-weight:600;display:inline-block'>
          Redefinir minha senha
        </a>
      </div>
      <p style='color:#9aab9c;font-size:13px;text-align:center'>
        O link expira em 1 hora.<br>
        Se você não solicitou a redefinição, ignore este email.
        Sua senha continua a mesma.
      </p>
      <hr style='border:none;border-top:1px solid #e8ede9;margin:24px 0'>
      <p style='color:#9aab9c;font-size:12px;text-align:center'>
        Sorriso Calories — Nutrição inteligente 🥗
      </p>
    </div>";

    return sendEmail($toEmail, $toName, $subject, $html);
}