<?php
/**
 * Админка: пользователи — список, бан/разбан, смена роли, удаление.
 * Свой аккаунт изменять нельзя (чтобы случайно не лишить себя доступа).
 */
require_once __DIR__ . '/../includes/functions.php';
$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $do = (string)($_POST['do'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    if ($id === (int)$admin['id']) {
        flash('error', 'Нельзя изменять собственный аккаунт.');
        redirect('admin/users.php');
    }
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    $target = $st->fetch();
    if (!$target) {
        flash('error', 'Пользователь не найден.');
        redirect('admin/users.php');
    }

    switch ($do) {
        case 'ban':
            db()->prepare('UPDATE users SET is_banned = 1, remember_token = NULL WHERE id = ?')->execute([$id]);
            flash('success', 'Пользователь «' . $target['username'] . '» заблокирован.');
            break;
        case 'unban':
            db()->prepare('UPDATE users SET is_banned = 0 WHERE id = ?')->execute([$id]);
            flash('success', 'Пользователь «' . $target['username'] . '» разблокирован.');
            break;
        case 'make_admin':
        case 'make_user':
            $role = $do === 'make_admin' ? 'admin' : 'user';
            db()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $id]);
            flash('success', 'Роль «' . $target['username'] . '» изменена на ' . $role . '.');
            break;
        case 'delete':
            // Файлы обложек его плейлистов и аватар удаляем с диска (записи БД уйдут каскадом)
            $st = db()->prepare('SELECT cover FROM playlists WHERE user_id = ? AND cover IS NOT NULL');
            $st->execute([$id]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) {
                delete_upload('covers', $c);
            }
            delete_upload('avatars', $target['avatar']);
            db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            flash('success', 'Пользователь «' . $target['username'] . '» удалён.');
            break;
    }
    redirect('admin/users.php' . (!empty($_POST['back']) ? '?' . ltrim((string)$_POST['back'], '?') : ''));
}

$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$where = '';
$params = [];
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $where = 'WHERE u.username LIKE ? OR u.email LIKE ?';
    $params = [$like, $like];
}
$st = db()->prepare('SELECT COUNT(*) FROM users u ' . $where);
$st->execute($params);
$total = (int)$st->fetchColumn();
$perPage = 20;
[$page, $pages, $offset] = paginate($total, $perPage, (int)($_GET['page'] ?? 1));
$st = db()->prepare(
    'SELECT u.*,
            (SELECT COUNT(*) FROM playlists p WHERE p.user_id = u.id) AS pl_count,
            (SELECT COUNT(*) FROM likes l WHERE l.user_id = u.id) AS like_count
     FROM users u ' . $where . ' ORDER BY u.id DESC LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset
);
$st->execute($params);
$rows = $st->fetchAll();
$back = http_build_query(array_filter(['q' => $q, 'page' => $page > 1 ? $page : null]));

$adminTitle = 'Пользователи';
$adminNav = 'users';
require __DIR__ . '/../includes/admin_header.php';

/** Кнопка-форма действия над пользователем */
function user_action(array $u, string $do, string $label, string $cls, string $confirm, string $back): string
{
    return '<form method="post"' . ($confirm ? ' onsubmit="return confirm(\'' . e($confirm) . '\');"' : '') . '>'
        . csrf_field()
        . '<input type="hidden" name="do" value="' . e($do) . '">'
        . '<input type="hidden" name="id" value="' . (int)$u['id'] . '">'
        . '<input type="hidden" name="back" value="' . e($back) . '">'
        . '<button class="btn btn-small ' . $cls . '" type="submit">' . e($label) . '</button></form>';
}
?>
<h1 class="page-title">Пользователи <span class="muted" style="font-size:18px">(<?= $total ?>)</span></h1>

<form class="filter-bar" method="get">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Поиск по имени или email">
    <button class="btn" type="submit">Найти</button>
</form>

<div class="panel table-wrap">
    <table class="table">
        <thead><tr><th>ID</th><th>Пользователь</th><th>Email</th><th>Роль</th><th>Статус</th><th>Плейлисты</th><th>Лайки</th><th>Регистрация</th><th>Действия</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $u): $self = (int)$u['id'] === (int)$admin['id']; ?>
            <tr>
                <td><?= (int)$u['id'] ?></td>
                <td><div style="display:flex;align-items:center;gap:10px"><?= avatar_html($u, 'avatar avatar-sm') ?><strong><?= e($u['username']) ?></strong></div></td>
                <td class="muted"><?= e($u['email']) ?></td>
                <td><?= $u['role'] === 'admin' ? '<span class="badge badge-admin">admin</span>' : '<span class="badge">user</span>' ?></td>
                <td><?= (int)$u['is_banned'] ? '<span class="badge badge-ban">заблокирован</span>' : '<span class="badge">активен</span>' ?></td>
                <td><?= (int)$u['pl_count'] ?></td>
                <td><?= (int)$u['like_count'] ?></td>
                <td class="muted"><?= e(date('d.m.Y H:i', strtotime($u['created_at']))) ?></td>
                <td>
                    <?php if ($self): ?>
                        <span class="muted">это вы</span>
                    <?php else: ?>
                        <div class="actions">
                            <?= (int)$u['is_banned']
                                ? user_action($u, 'unban', 'Разбанить', '', '', $back)
                                : user_action($u, 'ban', 'Бан', '', 'Заблокировать пользователя?', $back) ?>
                            <?= $u['role'] === 'admin'
                                ? user_action($u, 'make_user', 'Снять админа', '', 'Снять права администратора?', $back)
                                : user_action($u, 'make_admin', 'Сделать админом', '', 'Выдать права администратора?', $back) ?>
                            <?= user_action($u, 'delete', 'Удалить', 'btn-danger', 'Удалить пользователя вместе с его плейлистами и лайками?', $back) ?>
                        </div>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="9" class="muted">Ничего не найдено.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <?= render_pagination($page, $pages) ?>
</div>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
