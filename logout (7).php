<?php
/**
 * Выход из админ-панели (ссылка содержит CSRF-токен ?t=...).
 */
require_once __DIR__ . '/../includes/functions.php';

if (current_user() && csrf_valid((string)($_GET['t'] ?? ''))) {
    logout_user();
}
redirect('admin/index.php');
