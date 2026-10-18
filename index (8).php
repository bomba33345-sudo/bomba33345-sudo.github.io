<?php
/**
 * Админ-панель: вход (admin / admin123 после install.php) и Dashboard со статистикой.
 * Доступ только для role = 'admin'.
 */
require_once __DIR__ . '/../includes/functions.php';

$errors = [];

// ---------- Вход в админку ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !is_admin()) {
    require_csrf();
    $login = trim((string)($_POST['login'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');

    if (($_SESSION['login_block'] ?? 0) > time()) {
        $errors[] = 'Слишком много попыток. Подождите ' . ($_SESSION['login_block'] - time()) . ' сек.';
    } else {
        $st = db()->prepare('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1');
        $st->execute([$login, $login]);
        $u = $st->fetch();
        if ($u && password_verify($pass, $u['password']) && $u['role'] === 'admin' && !(int)$u['is_banned']) {
            unset($_SESSION['login_fails'], $_SESSION['login_block']);
            login_user($u, false);
            redirect('admin/index.php');
        }
        $_SESSION['login_fails'] = ($_SESSION['login_fails'] ?? 0) + 1;
        if ($_SESSION['login_fails'] >= 5) {
            $_SESSION['login_block'] = time() + 60;
            $_SESSION['login_fails'] = 0;
        }
        $errors[] = 'Неверный логин или пароль, либо у аккаунта нет прав администратора.';
    }
}

if (!is_admin()) {
    $adminTitle = 'Вход в админ-панель';
    $adminLogin = true;
    require __DIR__ . '/../includes/admin_header.php';
    ?>
    <div class="auth-card">
        <a class="logo logo-center" href="<?= e(url('index.php')) ?>"><span class="logo-ico">♫</span> MusicStream</a>
        <h1>Админ-панель</h1>
        <?php if (current_user()): ?>
            <div class="alert alert-error">Ваш аккаунт не имеет прав администратора. Войдите под администратором.</div>
        <?php endif; ?>
        <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
        <form method="post">
            <?= csrf_field() ?>
            <label class="field"><span>Логин</span>
                <input type="text" name="login" required autofocus autocomplete="username"></label>
            <label class="field"><span>Пароль</span>
                <input type="password" name="password" required autocomplete="current-password"></label>
            <button type="submit" class="btn btn-primary btn-block">Войти</button>
        </form>
        <p class="auth-alt"><a href="<?= e(url('index.php')) ?>">← На сайт</a></p>
    </div>
    <?php
    require __DIR__ . '/../includes/admin_footer.php';
    exit;
}

// ---------- Dashboard ----------
$pdo = db();
$stats = [
    'users'     => (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'tracks'    => (int)$pdo->query('SELECT COUNT(*) FROM tracks')->fetchColumn(),
    'plays'     => (int)$pdo->query('SELECT COALESCE(SUM(plays), 0) FROM tracks')->fetchColumn(),
    'playlists' => (int)$pdo->query('SELECT COUNT(*) FROM playlists')->fetchColumn(),
    'likes'     => (int)$pdo->query('SELECT COUNT(*) FROM likes')->fetchColumn(),
    'new_users' => (int)$pdo->query('SELECT COUNT(*) FROM users WHERE created_at >= (NOW() - INTERVAL 7 DAY)')->fetchColumn(),
];
$topTracks   = $pdo->query('SELECT id, title, artist, plays FROM tracks ORDER BY plays DESC, id DESC LIMIT 5')->fetchAll();
$lastUsers   = $pdo->query('SELECT id, username, email, role, is_banned, created_at FROM users ORDER BY id DESC LIMIT 5')->fetchAll();
$lastTracks  = $pdo->query('SELECT id, title, artist, created_at FROM tracks ORDER BY id DESC LIMIT 5')->fetchAll();
$maxPlays    = max(1, (int)($topTracks[0]['plays'] ?? 1));

$adminTitle = 'Dashboard';
$adminNav = 'dashboard';
require __DIR__ . '/../includes/admin_header.php';
?>
<h1 class="page-title">Dashboard</h1>

<div class="stat-grid">
    <div class="stat-card"><div class="stat-num"><?= $stats['users'] ?></div><div class="stat-label">Пользователей (+<?= $stats['new_users'] ?> за 7 дней)</div></div>
    <div class="stat-card"><div class="stat-num"><?= $stats['tracks'] ?></div><div class="stat-label">Треков</div></div>
    <div class="stat-card"><div class="stat-num"><?= number_format($stats['plays'], 0, '', ' ') ?></div><div class="stat-label">Прослушиваний</div></div>
    <div class="stat-card"><div class="stat-num"><?= $stats['playlists'] ?></div><div class="stat-label">Плейлистов</div></div>
    <div class="stat-card"><div class="stat-num"><?= $stats['likes'] ?></div><div class="stat-label">Лайков</div></div>
</div>

<div class="panel">
    <h2>Топ-5 треков по прослушиваниям</h2>
    <?php if (!$topTracks): ?>
        <div class="muted">Треков пока нет.</div>
    <?php endif; ?>
    <?php foreach ($topTracks as $t): ?>
        <div class="bar-row">
            <div title="<?= e($t['title'] . ' — ' . $t['artist']) ?>" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                <?= e($t['title']) ?> <span class="muted">— <?= e($t['artist']) ?></span>
            </div>
            <div class="bar"><i style="width: <?= round($t['plays'] / $maxPlays * 100) ?>%"></i></div>
            <div><?= number_format((int)$t['plays'], 0, '', ' ') ?></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="two-col">
    <div class="panel">
        <h2>Новые пользователи</h2>
        <div class="table-wrap"><table class="table">
            <?php foreach ($lastUsers as $u): ?>
                <tr>
                    <td><?= e($u['username']) ?>
                        <?= $u['role'] === 'admin' ? '<span class="badge badge-admin">admin</span>' : '' ?>
                        <?= (int)$u['is_banned'] ? '<span class="badge badge-ban">бан</span>' : '' ?></td>
                    <td class="muted"><?= e($u['email']) ?></td>
                    <td class="muted"><?= e(date('d.m.Y', strtotime($u['created_at']))) ?></td>
                </tr>
            <?php endforeach; ?>
        </table></div>
        <p><a href="<?= e(url('admin/users.php')) ?>">Все пользователи →</a></p>
    </div>
    <div class="panel">
        <h2>Последние треки</h2>
        <div class="table-wrap"><table class="table">
            <?php foreach ($lastTracks as $t): ?>
                <tr>
                    <td><?= e($t['title']) ?></td>
                    <td class="muted"><?= e($t['artist']) ?></td>
                    <td class="muted"><?= e(date('d.m.Y', strtotime($t['created_at']))) ?></td>
                </tr>
            <?php endforeach; ?>
        </table></div>
        <p><a href="<?= e(url('admin/tracks.php')) ?>">Управление треками →</a></p>
    </div>
</div>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
