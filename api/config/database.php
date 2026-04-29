<?php
// Carrega .env.local se existir (ambiente local)
$envFile = __DIR__ . '/../../.env.local';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            [$key, $value] = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
}

// Em produção as variáveis são definidas direto no servidor
// NUNCA coloque credenciais reais aqui

define('DB_HOST',    $_ENV['DB_HOST']    ?? 'localhost');
define('DB_PORT',    $_ENV['DB_PORT']    ?? '3306');
define('DB_NAME',    $_ENV['DB_NAME']    ?? 'sorriso_calories');
define('DB_USER',    $_ENV['DB_USER']    ?? 'root');
define('DB_PASS',    $_ENV['DB_PASS']    ?? 'root');
define('DB_CHARSET', 'utf8mb4');

// Detecta produção para uso em outros arquivos
$isProducao = isset($_SERVER['HTTP_HOST']) &&
              str_contains($_SERVER['HTTP_HOST'], 'infinityfreeapp.com');

class Database {
    private static ?PDO $instance = null;

    public static function connect(): PDO {
        if (self::$instance !== null) return self::$instance;

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );

        try {
            self::$instance = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            // Em produção não vaza detalhes do erro
            die(json_encode(['error' => 'Falha na conexão com o banco de dados.']));
        }

        return self::$instance;
    }
}