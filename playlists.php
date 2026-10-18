<?php
/**
 * Админка: плейлисты — просмотр и удаление.
 */
require_once __DIR__ . '/../includes/functions.php';
$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'delete') {
    require_csrf();
    $st = db()->prepare('SELECT * FROM playlists WHERE id = ?');
    $st->execute([(int)($_POST['id'] ?? 0)]);
    if ($p = $st->fetch()) {
        delete_upload('covers', $p['cover']);
        db()->prepare('DELETE FROM playlists WHERE id = ?')->execute([$p['id']]); // playlist_tracks удаляются каскадом
        flash('success', 'Плейлист «' . $p['name'] . '» удалён.');
    }
    redirect('admin/playlists.php');
}

$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$where = '';
$params = [];
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $where = 'WHERE p.name LIKE ? OR u.username LIKE ?';
    $params = [$like, $like];
}
$st = db()->prepare('SELECT COUNT(*) FROM playlists p JOIN users u ON u.id = p.user_id ' . $where);
$st->execute($params);
$total = (int)$st->fetchColumn();
$perPage = 20;
[$page, $pages, $offset] = paginate($total, $perPage, (int)($_GET['page'] ?? 1));
$st = db()->prepare(
    'SELECT p.*, u.username,
            (SELECT COUNT(*) FROM playlist_tracks pt WHERE pt.playlist_id = p.id) AS tracks_count
     FROM playlists p JOIN users u ON u.id = p.user_id ' . $where
    . ' ORDER BY p.id DESC LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset
);
$st->execute($params);
$rows = $st->fetchAll();

$adminTitle = 'Плейлисты';
$adminNav = 'playlists';
require __DIR__ . '/../includes/admin_header.php';
?>
<h1 class="page-title">Плейлисты <span class="muted" style="font-size:18px">(<?= $total ?>)</span></h1>

<form class="filter-bar" method="get">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Поиск по названию или владельцу">
    <button class="btn" type="submit">Найти</button>
</form>

<div class="panel table-wrap">
    <table class="table">
        <thead><tr><th></th><th>Название</th><th>Владелец</th><th>Треков</th><th>Доступ</th><th>Создан</th><th>Действия</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $p): ?>
            <tr>
                <td><div class="thumb"><?= cover_img($p['cover']) ?></div></td>
                <td><strong><?= e($p['name']) ?></strong>
                    <?php if ($p['description'] !== ''): ?><div class="muted"><?= e(mb_strimwidth($p['description'], 0, 70, '…')) ?></div><?php endif; ?></td>
                <td><?= e($p['username']) ?></td>
                <td><?= (int)$p['tracks_count'] ?></td>
                <td><?= (int)$p['is_public'] ? '<span class="badge badge-admin">публичный</span>' : '<span class="badge">приватный</span>' ?></td>
                <td class="muted"><?= e(date('d.m.Y', strtotime($p['created_at']))) ?></td>
                <td><div class="actions">
                    <a class="btn btn-small" href="<?= e(url('playlist.php?id=' . (int)$p['id'])) ?>" target="_blank" rel="noopener" data-hard>Открыть</a>
                    <form method="post" onsubmit="return confirm('Удалить плейлист?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="do" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                        <button class="btn btn-small btn-danger" type="submit">Удалить</button>
                    </form>
                </div></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="muted">Плейлистов пока нет.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <?= render_pagination($page, $pages) ?>
</div>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
