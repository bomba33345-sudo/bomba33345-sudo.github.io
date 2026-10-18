<?php
/**
 * Админка: управление треками (CRUD + загрузка MP3 и обложек).
 *   tracks.php           — список с поиском и пагинацией
 *   tracks.php?new=1     — форма добавления
 *   tracks.php?edit=ID   — форма редактирования
 */
require_once __DIR__ . '/../includes/functions.php';
$admin = require_admin();

$errors = [];
$form = ['id' => 0, 'title' => '', 'artist' => '', 'album' => '', 'genre' => '', 'duration' => 0];
$editing = null;

// ---------- Удаление ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'delete') {
    require_csrf();
    $st = db()->prepare('SELECT * FROM tracks WHERE id = ?');
    $st->execute([(int)($_POST['id'] ?? 0)]);
    if ($t = $st->fetch()) {
        delete_upload('audio', $t['audio_file']);
        delete_upload('covers', $t['cover']);
        db()->prepare('DELETE FROM tracks WHERE id = ?')->execute([$t['id']]);
        flash('success', 'Трек «' . $t['title'] . '» удалён.');
    }
    redirect('admin/tracks.php');
}

// ---------- Создание / сохранение ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'save') {
    require_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $form = [
        'id'       => $id,
        'title'    => mb_substr(trim((string)($_POST['title'] ?? '')), 0, 255),
        'artist'   => mb_substr(trim((string)($_POST['artist'] ?? '')), 0, 255),
        'album'    => mb_substr(trim((string)($_POST['album'] ?? '')), 0, 255),
        'genre'    => mb_substr(trim((string)($_POST['genre'] ?? '')), 0, 100),
        'duration' => max(0, (int)($_POST['duration'] ?? 0)),
    ];
    if ($id) {
        $st = db()->prepare('SELECT * FROM tracks WHERE id = ?');
        $st->execute([$id]);
        $editing = $st->fetch() ?: null;
        if (!$editing) {
            flash('error', 'Трек не найден.');
            redirect('admin/tracks.php');
        }
    }
    if ($form['title'] === '')  { $errors[] = 'Укажите название трека.'; }
    if ($form['artist'] === '') { $errors[] = 'Укажите исполнителя.'; }

    // Загрузки: сначала всё проверяем, при ошибке откатываем сохранённые файлы
    [$audio, $errA] = handle_upload($_FILES['audio'] ?? [], 'audio', 'audio');
    [$cover, $errC] = handle_upload($_FILES['cover'] ?? [], 'image', 'covers');
    if ($errA) { $errors[] = $errA; }
    if ($errC) { $errors[] = $errC; }
    if (!$id && !$audio && !$errA) { $errors[] = 'Загрузите аудиофайл (MP3, OGG, WAV или M4A).'; }

    if ($errors) {
        delete_upload('audio', $audio);
        delete_upload('covers', $cover);
    } else {
        if ($id) {
            $newAudio = $editing['audio_file'];
            $newCover = $editing['cover'];
            if ($audio) { delete_upload('audio', $editing['audio_file']); $newAudio = $audio; }
            if ($cover) { delete_upload('covers', $editing['cover']); $newCover = $cover; }
            elseif (!empty($_POST['remove_cover'])) { delete_upload('covers', $editing['cover']); $newCover = null; }
            db()->prepare('UPDATE tracks SET title=?, artist=?, album=?, genre=?, duration=?, audio_file=?, cover=? WHERE id=?')
                ->execute([$form['title'], $form['artist'], $form['album'], $form['genre'], $form['duration'], $newAudio, $newCover, $id]);
            flash('success', 'Трек обновлён.');
        } else {
            db()->prepare('INSERT INTO tracks (title, artist, album, genre, duration, audio_file, cover, uploader_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$form['title'], $form['artist'], $form['album'], $form['genre'], $form['duration'], $audio, $cover, $admin['id']]);
            flash('success', 'Трек добавлен.');
        }
        redirect('admin/tracks.php');
    }
}

// ---------- Режим отображения ----------
$showForm = isset($_GET['new']) || isset($_GET['edit']) || $errors;
if ($showForm && !$errors && isset($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM tracks WHERE id = ?');
    $st->execute([(int)$_GET['edit']]);
    $editing = $st->fetch();
    if (!$editing) {
        flash('error', 'Трек не найден.');
        redirect('admin/tracks.php');
    }
    $form = array_intersect_key($editing, $form);
}

$adminTitle = 'Треки';
$adminNav = 'tracks';
require __DIR__ . '/../includes/admin_header.php';

if ($showForm):
    $isEdit = !empty($form['id']);
    ?>
    <h1 class="page-title"><?= $isEdit ? 'Редактирование трека' : 'Новый трек' ?></h1>
    <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

    <form class="panel" method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="save">
        <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
        <div class="admin-form">
            <label class="field"><span>Название *</span><input type="text" name="title" value="<?= e($form['title']) ?>" required maxlength="255"></label>
            <label class="field"><span>Исполнитель *</span><input type="text" name="artist" value="<?= e($form['artist']) ?>" required maxlength="255"></label>
            <label class="field"><span>Альбом</span><input type="text" name="album" value="<?= e($form['album']) ?>" maxlength="255"></label>
            <label class="field"><span>Жанр</span><input type="text" name="genre" value="<?= e($form['genre']) ?>" maxlength="100" list="genre-list"></label>
            <label class="field"><span>Длительность, сек (определяется автоматически)</span>
                <input type="number" name="duration" id="duration" value="<?= (int)$form['duration'] ?>" min="0"></label>
        </div>
        <datalist id="genre-list">
            <?php foreach (db()->query("SELECT DISTINCT genre FROM tracks WHERE genre <> '' ORDER BY genre")->fetchAll(PDO::FETCH_COLUMN) as $g): ?>
                <option value="<?= e($g) ?>">
            <?php endforeach; ?>
        </datalist>

        <label class="field"><span>Аудиофайл (MP3, OGG, WAV, M4A до 50 МБ)<?= $isEdit ? ' — оставьте пустым, чтобы не менять' : ' *' ?></span>
            <input type="file" name="audio" id="audio-file" accept=".mp3,.ogg,.wav,.m4a,audio/*"></label>
        <?php if ($isEdit && $editing): ?>
            <audio controls preload="none" src="<?= e(media_url('audio', $editing['audio_file'])) ?>" style="width:100%;max-width:480px;margin-bottom:16px"></audio>
        <?php endif; ?>

        <label class="field"><span>Обложка (JPG, PNG, GIF, WEBP до 5 МБ)</span>
            <input type="file" name="cover" accept="image/*"></label>
        <?php if ($isEdit && $editing && $editing['cover']): ?>
            <div style="display:flex;align-items:center;gap:14px;margin-bottom:16px">
                <div class="thumb" style="width:80px;height:80px;border-radius:6px;overflow:hidden"><?= cover_img($editing['cover']) ?></div>
                <label class="check" style="margin:0"><input type="checkbox" name="remove_cover" value="1"> <span>Удалить обложку</span></label>
            </div>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary">Сохранить</button>
        <a class="btn" href="<?= e(url('admin/tracks.php')) ?>">Отмена</a>
    </form>

    <script>
    // Автоопределение длительности при выборе аудиофайла
    document.getElementById('audio-file').addEventListener('change', function () {
        var f = this.files && this.files[0];
        if (!f) { return; }
        var a = new Audio();
        a.preload = 'metadata';
        a.src = URL.createObjectURL(f);
        a.addEventListener('loadedmetadata', function () {
            if (isFinite(a.duration)) { document.getElementById('duration').value = Math.round(a.duration); }
            URL.revokeObjectURL(a.src);
        });
    });
    </script>

<?php else:
    // ---------- Список ----------
    $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
    $where = '';
    $params = [];
    if ($q !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $where = 'WHERE title LIKE ? OR artist LIKE ? OR album LIKE ? OR genre LIKE ?';
        $params = [$like, $like, $like, $like];
    }
    $st = db()->prepare('SELECT COUNT(*) FROM tracks ' . $where);
    $st->execute($params);
    $total = (int)$st->fetchColumn();
    $perPage = 15;
    [$page, $pages, $offset] = paginate($total, $perPage, (int)($_GET['page'] ?? 1));
    $st = db()->prepare('SELECT * FROM tracks ' . $where . ' ORDER BY id DESC LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset);
    $st->execute($params);
    $rows = $st->fetchAll();
    ?>
    <div class="section-head">
        <h1 class="page-title" style="margin:12px 0">Треки <span class="muted" style="font-size:18px">(<?= $total ?>)</span></h1>
        <a class="btn btn-primary" href="?new=1">＋ Добавить трек</a>
    </div>

    <form class="filter-bar" method="get">
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Поиск по названию, исполнителю, альбому, жанру">
        <button class="btn" type="submit">Найти</button>
    </form>

    <div class="panel table-wrap">
        <table class="table">
            <thead><tr><th></th><th>Название</th><th>Исполнитель</th><th>Альбом</th><th>Жанр</th><th>Время</th><th>Плеи</th><th>Добавлен</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $t): ?>
                <tr>
                    <td><div class="thumb"><?= cover_img($t['cover']) ?></div></td>
                    <td><strong><?= e($t['title']) ?></strong></td>
                    <td><?= e($t['artist']) ?></td>
                    <td class="muted"><?= e($t['album']) ?></td>
                    <td><?= $t['genre'] !== '' ? '<span class="badge">' . e($t['genre']) . '</span>' : '' ?></td>
                    <td><?= e(format_duration($t['duration'])) ?></td>
                    <td><?= number_format((int)$t['plays'], 0, '', ' ') ?></td>
                    <td class="muted"><?= e(date('d.m.Y', strtotime($t['created_at']))) ?></td>
                    <td><div class="actions">
                        <a class="btn btn-small" href="?edit=<?= (int)$t['id'] ?>">Изменить</a>
                        <form method="post" onsubmit="return confirm('Удалить трек и его файлы?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="do" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                            <button class="btn btn-small btn-danger" type="submit">Удалить</button>
                        </form>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="9" class="muted">Ничего не найдено.</td></tr><?php endif; ?>
            </tbody>
        </table>
        <?= render_pagination($page, $pages) ?>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
