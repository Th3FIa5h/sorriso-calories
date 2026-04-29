<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
// Permite apenas origens conhecidas
$allowedOrigins = [
    'http://localhost',
    'http://sorrisocalories.infinityfreeapp.com',
    'https://sorrisocalories.infinityfreeapp.com',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    header('Access-Control-Allow-Origin: https://sorrisocalories.infinityfreeapp.com');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-HTTP-Method-Override');

// Headers de segurança
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers.php';

// Analisa a URL — funciona tanto no Laragon quanto no InfinityFree
$uri = $_GET['_url'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = preg_replace('#^/sorriso-calories/api#', '', $uri);
$uri = preg_replace('#^/api#', '', $uri);
$uri = trim($uri, '/');
$parts = explode('/', $uri);

$resource = $parts[0] ?? '';
$id       = isset($parts[1]) && is_numeric($parts[1]) ? (int)$parts[1] : null;
$action   = isset($parts[1]) && !is_numeric($parts[1]) ? $parts[1] : null;
$sub      = $parts[2] ?? null;
$subId    = isset($parts[3]) && is_numeric($parts[3]) ? (int)$parts[3] : null;

// Lê o método HTTP real
// Suporta override via query string ?_method=DELETE (para InfinityFree)
// e via cabeçalho X-HTTP-Method-Override
// Lê o método — suporta override via _method na query string
$method = strtoupper($_GET['_method'] ?? $_SERVER['REQUEST_METHOD']);

// Força POST para qualquer método no InfinityFree
// O método real vem via _method na query string
if ($method !== 'GET' && $method !== 'POST' && $method !== 'OPTIONS') {
    $method = $_GET['_method'] ? strtoupper($_GET['_method']) : $method;
}
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

try {
    switch ($resource) {

        // Rota pública: não exige token
        case 'auth':
            require_once __DIR__ . '/routes/auth.php';
            routeAuth($method, $action, $body);
            break;

        // Rotas protegidas: exigem token válido
        case 'alimentos':
            require_once __DIR__ . '/routes/auth.php';
            require_once __DIR__ . '/routes/alimentos.php';
            autenticar(); // verifica o token antes de continuar
            routeAlimentos($method, $id, $body);
            break;

        case 'refeicoes':
            require_once __DIR__ . '/routes/auth.php';
            require_once __DIR__ . '/routes/refeicoes.php';
            autenticar();
            routeRefeicoes($method, $id, $sub, $subId, $body);
            break;

        case 'usuarios':
            require_once __DIR__ . '/routes/auth.php';
            require_once __DIR__ . '/routes/usuarios.php';
            autenticar();
            routeUsuarios($method, $id, $body);
            break;

        case 'dashboard':
            require_once __DIR__ . '/routes/auth.php';
            require_once __DIR__ . '/routes/dashboard.php';
            autenticar();
            routeDashboard();
            break;

        case 'historico':
            require_once __DIR__ . '/routes/auth.php';
            require_once __DIR__ . '/routes/historico.php';
            autenticar();
            routeHistorico();
            break;

        case '':
            jsonResponse(['status' => 'ok', 'version' => '2.0', 'app' => 'Sorriso Calories API']);
            break;

        case 'deletar-conta':
            require_once __DIR__ . '/routes/auth.php';
            require_once __DIR__ . '/routes/usuarios.php';
            $user = autenticar();
            deleteUsuario(Database::connect(), $user['id']);
            break;

        default:
            jsonError("Rota não encontrada: /$resource", 404);
}
} catch (Throwable $e) {
    // Loga o erro internamente mas não vaza detalhes para o cliente
    error_log('[Sorriso Calories] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    jsonError('Erro interno do servidor.', 500);
}