<?php
/**
 * JSON API MusicStream.
 *
 * Эндпоинты (параметры можно передавать через GET или POST):
 *   ?action=like&track_id=X                         — переключить лайк
 *   ?action=play&track_id=X                         — +1 к прослушиваниям
 *   ?action=search&q=...[&genre=...][&playlist_id=] — поиск (в ответе есть готовый html)
 *   ?action=add_to_playlist&playlist_id=X&track_id=Y
 *   ?action=remove_from_playlist&playlist_id=X&track_id=Y
 *   ?action=my_playlists[&track_id=Y]               — плейлисты пользователя
 *   ?action=create_playlist&name=...[&track_id=Y]   — создать плейлист
 *
 * Изменяющие действия требуют сессию и CSRF-токен (заголовок X-CSRF-Token или параметр csrf).
 */
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/** Отправить JSON и завершить работу */
function out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$action = (string)($_REQUEST['action'] ?? '');
$user   = current_user();
$uid    = $user ? (int)$user['id'] : 0;

$needAuth = ['like', 'add_to_playlist', 'remove_from_playlist', 'create_playlist', 'my_playlists'];
$needCsrf = ['like', 'play', 'add_to_playlist', 'remove_from_playlist', 'create_playlist'];

if (in_array($action, $needAuth, true) && !$user) {
    out(['ok' => false, 'error' => 'auth_required', 'message' => 'Войдите в аккаунт.'], 401);
}
if (in_array($action, $needCsrf, true) && !csrf_valid()) {
    out(['ok' => false, 'error' => 'csrf', 'message' => 'Недействительный токен. Обновите страницу.'], 403);
}

/** Проверка существования трека */
function track_exists(int $id): bool
{
    $st = db()->prepare('SELECT 1 FROM tracks WHERE id = ?');
    $st->execute([$id]);
    return (bool)$st->fetchColumn();
}

/** Плейлист пользователя или null */
function own_playlist(int $pid, int $uid): ?array
{
    $st = db()->prepare('SELECT * FROM playlists WHERE id = ? AND user_id = ?');
    $st->execute([$pid, $uid]);
    $row = $st->fetch();
    return $row ?: null;
}

/** Добавить трек в плейлист; true — добавлен, false — уже был */
function add_track_to_playlist(int $pid, int $tid): bool
{
    $st = db()->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM playlist_tracks WHERE playlist_id = ?');
    $st->execute([$pid]);
    $pos = (int)$st->fetchColumn();
    $ins = db()->prepare('INSERT IGNORE INTO playlist_tracks (playlist_id, track_id, position) VALUES (?, ?, ?)');
    $ins->execute([$pid, $tid, $pos]);
    return $ins->rowCount() > 0;
}

$tid = (int)($_REQUEST['track_id'] ?? 0);
$pid = (int)($_REQUEST['playlist_id'] ?? 0);

try {
    switch ($action) {

        // ---------- Лайк (переключатель) ----------
        case 'like':
            if (!track_exists($tid)) {
                out(['ok' => false, 'error' => 'not_found', 'message' => 'Трек не найден.'], 404);
            }
            $del = db()->prepare('DELETE FROM likes WHERE user_id = ? AND track_id = ?');
            $del->execute([$uid, $tid]);
            if ($del->rowCount() > 0) {
                $liked = false;
            } else {
                db()->prepare('INSERT IGNORE INTO likes (user_id, track_id) VALUES (?, ?)')->execute([$uid, $tid]);
                $liked = true;
            }
            $cnt = db()->prepare('SELECT COUNT(*) FROM likes WHERE track_id = ?');
            $cnt->execute([$tid]);
            out(['ok' => true, 'liked' => $liked, 'likes' => (int)$cnt->fetchColumn()]);

        // ---------- Счётчик прослушиваний ----------
        case 'play':
            if (!track_exists($tid)) {
                out(['ok' => false, 'error' => 'not_found'], 404);
            }
            // Защита от накрутки: не чаще одного раза в 20 секунд на трек в одной сессии
            $now = time();
            $last = $_SESSION['play_ts'][$tid] ?? 0;
            if ($now - $last < 20) {
                out(['ok' => true, 'counted' => false]);
            }
            $_SESSION['play_ts'][$tid] = $now;
            if (count($_SESSION['play_ts']) > 200) {
                $_SESSION['play_ts'] = array_slice($_SESSION['play_ts'], -100, null, true);
            }
            db()->prepare('UPDATE tracks SET plays = plays + 1 WHERE id = ?')->execute([$tid]);
            $st = db()->prepare('SELECT plays FROM tracks WHERE id = ?');
            $st->execute([$tid]);
            out(['ok' => true, 'counted' => true, 'plays' => (int)$st->fetchColumn()]);

        // ---------- Поиск ----------
        case 'search':
            session_write_close(); // не блокируем параллельные запросы
            $q     = mb_substr(trim((string)($_REQUEST['q'] ?? '')), 0, 100);
            $genre = mb_substr(trim((string)($_REQUEST['genre'] ?? '')), 0, 100);

            $where = [];
            $params = [];
            if ($q !== '') {
                $like = '%' . addcslashes($q, '%_\\') . '%';
                $where[] = '(t.title LIKE ? OR t.artist LIKE ? OR t.album LIKE ?)';
                array_push($params, $like, $like, $like);
            }
            if ($genre !== '') {
                $where[] = 't.genre = ?';
                $params[] = $genre;
            }

            $tracks = [];
            if ($where) {
                $tracks = tracks_query(
                    'WHERE ' . implode(' AND ', $where) . ' ORDER BY t.plays DESC, t.id DESC LIMIT 50',
                    $params
                );
            }

            // Режим "добавить в мой плейлист": отмечаем треки, которые уже в плейлисте
            $opts = [];
            if ($pid > 0 && $user && own_playlist($pid, $uid)) {
                $st = db()->prepare('SELECT track_id FROM playlist_tracks WHERE playlist_id = ?');
                $st->execute([$pid]);
                $inPl = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
            }

            $html = '';
            $json = [];
            foreach ($tracks as $i => $t) {
                $o = ['index' => $i + 1];
                if (isset($inPl)) {
                    $o['add_to']  = $pid;
                    $o['already'] = isset($inPl[(int)$t['id']]);
                }
                $html .= render_track_row($t, $o);
                $json[] = [
                    'id'       => (int)$t['id'],
                    'title'    => $t['title'],
                    'artist'   => $t['artist'],
                    'album'    => $t['album'],
                    'genre'    => $t['genre'],
                    'duration' => (int)$t['duration'],
                    'plays'    => (int)$t['plays'],
                    'src'      => media_url('audio', $t['audio_file']),
                    'cover'    => $t['cover'] ? media_url('covers', $t['cover']) : null,
                    'liked'    => !empty($t['liked']),
                ];
            }
            if (!$tracks && $where) {
                $label = $q !== '' ? $q : $genre;
                $html = '<div class="empty">Ничего не найдено по запросу «' . e($label) . '».</div>';
            }
            out(['ok' => true, 'count' => count($tracks), 'tracks' => $json, 'html' => $html]);

        // ---------- Добавить трек в плейлист ----------
        case 'add_to_playlist':
            if (!own_playlist($pid, $uid)) {
                out(['ok' => false, 'error' => 'forbidden', 'message' => 'Плейлист не найден.'], 404);
            }
            if (!track_exists($tid)) {
                out(['ok' => false, 'error' => 'not_found', 'message' => 'Трек не найден.'], 404);
            }
            $added = add_track_to_playlist($pid, $tid);
            out(['ok' => true, 'added' => $added]);

        // ---------- Убрать трек из плейлиста ----------
        case 'remove_from_playlist':
            if (!own_playlist($pid, $uid)) {
                out(['ok' => false, 'error' => 'forbidden', 'message' => 'Плейлист не найден.'], 404);
            }
            $st = db()->prepare('DELETE FROM playlist_tracks WHERE playlist_id = ? AND track_id = ?');
            $st->execute([$pid, $tid]);
            out(['ok' => true, 'removed' => $st->rowCount() > 0]);

        // ---------- Список плейлистов пользователя ----------
        case 'my_playlists':
            session_write_close();
            $st = db()->prepare(
                'SELECT p.id, p.name, p.is_public,
                        (SELECT COUNT(*) FROM playlist_tracks pt WHERE pt.playlist_id = p.id) AS tracks_count,
                        EXISTS(SELECT 1 FROM playlist_tracks pt2 WHERE pt2.playlist_id = p.id AND pt2.track_id = ?) AS has_track
                 FROM playlists p WHERE p.user_id = ? ORDER BY p.id DESC'
            );
            $st->execute([$tid, $uid]);
            $list = [];
            foreach ($st->fetchAll() as $r) {
                $list[] = [
                    'id'     => (int)$r['id'],
                    'name'   => $r['name'],
                    'count'  => (int)$r['tracks_count'],
                    'has'    => (bool)$r['has_track'],
                    'public' => (bool)$r['is_public'],
                ];
            }
            out(['ok' => true, 'playlists' => $list]);

        // ---------- Создать плейлист ----------
        case 'create_playlist':
            $name = mb_substr(trim((string)($_REQUEST['name'] ?? '')), 0, 100);
            if ($name === '') {
                out(['ok' => false, 'error' => 'validation', 'message' => 'Введите название плейлиста.'], 422);
            }
            $st = db()->prepare('INSERT INTO playlists (name, description, user_id, is_public) VALUES (?, ?, ?, 1)');
            $st->execute([$name, '', $uid]);
            $newId = (int)db()->lastInsertId();
            if ($tid > 0 && track_exists($tid)) {
                add_track_to_playlist($newId, $tid);
            }
            out(['ok' => true, 'id' => $newId, 'name' => $name, 'url' => url('playlist.php?id=' . $newId)]);

        default:
            out(['ok' => false, 'error' => 'unknown_action', 'message' => 'Неизвестное действие.'], 400);
    }
} catch (PDOException $e) {
    out(['ok' => false, 'error' => 'db', 'message' => 'Ошибка базы данных.'], 500);
}
