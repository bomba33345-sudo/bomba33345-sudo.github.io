/**
 * MusicStream — аудиоплеер и интерфейс (Vanilla JS, HTML5 Audio API).
 *
 * Возможности: play/pause, next/prev, перемотка, громкость, очередь, повтор, шаффл,
 * лайки, добавление в плейлист, live-поиск, учёт прослушиваний.
 * Навигация между страницами выполняется через fetch ("мягкая"), поэтому музыка не прерывается.
 * Состояние плеера сохраняется в localStorage и восстанавливается после обычной перезагрузки.
 */
(function () {
    'use strict';

    var audio = document.getElementById('audio');
    var player = document.getElementById('player');
    if (!audio || !player) { return; } // страницы входа/регистрации — без плеера

    function meta(name) {
        var m = document.querySelector('meta[name="' + name + '"]');
        return m ? m.getAttribute('content') : '';
    }
    var BASE = meta('base-url');
    var CSRF = meta('csrf-token');
    var LOGGED = document.body.getAttribute('data-logged') === '1';
    var STORE_KEY = 'musicstream_player_v1';

    function $(sel, root) { return (root || document).querySelector(sel); }
    function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    function fmt(sec) {
        sec = Math.max(0, Math.floor(sec || 0));
        var m = Math.floor(sec / 60), s = sec % 60;
        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    /* ---------------------------------------------------------
       Уведомления и API
       --------------------------------------------------------- */
    var toastTimer = null;
    function toast(msg, isError) {
        var el = $('#toast');
        if (!el) { return; }
        el.textContent = msg;
        el.className = 'toast show' + (isError ? ' error' : '');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { el.className = 'toast'; }, 2400);
    }

    /** Запрос к api.php; всегда возвращает объект (при сбое — {ok:false}) */
    function api(action, data, method) {
        method = method || 'POST';
        var params = new URLSearchParams();
        params.set('action', action);
        Object.keys(data || {}).forEach(function (k) { params.set(k, data[k]); });
        var opts = {
            method: method,
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': CSRF, 'X-Requested-With': 'fetch' }
        };
        var url = BASE + '/api.php';
        if (method === 'GET') { url += '?' + params.toString(); } else { opts.body = params; }
        return fetch(url, opts).then(function (r) {
            return r.json().then(function (j) { j._status = r.status; return j; });
        }).catch(function () { return { ok: false, error: 'network', message: 'Ошибка сети.' }; });
    }

    /* ---------------------------------------------------------
       Состояние плеера
       --------------------------------------------------------- */
    var queue = [];        // массив треков {id,title,artist,src,cover,duration}
    var order = [];        // порядок воспроизведения (индексы queue)
    var pos = -1;          // позиция в order
    var current = null;
    var shuffle = false;
    var repeat = 0;        // 0 — выкл, 1 — всё, 2 — один трек
    var counted = false;   // прослушивание уже засчитано
    var seeking = false;
    var likedMap = {};     // id -> bool (актуальное состояние лайков)
    var lastSave = 0;
    var restoreTime = 0;

    var el = {
        title: $('#pl-title'), artist: $('#pl-artist'), cover: $('#pl-cover'),
        play: $('#pl-play'), prev: $('#pl-prev'), next: $('#pl-next'),
        shuffle: $('#pl-shuffle'), repeat: $('#pl-repeat'),
        seek: $('#pl-seek'), cur: $('#pl-cur'), dur: $('#pl-dur'),
        vol: $('#pl-volume'), mute: $('#pl-mute'),
        like: $('#pl-like'), qPanel: $('#queue-panel'), qList: $('#queue-list')
    };

    function readTrack(node) {
        var d = node.dataset;
        var t = {
            id: parseInt(d.id, 10), title: d.title || '', artist: d.artist || '',
            src: d.src || '', cover: d.cover || '', duration: parseInt(d.duration, 10) || 0
        };
        return t;
    }

    /** Подтягиваем состояние лайков из DOM (после загрузки/смены страницы) */
    function syncLikesFromDOM() {
        $$('[data-track]').forEach(function (n) {
            likedMap[parseInt(n.dataset.id, 10)] = n.dataset.liked === '1';
        });
    }

    function setLikedUI(id, liked) {
        likedMap[id] = liked;
        $$('[data-track][data-id="' + id + '"]').forEach(function (n) {
            n.dataset.liked = liked ? '1' : '0';
            var b = $('.like-btn', n);
            if (b) { b.classList.toggle('liked', liked); }
        });
        if (current && current.id === id && el.like) { el.like.classList.toggle('liked', liked); }
    }

    function coverHTML(node, src) {
        node.innerHTML = '';
        if (src) {
            var img = document.createElement('img');
            img.className = 'cover'; img.alt = ''; img.src = src;
            node.appendChild(img);
        } else {
            var d = document.createElement('div');
            d.className = 'cover cover-ph'; d.textContent = '♪';
            node.appendChild(d);
        }
    }

    /* ---------- Порядок воспроизведения / шаффл ---------- */
    function buildOrder(startIdx) {
        order = queue.map(function (_, i) { return i; });
        if (shuffle) {
            for (var i = order.length - 1; i > 0; i--) {
                var j = Math.floor(Math.random() * (i + 1));
                var tmp = order[i]; order[i] = order[j]; order[j] = tmp;
            }
            var at = order.indexOf(startIdx);
            if (at > 0) { order.splice(at, 1); order.unshift(startIdx); }
        }
        pos = Math.max(0, order.indexOf(startIdx));
    }

    /* ---------- Загрузка и воспроизведение ---------- */
    function loadCurrent(autoplay) {
        if (pos < 0 || pos >= order.length) { return; }
        current = queue[order[pos]];
        counted = false;
        audio.src = current.src;
        if (autoplay) {
            var p = audio.play();
            if (p && p.catch) { p.catch(function () { /* автозапуск заблокирован браузером */ }); }
        }
        updateTrackUI();
        renderQueue();
        markPlaying();
        if (autoplay) { saveState(true); }
    }

    function startQueue(tracks, index) {
        queue = tracks;
        buildOrder(index);
        loadCurrent(true);
    }

    function togglePlay() {
        if (!current) { return; }
        if (audio.paused) {
            var p = audio.play();
            if (p && p.catch) { p.catch(function () {}); }
        } else {
            audio.pause();
        }
    }

    function next(auto) {
        if (!queue.length) { return; }
        if (pos + 1 < order.length) {
            pos++;
            loadCurrent(true);
        } else if (repeat === 1 || !auto) {
            if (shuffle) { buildOrder(order[order.length - 1]); pos = 0; }
            else { pos = 0; }
            loadCurrent(true);
        } else {
            // конец очереди без повтора: останавливаемся на первом треке
            audio.pause();
            pos = 0;
            loadCurrent(false);
        }
    }

    function prev() {
        if (!queue.length) { return; }
        if (audio.currentTime > 3 || pos === 0) {
            audio.currentTime = 0;
            return;
        }
        pos--;
        loadCurrent(true);
    }

    /* ---------- Интерфейс плеера ---------- */
    function updateTrackUI() {
        if (!current) { return; }
        el.title.textContent = current.title;
        el.artist.textContent = current.artist;
        coverHTML(el.cover, current.cover);
        el.dur.textContent = fmt(current.duration);
        el.seek.value = 0;
        setRangeFill(el.seek);
        el.cur.textContent = '0:00';
        if (el.like) { el.like.classList.toggle('liked', !!likedMap[current.id]); }
        document.title = current.title + ' — ' + current.artist;
        if ('mediaSession' in navigator && window.MediaMetadata) {
            navigator.mediaSession.metadata = new MediaMetadata({
                title: current.title, artist: current.artist,
                artwork: current.cover ? [{ src: current.cover }] : []
            });
        }
    }

    function setRangeFill(r) {
        var max = parseFloat(r.max) || 100;
        r.style.setProperty('--p', (parseFloat(r.value) / max * 100) + '%');
    }

    function markPlaying() {
        $$('[data-track].playing').forEach(function (n) { n.classList.remove('playing'); });
        if (current) {
            $$('[data-track][data-id="' + current.id + '"]').forEach(function (n) { n.classList.add('playing'); });
        }
        document.body.classList.toggle('is-playing', !audio.paused && !!current);
    }

    function renderQueue() {
        if (!el.qList) { return; }
        el.qList.innerHTML = '';
        if (!queue.length) {
            var e = document.createElement('div');
            e.className = 'empty'; e.textContent = 'Очередь пуста';
            el.qList.appendChild(e);
            return;
        }
        order.forEach(function (qi, i) {
            var t = queue[qi];
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'queue-item' + (i === pos ? ' current' : '');
            b.setAttribute('data-pos', i);
            var c = document.createElement('div'); c.className = 'q-cover'; coverHTML(c, t.cover);
            var info = document.createElement('div'); info.className = 'q-info';
            var a = document.createElement('div'); a.className = 'q-title'; a.textContent = t.title;
            var b2 = document.createElement('div'); b2.className = 'q-artist'; b2.textContent = t.artist;
            info.appendChild(a); info.appendChild(b2);
            b.appendChild(c); b.appendChild(info);
            el.qList.appendChild(b);
        });
        var cur = $('.queue-item.current', el.qList);
        if (cur && cur.scrollIntoView) { cur.scrollIntoView({ block: 'nearest' }); }
    }

    /* ---------- События <audio> ---------- */
    audio.addEventListener('play', function () { document.body.classList.add('is-playing'); });
    audio.addEventListener('pause', function () { document.body.classList.remove('is-playing'); saveState(true); });
    audio.addEventListener('loadedmetadata', function () {
        if (isFinite(audio.duration) && audio.duration > 0) { el.dur.textContent = fmt(audio.duration); }
        if (restoreTime > 0) { audio.currentTime = Math.min(restoreTime, audio.duration || restoreTime); restoreTime = 0; }
    });
    audio.addEventListener('timeupdate', function () {
        var d = isFinite(audio.duration) && audio.duration > 0 ? audio.duration : (current ? current.duration : 0);
        if (!seeking) {
            el.cur.textContent = fmt(audio.currentTime);
            el.seek.value = d ? Math.round(audio.currentTime / d * 1000) : 0;
            setRangeFill(el.seek);
        }
        // Засчитываем прослушивание после 5 секунд (или половины очень короткого трека)
        if (!counted && current && audio.currentTime >= Math.min(5, (d || 10) / 2)) {
            counted = true;
            api('play', { track_id: current.id });
        }
        var now = Date.now();
        if (now - lastSave > 5000) { saveState(); }
    });
    audio.addEventListener('ended', function () {
        if (repeat === 2) {
            audio.currentTime = 0;
            var p = audio.play();
            if (p && p.catch) { p.catch(function () {}); }
        } else {
            next(true);
        }
    });
    audio.addEventListener('error', function () {
        if (current) { toast('Не удалось загрузить трек «' + current.title + '»', true); }
    });

    /* ---------- Элементы управления ---------- */
    el.play.addEventListener('click', togglePlay);
    el.next.addEventListener('click', function () { next(false); });
    el.prev.addEventListener('click', prev);

    el.shuffle.addEventListener('click', function () {
        shuffle = !shuffle;
        el.shuffle.classList.toggle('active', shuffle);
        if (queue.length && pos >= 0) {
            buildOrder(order[pos]);
            renderQueue();
        }
        saveState(true);
    });
    el.repeat.addEventListener('click', function () {
        repeat = (repeat + 1) % 3;
        applyRepeatUI();
        saveState(true);
    });
    function applyRepeatUI() {
        el.repeat.classList.toggle('active', repeat > 0);
        el.repeat.classList.toggle('one', repeat === 2);
        el.repeat.title = ['Повтор выключен', 'Повторять всё', 'Повторять трек'][repeat];
    }

    // Перемотка
    el.seek.addEventListener('pointerdown', function () { seeking = true; });
    el.seek.addEventListener('input', function () {
        seeking = true;
        var d = isFinite(audio.duration) && audio.duration > 0 ? audio.duration : (current ? current.duration : 0);
        el.cur.textContent = fmt(el.seek.value / 1000 * d);
        setRangeFill(el.seek);
    });
    el.seek.addEventListener('change', function () {
        var d = isFinite(audio.duration) && audio.duration > 0 ? audio.duration : (current ? current.duration : 0);
        if (d) { audio.currentTime = el.seek.value / 1000 * d; }
        seeking = false;
    });

    // Громкость
    var lastVolume = 80;
    function setVolume(v) {
        v = Math.min(100, Math.max(0, v));
        audio.volume = v / 100;
        el.vol.value = v;
        setRangeFill(el.vol);
        el.mute.style.opacity = v === 0 ? '.5' : '1';
    }
    el.vol.addEventListener('input', function () {
        setVolume(parseInt(el.vol.value, 10));
        if (audio.volume > 0) { lastVolume = parseInt(el.vol.value, 10); }
        saveState(true);
    });
    el.mute.addEventListener('click', function () {
        if (audio.volume > 0) { lastVolume = Math.round(audio.volume * 100); setVolume(0); }
        else { setVolume(lastVolume || 80); }
        saveState(true);
    });

    // Системные медиа-клавиши и экран блокировки
    if ('mediaSession' in navigator) {
        try {
            navigator.mediaSession.setActionHandler('play', togglePlay);
            navigator.mediaSession.setActionHandler('pause', togglePlay);
            navigator.mediaSession.setActionHandler('previoustrack', prev);
            navigator.mediaSession.setActionHandler('nexttrack', function () { next(false); });
        } catch (e) { /* не поддерживается */ }
    }

    // Пробел — пауза/играть (если не вводим текст)
    document.addEventListener('keydown', function (e) {
        if (e.code !== 'Space' || e.target.closest('input, textarea, select, button, a, [contenteditable]')) { return; }
        e.preventDefault();
        togglePlay();
    });

    /* ---------------------------------------------------------
       Сохранение / восстановление состояния
       --------------------------------------------------------- */
    function saveState(force) {
        lastSave = Date.now();
        try {
            localStorage.setItem(STORE_KEY, JSON.stringify({
                queue: queue.slice(0, 200), idx: pos >= 0 ? order[pos] : -1,
                time: audio.currentTime || 0, shuffle: shuffle, repeat: repeat,
                volume: Math.round(audio.volume * 100)
            }));
        } catch (e) { /* localStorage недоступен */ }
    }
    window.addEventListener('pagehide', function () { saveState(true); });

    function restoreState() {
        var s = null;
        try { s = JSON.parse(localStorage.getItem(STORE_KEY) || 'null'); } catch (e) { s = null; }
        setVolume(s && typeof s.volume === 'number' ? s.volume : 80);
        if (!s) { applyRepeatUI(); return; }
        shuffle = !!s.shuffle;
        repeat = s.repeat || 0;
        el.shuffle.classList.toggle('active', shuffle);
        applyRepeatUI();
        if (s.queue && s.queue.length && s.idx >= 0 && s.idx < s.queue.length) {
            queue = s.queue;
            buildOrder(s.idx);
            restoreTime = s.time || 0;
            loadCurrent(false);
        }
    }

    /* ---------------------------------------------------------
       Действия на странице (делегирование событий)
       --------------------------------------------------------- */
    function playFromNode(node) {
        var id = parseInt(node.dataset.id, 10);
        // Тот же трек уже играет — просто пауза/продолжить
        if (current && current.id === id && node.classList.contains('playing')) {
            togglePlay();
            return;
        }
        var container = node.closest('[data-queue]');
        var nodes = container ? $$('[data-track]', container) : [node];
        var idx = nodes.indexOf(node);
        if (idx < 0) { nodes = [node]; idx = 0; }
        startQueue(nodes.map(readTrack), idx);
    }

    function requireLogin() {
        if (LOGGED) { return true; }
        toast('Войдите в аккаунт, чтобы это сделать', true);
        setTimeout(function () { location.href = BASE + '/login.php'; }, 900);
        return false;
    }

    function toggleLike(id) {
        if (!requireLogin()) { return; }
        api('like', { track_id: id }).then(function (r) {
            if (r.ok) {
                setLikedUI(id, r.liked);
                toast(r.liked ? 'Добавлено в «Любимое»' : 'Удалено из «Любимого»');
                // На странице «Любимое» убираем строку сразу
                if (!r.liked && /[?&]liked(=|&|$)/.test(location.search)) {
                    $$('#playlist-tracks [data-track][data-id="' + id + '"]').forEach(function (n) { n.remove(); });
                }
            } else { toast(r.message || 'Ошибка', true); }
        });
    }

    /* ---------- Модальное окно «В плейлист» ---------- */
    function openModal(title) {
        var back = document.createElement('div');
        back.className = 'modal-backdrop';
        back.innerHTML = '<div class="modal" role="dialog" aria-modal="true"><div class="modal-head"><h3></h3>' +
            '<button type="button" class="icon-btn" data-modal-close aria-label="Закрыть">✕</button></div>' +
            '<div class="modal-body"></div></div>';
        $('h3', back).textContent = title;
        back.addEventListener('click', function (e) {
            if (e.target === back || e.target.closest('[data-modal-close]')) { back.remove(); }
        });
        document.body.appendChild(back);
        return $('.modal-body', back);
    }

    function openPlaylistModal(trackId) {
        if (!requireLogin()) { return; }
        var body = openModal('Добавить в плейлист');
        body.textContent = 'Загрузка…';
        api('my_playlists', { track_id: trackId }, 'GET').then(function (r) {
            if (!r.ok) { body.textContent = r.message || 'Ошибка'; return; }
            body.innerHTML = '';
            var list = document.createElement('div');
            body.appendChild(list);

            function addItem(p) {
                var b = document.createElement('button');
                b.type = 'button'; b.className = 'modal-item';
                var n = document.createElement('span'); n.textContent = p.name;
                var tick = document.createElement('span'); tick.className = 'tick'; tick.textContent = p.has ? '✓' : '';
                b.appendChild(n); b.appendChild(tick);
                b.addEventListener('click', function () {
                    var act = p.has ? 'remove_from_playlist' : 'add_to_playlist';
                    api(act, { playlist_id: p.id, track_id: trackId }).then(function (res) {
                        if (res.ok) {
                            p.has = !p.has;
                            tick.textContent = p.has ? '✓' : '';
                            toast(p.has ? 'Добавлено в «' + p.name + '»' : 'Удалено из «' + p.name + '»');
                        } else { toast(res.message || 'Ошибка', true); }
                    });
                });
                list.appendChild(b);
            }
            if (!r.playlists.length) {
                var hint = document.createElement('p');
                hint.className = 'muted'; hint.textContent = 'У вас ещё нет плейлистов — создайте первый:';
                list.appendChild(hint);
            }
            r.playlists.forEach(addItem);

            var form = document.createElement('form');
            form.className = 'modal-create';
            form.innerHTML = '<input type="text" placeholder="Новый плейлист" maxlength="100" required>' +
                '<button type="submit" class="btn btn-primary btn-small">Создать</button>';
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var name = $('input', form).value.trim();
                if (!name) { return; }
                api('create_playlist', { name: name, track_id: trackId }).then(function (res) {
                    if (res.ok) {
                        addItem({ id: res.id, name: res.name, has: true });
                        $('input', form).value = '';
                        toast('Плейлист «' + res.name + '» создан');
                    } else { toast(res.message || 'Ошибка', true); }
                });
            });
            body.appendChild(form);
        });
    }

    /** Перезагрузить кусок страницы (например, список треков плейлиста) без перезагрузки всей страницы */
    function refreshRegion(selector) {
        return fetch(location.href, { credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (t) {
            var doc = new DOMParser().parseFromString(t, 'text/html');
            var fresh = doc.querySelector(selector), old = $(selector);
            if (fresh && old) { old.innerHTML = fresh.innerHTML; markPlaying(); syncLikesFromDOM(); }
        });
    }

    /* ---------- Главный обработчик кликов ---------- */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-action]');
        if (btn) {
            var action = btn.getAttribute('data-action');
            var node = btn.closest('[data-track]');
            switch (action) {
                case 'play-track':
                    if (node) { e.preventDefault(); playFromNode(node); }
                    return;
                case 'like':
                    e.preventDefault();
                    if (node) { toggleLike(parseInt(node.dataset.id, 10)); }
                    else if (current) { toggleLike(current.id); }
                    return;
                case 'add-to-playlist':
                    e.preventDefault();
                    if (node) { openPlaylistModal(parseInt(node.dataset.id, 10)); }
                    return;
                case 'remove-from-playlist':
                    e.preventDefault();
                    if (node) {
                        var rid = parseInt(node.dataset.id, 10);
                        api('remove_from_playlist', { playlist_id: btn.getAttribute('data-playlist'), track_id: rid }).then(function (r) {
                            if (r.ok) { node.remove(); toast('Трек удалён из плейлиста'); }
                            else { toast(r.message || 'Ошибка', true); }
                        });
                    }
                    return;
                case 'add-here':
                    e.preventDefault();
                    if (node) {
                        api('add_to_playlist', { playlist_id: btn.getAttribute('data-playlist'), track_id: node.dataset.id }).then(function (r) {
                            if (r.ok) {
                                btn.textContent = '✓ В плейлисте'; btn.disabled = true; btn.classList.remove('btn-primary');
                                toast(r.added ? 'Трек добавлен' : 'Трек уже в плейлисте');
                                refreshRegion('#playlist-tracks');
                            } else { toast(r.message || 'Ошибка', true); }
                        });
                    }
                    return;
                case 'play-all':
                    e.preventDefault();
                    var target = $(btn.getAttribute('data-target'));
                    if (target) {
                        var nodes = $$('[data-track]', target);
                        if (nodes.length) { startQueue(nodes.map(readTrack), 0); }
                    }
                    return;
                case 'open-queue':
                    el.qPanel.hidden = !el.qPanel.hidden;
                    if (!el.qPanel.hidden) { renderQueue(); }
                    return;
                case 'close-queue':
                    el.qPanel.hidden = true;
                    return;
                case 'toggle-sidebar':
                    $('#sidebar').classList.toggle('open');
                    return;
            }
        }

        // Клик по элементу очереди
        var qi = e.target.closest('.queue-item');
        if (qi) {
            pos = parseInt(qi.getAttribute('data-pos'), 10);
            loadCurrent(true);
            return;
        }

        // Клик по строке/карточке трека (не по кнопке/ссылке) — играть
        var row = e.target.closest('[data-track]');
        if (row && !e.target.closest('a, button, input, select, textarea')) {
            playFromNode(row);
            return;
        }

        // Закрыть очередь кликом вне её
        if (!el.qPanel.hidden && !e.target.closest('#queue-panel')) { el.qPanel.hidden = true; }

        // Мобильное меню: закрыть при клике вне
        var sb = $('#sidebar');
        if (sb && sb.classList.contains('open') && !e.target.closest('#sidebar')) { sb.classList.remove('open'); }

        // Мягкая навигация
        var a = e.target.closest('a[href]');
        if (a && isSoftLink(a, e)) {
            e.preventDefault();
            navigate(a.href, true);
        }
    });

    /* ---------------------------------------------------------
       Live-поиск (api.php?action=search)
       --------------------------------------------------------- */
    var searchSeq = 0;
    function initLiveSearch() {
        $$('[data-live-search]').forEach(function (input) {
            if (input.__bound) { return; }
            input.__bound = true;
            var timer = null;
            input.addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(function () { runSearch(input); }, 250);
            });
            input.form && input.form.addEventListener('submit', function (e) {
                if (input.hasAttribute('data-sync-url')) { e.preventDefault(); runSearch(input); }
            });
        });
    }
    function runSearch(input) {
        var target = $(input.getAttribute('data-results'));
        if (!target) { return; }
        var q = input.value.trim();
        var genre = input.getAttribute('data-genre') || '';
        var playlist = input.getAttribute('data-playlist') || '';
        var seq = ++searchSeq;

        if (input.hasAttribute('data-sync-url') && window.history.replaceState) {
            var u = new URL(location.href);
            if (q) { u.searchParams.set('q', q); } else { u.searchParams.delete('q'); }
            history.replaceState(null, '', u.toString());
        }
        if (q === '' && genre === '') {
            target.innerHTML = playlist ? '' : '<div class="empty">Начните вводить название, исполнителя или альбом — результаты появятся сразу.</div>';
            return;
        }
        var data = { q: q };
        if (genre) { data.genre = genre; }
        if (playlist) { data.playlist_id = playlist; }
        api('search', data, 'GET').then(function (r) {
            if (seq !== searchSeq) { return; } // пришёл устаревший ответ
            if (r.ok) { target.innerHTML = r.html; markPlaying(); syncLikesFromDOM(); }
        });
    }

    /* ---------------------------------------------------------
       Мягкая навигация (музыка не прерывается)
       --------------------------------------------------------- */
    var HARD = /\/(login|register|logout|install|api)\.php$|\/admin(\/|$)|\/uploads\//;

    function isSoftLink(a, e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return false; }
        if (a.hasAttribute('download') || a.hasAttribute('data-hard')) { return false; }
        if (a.target && a.target !== '_self') { return false; }
        var u;
        try { u = new URL(a.href, location.href); } catch (err) { return false; }
        if (u.origin !== location.origin) { return false; }
        if (BASE && u.pathname.indexOf(BASE + '/') !== 0 && u.pathname !== BASE) { return false; }
        if (HARD.test(u.pathname)) { return false; }
        if (u.pathname === location.pathname && u.search === location.search && u.hash) { return false; } // якорь
        return true;
    }

    function navigate(url, push) {
        fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'softnav' } })
            .then(function (r) {
                return r.text().then(function (t) { return { text: t, finalUrl: r.url, ok: r.ok }; });
            })
            .then(function (res) {
                var doc = new DOMParser().parseFromString(res.text, 'text/html');
                var newMain = doc.getElementById('main');
                // Страница без плеера (вход, ошибка) — обычный переход
                if (!newMain || !doc.getElementById('player')) { location.href = res.finalUrl || url; return; }

                $('#main').innerHTML = newMain.innerHTML;
                $('#main').className = newMain.className;
                var nav = $('#main-nav'), newNav = doc.getElementById('main-nav');
                if (nav && newNav) { nav.innerHTML = newNav.innerHTML; }
                var sp = $('#sidebar-playlists'), newSp = doc.getElementById('sidebar-playlists');
                if (sp && newSp) { sp.innerHTML = newSp.innerHTML; }
                if (!audio.paused && current) { document.title = current.title + ' — ' + current.artist; } else { document.title = doc.title; }
                var hash = new URL(url, location.href).hash;
                if (push) { history.pushState({ soft: true }, '', (res.finalUrl || url).split('#')[0] + hash); }
                window.scrollTo(0, 0);
                afterRender();
                if (hash) {
                    var anchor = document.getElementById(hash.slice(1));
                    if (anchor) { anchor.scrollIntoView(); }
                }
            })
            .catch(function () { location.href = url; });
    }

    window.addEventListener('popstate', function () { navigate(location.href, false); });

    function afterRender() {
        syncLikesFromDOM();
        markPlaying();
        initLiveSearch();
        var sb = $('#sidebar');
        if (sb) { sb.classList.remove('open'); }
        // Если открыт поиск с ?q= и ничего не отрисовано — запускаем поиск
        $$('[data-live-search]').forEach(function (inp) {
            var target = $(inp.getAttribute('data-results'));
            if (target && inp.value.trim() !== '' && !$('[data-track]', target) && !$('.empty', target)) { runSearch(inp); }
        });
    }

    /* ---------- Старт ---------- */
    restoreState();
    syncLikesFromDOM();
    if (current && el.like) { el.like.classList.toggle('liked', !!likedMap[current.id]); }
    afterRender();
})();
