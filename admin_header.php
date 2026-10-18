<?php
/**
 * Шапка админ-панели.
 * Переменные: $adminTitle, $adminNav (dashboard|tracks|users|playlists), $adminLogin (true — форма входа без меню).
 */
require_once __DIR__ . '/functions.php';

$adminTitle = $adminTitle ?? 'Админ-панель';
$adminNav   = $adminNav ?? '';
$adminLogin = $adminLogin ?? false;
$adminUser  = current_user();

function admin_link(string $key, string $active, string $href, string $icon, string $label): string
{
    return '<a class="nav-link' . ($key === $active ? ' active' : '') . '" href="' . e(url($href)) . '">'
        . '<span class="nav-ico">' . $icon . '</span><span>' . e($label) . '</span></a>';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= e($adminTitle) ?> — MusicStream Admin</title>
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
</head>
<body class="admin<?= $adminLogin ? ' bare' : '' ?>">
<div class="app">
<?php if (!$adminLogin): ?>
    <aside class="sidebar" id="sidebar">
        <a class="logo" href="<?= e(url('admin/index.php')) ?>"><span class="logo-ico">♫</span> Admin</a>
        <nav class="nav">
            <?= admin_link('dashboard', $adminNav, 'admin/index.php', '▦', 'Dashboard') ?>
            <?= admin_link('tracks', $adminNav, 'admin/tracks.php', '♪', 'Треки') ?>
            <?= admin_link('users', $adminNav, 'admin/users.php', '☺', 'Пользователи') ?>
            <?= admin_link('playlists', $adminNav, 'admin/playlists.php', '☰', 'Плейлисты') ?>
        </nav>
        <div class="sidebar-title">Сайт</div>
        <a class="side-pl" href="<?= e(url('index.php')) ?>">← Вернуться на сайт</a>
        <a class="side-pl" href="<?= e(url('admin/logout.php?t=' . csrf_token())) ?>">Выйти (<?= e($adminUser['username'] ?? '') ?>)</a>
    </aside>
<?php endif; ?>
    <div class="content">
<?php if (!$adminLogin): ?>
        <header class="topbar">
            <button type="button" class="icon-btn burger" aria-label="Меню"
                    onclick="document.getElementById('sidebar').classList.toggle('open')">☰</button>
            <strong><?= e($adminTitle) ?></strong>
            <div class="topbar-spacer"></div>
            <a class="btn btn-small" href="<?= e(url('admin/logout.php?t=' . csrf_token())) ?>">Выйти</a>
        </header>
<?php endif; ?>
        <main class="<?= $adminLogin ? 'auth-wrap' : 'admin-main' ?>">
<?= $adminLogin ? '' : render_flash() ?>
