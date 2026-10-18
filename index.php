<?php
/**
 * Главная страница: новинки, популярное, жанры.
 */
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Главная';
$activeNav = 'home';

$newTracks = tracks_query('ORDER BY t.created_at DESC, t.id DESC LIMIT 12');
$popular   = tracks_query('ORDER BY t.plays DESC, t.id DESC LIMIT 10');
$genres    = db()->query(
    "SELECT genre, COUNT(*) AS cnt FROM tracks WHERE genre <> '' GROUP BY genre ORDER BY cnt DESC, genre LIMIT 12"
)->fetchAll();

$hour = (int)date('G');
if ($hour < 5)       $hello = 'Доброй ночи';
elseif ($hour < 12)  $hello = 'Доброе утро';
elseif ($hour < 18)  $hello = 'Добрый день';
else                 $hello = 'Добрый вечер';
$u = current_user();

require __DIR__ . '/includes/header.php';
?>
<h1 class="page-title"><?= e($hello) ?><?= $u ? ', ' . e($u['username']) : '' ?></h1>
<?= render_flash() ?>

<?php if (!$newTracks): ?>
    <div class="empty">В каталоге пока нет треков.
        <?php if (is_admin()): ?><a href="<?= e(url('admin/tracks.php?new=1')) ?>" data-hard>Добавить трек</a><?php endif; ?>
    </div>
<?php else: ?>

<section class="section">
    <div class="section-head"><h2>Новинки</h2></div>
    <div class="grid" data-queue>
        <?php foreach ($newTracks as $t) echo render_track_card($t); ?>
    </div>
</section>

<section class="section">
    <div class="section-head">
        <h2>Популярное</h2>
        <button type="button" class="btn btn-small btn-primary" data-action="play-all" data-target="#popular-list">▶ Слушать</button>
    </div>
    <div class="track-list" id="popular-list" data-queue>
        <?php foreach ($popular as $i => $t) echo render_track_row($t, ['index' => $i + 1]); ?>
    </div>
</section>

<?php if ($genres): ?>
<section class="section">
    <div class="section-head"><h2>Жанры</h2></div>
    <div class="genre-grid">
        <?php foreach ($genres as $g): ?>
            <a class="genre-card" style="background: <?= e(genre_color($g['genre'])) ?>"
               href="<?= e(url('search.php?genre=' . rawurlencode($g['genre']))) ?>">
                <span class="genre-name"><?= e($g['genre']) ?></span>
                <span class="genre-cnt"><?= (int)$g['cnt'] ?> тр.</span>
            </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
