<?php
// =============================================================
//  ARQUIVO: api/routes/historico.php
//  FUNÇÃO:  Retorna o histórico calórico de um usuário por período.
//
//  Rota atendida:
//    GET /api/historico?usuario_id=1&dias=30
//
//  Parâmetros:
//    usuario_id → ID do usuário
//    dias       → período em dias (padrão: 30 | mínimo: 7 | máximo: 90)
//
//  Retorna cada dia do período com totais calóricos, percentual
//  da meta atingido e streak atual de dias consecutivos.
// =============================================================

/**
 * Retorna o histórico calórico de um usuário em um período.
 */
function routeHistorico(): void {
    $db     = Database::connect();
    $user = autenticar();
    $uid  = (int)($_GET['usuario_id'] ?? 0);
    $dias   = min(90, max(7, (int)($_GET['dias'] ?? 30)));
    $inicio = date('Y-m-d', strtotime("-{$dias} days"));

    // Garante que só acessa o próprio histórico
    if ($uid && $uid !== $user['id']) {
        jsonError('Acesso negado', 403);
    }

    $uid = $user['id']; // usa sempre o ID do token

    // Busca o histórico do período com percentual da meta atingido por dia
    // NULLIF evita divisão por zero quando meta_kcal for 0
    $stmt = $db->prepare("
        SELECT h.*,
               u.meta_kcal,
               ROUND(h.kcal_total / NULLIF(u.meta_kcal, 0) * 100, 1) AS pct_meta
        FROM historico_diario h
        JOIN usuarios u ON u.id = h.usuario_id
        WHERE h.usuario_id=? AND h.data>=?
        ORDER BY h.data ASC
    ");
    $stmt->execute([$uid, $inicio]);
    $rows = $stmt->fetchAll();

    // Busca os últimos 365 dias especificamente para calcular o streak
    // independente do período solicitado pelo usuário
    $streakStmt = $db->prepare(
        "SELECT data FROM historico_diario WHERE usuario_id=? ORDER BY data DESC LIMIT 365"
    );
    $streakStmt->execute([$uid]);
    $datasStreak = array_column($streakStmt->fetchAll(), 'data');
    $streak = 0;
    foreach ($datasStreak as $i => $d) {
        if ($d === date('Y-m-d', strtotime("-{$i} days"))) $streak++;
        else break;
    }

    jsonResponse([
        'historico' => $rows,
        'streak'    => $streak,
        'periodo'   => [
            'inicio' => $inicio,
            'fim'    => date('Y-m-d'),
            'dias'   => $dias,
        ],
    ]);
}
