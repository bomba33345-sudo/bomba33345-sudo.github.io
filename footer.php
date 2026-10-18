<?php
/**
 * Общий подвал: закрывает разметку, выводит фиксированный аудиоплеер и подключает JS.
 * Переменная $bare (true) — плеер и скрипты не выводятся (страницы входа/регистрации).
 */
$bare = $bare ?? false;
?>
        </main>
    </div><!-- /.content -->
</div><!-- /.app -->

<?php if (!$bare): ?>
<footer class="player" id="player">
    <div class="pl-track">
        <div class="pl-cover" id="pl-cover"><div class="cover cover-ph">♪</div></div>
        <div class="pl-info">
            <div class="pl-title" id="pl-title">Выберите трек</div>
            <div class="pl-artist" id="pl-artist">MusicStream</div>
        </div>
        <button type="button" class="icon-btn like-btn" id="pl-like" data-action="like" aria-label="Нравится">♥</button>
    </div>

    <div class="pl-center">
        <div class="pl-controls">
            <button type="button" class="icon-btn" id="pl-shuffle" title="Перемешать" aria-label="Перемешать">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M10.6 9.2 5.4 4 4 5.4l5.2 5.2 1.4-1.4zM14.5 4l2.05 2.05L4 18.6 5.4 20l12.55-12.55L20 9.5V4h-5.5zm.5 10.8-1.4 1.4 2.45 2.45L14.5 20H20v-5.5l-2.05 2.05L15 14.8z"/></svg>
            </button>
            <button type="button" class="icon-btn" id="pl-prev" title="Предыдущий" aria-label="Предыдущий">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M6 6h2v12H6zm3.5 6 8.5 6V6z"/></svg>
            </button>
            <button type="button" class="play-btn" id="pl-play" title="Играть / пауза" aria-label="Играть">
                <svg class="ico-play" viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                <svg class="ico-pause" viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M6 19h4V5H6zm8-14v14h4V5z"/></svg>
            </button>
            <button type="button" class="icon-btn" id="pl-next" title="Следующий" aria-label="Следующий">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="m6 18 8.5-6L6 6zm10-12v12h2V6z"/></svg>
            </button>
            <button type="button" class="icon-btn" id="pl-repeat" title="Повтор" aria-label="Повтор">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M7 7h10v3l4-4-4-4v3H5v6h2zm10 10H7v-3l-4 4 4 4v-3h12v-6h-2z"/></svg>
                <span class="repeat-one">1</span>
            </button>
            <button type="button" class="icon-btn" id="pl-queue-btn" data-action="open-queue" title="Очередь" aria-label="Очередь">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M3 13h12v-2H3zm0-6v2h12V7zm0 10h8v-2H3zm14-4v6l5-3z"/></svg>
            </button>
        </div>
        <div class="pl-progress">
            <span class="pl-time" id="pl-cur">0:00</span>
            <input type="range" class="range" id="pl-seek" min="0" max="1000" value="0" step="1" aria-label="Перемотка">
            <span class="pl-time" id="pl-dur">0:00</span>
        </div>
    </div>

    <div class="pl-right">
        <button type="button" class="icon-btn" id="pl-mute" title="Звук" aria-label="Звук">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3A4.5 4.5 0 0 0 14 7.97v8.05c1.48-.73 2.5-2.25 2.5-4.02z"/></svg>
        </button>
        <input type="range" class="range range-vol" id="pl-volume" min="0" max="100" value="80" aria-label="Громкость">
    </div>

    <div class="queue-panel" id="queue-panel" hidden>
        <div class="queue-head"><strong>Очередь</strong>
            <button type="button" class="icon-btn" data-action="close-queue" aria-label="Закрыть">✕</button>
        </div>
        <div class="queue-list" id="queue-list"></div>
    </div>

    <audio id="audio" preload="metadata"></audio>
</footer>
<div class="toast" id="toast" role="status" aria-live="polite"></div>
<script src="<?= e(url('assets/js/player.js')) ?>" defer></script>
<?php endif; ?>
</body>
</html>
