<?php
// =============================================================
//  ARQUIVO: api/helpers.php
//  FUNÇÃO:  Funções auxiliares reutilizadas por todas as rotas.
//
//  Centraliza comportamentos comuns:
//  - Enviar respostas JSON padronizadas
//  - Validar campos obrigatórios
//  - Calcular macronutrientes proporcionalmente à quantidade
//  - Recalcular totais de refeições após mudanças
//  - Atualizar o histórico calórico diário
//  - Calcular a meta calórica do usuário (Mifflin-St Jeor)
// =============================================================

/**
 * Envia uma resposta JSON com o código HTTP informado e encerra a execução.
 *
 * @param mixed $data  Dados a serializar em JSON
 * @param int   $code  Código HTTP (padrão 200 OK)
 */
function jsonResponse(mixed $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data,
        JSON_UNESCAPED_UNICODE |  // mantém acentos legíveis: "ação" não vira "\u00e7\u00e3o"
        JSON_UNESCAPED_SLASHES |  // mantém barras: "a/b" não vira "a\/b"
        JSON_NUMERIC_CHECK        // converte strings numéricas: "3.14" vira 3.14
    );
    exit;
}

/**
 * Atalho para enviar uma resposta de erro JSON e encerrar.
 *
 * @param string $msg   Mensagem de erro descritiva
 * @param int    $code  Código HTTP (padrão 400 Bad Request)
 */
function jsonError(string $msg, int $code = 400): void {
    jsonResponse(['error' => $msg], $code);
}

/**
 * Atalho para enviar uma resposta de sucesso JSON e encerrar.
 *
 * @param string $msg    Mensagem de sucesso
 * @param array  $extra  Dados adicionais a incluir na resposta
 */
function jsonSuccess(string $msg, array $extra = []): void {
    jsonResponse(array_merge(['success' => true, 'message' => $msg], $extra));
}

/**
 * Valida que todos os campos obrigatórios estão presentes no corpo da requisição.
 * Retorna erro 422 e encerra se algum campo estiver ausente ou vazio.
 *
 * @param array $body    Array decodificado do JSON da requisição
 * @param array $fields  Lista de nomes de campos obrigatórios
 */
function requireFields(array $body, array $fields): void {
    foreach ($fields as $f) {
        if (!isset($body[$f]) || $body[$f] === '') {
            jsonError("Campo obrigatório ausente: $f", 422);
        }
    }
}

/**
 * Calcula os macronutrientes de um alimento para uma quantidade específica.
 *
 * Os valores nutricionais do banco estão referenciados à porção padrão
 * do alimento (ex: 100g). Esta função aplica uma regra de três para
 * converter para a quantidade real informada pelo usuário.
 *
 * Exemplo: frango tem 159 kcal por 100g. Para 150g: 159 × (150/100) = 238,5 kcal
 *
 * @param array $alimento  Linha da tabela alimentos com dados nutricionais
 * @param float $qtdG      Quantidade em gramas informada pelo usuário
 * @return array           Array com kcal, proteina_g, carb_g e gordura_g calculados
 */
function calcMacros(array $alimento, float $qtdG): array {
    $base = max(1, (float)$alimento['porcao_g']); // porção de referência (nunca zero)
    $m    = $qtdG / $base;                         // fator de proporção

    return [
        'kcal'       => round($alimento['kcal']       * $m, 2),
        'proteina_g' => round($alimento['proteina_g'] * $m, 2),
        'carb_g'     => round($alimento['carb_g']     * $m, 2),
        'gordura_g'  => round($alimento['gordura_g']  * $m, 2),
    ];
}

/**
 * Recalcula e atualiza os totais de macronutrientes de uma refeição.
 *
 * Deve ser chamada sempre que um item é adicionado, editado ou removido.
 * Soma todos os itens da refeição e salva os totais na tabela `refeicoes`.
 *
 * @param PDO $db     Conexão com o banco de dados
 * @param int $refId  ID da refeição a recalcular
 */
function recalcRefeicao(PDO $db, int $refId): void {
    // Soma os macros de todos os itens que pertencem à refeição
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(kcal),0)       AS kcal,
               COALESCE(SUM(proteina_g),0) AS prot,
               COALESCE(SUM(carb_g),0)     AS carb,
               COALESCE(SUM(gordura_g),0)  AS fat
        FROM refeicao_itens WHERE refeicao_id = ?
    ");
    $stmt->execute([$refId]);
    $t = $stmt->fetch();

    // Salva os totais calculados na linha da refeição
    $db->prepare("
        UPDATE refeicoes
        SET kcal_total=?, proteina_g=?, carb_g=?, gordura_g=?
        WHERE id=?
    ")->execute([$t['kcal'], $t['prot'], $t['carb'], $t['fat'], $refId]);
}

/**
 * Recalcula e salva o histórico calórico diário do usuário.
 *
 * Deve ser chamada após qualquer alteração em refeições do dia.
 * Soma todas as refeições do dia e usa INSERT ... ON DUPLICATE KEY UPDATE
 * para criar ou atualizar o registro do dia na tabela `historico_diario`.
 *
 * @param PDO    $db    Conexão com o banco de dados
 * @param int    $uid   ID do usuário
 * @param string $data  Data no formato 'Y-m-d'
 */
function recalcHistorico(PDO $db, int $uid, string $data): void {
    // Soma os macros de todas as refeições do usuário no dia informado
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(kcal_total),0) AS kcal,
               COALESCE(SUM(proteina_g),0) AS prot,
               COALESCE(SUM(carb_g),0)     AS carb,
               COALESCE(SUM(gordura_g),0)  AS fat
        FROM refeicoes WHERE usuario_id=? AND data_ref=?
    ");
    $stmt->execute([$uid, $data]);
    $t = $stmt->fetch();

    // Busca a meta calórica atual do usuário para salvar junto
    $meta = $db->prepare("SELECT meta_kcal FROM usuarios WHERE id=?");
    $meta->execute([$uid]);
    $metaKcal = (int)($meta->fetchColumn() ?: 0);

    // Insere ou atualiza o registro do dia (ON DUPLICATE KEY trata os dois casos)
    $db->prepare("
        INSERT INTO historico_diario
            (usuario_id, data, kcal_total, kcal_meta, proteina_g, carb_g, gordura_g)
        VALUES (?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            kcal_total=VALUES(kcal_total), kcal_meta=VALUES(kcal_meta),
            proteina_g=VALUES(proteina_g), carb_g=VALUES(carb_g),
            gordura_g=VALUES(gordura_g),   atualizado_em=NOW()
    ")->execute([$uid, $data, $t['kcal'], $metaKcal, $t['prot'], $t['carb'], $t['fat']]);
}

/**
 * Calcula a meta calórica diária usando a fórmula Mifflin-St Jeor.
 *
 * PASSO 1 — Calcula o TMB (Taxa Metabólica Basal):
 *   calorias mínimas para manter as funções vitais em repouso total.
 *   Homem: TMB = 10×peso + 6,25×altura − 5×idade + 5
 *   Mulher: TMB = 10×peso + 6,25×altura − 5×idade − 161
 *
 * PASSO 2 — Calcula o TDEE (gasto total com atividade física):
 *   TDEE = TMB × fator de atividade (entre 1.2 e 1.9)
 *
 * PASSO 3 — Ajusta conforme o objetivo:
 *   Perda de peso  → TDEE − 400 kcal (déficit calórico)
 *   Manutenção     → TDEE (equilíbrio)
 *   Ganho de massa → TDEE + 300 kcal (superávit calórico)
 *
 * @param array $u  Dados do usuário
 * @return int      Meta calórica diária em kcal
 */
function calcMetaKcal(array $u): int {
    $w    = (float)($u['peso_atual']  ?? 70);
    $h    = (float)($u['altura_cm']   ?? 170);
    
    // Aplica limites mínimos e máximos
    $w = max(20, min(300, $w > 0 ? $w : 70));
	$h = max(100, min(300, $h > 0 ? $h : 170));
    
    $nasc = $u['data_nasc']  ?? null;
    $gen  = $u['genero']     ?? 'F';
    $ativ = $u['nivel_ativ'] ?? 'light';
    $obj  = $u['objetivo']   ?? 'loss';

    // Calcula a idade a partir da data de nascimento
    $age = $nasc ? (int)date_diff(new DateTime($nasc), new DateTime())->y : 30;

    // Passo 1: TMB diferenciado por gênero
    $bmr = $gen === 'M'
        ? 10 * $w + 6.25 * $h - 5 * $age + 5     // masculino
        : 10 * $w + 6.25 * $h - 5 * $age - 161;  // feminino

    // Passo 2: multiplica pelo fator de atividade para obter o TDEE
    $factors = [
        'sedentary' => 1.2,    // sem exercício
        'light'     => 1.375,  // 1 a 3 dias por semana
        'moderate'  => 1.55,   // 3 a 5 dias por semana
        'active'    => 1.725,  // 6 a 7 dias por semana
        'extra'     => 1.9,    // atleta ou trabalho físico intenso
    ];
    $tdee = $bmr * ($factors[$ativ] ?? 1.375);

    // Passo 3: ajusta conforme o objetivo
    return (int)round(match($obj) {
        'gain'     => $tdee + 300,  // superávit para ganho de massa
        'maintain' => $tdee,        // equilíbrio para manutenção
        default    => $tdee - 400,  // déficit para perda de peso
    });
}
