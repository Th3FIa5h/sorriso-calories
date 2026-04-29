<?php
// =============================================================
//  ARQUIVO: api/routes/auth.php
//  FUNÇÃO:  Autenticação + verificação de email + recuperação de senha
// =============================================================

/**
 * Valida os requisitos de senha.
 * Retorna null se válida ou string com o erro se inválida.
 */
function validarSenha(string $senha): ?string {
    if (strlen($senha) < 8)
        return 'A senha deve ter pelo menos 8 caracteres.';
    if (!preg_match('/[A-Z]/', $senha))
        return 'A senha deve ter pelo menos 1 letra maiúscula.';
    if (!preg_match('/[a-z]/', $senha))
        return 'A senha deve ter pelo menos 1 letra minúscula.';
    if (!preg_match('/[0-9]/', $senha))
        return 'A senha deve ter pelo menos 1 número.';
    if (!preg_match('/[^A-Za-z0-9]/', $senha))
        return 'A senha deve ter pelo menos 1 caractere especial (!@#$%...).';
    return null;
}
    
function routeAuth(string $method, ?string $action, array $body): void {
    if ($method !== 'POST') jsonError('Método não permitido', 405);
    match($action) {
        'login'              => authLogin($body),
        'cadastro'           => authCadastro($body),
        'logout'             => authLogout(),
        'verificar-email'    => authVerificarEmail($body),
        'esqueci-senha'      => authEsqueciSenha($body),
        'redefinir-senha'    => authRedefinirSenha($body),
        default              => jsonError('Ação não encontrada', 404),
    };
}

/**
 * Login: valida email e senha, gera e retorna token de sessão.
 * Bloqueia login se o email não foi verificado.
 */
function authLogin(array $body): void {
    requireFields($body, ['email', 'senha']);
    $db = Database::connect();

    $stmt = $db->prepare("SELECT * FROM usuarios WHERE email = ?");
    $stmt->execute([trim($body['email'])]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($body['senha'], $user['senha'])) {
        jsonError('Email ou senha incorretos', 401);
    }

    // Bloqueia login se email não foi verificado
    if (!$user['email_verificado']) {
        jsonError('Confirme seu email antes de entrar. Verifique sua caixa de entrada.', 403);
    }

    $token     = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

    $db->prepare("
        INSERT INTO tokens (usuario_id, token, expires_at)
        VALUES (?, ?, ?)
    ")->execute([$user['id'], $token, $expiresAt]);

    unset($user['senha']);
    jsonResponse(['token' => $token, 'expires_at' => $expiresAt, 'usuario' => $user]);
}

/**
 * Cadastro: cria conta, envia email de confirmação.
 * O usuário só consegue logar após confirmar o email.
 */
function authCadastro(array $body): void {
    requireFields($body, ['nome', 'email', 'senha']);

    $db = Database::connect();
    
    // Deleta cadastros não confirmados com mais de 12 horas
    $db->prepare("
        DELETE FROM usuarios
        WHERE email_verificado = 0
          AND token_verificacao_exp < DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ")->execute();
    // Valida formato do email
	if (!filter_var(trim($body['email']), FILTER_VALIDATE_EMAIL)) {
    	jsonError('Email inválido. Use um formato como: nome@exemplo.com', 422);
	}


    $check = $db->prepare("SELECT id FROM usuarios WHERE email = ?");
    $check->execute([trim($body['email'])]);
    if ($check->fetch()) jsonError('Este email já está cadastrado', 409);

    $erroSenha = validarSenha($body['senha']);
    if ($erroSenha) jsonError($erroSenha, 422);

    $senhaHash = password_hash($body['senha'], PASSWORD_BCRYPT);
    $kcal      = calcMetaKcal($body);

    // Gera token de verificação de email (expira em 24h)
    $tokenVerif = bin2hex(random_bytes(32));
    $tokenExp   = date('Y-m-d H:i:s', strtotime('+1 hour'));

    $stmt = $db->prepare("
        INSERT INTO usuarios
            (nome, email, senha, email_verificado, token_verificacao,
             token_verificacao_exp, genero, data_nasc, peso_atual, peso_alvo,
             altura_cm, nivel_ativ, meta_kcal, meta_agua_l, refeicoes_dia, objetivo)
        VALUES (?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        trim($body['nome']),
        trim($body['email']),
        $senhaHash,
        $tokenVerif,
        $tokenExp,
        $body['genero']        ?? 'F',
        $body['data_nasc']     ?? null,
        $body['peso_atual']    ?? null,
        $body['peso_alvo']     ?? null,
        $body['altura_cm']     ?? null,
        $body['nivel_ativ']    ?? 'light',
        $kcal,
        $body['meta_agua_l']   ?? 2.5,
        $body['refeicoes_dia'] ?? 4,
        $body['objetivo']      ?? 'loss',
    ]);

    // Envia email de confirmação
    require_once __DIR__ . '/email.php';

    $emailEnviado = sendEmailVerificacao(
        trim($body['email']),
        trim($body['nome']),
        $tokenVerif
    );

    jsonResponse([
        'message' => 'Cadastro realizado! Verifique seu email para ativar a conta.',
    ], 201);
}

/**
 * Verifica o token de confirmação de email.
 * Ativa a conta do usuário se o token for válido e não expirou.
 */
function authVerificarEmail(array $body): void {
    requireFields($body, ['token']);
    $db = Database::connect();

    $stmt = $db->prepare("
        SELECT id, nome FROM usuarios
        WHERE token_verificacao = ?
          AND token_verificacao_exp > NOW()
          AND email_verificado = 0
    ");
    $stmt->execute([$body['token']]);
    $user = $stmt->fetch();

    if (!$user) {
        jsonError('Link de verificação inválido ou expirado.', 400);
    }

    // Ativa a conta e limpa os campos de verificação
    $db->prepare("
        UPDATE usuarios
        SET email_verificado = 1,
            token_verificacao = NULL,
            token_verificacao_exp = NULL
        WHERE id = ?
    ")->execute([$user['id']]);

    jsonSuccess('Email confirmado com sucesso! Você já pode fazer login.');
}

/**
 * Esqueci minha senha: gera token e envia email com link de redefinição.
 * Não informa se o email existe ou não (segurança contra enumeração).
 */
function authEsqueciSenha(array $body): void {
    requireFields($body, ['email']);
    // Valida formato do email
    if (!filter_var(trim($body['email']), FILTER_VALIDATE_EMAIL)) {
        jsonError('Email inválido. Use um formato como: nome@exemplo.com', 422);
    }
    $db = Database::connect();

    $stmt = $db->prepare("SELECT id, nome FROM usuarios WHERE email = ?");
    $stmt->execute([trim($body['email'])]);
    $user = $stmt->fetch();

    // Retorna sucesso mesmo se o email não existir (evita enumeração)
    if (!$user) {
        jsonSuccess('Se este email estiver cadastrado, você receberá as instruções em breve.');
        return;
    }

    // Remove tokens anteriores do usuário
    $db->prepare("DELETE FROM tokens_senha WHERE usuario_id = ?")->execute([$user['id']]);

    // Gera novo token (expira em 1 hora)
    $token     = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

    $db->prepare("
        INSERT INTO tokens_senha (usuario_id, token, expires_at)
        VALUES (?, ?, ?)
    ")->execute([$user['id'], $token, $expiresAt]);

    // Envia email com link de redefinição
    require_once __DIR__ . '/email.php';
    sendEmailRecuperacao(trim($body['email']), $user['nome'], $token);

    jsonSuccess('Se este email estiver cadastrado, você receberá as instruções em breve.');
}

/**
 * Redefine a senha usando o token recebido por email.
 */
function authRedefinirSenha(array $body): void {
    requireFields($body, ['token', 'senha']);
    $db = Database::connect();

    $erroSenha = validarSenha($body['senha']);
	if ($erroSenha) jsonError($erroSenha, 422);

    // Verifica se o token é válido e não foi usado
    $stmt = $db->prepare("
        SELECT t.id, t.usuario_id
        FROM tokens_senha t
        WHERE t.token = ?
          AND t.expires_at > NOW()
          AND t.usado = 0
    ");
    $stmt->execute([$body['token']]);
    $tokenRow = $stmt->fetch();

    if (!$tokenRow) {
        jsonError('Link de redefinição inválido ou expirado.', 400);
    }

    // Atualiza a senha
    $novasenha = password_hash($body['senha'], PASSWORD_BCRYPT);
    $db->prepare("UPDATE usuarios SET senha = ? WHERE id = ?")
       ->execute([$novasenha, $tokenRow['usuario_id']]);

    // Marca o token como usado
    $db->prepare("UPDATE tokens_senha SET usado = 1 WHERE id = ?")
       ->execute([$tokenRow['id']]);

    // Invalida todas as sessões ativas do usuário
    $db->prepare("DELETE FROM tokens WHERE usuario_id = ?")
       ->execute([$tokenRow['usuario_id']]);

    jsonSuccess('Senha redefinida com sucesso! Faça login com a nova senha.');
}

/**
 * Logout: invalida o token atual.
 */
function authLogout(): void {
    $db    = Database::connect();
    $token = getBearerToken();
    if ($token) {
        $db->prepare("DELETE FROM tokens WHERE token = ?")->execute([$token]);
    }
    jsonSuccess('Logout realizado');
}

/**
 * Extrai o token do cabeçalho Authorization: Bearer {token}
 */
function getBearerToken(): ?string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)/i', $header, $m)) return $m[1];
    return null;
}

/**
 * Verifica o token e retorna o usuário autenticado.
 * Chamada no início de todas as rotas protegidas.
 */
function autenticar(): array {
    $token = getBearerToken();
    if (!$token) jsonError('Token não fornecido. Faça login.', 401);

    $db   = Database::connect();
    $stmt = $db->prepare("
        SELECT u.*
        FROM tokens t
        JOIN usuarios u ON u.id = t.usuario_id
        WHERE t.token = ? AND t.expires_at > NOW()
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) jsonError('Token inválido ou expirado. Faça login novamente.', 401);

    unset($user['senha']);
    return $user;
}