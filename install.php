<?php
/**
 * Установщик MusicStream.
 * Создаёт БД и таблицы, администратора admin / admin123, папки uploads/ и 3 демо-трека.
 * Скрипт безопасно запускать повторно: существующие данные не затираются.
 * ПОСЛЕ УСТАНОВКИ УДАЛИТЕ ЭТОТ ФАЙЛ С СЕРВЕРА.
 */
require_once __DIR__ . '/config/database.php';

$log = [];
$ok = true;
$say = function (string $msg, bool $good = true) use (&$log) {
    $log[] = [$good, $msg];
};

/** Генерация WAV-файла из мелодии [[частота_Гц, длительность_в_долях], ...]. Возвращает длительность в секундах. */
function make_wav(string $file, array $melody, int $bpm, int $rate = 11025): int
{
    $beat = 60 / $bpm;
    $pcm = '';
    foreach ($melody as [$freq, $beats]) {
        $n = (int)round($beats * $beat * $rate);
        $buf = [];
        for ($i = 0; $i < $n; $i++) {
            if ($freq <= 0) {
                $v = 0.0;
            } else {
                $t   = $i / $rate;
                $att = min(1, $i / ($rate * 0.01));
                $rel = min(1, ($n - $i) / ($rate * 0.08));
                $env = $att * $rel * exp(-1.1 * $t);
                $v = $env * (sin(2 * M_PI * $freq * $t) * 0.7
                    + sin(4 * M_PI * $freq * $t) * 0.2
                    + sin(6 * M_PI * $freq * $t) * 0.1);
            }
            $buf[] = ((int)round($v * 0.6 * 32767)) & 0xFFFF;
        }
        foreach (array_chunk($buf, 2048) as $chunk) {
            $pcm .= pack('v*', ...$chunk);
        }
    }
    $len = strlen($pcm);
    $header = 'RIFF' . pack('V', 36 + $len) . 'WAVE' . 'fmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16)
        . 'data' . pack('V', $len);
    file_put_contents($file, $header . $pcm);
    return (int)round($len / ($rate * 2));
}

/** Арпеджио по аккордам: каждая группа нот проигрывается вверх и вниз */
function arpeggio(array $chords, float $beatsEach): array
{
    $m = [];
    foreach ($chords as $c) {
        foreach ([0, 1, 2, 3, 2, 1] as $i) {
            $m[] = [$c[$i], $beatsEach];
        }
    }
    return $m;
}

/** Квадратная SVG-обложка с градиентом */
function make_cover(string $file, string $title, string $c1, string $c2): void
{
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $svg = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="400" viewBox="0 0 400 400">'
        . '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
        . '<stop offset="0" stop-color="' . $c1 . '"/><stop offset="1" stop-color="' . $c2 . '"/></linearGradient></defs>'
        . '<rect width="400" height="400" fill="url(#g)"/>'
        . '<circle cx="200" cy="180" r="90" fill="none" stroke="rgba(255,255,255,.35)" stroke-width="10"/>'
        . '<circle cx="200" cy="180" r="28" fill="rgba(255,255,255,.55)"/>'
        . '<text x="200" y="340" text-anchor="middle" font-family="Arial,sans-serif" font-size="32" font-weight="700" fill="#fff">' . $t . '</text>'
        . '</svg>';
    file_put_contents($file, $svg);
}

try {
    // 1. Подключение к серверу MySQL (без выбора БД)
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $say('Подключение к MySQL установлено.');

    // 2. База данных
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `' . DB_NAME . '`');
    $say('База данных «' . DB_NAME . '» готова.');

    // 3. Таблицы (порядок важен из-за внешних ключей)
    $tables = [
        'users' => "CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL,
            email VARCHAR(190) NOT NULL,
            password VARCHAR(255) NOT NULL,
            role ENUM('user','admin') NOT NULL DEFAULT 'user',
            avatar VARCHAR(255) DEFAULT NULL,
            is_banned TINYINT(1) NOT NULL DEFAULT 0,
            remember_token CHAR(64) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_users_username (username),
            UNIQUE KEY uq_users_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'tracks' => "CREATE TABLE IF NOT EXISTS tracks (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            artist VARCHAR(255) NOT NULL,
            album VARCHAR(255) NOT NULL DEFAULT '',
            genre VARCHAR(100) NOT NULL DEFAULT '',
            duration INT UNSIGNED NOT NULL DEFAULT 0,
            audio_file VARCHAR(255) NOT NULL,
            cover VARCHAR(255) DEFAULT NULL,
            plays INT UNSIGNED NOT NULL DEFAULT 0,
            uploader_id INT UNSIGNED DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_tracks_genre (genre),
            KEY idx_tracks_plays (plays),
            KEY idx_tracks_created (created_at),
            CONSTRAINT fk_tracks_uploader FOREIGN KEY (uploader_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'playlists' => "CREATE TABLE IF NOT EXISTS playlists (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            description VARCHAR(500) NOT NULL DEFAULT '',
            cover VARCHAR(255) DEFAULT NULL,
            user_id INT UNSIGNED NOT NULL,
            is_public TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_playlists_user (user_id),
            CONSTRAINT fk_playlists_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'playlist_tracks' => "CREATE TABLE IF NOT EXISTS playlist_tracks (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            playlist_id INT UNSIGNED NOT NULL,
            track_id INT UNSIGNED NOT NULL,
            position INT UNSIGNED NOT NULL DEFAULT 0,
            UNIQUE KEY uq_playlist_track (playlist_id, track_id),
            KEY idx_pt_track (track_id),
            CONSTRAINT fk_pt_playlist FOREIGN KEY (playlist_id) REFERENCES playlists(id) ON DELETE CASCADE,
            CONSTRAINT fk_pt_track FOREIGN KEY (track_id) REFERENCES tracks(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'likes' => "CREATE TABLE IF NOT EXISTS likes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            track_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_likes_user_track (user_id, track_id),
            KEY idx_likes_track (track_id),
            CONSTRAINT fk_likes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_likes_track FOREIGN KEY (track_id) REFERENCES tracks(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($tables as $name => $sql) {
        $pdo->exec($sql);
        $say('Таблица «' . $name . '» готова.');
    }

    // 4. Папки загрузок
    foreach (['audio', 'covers', 'avatars'] as $sub) {
        $dir = __DIR__ . '/uploads/' . $sub;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            $say('Не удалось создать папку uploads/' . $sub . ' — создайте её вручную.', false);
            $ok = false;
        }
    }
    $say('Папки uploads/audio, uploads/covers, uploads/avatars проверены.');

    // 5. Администратор admin / admin123 (пароль хранится как password_hash)
    $st = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $st->execute(['admin']);
    $adminId = $st->fetchColumn();
    if (!$adminId) {
        $ins = $pdo->prepare('INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)');
        $ins->execute(['admin', 'admin@musicstream.local', password_hash('admin123', PASSWORD_DEFAULT), 'admin']);
        $adminId = (int)$pdo->lastInsertId();
        $say('Создан администратор: логин admin, пароль admin123.');
    } else {
        $say('Пользователь admin уже существует — пароль не изменён.');
    }

    // 6. Демо-треки (только если таблица пуста)
    $count = (int)$pdo->query('SELECT COUNT(*) FROM tracks')->fetchColumn();
    if ($count === 0) {
        $C4 = 261.63; $D4 = 293.66; $E4 = 329.63; $F4 = 349.23; $G4 = 392.00; $A4 = 440.00; $B4 = 493.88;
        $C5 = 523.25; $E5 = 659.25; $G5 = 783.99;
        $F3 = 174.61; $G3 = 196.00; $A3 = 220.00; $B3 = 246.94; $A2 = 110.00; $E3 = 164.81; $C3 = 130.81;

        $demo = [
            [
                'title' => 'Sunrise Arpeggio', 'artist' => 'Demo Artist', 'album' => 'Demo Tape', 'genre' => 'Electronic',
                'file' => 'demo1.wav', 'cover' => 'demo1.svg', 'c1' => '#1DB954', 'c2' => '#0b4d2a', 'bpm' => 110,
                'melody' => arpeggio([[$C4, $E4, $G4, $C5], [$A3, $C4, $E4, $A4], [$F3, $A3, $C4, $F4], [$G3, $B3, $D4, $G4]], 0.5),
            ],
            [
                'title' => 'Night Drive', 'artist' => 'Synth Ghost', 'album' => 'Neon City', 'genre' => 'Synthwave',
                'file' => 'demo2.wav', 'cover' => 'demo2.svg', 'c1' => '#8e2de2', 'c2' => '#1a1a4e', 'bpm' => 90,
                'melody' => array_merge(
                    [[$A2, 1], [$A2, 1], [$E3, 1], [$C3, 1], [$A2, 1], [$A2, 1], [$E3, 1], [$G3, 1]],
                    [[$A4, 2], [$G4, 1], [$E4, 1], [$C5, 2], [$B4, 1], [$G4, 1]],
                    [[$A2, 1], [$A2, 1], [$E3, 1], [$C3, 1]]
                ),
            ],
            [
                'title' => 'Lazy Sunday', 'artist' => 'Chill Collective', 'album' => 'Slow Mornings', 'genre' => 'Chill',
                'file' => 'demo3.wav', 'cover' => 'demo3.svg', 'c1' => '#f7971e', 'c2' => '#7a2e00', 'bpm' => 72,
                'melody' => [
                    [$C5, 1], [$E5, 1], [$G5, 1], [$E5, 1], [$D4 * 2, 1], [$F4 * 2, 1], [$A4 * 2, 1], [$F4 * 2, 1],
                    [$E5, 1], [$G5, 1], [$C5 * 2, 2], [$G5, 1], [$E5, 1], [$C5, 2], [0, 1],
                ],
            ],
        ];

        $ins = $pdo->prepare(
            'INSERT INTO tracks (title, artist, album, genre, duration, audio_file, cover, plays, uploader_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $fakePlays = [128, 342, 87];
        foreach ($demo as $i => $d) {
            $duration = make_wav(__DIR__ . '/uploads/audio/' . $d['file'], $d['melody'], $d['bpm']);
            make_cover(__DIR__ . '/uploads/covers/' . $d['cover'], $d['title'], $d['c1'], $d['c2']);
            $ins->execute([$d['title'], $d['artist'], $d['album'], $d['genre'], $duration, $d['file'], $d['cover'], $fakePlays[$i], $adminId]);
        }
        $say('Добавлено 3 демо-трека (WAV и SVG-обложки сгенерированы автоматически).');
    } else {
        $say('В таблице треков уже есть данные — демо-треки не добавлялись.');
    }
} catch (Throwable $e) {
    $ok = false;
    $say('Ошибка: ' . $e->getMessage(), false);
    $say('Проверьте, что MySQL запущен, и настройки в config/database.php.', false);
}

$home = (defined('BASE_URL') ? BASE_URL : '') . '/index.php';
$admin = (defined('BASE_URL') ? BASE_URL : '') . '/admin/index.php';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Установка MusicStream</title>
    <style>
        body { background:#121212; color:#fff; font-family:system-ui,Arial,sans-serif; margin:0; padding:40px 16px; }
        .box { max-width:640px; margin:0 auto; background:#181818; border-radius:12px; padding:28px; }
        h1 { margin-top:0; }
        ul { list-style:none; padding:0; }
        li { padding:6px 0; border-bottom:1px solid #282828; }
        .good::before { content:"✓ "; color:#1DB954; font-weight:700; }
        .bad::before { content:"✗ "; color:#ff5c5c; font-weight:700; }
        a.btn { display:inline-block; margin:12px 8px 0 0; padding:12px 24px; border-radius:30px; background:#1DB954; color:#000; font-weight:700; text-decoration:none; }
        a.btn.alt { background:#282828; color:#fff; }
        .warn { background:#3a2e12; color:#ffd36b; padding:12px 14px; border-radius:8px; margin-top:16px; }
    </style>
</head>
<body>
<div class="box">
    <h1>♫ Установка MusicStream</h1>
    <ul>
        <?php foreach ($log as [$good, $msg]): ?>
            <li class="<?= $good ? 'good' : 'bad' ?>"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
    </ul>
    <?php if ($ok): ?>
        <p><strong>Готово!</strong> Админ-панель: логин <code>admin</code>, пароль <code>admin123</code>.</p>
        <a class="btn" href="<?= htmlspecialchars($home, ENT_QUOTES, 'UTF-8') ?>">Перейти на главную</a>
        <a class="btn alt" href="<?= htmlspecialchars($admin, ENT_QUOTES, 'UTF-8') ?>">Админ-панель</a>
        <div class="warn">⚠ Удалите файл <code>install.php</code> с сервера и смените пароль администратора на странице «Профиль».</div>
    <?php else: ?>
        <p>Установка завершилась с ошибкой. Исправьте проблему и обновите страницу.</p>
    <?php endif; ?>
</div>
</body>
</html>
