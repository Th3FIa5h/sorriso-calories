<?php
// =============================================================
//  ARQUIVO: api/routes/refeicoes.php
//  FUNÇÃO:  CRUD de refeições e dos alimentos (itens) que as compõem.
//
//  Rotas atendidas:
//    GET    /api/refeicoes?usuario_id=1&data=2026-04-15  → refeições do dia
//    GET    /api/refeicoes/{id}                          → detalhe com itens
//    POST   /api/refeicoes                               → criar refeição
//    PUT    /api/refeicoes/{id}                          → editar cabeçalho
//    DELETE /api/refeicoes/{id}                          → excluir refeição
//    POST   /api/refeicoes/{id}/itens                    → adicionar alimento
//    PUT    /api/refeicoes/{id}/itens/{itemId}           → editar quantidade
//    DELETE /api/refeicoes/{id}/itens/{itemId}           → remover alimento
// =============================================================

/**
 * Distribuidor: detecta se é uma requisição para a refeição ou para seus itens.
 */
function routeRefeicoes(string $method, ?int $id, ?string $sub, ?int $subId, array $body): void {
    $db   = Database::connect();
    $user = autenticar();

    // Valida usuario_id no body ou query string
    $uidReq = (int)($_GET['usuario_id'] ?? $body['usuario_id'] ?? 0);

    if ($uidReq && $uidReq !== $user['id']) {
        jsonError('Acesso negado', 403);
    }
    
    // Sub-recurso itens: /refeicoes/{id}/itens[/{itemId}]
    if ($sub === 'itens' && $id) {
        match($method) {
            'POST'   => addItem($db, $id, $body),
            'PUT'    => $subId ? updateItem($db, $id, $subId, $body) : jsonError('ID do item obrigatório', 400),
            'DELETE' => $subId ? deleteItem($db, $id, $subId)        : jsonError('ID do item obrigatório', 400),
            default  => jsonError('Método não permitido', 405),
        };
        return;
    }

    // Recurso principal: /refeicoes[/{id}]
    match($method) {
        'GET'    => $id ? getRefeicao($db, $id) : listRefeicoes($db, $user['id']),
        'POST'   => createRefeicao($db, $body, $user['id']),
        'PUT'    => $id ? updateRefeicao($db, $id, $body) : jsonError('ID obrigatório', 400),
        'DELETE' => $id ? deleteRefeicao($db, $id)        : jsonError('ID obrigatório', 400),
        default  => jsonError('Método não permitido', 405),
    };
}

/**
 * Lista todas as refeições de um usuário em uma data.
 * Para cada refeição, busca também seus itens (alimentos).
 * Retorna os totais calóricos somados do dia inteiro.
 */
function listRefeicoes(PDO $db, int $uidAutenticado): void {
    // Usa sempre o ID do usuário autenticado — ignora qualquer usuario_id da query string
    $uid  = $uidAutenticado;
    $data = $_GET['data'] ?? date('Y-m-d');

    $stmt = $db->prepare("
        SELECT r.*, COUNT(ri.id) AS num_itens
        FROM refeicoes r
        LEFT JOIN refeicao_itens ri ON ri.refeicao_id = r.id
        WHERE r.usuario_id=? AND r.data_ref=?
        GROUP BY r.id ORDER BY r.hora_ref ASC
    ");
    $stmt->execute([$uid, $data]);
    $refeicoes = $stmt->fetchAll();

    foreach ($refeicoes as &$r) {
        $si = $db->prepare("
            SELECT ri.*, a.nome, a.emoji, a.categoria
            FROM refeicao_itens ri
            JOIN alimentos a ON a.id = ri.alimento_id
            WHERE ri.refeicao_id=?
        ");
        $si->execute([$r['id']]);
        $r['itens'] = $si->fetchAll();
    }

    $tot = $db->prepare("
        SELECT COALESCE(SUM(kcal_total),0) AS kcal,
               COALESCE(SUM(proteina_g),0) AS prot,
               COALESCE(SUM(carb_g),0)     AS carb,
               COALESCE(SUM(gordura_g),0)  AS fat
        FROM refeicoes WHERE usuario_id=? AND data_ref=?
    ");
    $tot->execute([$uid, $data]);

    jsonResponse(['refeicoes' => $refeicoes, 'totais' => $tot->fetch(), 'data' => $data]);
}

/**
 * Retorna uma refeição completa com todos os seus itens.
 */
function getRefeicao(PDO $db, int $id): void {
    $stmt = $db->prepare("SELECT * FROM refeicoes WHERE id=?");
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    if (!$r) jsonError('Refeição não encontrada', 404);

    $si = $db->prepare("
        SELECT ri.*, a.nome, a.emoji, a.categoria
        FROM refeicao_itens ri
        JOIN alimentos a ON a.id = ri.alimento_id
        WHERE ri.refeicao_id=?
    ");
    $si->execute([$id]);
    $r['itens'] = $si->fetchAll();
    jsonResponse($r);
}

/**
 * Cria uma nova refeição.
 * Se o body incluir um array 'itens', os alimentos são inseridos automaticamente
 * e os totais são calculados logo em seguida.
 */
function createRefeicao(PDO $db, array $body, int $uidAutenticado): void {
    requireFields($body, ['tipo', 'data_ref']);

    // Usa sempre o ID do usuário autenticado
    $body['usuario_id'] = $uidAutenticado;

    $stmt = $db->prepare("
        INSERT INTO refeicoes (usuario_id, tipo, nome_custom, data_ref, hora_ref, obs)
        VALUES (?,?,?,?,?,?)
    ");
    $stmt->execute([
        $uidAutenticado,
        $body['tipo'],
        $body['nome_custom'] ?? null,
        $body['data_ref'],
        $body['hora_ref']    ?? date('H:i:s'),
        $body['obs']         ?? null,
    ]);
    $refId = (int)$db->lastInsertId();

    if (!empty($body['itens']) && is_array($body['itens'])) {
        foreach ($body['itens'] as $item) _insertItem($db, $refId, $item);
        recalcRefeicao($db, $refId);
        recalcHistorico($db, $uidAutenticado, $body['data_ref']);
    }

    jsonResponse(['id' => $refId, 'message' => 'Refeição criada'], 201);
}

/**
 * Atualiza o cabeçalho de uma refeição (tipo, hora, observações).
 * Não altera os itens da refeição.
 */
function updateRefeicao(PDO $db, int $id, array $body): void {
    $allowed = ['tipo','nome_custom','data_ref','hora_ref','obs'];
    $sets = []; $params = [':id' => $id];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $body)) { $sets[] = "$f=:$f"; $params[":$f"] = $body[$f]; }
    }
    if ($sets) $db->prepare("UPDATE refeicoes SET ".implode(',',$sets)." WHERE id=:id")->execute($params);
    jsonSuccess('Refeição atualizada');
}

/**
 * Exclui uma refeição e todos os seus itens.
 * A exclusão em cascata dos itens é garantida pela FOREIGN KEY do banco.
 * Atualiza o histórico do dia após a exclusão.
 */
function deleteRefeicao(PDO $db, int $id): void {
    // Salva antes de deletar para poder atualizar o histórico
    $r = $db->prepare("SELECT usuario_id,data_ref FROM refeicoes WHERE id=?");
    $r->execute([$id]);
    $row = $r->fetch();

    $db->prepare("DELETE FROM refeicoes WHERE id=?")->execute([$id]);

    if ($row) recalcHistorico($db, (int)$row['usuario_id'], $row['data_ref']);
    jsonSuccess('Refeição excluída');
}

/**
 * Adiciona um alimento a uma refeição existente.
 * Calcula os macros proporcionais à quantidade e atualiza os totais.
 */
function addItem(PDO $db, int $refId, array $body): void {
    requireFields($body, ['alimento_id', 'quantidade_g']);
    _insertItem($db, $refId, $body);
    recalcRefeicao($db, $refId);

    $r = $db->prepare("SELECT usuario_id,data_ref FROM refeicoes WHERE id=?");
    $r->execute([$refId]);
    $row = $r->fetch();
    if ($row) recalcHistorico($db, (int)$row['usuario_id'], $row['data_ref']);

    jsonSuccess('Item adicionado', ['refeicao_id' => $refId]);
}

/**
 * Função interna: insere um item na tabela refeicao_itens.
 * Busca os dados nutricionais do alimento e calcula os macros
 * proporcionalmente à quantidade informada.
 */
function _insertItem(PDO $db, int $refId, array $item): void {
    if (empty($item['alimento_id']) || empty($item['quantidade_g'])) {
        jsonError('alimento_id e quantidade_g são obrigatórios para cada item', 422);
    }

    $a = $db->prepare("SELECT * FROM alimentos WHERE id=? AND ativo=1");
    $a->execute([$item['alimento_id']]);
    $al = $a->fetch();

    if (!$al) {
        jsonError("Alimento ID {$item['alimento_id']} não encontrado ou inativo.", 404);
    }

    $macros = calcMacros($al, (float)$item['quantidade_g']);
    $db->prepare("
        INSERT INTO refeicao_itens (refeicao_id,alimento_id,quantidade_g,kcal,proteina_g,carb_g,gordura_g)
        VALUES (?,?,?,?,?,?,?)
    ")->execute([$refId, $item['alimento_id'], $item['quantidade_g'],
                 $macros['kcal'], $macros['proteina_g'], $macros['carb_g'], $macros['gordura_g']]);
}

/**
 * Atualiza a quantidade de um item e recalcula seus macros.
 */
function updateItem(PDO $db, int $refId, int $itemId, array $body): void {
    if (!isset($body['quantidade_g'])) jsonError('quantidade_g obrigatório', 422);

    $ai = $db->prepare("SELECT alimento_id FROM refeicao_itens WHERE id=? AND refeicao_id=?");
    $ai->execute([$itemId, $refId]);
    $item = $ai->fetch();
    if (!$item) jsonError('Item não encontrado', 404);

    $a = $db->prepare("SELECT * FROM alimentos WHERE id=?");
    $a->execute([$item['alimento_id']]);
    $macros = calcMacros($a->fetch(), (float)$body['quantidade_g']);

    $db->prepare("
        UPDATE refeicao_itens SET quantidade_g=?,kcal=?,proteina_g=?,carb_g=?,gordura_g=?
        WHERE id=? AND refeicao_id=?
    ")->execute([$body['quantidade_g'],$macros['kcal'],$macros['proteina_g'],
                 $macros['carb_g'],$macros['gordura_g'],$itemId,$refId]);

    recalcRefeicao($db, $refId);
    $r = $db->prepare("SELECT usuario_id,data_ref FROM refeicoes WHERE id=?");
    $r->execute([$refId]);
    $row = $r->fetch();
    if ($row) recalcHistorico($db, (int)$row['usuario_id'], $row['data_ref']);

    jsonSuccess('Item atualizado');
}

/**
 * Remove um alimento de uma refeição e recalcula os totais.
 */
function deleteItem(PDO $db, int $refId, int $itemId): void {
    $db->prepare("DELETE FROM refeicao_itens WHERE id=? AND refeicao_id=?")->execute([$itemId, $refId]);

    recalcRefeicao($db, $refId);
    $r = $db->prepare("SELECT usuario_id,data_ref FROM refeicoes WHERE id=?");
    $r->execute([$refId]);
    $row = $r->fetch();
    if ($row) recalcHistorico($db, (int)$row['usuario_id'], $row['data_ref']);

    jsonSuccess('Item removido');
}
