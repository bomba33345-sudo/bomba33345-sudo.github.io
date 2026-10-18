<?php
/**
 * Вход по имени пользователя или email. Опция "запомнить меня" — cookie на 30 дней.
 */
require_once __DIR__ . '/includes/functions.php';

if (current_user()) {
    redirect('index.php');
}

$errors = [];
$login = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $login    = trim((string)($_POST['login'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $remember = !empty($_POST['remember']);

    // Простая защита от подбора: 5 неудачных попыток -> пауза 60 секунд
    $fails = $_SESSION['login_fails'] ?? 0;
    $blockUntil = $_SESSION['login_block'] ?? 0;
    if ($blockUntil > time()) {
        $errors[] = 'Слишком много попыток. Подождите ' . ($blockUntil - time()) . ' сек.';
    } else {
        $st = db()->prepare('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1');
        $st->execute([$login, $login]);
        $u = $st->fetch();

        if ($u && password_verify($password, $u['password'])) {
            if ((int)$u['is_banned']) {
                $errors[] = 'Ваш аккаунт заблокирован администратором.';
            } else {
                // Обновляем хэш, если алгоритм по умолчанию изменился
                if (password_needs_rehash($u['password'], PASSWORD_DEFAULT)) {
                    db()->prepare('UPDATE users SET password = ? WHERE id = ?')
                        ->execute([password_hash($password, PASSWORD_DEFAULT), $u['id']]);
                }
                unset($_SESSION['login_fails'], $_SESSION['login_block']);
                login_user($u, $remember);
                redirect('index.php');
            }
        } else {
            $fails++;
            $_SESSION['login_fails'] = $fails;
            if ($fails >= 5) {
                $_SESSION['login_block'] = time() + 60;
                $_SESSION['login_fails'] = 0;
            }
            $errors[] = 'Неверное имя пользователя или пароль.';
        }
    }
}

$pageTitle = 'Вход';
$bare = true;
require __DIR__ . '/includes/header.php';
?>
<div class="auth-card">
    <a class="logo logo-center" href="<?= e(url('index.php')) ?>"><span class="logo-ico">♫</span> MusicStream</a>
    <h1>Войдите в аккаунт</h1>
    <?= render_flash() ?>
    <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
    <form method="post">
        <?= csrf_field() ?>
        <label class="field"><span>Имя пользователя или email</span>
            <input type="text" name="login" value="<?= e($login) ?>" required autocomplete="username" autofocus></label>
        <label class="field"><span>Пароль</span>
            <input type="password" name="password" required autocomplete="current-password"></label>
        <label class="check"><input type="checkbox" name="remember" value="1"> <span>Запомнить меня на 30 дней</span></label>
        <button type="submit" class="btn btn-primary btn-block">Войти</button>
    </form>
    <p class="auth-alt">Нет аккаунта? <a href="<?= e(url('register.php')) ?>">Зарегистрироваться</a></p>
    <p class="auth-alt"><a href="<?= e(url('index.php')) ?>">← На главную</a></p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
