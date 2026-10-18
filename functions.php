<?php
/**
 * Общие функции MusicStream: сессии, безопасность (CSRF, XSS),
 * авторизация, загрузка файлов, шаблоны треков.
 */

require_once __DIR__ . '/../config/database.php';

// Часовой пояс сайта (при необходимости измените)
date_default_timezone_set('Europe/Moscow');

const REMEMBER_COOKIE = 'ms_remember';
const REMEMBER_DAYS   = 30;

/** Работает ли соединение по HTTPS (для флага secure у cookie) */
function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

// ---------- Сессия ----------
if (session_status() === PHP_SESSION_NONE) {
    session_name('MUSICSTREAM_SID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => BASE_URL === '' ? '/' : BASE_URL . '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => is_https(),
    ]);
    session_start();
}

// ---------- Базовые хелперы ----------

/** Экранирование вывода (защита от XSS) */
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Абсолютный (от корня сайта) URL внутри проекта */
function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

/** Редирект на внутренний путь и завершение скрипта */
function redirect(string $path): void
{
    if (!preg_match('#^https?://#i', $path)) {
        $path = url($path);
    }
    header('Location: ' . $path);
    exit;
}

/** Flash-сообщения (показываются один раз) */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function render_flash(): string
{
    if (empty($_SESSION['flash'])) {
        return '';
    }
    $html = '';
    foreach ($_SESSION['flash'] as $f) {
        $type = $f['type'] === 'success' ? 'success' : 'error';
        $html .= '<div class="alert alert-' . $type . '">' . e($f['message']) . '</div>';
    }
    unset($_SESSION['flash']);
    return $html;
}

// ---------- CSRF ----------

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

/** Проверка токена: POST-поле, заголовок X-CSRF-Token или GET-параметр csrf */
function csrf_valid(?string $token = null): bool
{
    if ($token === null) {
        $token = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_GET['csrf'] ?? '';
    }
    return is_string($token) && $token !== '' && hash_equals(csrf_token(), $token);
}

/** Для обычных форм: любой POST без верного токена отклоняется */
function require_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_valid()) {
        http_response_code(403);
        exit('Недействительный CSRF-токен. Вернитесь назад и обновите страницу.');
    }
}

// ---------- Пользователь / авторизация ----------

/** Текущий пользователь (массив из БД) или null. Поддерживает "запомнить меня". */
function current_user(bool $refresh = false): ?array
{
    static $user = false;
    if ($refresh) {
        $user = false;
    }
    if ($user !== false) {
        return $user;
    }
    $user = null;
    $uid = $_SESSION['user_id'] ?? null;

    // Автовход по cookie "запомнить меня"
    if (!$uid && !empty($_COOKIE[REMEMBER_COOKIE]) && is_string($_COOKIE[REMEMBER_COOKIE])) {
        $parts = explode(':', $_COOKIE[REMEMBER_COOKIE], 2);
        if (count($parts) === 2 && ctype_digit($parts[0]) && ctype_xdigit($parts[1])) {
            $st = db()->prepare('SELECT id, remember_token FROM users WHERE id = ? AND is_banned = 0');
            $st->execute([(int)$parts[0]]);
            $row = $st->fetch();
            if ($row && $row['remember_token'] && hash_equals($row['remember_token'], hash('sha256', $parts[1]))) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$row['id'];
                $uid = (int)$row['id'];
            } else {
                clear_remember_cookie();
            }
        }
    }

    if ($uid) {
        $st = db()->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([(int)$uid]);
        $row = $st->fetch();
        if ($row && !(int)$row['is_banned']) {
            $user = $row;
        } else {
            unset($_SESSION['user_id']);
            clear_remember_cookie();
        }
    }
    return $user;
}

function is_admin(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'admin';
}

function set_remember_cookie(int $userId, string $token): void
{
    setcookie(REMEMBER_COOKIE, $userId . ':' . $token, [
        'expires'  => time() + REMEMBER_DAYS * 86400,
        'path'     => BASE_URL === '' ? '/' : BASE_URL . '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => is_https(),
    ]);
}

function clear_remember_cookie(): void
{
    setcookie(REMEMBER_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => BASE_URL === '' ? '/' : BASE_URL . '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Вход пользователя; $remember — запомнить на 30 дней */
function login_user(array $user, bool $remember = false): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    if ($remember) {
        $token = bin2hex(random_bytes(32));
        db()->prepare('UPDATE users SET remember_token = ? WHERE id = ?')
            ->execute([hash('sha256', $token), $user['id']]);
        set_remember_cookie((int)$user['id'], $token);
    }
    current_user(true);
}

function logout_user(): void
{
    if (!empty($_SESSION['user_id'])) {
        db()->prepare('UPDATE users SET remember_token = NULL WHERE id = ?')->execute([$_SESSION['user_id']]);
    }
    clear_remember_cookie();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        flash('error', 'Войдите в аккаунт, чтобы продолжить.');
        redirect('login.php');
    }
    return $u;
}

/** Доступ только для role='admin'. Иначе — на форму входа админки. */
function require_admin(): array
{
    $u = current_user();
    if (!$u || $u['role'] !== 'admin') {
        redirect('admin/index.php');
    }
    return $u;
}

// ---------- Форматирование ----------

function format_duration($sec): string
{
    $sec = max(0, (int)$sec);
    if ($sec >= 3600) {
        return sprintf('%d:%02d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60), $sec % 60);
    }
    return sprintf('%d:%02d', intdiv($sec, 60), $sec % 60);
}

/** Цвет карточки жанра на основе названия */
function genre_color(string $name): string
{
    $h = hexdec(substr(md5(mb_strtolower($name)), 0, 6)) % 360;
    return 'hsl(' . $h . ', 55%, 38%)';
}

// ---------- Загрузка файлов ----------

function upload_dir(string $sub): string
{
    return ROOT_PATH . '/uploads/' . $sub;
}

function media_url(string $sub, string $file): string
{
    return url('uploads/' . $sub . '/' . rawurlencode($file));
}

function upload_error_text(int $code): string
{
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'Файл слишком большой (увеличьте upload_max_filesize и post_max_size в php.ini).';
        case UPLOAD_ERR_PARTIAL:
            return 'Файл загружен частично. Повторите попытку.';
        default:
            return 'Ошибка загрузки файла (код ' . $code . ').';
    }
}

/**
 * Безопасная загрузка файла.
 * $kind: 'image' (jpg/png/gif/webp, до 5 МБ) или 'audio' (mp3/ogg/wav/m4a, до 50 МБ).
 * Возвращает [имя_файла|null, ошибка|null]. Если файл не выбран — [null, null].
 */
function handle_upload(array $file, string $kind, string $subdir): array
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [null, upload_error_text((int)$file['error'])];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return [null, 'Некорректная загрузка файла.'];
    }

    if ($kind === 'image') {
        if ($file['size'] > 5 * 1024 * 1024) {
            return [null, 'Изображение больше 5 МБ.'];
        }
        $info = @getimagesize($file['tmp_name']);
        $map = [
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG  => 'png',
            IMAGETYPE_GIF  => 'gif',
            IMAGETYPE_WEBP => 'webp',
        ];
        if (!$info || !isset($map[$info[2]])) {
            return [null, 'Допустимы только изображения JPG, PNG, GIF или WEBP.'];
        }
        $ext = $map[$info[2]];
    } else {
        if ($file['size'] > 50 * 1024 * 1024) {
            return [null, 'Аудиофайл больше 50 МБ.'];
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['mp3', 'ogg', 'wav', 'm4a'], true)) {
            return [null, 'Допустимые форматы аудио: MP3, OGG, WAV, M4A.'];
        }
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $fi ? (string)finfo_file($fi, $file['tmp_name']) : '';
            if ($fi) {
                finfo_close($fi);
            }
            $okMimes = ['audio/mpeg', 'audio/mp3', 'audio/ogg', 'application/ogg', 'audio/wav', 'audio/x-wav',
                'audio/wave', 'audio/vnd.wave', 'audio/mp4', 'audio/x-m4a', 'video/mp4', 'audio/aac',
                'application/octet-stream'];
            if ($mime !== '' && !in_array($mime, $okMimes, true)) {
                return [null, 'Файл не похож на аудио (тип: ' . $mime . ').'];
            }
        }
    }

    $dir = upload_dir($subdir);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return [null, 'Не удалось создать папку uploads/' . $subdir . '.'];
    }
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        return [null, 'Не удалось сохранить файл. Проверьте права на папку uploads/.'];
    }
    @chmod($dir . '/' . $name, 0644);
    return [$name, null];
}

/** Удаление загруженного файла (только внутри uploads/<sub>) */
function delete_upload(string $sub, ?string $file): void
{
    if (!$file) {
        return;
    }
    $path = upload_dir($sub) . '/' . basename($file);
    if (is_file($path)) {
        @unlink($path);
    }
}

// ---------- Шаблонные хелперы ----------

/** Обложка: картинка или заглушка с нотой */
function cover_img(?string $file, string $class = 'cover'): string
{
    if ($file) {
        return '<img class="' . e($class) . '" src="' . e(media_url('covers', $file)) . '" alt="" loading="lazy">';
    }
    return '<div class="' . e($class) . ' cover-ph">♪</div>';
}

/** Аватар пользователя: картинка или буква */
function avatar_html(array $u, string $class = 'avatar'): string
{
    if (!empty($u['avatar'])) {
        return '<img class="' . e($class) . '" src="' . e(media_url('avatars', $u['avatar'])) . '" alt="">';
    }
    $ch = mb_strtoupper(mb_substr((string)$u['username'], 0, 1));
    return '<span class="' . e($class) . ' avatar-ph">' . e($ch) . '</span>';
}

/** data-атрибуты трека — по ним JS-плеер собирает очередь */
function track_attrs(array $t): string
{
    return 'data-track'
        . ' data-id="' . (int)$t['id'] . '"'
        . ' data-title="' . e($t['title']) . '"'
        . ' data-artist="' . e($t['artist']) . '"'
        . ' data-src="' . e(media_url('audio', $t['audio_file'])) . '"'
        . ' data-cover="' . e(!empty($t['cover']) ? media_url('covers', $t['cover']) : '') . '"'
        . ' data-duration="' . (int)$t['duration'] . '"'
        . ' data-liked="' . (!empty($t['liked']) ? 1 : 0) . '"';
}

/**
 * Список треков с признаком "лайкнут текущим пользователем".
 * $extra — SQL после "FROM tracks t" (JOIN / WHERE / ORDER / LIMIT), $params — для его "?".
 */
function tracks_query(string $extra = '', array $params = []): array
{
    $u = current_user();
    $uid = $u ? (int)$u['id'] : 0;
    $sql = 'SELECT t.*, EXISTS(SELECT 1 FROM likes l WHERE l.track_id = t.id AND l.user_id = ?) AS liked '
         . 'FROM tracks t ' . $extra;
    $st = db()->prepare($sql);
    $st->execute(array_merge([$uid], $params));
    return $st->fetchAll();
}

/**
 * Строка трека (список).
 * $o: index (номер), remove_from (id плейлиста — кнопка удаления),
 *     add_to (id плейлиста — кнопка "Добавить сюда"), already (уже в плейлисте).
 */
function render_track_row(array $t, array $o = []): string
{
    $liked = !empty($t['liked']);
    $h  = '<div class="track-row" ' . track_attrs($t) . '>';
    $h .= '<div class="row-num"><span class="num">' . (isset($o['index']) ? (int)$o['index'] : '') . '</span>'
        . '<button type="button" class="row-play" data-action="play-track" aria-label="Играть">'
        . '<span class="i-play">▶</span><span class="i-pause">❚❚</span></button></div>';
    $h .= '<div class="row-cover">' . cover_img($t['cover'] ?? null, 'cover') . '</div>';
    $h .= '<div class="row-main"><div class="row-title">' . e($t['title']) . '</div>'
        . '<div class="row-artist">' . e($t['artist']) . '</div></div>';
    $h .= '<div class="row-album">' . e($t['album']) . '</div>';
    $h .= '<div class="row-plays" title="Прослушиваний">' . number_format((int)$t['plays'], 0, '', ' ') . '</div>';
    $h .= '<div class="row-actions">';
    $h .= '<button type="button" class="icon-btn like-btn' . ($liked ? ' liked' : '') . '" data-action="like" '
        . 'aria-label="Нравится" title="Нравится">♥</button>';
    if (!empty($o['add_to'])) {
        if (!empty($o['already'])) {
            $h .= '<button type="button" class="btn btn-small" disabled>✓ В плейлисте</button>';
        } else {
            $h .= '<button type="button" class="btn btn-small btn-primary" data-action="add-here" '
                . 'data-playlist="' . (int)$o['add_to'] . '">Добавить</button>';
        }
    } else {
        $h .= '<button type="button" class="icon-btn" data-action="add-to-playlist" '
            . 'aria-label="В плейлист" title="Добавить в плейлист">＋</button>';
    }
    if (!empty($o['remove_from'])) {
        $h .= '<button type="button" class="icon-btn" data-action="remove-from-playlist" '
            . 'data-playlist="' . (int)$o['remove_from'] . '" aria-label="Убрать из плейлиста" '
            . 'title="Убрать из плейлиста">✕</button>';
    }
    $h .= '</div><div class="row-dur">' . format_duration($t['duration']) . '</div></div>';
    return $h;
}

/** Карточка трека (сетка) */
function render_track_card(array $t): string
{
    $h  = '<div class="card" ' . track_attrs($t) . '>';
    $h .= '<div class="card-cover">' . cover_img($t['cover'] ?? null, 'cover')
        . '<button type="button" class="card-play" data-action="play-track" aria-label="Играть">'
        . '<span class="i-play">▶</span><span class="i-pause">❚❚</span></button></div>';
    $h .= '<div class="card-title">' . e($t['title']) . '</div>';
    $h .= '<div class="card-sub">' . e($t['artist']) . '</div>';
    $h .= '</div>';
    return $h;
}

/** Постраничная навигация: возвращает [страница, всего_страниц, offset] */
function paginate(int $total, int $perPage, int $page): array
{
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min(max(1, $page), $pages);
    return [$page, $pages, ($page - 1) * $perPage];
}

function render_pagination(int $page, int $pages): string
{
    if ($pages <= 1) {
        return '';
    }
    $h = '<div class="pagination">';
    for ($i = 1; $i <= $pages; $i++) {
        $q = $_GET;
        $q['page'] = $i;
        $h .= '<a class="page-link' . ($i === $page ? ' active' : '') . '" href="?' . e(http_build_query($q)) . '">' . $i . '</a>';
    }
    return $h . '</div>';
}
