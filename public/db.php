<?php
/**
 * Подключение к PostgreSQL через DATABASE_URL из переменных окружения RelaxDev.
 */

function get_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $url = getenv('DATABASE_URL');
    if (!$url) {
        // Fallback для локальной разработки
        $url = 'postgresql://u_cmtvxgdm10:пароль@db-team-cmtvosk8100a5mx01j0y0dojxe:5432/db_ipg';
    }

    // RelaxDev отдаёт URL в формате postgresql://user:pass@host:port/db
    // PDO ожидает: pgsql:host=...;port=...;dbname=...;user=...;password=...
    $parts = parse_url($url);
    if ($parts === false) {
        throw new RuntimeException('Неверный формат DATABASE_URL');
    }

    $host = $parts['host'] ?? 'localhost';
    $port = $parts['port'] ?? 5432;
    $db   = ltrim($parts['path'] ?? '', '/');
    $user = $parts['user'] ?? '';
    $pass = $parts['pass'] ?? '';

    // SSL отключаем — база доступна только во внутренней сети RelaxDev
    $dsn = "pgsql:host={$host};port={$port};dbname={$db};sslmode=disable";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

/**
 * Старт сессии (для авторизации).
 */
function start_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * Текущий авторизованный пользователь или null.
 */
function current_user(): ?array {
    start_session();
    if (empty($_SESSION['user_id'])) return null;

    $stmt = get_db()->prepare('SELECT id, email, name, created_at FROM users WHERE id = :id');
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $u = $stmt->fetch();
    return $u ?: null;
}

/**
 * Экранирование HTML.
 */
function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
