<?php
// =============================================================
//  ARQUIVO: api/routes/alimentos.php
//  FUNÇÃO:  CRUD completo de alimentos.
//
//  Rotas atendidas:
//    GET    /api/alimentos              → lista com busca e paginação
//    GET    /api/alimentos/{id}         → detalhe de um alimento
//    POST   /api/alimentos              → cadastrar novo alimento
//    PUT    /api/alimentos/{id}         → editar alimento existente
//    DELETE /api/alimentos/{id}         → remover (soft delete)
// =============================================================

/**
 * Distribuidor: recebe o método HTTP e redireciona para a função correta.
 */
function routeAlimentos(string $method, ?int $id, array $body): void {
    $db = Database::connect();
    match($method) {
        'GET'    => $id ? getAlimento($db, $id)           : listAlimentos($db),
        'POST'   => createAlimento($db, $body),
        'PUT'    => $id ? updateAlimento($db, $id, $body) : jsonError('ID obrigatório', 400),
        'DELETE' => $id ? deleteAlimento($db, $id)        : jsonError('ID obrigatório', 400),
        default  => jsonError('Método não permitido', 405),
    };
}

/**
 * Lista alimentos com busca por nome, filtro por categoria e paginação.
 *
 * Query string aceita:
 *   ?q=frango           → busca no nome e descrição
 *   ?categoria=Proteína → filtra por categoria
 *   ?page=2             → página (padrão: 1)
 *   ?limit=15           → itens por página (padrão: 20, máx: 100)
 */
function listAlimentos(PDO $db): void {
    $q      = trim($_GET['q']         ?? '');
    $cat    = trim($_GET['categoria'] ?? '');
    $page   = max(1, (int)($_GET['page']  ?? 1));
    $limit  = min(100, max(5, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit; // quantos registros pular

    // Monta o WHERE dinamicamente com base nos filtros recebidos
    $where  = ['ativo = 1']; // só retorna alimentos não deletados
    $params = [];

    if ($q !== '') {
        // Busca em nome e descrição usando LIKE com curinga %
        $where[]  = '(nome LIKE ? OR descricao LIKE ?)';
        $params[] = "%$q%";
        $params[] = "%$q%";
    }
    if ($cat !== '') {
        $where[]  = 'categoria = ?';
        $params[] = $cat;
    }

    $sql = implode(' AND ', $where);

    // Contagem total para calcular o número de páginas
    $cntStmt = $db->prepare("SELECT COUNT(*) FROM alimentos WHERE $sql");
    $cntStmt->execute($params);
    $total = (int)$cntStmt->fetchColumn();

    // LIMIT e OFFSET são inteiros validados acima — interpolação direta é segura
    $stmt = $db->prepare("
        SELECT id, nome, descricao, categoria, emoji, porcao_g, unidade,
               kcal, proteina_g, carb_g, gordura_g, gord_sat_g,
               fibra_g, acucar_g, sodio_mg, fonte
        FROM alimentos
        WHERE $sql
        ORDER BY nome ASC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);

    jsonResponse([
        'data'       => $stmt->fetchAll(),
        'total'      => $total,
        'page'       => $page,
        'limit'      => $limit,
        'totalPages' => (int)ceil($total / $limit),
    ]);
}

/**
 * Retorna os dados completos de um único alimento pelo ID.
 */
function getAlimento(PDO $db, int $id): void {
    $stmt = $db->prepare("SELECT * FROM alimentos WHERE id = ? AND ativo = 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Alimento não encontrado', 404);
    jsonResponse($row);
}

/**
 * Cadastra um novo alimento.
 * Campos obrigatórios: nome, kcal.
 * Todos os outros campos têm valores padrão.
 */
function createAlimento(PDO $db, array $body): void {
    requireFields($body, ['nome', 'kcal']);

    $stmt = $db->prepare("
        INSERT INTO alimentos
          (nome, descricao, marca, categoria, emoji, porcao_g, unidade,
           kcal, proteina_g, carb_g, gordura_g, gord_sat_g,
           fibra_g, acucar_g, sodio_mg, calcio_mg, ferro_mg, vitamina_c_mg, fonte)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $body['nome'],
        $body['descricao']     ?? null,
        $body['marca']         ?? null,
        $body['categoria']     ?? 'Outro',
        $body['emoji']         ?? '🍽️',
        $body['porcao_g']      ?? 100,
        $body['unidade']       ?? 'g',
        $body['kcal'],
        $body['proteina_g']    ?? 0,
        $body['carb_g']        ?? 0,
        $body['gordura_g']     ?? 0,
        $body['gord_sat_g']    ?? 0,
        $body['fibra_g']       ?? 0,
        $body['acucar_g']      ?? 0,
        $body['sodio_mg']      ?? 0,
        $body['calcio_mg']     ?? 0,
        $body['ferro_mg']      ?? 0,
        $body['vitamina_c_mg'] ?? 0,
        $body['fonte']         ?? 'Manual',
    ]);

    // Retorna o ID do novo alimento com código 201 (Created)
    jsonResponse(['id' => (int)$db->lastInsertId(), 'message' => 'Alimento criado'], 201);
}

/**
 * Atualiza campos de um alimento existente.
 * Só altera os campos enviados na requisição (atualização parcial).
 */
function updateAlimento(PDO $db, int $id, array $body): void {
    $allowed = [
        'nome', 'descricao', 'marca', 'categoria', 'emoji', 'porcao_g', 'unidade',
        'kcal', 'proteina_g', 'carb_g', 'gordura_g', 'gord_sat_g',
        'fibra_g', 'acucar_g', 'sodio_mg', 'calcio_mg', 'ferro_mg', 'vitamina_c_mg'
    ];
    $sets   = [];
    $params = [];

    // Adiciona ao UPDATE somente os campos que foram enviados
    foreach ($allowed as $f) {
        if (array_key_exists($f, $body)) {
            $sets[]   = "$f = ?";
            $params[] = $body[$f];
        }
    }
    if (!$sets) jsonError('Nenhum campo para atualizar', 422);

    $params[] = $id; // valor para WHERE id = ?
    $db->prepare("UPDATE alimentos SET " . implode(', ', $sets) . " WHERE id = ?")
       ->execute($params);

    jsonSuccess('Alimento atualizado');
}

/**
 * Remove um alimento usando soft delete.
 *
 * Em vez de apagar o registro do banco, marca como inativo (ativo = 0).
 * Isso preserva o histórico de refeições que já usaram esse alimento.
 * O alimento não aparecerá mais nas buscas (a query filtra por ativo = 1).
 */
function deleteAlimento(PDO $db, int $id): void {
    $db->prepare("UPDATE alimentos SET ativo = 0 WHERE id = ?")->execute([$id]);
    jsonSuccess('Alimento removido');
}
