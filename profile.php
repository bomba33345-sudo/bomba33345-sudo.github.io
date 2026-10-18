<?php
/**
 * Личный кабинет: смена аватара и пароля, статистика.
 */
require_once __DIR__ . '/includes/functions.php';

$user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $do = (string)($_POST['do'] ?? '');

    // ----- Смена аватара -----
    if ($do === 'avatar') {
        if (!empty($_POST['remove_avatar'])) {
            delete_upload('avatars', $user['avatar']);
            db()->prepare('UPDATE users SET avatar = NULL WHERE id = ?')->execute([$user['id']]);
            flash('success', 'Аватар удалён.');
        } else {
            [$file, $err] = handle_upload($_FILES['avatar'] ?? [], 'image', 'avatars');
            if ($err) {
                flash('error', $err);
            } elseif (!$file) {
                flash('error', 'Выберите изображение.');
            } else {
                delete_upload('avatars', $user['avatar']);
                db()->prepare('UPDATE users SET avatar = ? WHERE id = ?')->execute([$file, $user['id']]);
                flash('success', 'Аватар обновлён.');
            }
        }
        redirect('profile.php');
    }

    // ----- Смена пароля -----
    if ($do === 'password') {
        $cur  = (string)($_POST['current'] ?? '');
        $new  = (string)($_POST['new'] ?? '');
        $new2 = (string)($_POST['new2'] ?? '');
        if (!password_verify($cur, $user['password'])) {
            flash('error', 'Текущий пароль указан неверно.');
        } elseif (mb_strlen($new) < 6) {
            flash('error', 'Новый пароль должен быть не короче 6 символов.');
        } elseif ($new !== $new2) {
            flash('error', 'Новые пароли не совпадают.');
        } else {
            // Сброс токена "запомнить меня" — другие устройства потребуют повторного входа
            db()->prepare('UPDATE users SET password = ?, remember_token = NULL WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            session_regenerate_id(true);
            clear_remember_cookie();
            flash('success', 'Пароль изменён.');
        }
        redirect('profile.php');
    }
    redirect('profile.php');
}

$st = db()->prepare('SELECT COUNT(*) FROM playlists WHERE user_id = ?');
$st->execute([$user['id']]);
$plCount = (int)$st->fetchColumn();
$st = db()->prepare('SELECT COUNT(*) FROM likes WHERE user_id = ?');
$st->execute([$user['id']]);
$likeCount = (int)$st->fetchColumn();

$pageTitle = 'Профиль';
$activeNav = 'profile';
require __DIR__ . '/includes/header.php';
?>
<h1 class="page-title">Профиль</h1>
<?= render_flash() ?>

<div class="profile-head">
    <?= avatar_html($user, 'avatar avatar-lg') ?>
    <div>
        <div class="profile-name"><?= e($user['username']) ?></div>
        <div class="muted"><?= e($user['email']) ?> · <?= $user['role'] === 'admin' ? 'администратор' : 'пользователь' ?></div>
        <div class="muted">С нами с <?= e(date('d.m.Y', strtotime($user['created_at']))) ?>
            · плейлистов: <?= $plCount ?> · лайков: <?= $likeCount ?></div>
    </div>
</div>

<div class="two-col">
    <form class="form-card" method="post" enctype="multipart/form-data">
        <h2>Аватар</h2>
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="avatar">
        <label class="field"><span>Новое изображение (JPG, PNG, GIF, WEBP до 5 МБ)</span>
            <input type="file" name="avatar" accept="image/*"></label>
        <?php if ($user['avatar']): ?>
            <label class="check"><input type="checkbox" name="remove_avatar" value="1"> <span>Удалить текущий аватар</span></label>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary">Сохранить аватар</button>
    </form>

    <form class="form-card" method="post" autocomplete="off">
        <h2>Смена пароля</h2>
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="password">
        <label class="field"><span>Текущий пароль</span><input type="password" name="current" required autocomplete="current-password"></label>
        <label class="field"><span>Новый пароль (от 6 символов)</span><input type="password" name="new" required minlength="6" autocomplete="new-password"></label>
        <label class="field"><span>Повторите новый пароль</span><input type="password" name="new2" required minlength="6" autocomplete="new-password"></label>
        <button type="submit" class="btn btn-primary">Изменить пароль</button>
    </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
