<?php
/**
 * Общая шапка: <head>, боковое меню, верхняя панель.
 * Переменные страницы: $pageTitle, $activeNav, $bare (true — без меню и плеера: вход/регистрация).
 */
require_once __DIR__ . '/functions.php';

$user      = current_user();
$pageTitle = $pageTitle ?? 'MusicStream';
$activeNav = $activeNav ?? '';
$bare      = $bare ?? false;

// Плейлисты пользователя для бокового меню
$sidebarPlaylists = [];
if ($user && !$bare) {
    $st = db()->prepare('SELECT id, name FROM playlists WHERE user_id = ? ORDER BY id DESC LIMIT 15');
    $st->execute([$user['id']]);
    $sidebarPlaylists = $st->fetchAll();
}

/** Ссылка бокового меню с подсветкой активного пункта */
function nav_link(string $key, string $active, string $href, string $icon, string $label): string
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
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="base-url" content="<?= e(BASE_URL) ?>">
    <meta name="theme-color" content="#121212">
    <title><?= e($pageTitle) ?> — MusicStream</title>
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
</head>
<body class="<?= $bare ? 'bare' : '' ?>" data-logged="<?= $user ? '1' : '0' ?>">
<div class="app">
<?php if (!$bare): ?>
    <aside class="sidebar" id="sidebar">
        <a class="logo" href="<?= e(url('index.php')) ?>"><span class="logo-ico">♫</span> MusicStream</a>
        <nav class="nav" id="main-nav">
            <?= nav_link('home', $activeNav, 'index.php', '⌂', 'Главная') ?>
            <?= nav_link('search', $activeNav, 'search.php', '⌕', 'Поиск') ?>
            <?= nav_link('playlists', $activeNav, 'playlist.php', '☰', 'Мои плейлисты') ?>
            <?= nav_link('liked', $activeNav, 'playlist.php?liked=1', '♥', 'Любимое') ?>
            <?= nav_link('profile', $activeNav, 'profile.php', '☺', 'Профиль') ?>
        </nav>
        <div class="sidebar-title">Плейлисты</div>
        <div class="sidebar-playlists" id="sidebar-playlists">
            <?php if ($user): ?>
                <?php foreach ($sidebarPlaylists as $sp): ?>
                    <a class="side-pl" href="<?= e(url('playlist.php?id=' . (int)$sp['id'])) ?>"><?= e($sp['name']) ?></a>
                <?php endforeach; ?>
                <a class="side-pl side-pl-new" href="<?= e(url('playlist.php#create')) ?>">＋ Создать плейлист</a>
            <?php else: ?>
                <a class="side-pl" href="<?= e(url('login.php')) ?>" data-hard>Войдите, чтобы создавать плейлисты</a>
            <?php endif; ?>
        </div>
    </aside>
<?php endif; ?>
    <div class="content">
<?php if (!$bare): ?>
        <header class="topbar">
            <button type="button" class="icon-btn burger" data-action="toggle-sidebar" aria-label="Меню">☰</button>
            <div class="topbar-spacer"></div>
            <?php if ($user): ?>
                <div class="user-menu">
                    <a class="user-chip" href="<?= e(url('profile.php')) ?>">
                        <?= avatar_html($user, 'avatar avatar-sm') ?>
                        <span class="user-name"><?= e($user['username']) ?></span>
                    </a>
                    <?php if ($user['role'] === 'admin'): ?>
                        <a class="btn btn-small" href="<?= e(url('admin/index.php')) ?>" data-hard>Админка</a>
                    <?php endif; ?>
                    <a class="btn btn-small" href="<?= e(url('logout.php?t=' . csrf_token())) ?>" data-hard>Выйти</a>
                </div>
            <?php else: ?>
                <div class="user-menu">
                    <a class="btn btn-small" href="<?= e(url('register.php')) ?>" data-hard>Регистрация</a>
                    <a class="btn btn-small btn-primary" href="<?= e(url('login.php')) ?>" data-hard>Войти</a>
                </div>
            <?php endif; ?>
        </header>
<?php endif; ?>
        <main id="main" class="<?= $bare ? 'auth-wrap' : 'main' ?>">
