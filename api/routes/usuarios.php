<?php
// =============================================================
//  ARQUIVO: api/routes/usuarios.php
//  FUNÇÃO:  Gerenciamento de usuários.
//
//  Rotas atendidas:
//    GET   /api/usuarios       → lista todos os usuários
//    GET   /api/usuarios/{id}  → dados do usuário + IMC calculado
//    POST  /api/usuarios       → criar usuário
//    PUT   /api/usuarios/{id}  → atualizar perfil
//
//  A meta calórica é recalculada automaticamente na criação
//  e em toda atualização de dados físicos ou objetivo.
// =============================================================

/**
 * Distribuidor de rotas para o recurso 'usuarios'.
 */
function routeUsuarios(string $method, ?int $id, array $body): void {
    $db   = Database::connect();
    $user = autenticar();

    // Garante que o usuário só acessa os próprios dados
    if ($id && $id !== $user['id']) {
        jsonError('Acesso negado', 403);
    }

    match($method) {
        'GET'    => $id ? getUsuario($db, $id) : listUsuarios($db),
        'POST'   => createUsuario($db, $body),
        'PUT'    => $id ? updateUsuario($db, $id, $body) : jsonError('ID obrigatório', 400),
        'DELETE' => $id ? deleteUsuario($db, $id)        : jsonError('ID obrigatório', 400),
        default  => jsonError('Método não permitido', 405),
    };
}

/**
 * Lista todos os usuários com dados resumidos.
 */
function listUsuarios(PDO $db): void {
    // Retorna apenas os dados do usuário autenticado
    // Nunca expõe lista de todos os usuários
    $user = autenticar();
    getUsuario($db, $user['id']);
}

/**
 * Retorna os dados completos de um usuário.
 * Calcula e inclui o IMC se peso e altura estiverem preenchidos.
 *
 * Fórmula IMC: peso(kg) / altura²(m)
 * Classificação: <18,5 abaixo | 18,5–24,9 normal | 25–29,9 sobrepeso | ≥30 obesidade
 */
function getUsuario(PDO $db, int $id): void {
    $stmt = $db->prepare("SELECT * FROM usuarios WHERE id=?");
    $stmt->execute([$id]);
    $u = $stmt->fetch();
    if (!$u) jsonError('Usuário não encontrado', 404);
    if ($u['peso_atual'] && $u['altura_cm']) {
        $h = $u['altura_cm'] / 100;
        $u['imc'] = round($u['peso_atual'] / ($h * $h), 1);
    }
    unset($u['senha']); // nunca retorna senha para o frontend
    jsonResponse($u);
}

/**
 * Cria um novo usuário.
 * Campos obrigatórios: nome, email.
 * A meta calórica é calculada automaticamente pela fórmula Mifflin-St Jeor.
 */
function createUsuario(PDO $db, array $body): void {
    requireFields($body, ['nome', 'email']);

    // Calcula a meta calórica com os dados fornecidos
    $kcal = calcMetaKcal($body);

    $stmt = $db->prepare("
        INSERT INTO usuarios
            (nome,email,genero,data_nasc,peso_atual,peso_alvo,altura_cm,
             nivel_ativ,meta_kcal,meta_agua_l,refeicoes_dia,objetivo)
        VALUES (:nome,:email,:gen,:nasc,:peso,:alvo,:alt,:ativ,:kcal,:agua,:ref,:obj)
    ");
    $stmt->execute([
        ':nome'  => $body['nome'],
        ':email' => $body['email'],
        ':gen'   => $body['genero']        ?? 'F',
        ':nasc'  => $body['data_nasc']     ?? null,
        ':peso'  => $body['peso_atual']    ?? null,
        ':alvo'  => $body['peso_alvo']     ?? null,
        ':alt'   => $body['altura_cm']     ?? null,
        ':ativ'  => $body['nivel_ativ']    ?? 'light',
        ':kcal'  => $kcal,
        ':agua'  => $body['meta_agua_l']   ?? 2.5,
        ':ref'   => $body['refeicoes_dia'] ?? 4,
        ':obj'   => $body['objetivo']      ?? 'loss',
    ]);

    jsonResponse([
        'id'        => (int)$db->lastInsertId(),
        'meta_kcal' => $kcal,
        'message'   => 'Usuário criado'
    ], 201);
}

/**
 * Atualiza o perfil de um usuário.
 * Aceita atualização parcial: só os campos enviados são alterados.
 * Recalcula a meta calórica mesclando os dados antigos com os novos.
 */
function updateUsuario(PDO $db, int $id, array $body): void {
    $allowed = [
        'nome','email','genero','data_nasc','peso_atual','peso_alvo',
        'altura_cm','nivel_ativ','meta_agua_l','refeicoes_dia','objetivo'
    ];
    $sets = []; $params = [':id' => $id];

    foreach ($allowed as $f) {
        if (array_key_exists($f, $body)) {
            $sets[]        = "$f=:$f";
            $params[":$f"] = $body[$f];
        }
    }

    // Mescla os dados atuais com os novos para garantir cálculo correto da meta
    $current = $db->prepare("SELECT * FROM usuarios WHERE id=?");
    $current->execute([$id]);
    $kcal = calcMetaKcal(array_merge($current->fetch() ?: [], $body));

    // Atualiza a meta calórica junto com os demais campos
    $sets[]          = 'meta_kcal=:kcal';
    $params[':kcal'] = $kcal;

    if ($sets) {
        $db->prepare("UPDATE usuarios SET " . implode(',', $sets) . " WHERE id=:id")
           ->execute($params);
    }

    jsonResponse(['meta_kcal' => $kcal, 'message' => 'Perfil atualizado']);
}

	function deleteUsuario(PDO $db, int $id): void {
    $stmt = $db->prepare("SELECT id FROM usuarios WHERE id=?");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) jsonError('Usuário não encontrado', 404);

    $db->prepare("DELETE FROM usuarios WHERE id=?")->execute([$id]);
    jsonSuccess('Conta excluída com sucesso');
}
