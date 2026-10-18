<?php
/**
 * Поиск по названию / исполнителю / альбому с AJAX live-search (api.php?action=search).
 * Без JS страница тоже работает: результаты рендерятся на сервере.
 */
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Поиск';
$activeNav = 'search';

$q     = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$genre = mb_substr(trim((string)($_GET['genre'] ?? '')), 0, 100);

$genres = db()->query("SELECT genre, COUNT(*) AS cnt FROM tracks WHERE genre <> '' GROUP BY genre ORDER BY cnt DESC, genre")->fetchAll();

$tracks = [];
if ($q !== '' || $genre !== '') {
    $where = [];
    $params = [];
    if ($q !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $where[] = '(t.title LIKE ? OR t.artist LIKE ? OR t.album LIKE ?)';
        array_push($params, $like, $like, $like);
    }
    if ($genre !== '') {
        $where[] = 't.genre = ?';
        $params[] = $genre;
    }
    $tracks = tracks_query('WHERE ' . implode(' AND ', $where) . ' ORDER BY t.plays DESC, t.id DESC LIMIT 50', $params);
}

require __DIR__ . '/includes/header.php';
?>
<h1 class="page-title">Поиск</h1>

<form class="search-form" method="get" action="<?= e(url('search.php')) ?>" role="search">
    <input type="search" id="live-search" name="q" value="<?= e($q) ?>" placeholder="Трек, исполнитель или альбом"
           autocomplete="off" data-live-search data-results="#search-results" data-genre="<?= e($genre) ?>" data-sync-url>
    <?php if ($genre !== ''): ?><input type="hidden" name="genre" value="<?= e($genre) ?>"><?php endif; ?>
    <button type="submit" class="btn btn-primary">Найти</button>
</form>

<?php if ($genres): ?>
<div class="chips">
    <a class="chip<?= $genre === '' ? ' active' : '' ?>" href="<?= e(url('search.php' . ($q !== '' ? '?q=' . rawurlencode($q) : ''))) ?>">Все жанры</a>
    <?php foreach ($genres as $g): ?>
        <a class="chip<?= $genre === $g['genre'] ? ' active' : '' ?>"
           href="<?= e(url('search.php?genre=' . rawurlencode($g['genre']) . ($q !== '' ? '&q=' . rawurlencode($q) : ''))) ?>"><?= e($g['genre']) ?></a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="track-list" id="search-results" data-queue>
    <?php if ($tracks): ?>
        <?php foreach ($tracks as $i => $t) echo render_track_row($t, ['index' => $i + 1]); ?>
    <?php elseif ($q !== '' || $genre !== ''): ?>
        <div class="empty">Ничего не найдено по запросу «<?= e($q !== '' ? $q : $genre) ?>».</div>
    <?php else: ?>
        <div class="empty">Начните вводить название, исполнителя или альбом — результаты появятся сразу.</div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
