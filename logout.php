<?php
/**
 * Выход. Ссылка содержит CSRF-токен (?t=...), чтобы чужой сайт не мог разлогинить пользователя.
 */
require_once __DIR__ . '/includes/functions.php';

$token = (string)($_GET['t'] ?? $_POST['csrf'] ?? '');
if (current_user() && csrf_valid($token)) {
    logout_user();
}
redirect('login.php');
