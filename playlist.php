<?php
/**
 * Плейлисты:
 *   playlist.php           — мои плейлисты + создание + публичные плейлисты других
 *   playlist.php?id=N      — просмотр плейлиста (владелец: редактирование, добавление/удаление треков)
 *   playlist.php?liked=1   — любимые треки
 */
require_once __DIR__ . '/includes/functions.php';

$user = current_user();

/** Плейлист + владелец + обложка первого трека */
function fetch_playlist(int $id): ?array
{
    $st = db()->prepare(
        'SELECT p.*, u.username AS owner_name,
                (SELECT t.cover FROM playlist_tracks pt JOIN tracks t ON t.id = pt.track_id
                 WHERE pt.playlist_id = p.id AND t.cover IS NOT NULL ORDER BY pt.position, pt.id LIMIT 1) AS first_cover
         FROM playlists p JOIN users u ON u.id = p.user_id WHERE p.id = ?'
    );
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

/** Карточка плейлиста в сетке */
function render_playlist_card(array $p, bool $showOwner = false): string
{
    $cover = $p['cover'] ?: ($p['first_cover'] ?? null);
    $sub = (int)$p['tracks_count'] . ' тр.' . ($showOwner ? ' · ' . e($p['owner_name']) : '')
         . (!$showOwner && !(int)$p['is_public'] ? ' · 🔒 приватный' : '');
    return '<a class="card card-link" href="' . e(url('playlist.php?id=' . (int)$p['id'])) . '">'
        . '<div class="card-cover">' . cover_img($cover, 'cover') . '</div>'
        . '<div class="card-title">' . e($p['name']) . '</div>'
        . '<div class="card-sub">' . $sub . '</div></a>';
}

$listSql = 'SELECT p.*, u.username AS owner_name,
                   (SELECT COUNT(*) FROM playlist_tracks x WHERE x.playlist_id = p.id) AS tracks_count,
                   (SELECT t.cover FROM playlist_tracks pt JOIN tracks t ON t.id = pt.track_id
                    WHERE pt.playlist_id = p.id AND t.cover IS NOT NULL ORDER BY pt.position, pt.id LIMIT 1) AS first_cover
            FROM playlists p JOIN users u ON u.id = p.user_id ';

// ================= Обработка форм (POST) =================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = require_login();
    require_csrf();
    $do = (string)($_POST['do'] ?? '');

    if ($do === 'create') {
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 100);
        $desc = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 500);
        $pub  = !empty($_POST['is_public']) ? 1 : 0;
        if ($name === '') {
            flash('error', 'Введите название плейлиста.');
            redirect('playlist.php#create');
        }
        [$cover, $err] = handle_upload($_FILES['cover'] ?? [], 'image', 'covers');
        if ($err) {
            flash('error', $err);
            redirect('playlist.php#create');
        }
        db()->prepare('INSERT INTO playlists (name, description, cover, user_id, is_public) VALUES (?, ?, ?, ?, ?)')
            ->execute([$name, $desc, $cover, $user['id'], $pub]);
        flash('success', 'Плейлист создан.');
        redirect('playlist.php?id=' . (int)db()->lastInsertId());
    }

    $pid = (int)($_POST['id'] ?? 0);
    $pl = fetch_playlist($pid);
    if (!$pl || (int)$pl['user_id'] !== (int)$user['id']) {
        flash('error', 'Плейлист не найден.');
        redirect('playlist.php');
    }

    if ($do === 'update') {
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 100);
        $desc = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 500);
        $pub  = !empty($_POST['is_public']) ? 1 : 0;
        if ($name === '') {
            flash('error', 'Название не может быть пустым.');
            redirect('playlist.php?id=' . $pid);
        }
        [$cover, $err] = handle_upload($_FILES['cover'] ?? [], 'image', 'covers');
        if ($err) {
            flash('error', $err);
            redirect('playlist.php?id=' . $pid);
        }
        $newCover = $pl['cover'];
        if ($cover) {
            delete_upload('covers', $pl['cover']);
            $newCover = $cover;
        } elseif (!empty($_POST['remove_cover'])) {
            delete_upload('covers', $pl['cover']);
            $newCover = null;
        }
        db()->prepare('UPDATE playlists SET name = ?, description = ?, cover = ?, is_public = ? WHERE id = ?')
            ->execute([$name, $desc, $newCover, $pub, $pid]);
        flash('success', 'Плейлист обновлён.');
        redirect('playlist.php?id=' . $pid);
    }

    if ($do === 'delete') {
        delete_upload('covers', $pl['cover']);
        db()->prepare('DELETE FROM playlists WHERE id = ?')->execute([$pid]);
        flash('success', 'Плейлист удалён.');
        redirect('playlist.php');
    }

    redirect('playlist.php');
}

// ================= Просмотр (GET) =================
$mode = 'list';
$playlist = null;
$tracks = [];

if (isset($_GET['liked'])) {
    $user = require_login();
    $mode = 'liked';
    $tracks = tracks_query(
        'JOIN likes lk ON lk.track_id = t.id WHERE lk.user_id = ? ORDER BY lk.id DESC',
        [$user['id']]
    );
    $pageTitle = 'Любимое';
    $activeNav = 'liked';
} elseif (isset($_GET['id'])) {
    $mode = 'view';
    $playlist = fetch_playlist((int)$_GET['id']);
    $isOwner = $playlist && $user && (int)$playlist['user_id'] === (int)$user['id'];
    if (!$playlist || (!(int)$playlist['is_public'] && !$isOwner && !is_admin())) {
        flash('error', 'Плейлист не найден или он приватный.');
        redirect('playlist.php');
    }
    $tracks = tracks_query(
        'JOIN playlist_tracks pt ON pt.track_id = t.id WHERE pt.playlist_id = ? ORDER BY pt.position, pt.id',
        [$playlist['id']]
    );
    $pageTitle = $playlist['name'];
    $activeNav = $isOwner ? 'playlists' : '';
} else {
    $user = require_login();
    $st = db()->prepare($listSql . 'WHERE p.user_id = ? ORDER BY p.id DESC');
    $st->execute([$user['id']]);
    $myLists = $st->fetchAll();
    $st = db()->prepare($listSql . 'WHERE p.is_public = 1 AND p.user_id <> ? ORDER BY p.id DESC LIMIT 12');
    $st->execute([$user['id']]);
    $otherLists = $st->fetchAll();
    // счётчик треков для плейлистов (listSql без счётчика у $myLists считает subquery — уже есть tracks_count)
    $pageTitle = 'Мои плейлисты';
    $activeNav = 'playlists';
}

$totalDuration = 0;
foreach ($tracks as $t) {
    $totalDuration += (int)$t['duration'];
}

require __DIR__ . '/includes/header.php';
echo render_flash();
?>

<?php if ($mode === 'list'): ?>
    <h1 class="page-title">Мои плейлисты</h1>

    <section class="section">
        <?php if ($myLists): ?>
            <div class="grid">
                <?php foreach ($myLists as $p) echo render_playlist_card($p); ?>
            </div>
        <?php else: ?>
            <div class="empty">У вас пока нет плейлистов. Создайте первый ниже.</div>
        <?php endif; ?>
    </section>

    <section class="section" id="create">
        <div class="section-head"><h2>Новый плейлист</h2></div>
        <form class="form-card" method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="create">
            <label class="field"><span>Название</span><input type="text" name="name" required maxlength="100"></label>
            <label class="field"><span>Описание</span><textarea name="description" rows="3" maxlength="500"></textarea></label>
            <label class="field"><span>Обложка (необязательно)</span><input type="file" name="cover" accept="image/*"></label>
            <label class="check"><input type="checkbox" name="is_public" value="1" checked> <span>Публичный плейлист</span></label>
            <button type="submit" class="btn btn-primary">Создать</button>
        </form>
    </section>

    <?php if ($otherLists): ?>
    <section class="section">
        <div class="section-head"><h2>Плейлисты сообщества</h2></div>
        <div class="grid">
            <?php foreach ($otherLists as $p) echo render_playlist_card($p, true); ?>
        </div>
    </section>
    <?php endif; ?>

<?php else: ?>
    <?php
    $isLiked = $mode === 'liked';
    $coverFile = $isLiked ? null : ($playlist['cover'] ?: $playlist['first_cover']);
    ?>
    <div class="pl-hero">
        <div class="pl-hero-cover"><?= $isLiked ? '<div class="cover cover-ph cover-heart">♥</div>' : cover_img($coverFile, 'cover') ?></div>
        <div class="pl-hero-info">
            <div class="pl-hero-kind"><?= $isLiked ? 'Плейлист' : ((int)$playlist['is_public'] ? 'Публичный плейлист' : '🔒 Приватный плейлист') ?></div>
            <h1 class="pl-hero-title"><?= $isLiked ? 'Любимое' : e($playlist['name']) ?></h1>
            <?php if (!$isLiked && $playlist['description'] !== ''): ?>
                <p class="pl-hero-desc"><?= nl2br(e($playlist['description'])) ?></p>
            <?php endif; ?>
            <div class="pl-hero-meta">
                <?= $isLiked ? e($user['username']) : e($playlist['owner_name']) ?>
                · <?= count($tracks) ?> тр.<?= $totalDuration ? ', ' . e(format_duration($totalDuration)) : '' ?>
            </div>
        </div>
    </div>

    <div class="toolbar">
        <?php if ($tracks): ?>
            <button type="button" class="btn btn-primary" data-action="play-all" data-target="#playlist-tracks">▶ Слушать</button>
        <?php endif; ?>
        <?php if (!$isLiked && $isOwner): ?>
            <form method="post" onsubmit="return confirm('Удалить плейлист «<?= e(addslashes($playlist['name'])) ?>»?');">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="delete">
                <input type="hidden" name="id" value="<?= (int)$playlist['id'] ?>">
                <button type="submit" class="btn btn-danger">Удалить плейлист</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if (!$isLiked && $isOwner): ?>
        <details class="edit-box">
            <summary>Изменить название, описание, обложку</summary>
            <form class="form-card" method="post" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="update">
                <input type="hidden" name="id" value="<?= (int)$playlist['id'] ?>">
                <label class="field"><span>Название</span>
                    <input type="text" name="name" value="<?= e($playlist['name']) ?>" required maxlength="100"></label>
                <label class="field"><span>Описание</span>
                    <textarea name="description" rows="3" maxlength="500"><?= e($playlist['description']) ?></textarea></label>
                <label class="field"><span>Новая обложка</span><input type="file" name="cover" accept="image/*"></label>
                <?php if ($playlist['cover']): ?>
                    <label class="check"><input type="checkbox" name="remove_cover" value="1"> <span>Удалить текущую обложку</span></label>
                <?php endif; ?>
                <label class="check"><input type="checkbox" name="is_public" value="1" <?= (int)$playlist['is_public'] ? 'checked' : '' ?>>
                    <span>Публичный плейлист</span></label>
                <button type="submit" class="btn btn-primary">Сохранить</button>
            </form>
        </details>
    <?php endif; ?>

    <div class="track-list" id="playlist-tracks" data-queue>
        <?php if ($tracks): ?>
            <?php foreach ($tracks as $i => $t): ?>
                <?= render_track_row($t, ['index' => $i + 1, 'remove_from' => (!$isLiked && $isOwner) ? (int)$playlist['id'] : 0]) ?>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty"><?= $isLiked ? 'Вы ещё не отметили ни одного трека. Нажмите ♥ рядом с треком.' : 'В плейлисте пока нет треков.' ?></div>
        <?php endif; ?>
    </div>

    <?php if (!$isLiked && $isOwner): ?>
        <section class="section">
            <div class="section-head"><h2>Добавить треки</h2></div>
            <form class="search-form" onsubmit="return false;">
                <input type="search" placeholder="Найдите трек, исполнителя или альбом" autocomplete="off"
                       data-live-search data-results="#pl-add-results" data-playlist="<?= (int)$playlist['id'] ?>">
            </form>
            <div class="track-list" id="pl-add-results" data-queue></div>
        </section>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
