<?php
// =============================================================
//  ARQUIVO: api/routes/dashboard.php
//  FUNÇÃO:  Fornece todos os dados do dashboard em uma requisição.
//
//  Rota atendida:
//    GET /api/dashboard?usuario_id=1
//
//  Retorna:
//    - Dados do usuário (nome, meta, objetivo)
//    - Totais calóricos do dia (kcal, macros, % da meta, saldo)
//    - Lista de refeições do dia com contagem de itens
//    - 4 sugestões de alimentos baseadas no saldo calórico restante
//    - Streak: quantidade de dias consecutivos com registro
// =============================================================

/**
 * Monta e retorna o resumo completo do dia para o dashboard.
 */
function routeDashboard(): void {
    $db    = Database::connect();
    $user = autenticar();
    $uid  = (int)($_GET['usuario_id'] ?? 0);

    // Garante que só acessa o próprio dashboard
    if ($uid && $uid !== $user['id']) {
        jsonError('Acesso negado', 403);
    }

    $uid   = $user['id']; // usa sempre o ID do token
    $today = date('Y-m-d');

    // Busca os dados do usuário
    $uStmt = $db->prepare("SELECT * FROM usuarios WHERE id=?");
    $uStmt->execute([$uid]);
    $user = $uStmt->fetch();
    if (!$user) jsonError('Usuário não encontrado', 404);

    // Busca as refeições do dia com contagem de itens
    $rStmt = $db->prepare("
        SELECT r.*, COUNT(ri.id) AS num_itens
        FROM refeicoes r
        LEFT JOIN refeicao_itens ri ON ri.refeicao_id=r.id
        WHERE r.usuario_id=? AND r.data_ref=?
        GROUP BY r.id ORDER BY r.hora_ref ASC
    ");
    $rStmt->execute([$uid, $today]);
    $refeicoes = $rStmt->fetchAll();

    // Soma os macros de todas as refeições do dia
    $tStmt = $db->prepare("
        SELECT COALESCE(SUM(kcal_total),0) AS kcal,
               COALESCE(SUM(proteina_g),0) AS prot,
               COALESCE(SUM(carb_g),0)     AS carb,
               COALESCE(SUM(gordura_g),0)  AS fat
        FROM refeicoes WHERE usuario_id=? AND data_ref=?
    ");
    $tStmt->execute([$uid, $today]);
    $totais = $tStmt->fetch();

    // Saldo real — pode ser negativo se ultrapassou a meta
$saldo = ($user['meta_kcal'] ?? 1800) - $totais['kcal'];

// Só busca sugestões se ainda tem saldo disponível (mais de 50 kcal restantes)
$sugestoes = [];
if ($saldo > 50) {
    $sStmt = $db->prepare("
        SELECT id, nome, emoji, kcal, proteina_g, carb_g, gordura_g, porcao_g
        FROM alimentos WHERE ativo=1 AND kcal BETWEEN ? AND ?
        ORDER BY RAND() LIMIT 4
    ");
    $sStmt->execute([$saldo * 0.12, $saldo * 0.45]);
    $sugestoes = $sStmt->fetchAll();
}

    // Streak: dias consecutivos com pelo menos uma refeição registrada
    $dStmt = $db->prepare(
        "SELECT data FROM historico_diario WHERE usuario_id=? ORDER BY data DESC LIMIT 365"
    );
    $dStmt->execute([$uid]);
    $datas  = array_column($dStmt->fetchAll(), 'data');
    $streak = 0;
    foreach ($datas as $i => $d) {
        // Verifica se cada data é exatamente $i dias atrás (sequência contínua)
        if ($d === date('Y-m-d', strtotime("-{$i} days"))) $streak++;
        else break; // quebrou a sequência, para de contar
    }

    $meta = (int)($user['meta_kcal'] ?? 1800);

    jsonResponse([
        'usuario'     => $user,
        'hoje'        => [
            'data'       => $today,
            'kcal'       => round($totais['kcal'], 1),
            'proteina_g' => round($totais['prot'],  1),
            'carb_g'     => round($totais['carb'],  1),
            'gordura_g'  => round($totais['fat'],   1),
            'meta_kcal'  => $meta,
            'pct_meta'   => $meta > 0 ? round($totais['kcal'] / $meta * 100, 1) : 0,
            'saldo_kcal' => round($meta - $totais['kcal'], 1),
        ],
        'refeicoes'   => $refeicoes,
        'sugestoes'   => $sugestoes,
        'streak_dias' => $streak,
    ]);
}
