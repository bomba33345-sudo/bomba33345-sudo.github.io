<?php
/**
 * Регистрация: username, email, пароль + подтверждение.
 */
require_once __DIR__ . '/includes/functions.php';

if (current_user()) {
    redirect('index.php');
}

$errors = [];
$old = ['username' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $username = trim((string)($_POST['username'] ?? ''));
    $email    = trim((string)($_POST['email'] ?? ''));
    $pass     = (string)($_POST['password'] ?? '');
    $pass2    = (string)($_POST['password2'] ?? '');
    $old = ['username' => $username, 'email' => $email];

    if (!preg_match('/^[\p{L}\p{N}_.\-]{3,30}$/u', $username)) {
        $errors[] = 'Имя пользователя: 3–30 символов (буквы, цифры, _ . -).';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
        $errors[] = 'Введите корректный email.';
    }
    if (mb_strlen($pass) < 6) {
        $errors[] = 'Пароль должен быть не короче 6 символов.';
    }
    if ($pass !== $pass2) {
        $errors[] = 'Пароли не совпадают.';
    }

    if (!$errors) {
        $st = db()->prepare('SELECT username, email FROM users WHERE username = ? OR email = ?');
        $st->execute([$username, $email]);
        foreach ($st->fetchAll() as $row) {
            if (mb_strtolower($row['username']) === mb_strtolower($username)) {
                $errors[] = 'Это имя пользователя уже занято.';
            }
            if (mb_strtolower($row['email']) === mb_strtolower($email)) {
                $errors[] = 'Этот email уже зарегистрирован.';
            }
        }
        $errors = array_unique($errors);
    }

    if (!$errors) {
        try {
            $ins = db()->prepare('INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)');
            $ins->execute([$username, $email, password_hash($pass, PASSWORD_DEFAULT), 'user']);
            $st = db()->prepare('SELECT * FROM users WHERE id = ?');
            $st->execute([db()->lastInsertId()]);
            login_user($st->fetch(), false);
            flash('success', 'Добро пожаловать в MusicStream!');
            redirect('index.php');
        } catch (PDOException $e) {
            $errors[] = 'Не удалось зарегистрироваться: имя или email уже заняты.';
        }
    }
}

$pageTitle = 'Регистрация';
$bare = true;
require __DIR__ . '/includes/header.php';
?>
<div class="auth-card">
    <a class="logo logo-center" href="<?= e(url('index.php')) ?>"><span class="logo-ico">♫</span> MusicStream</a>
    <h1>Создайте аккаунт</h1>
    <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
    <form method="post" autocomplete="on">
        <?= csrf_field() ?>
        <label class="field"><span>Имя пользователя</span>
            <input type="text" name="username" value="<?= e($old['username']) ?>" required minlength="3" maxlength="30" autocomplete="username"></label>
        <label class="field"><span>Email</span>
            <input type="email" name="email" value="<?= e($old['email']) ?>" required maxlength="190" autocomplete="email"></label>
        <label class="field"><span>Пароль (не менее 6 символов)</span>
            <input type="password" name="password" required minlength="6" autocomplete="new-password"></label>
        <label class="field"><span>Подтверждение пароля</span>
            <input type="password" name="password2" required minlength="6" autocomplete="new-password"></label>
        <button type="submit" class="btn btn-primary btn-block">Зарегистрироваться</button>
    </form>
    <p class="auth-alt">Уже есть аккаунт? <a href="<?= e(url('login.php')) ?>">Войти</a></p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
