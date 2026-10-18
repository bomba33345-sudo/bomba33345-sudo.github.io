<?php
/**
 * Настройки подключения к базе данных и базовые константы проекта.
 * Измените DB_USER / DB_PASS под свой сервер (в XAMPP по умолчанию root без пароля).
 */
define('DB_HOST', 'localhost');
define('DB_NAME', 'musicstream');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Абсолютный путь к корню проекта на диске
define('ROOT_PATH', realpath(__DIR__ . '/..'));

/**
 * BASE_URL определяется автоматически (например "/musicstream").
 * Если сайт лежит в корне домена, значение будет пустой строкой.
 * Чтобы задать вручную, определите define('BASE_URL', '/musicstream'); ДО подключения этого файла.
 */
if (!defined('BASE_URL')) {
    $docRoot = !empty($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    $base = '';
    if ($docRoot && strpos(ROOT_PATH, $docRoot) === 0) {
        $base = str_replace('\\', '/', substr(ROOT_PATH, strlen($docRoot)));
    }
    define('BASE_URL', rtrim($base, '/'));
}

/**
 * Возвращает единое PDO-подключение (всегда prepared statements, без эмуляции).
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            exit('Не удалось подключиться к базе данных. Проверьте config/database.php и запустите install.php.');
        }
    }
    return $pdo;
}
