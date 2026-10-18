# ♫ MusicStream

Музыкальный стриминговый сервис в стиле Spotify / Яндекс Музыки на **чистом PHP 8 + MySQL + Vanilla JS** — без фреймворков, Composer и Node.js. Подходит для публикации на GitHub и запуска на XAMPP.

![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb3) ![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-00758f) ![License](https://img.shields.io/badge/license-MIT-green)

## Возможности

**Пользователям**
- Регистрация (username, email, пароль + подтверждение), вход по имени или email, «запомнить меня» на 30 дней
- Главная: новинки, популярное, жанры
- Live-поиск по названию / исполнителю / альбому (AJAX), фильтр по жанрам
- Личный кабинет: смена аватара и пароля
- Плейлисты: создание, редактирование, удаление, обложка, публичные/приватные, добавление и удаление треков
- Лайки (♥) и страница «Любимое»
- Аудиоплеер внизу экрана: play/pause, next/prev, перемотка, громкость, очередь, повтор (выкл / всё / один), шаффл, обложка трека, медиа-клавиши
- Музыка **не прерывается** при переходе между страницами (мягкая навигация), состояние плеера сохраняется в `localStorage`
- Счётчик прослушиваний (засчитывается после 5 секунд, с защитой от накрутки)
- Адаптивная вёрстка, тёмная тема (`#121212` / `#1DB954`)

**Админ-панель** (`/admin/`, только `role = 'admin'`)
- Dashboard: пользователи, треки, прослушивания, плейлисты, лайки, топ-5 треков
- Треки: добавление / редактирование / удаление, загрузка аудио и обложек, автоопределение длительности
- Пользователи: поиск, бан / разбан, смена роли, удаление
- Плейлисты: просмотр и удаление

## Структура проекта

```
musicstream/
├── .htaccess
├── config/
│   ├── database.php        # параметры БД, BASE_URL, PDO
│   └── .htaccess           # закрыт от прямого доступа
├── includes/
│   ├── functions.php       # сессии, CSRF, авторизация, загрузки, шаблоны треков
│   ├── header.php / footer.php
│   ├── admin_header.php / admin_footer.php
│   └── .htaccess
├── admin/
│   ├── index.php           # вход в админку + Dashboard
│   ├── tracks.php          # CRUD треков
│   ├── users.php           # пользователи
│   ├── playlists.php       # плейлисты
│   └── logout.php
├── assets/
│   ├── css/style.css
│   └── js/player.js
├── uploads/
│   ├── .htaccess           # запрет выполнения PHP/скриптов
│   ├── audio/  covers/  avatars/
├── install.php             # установщик (БД, таблицы, админ, демо-треки)
├── index.php  register.php  login.php  logout.php
├── search.php  playlist.php  profile.php
├── api.php                 # JSON API
└── README.md
```

## Установка (XAMPP + phpMyAdmin)

1. **Установите XAMPP** (PHP 8.0 или новее) и запустите в XAMPP Control Panel модули **Apache** и **MySQL**.
2. **Скопируйте папку** `musicstream` в `C:\xampp\htdocs\` (macOS/Linux: `/opt/lampp/htdocs/`).
3. **Проверьте настройки** в `config/database.php`. В XAMPP по умолчанию подходят значения `root` и пустой пароль:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'musicstream');
   define('DB_USER', 'root');
   define('DB_PASS', '');
   ```
4. **Откройте установщик:** <http://localhost/musicstream/install.php>
   Он создаст базу `musicstream` (можно убедиться в phpMyAdmin: <http://localhost/phpmyadmin>), все таблицы, администратора и 3 демо-трека.
5. Перейдите на главную: <http://localhost/musicstream/>
6. **Удалите `install.php`** с сервера после установки.

### Доступ в админ-панель

| URL | Логин | Пароль |
|-----|-------|--------|
| `/musicstream/admin/` | `admin` | `admin123` |

> ⚠ Сразу после установки смените пароль на странице «Профиль». Пароль хранится только в виде `password_hash`.

### Загрузка больших файлов

По умолчанию PHP ограничивает загрузку 2 МБ — для MP3 этого мало. В `php.ini` (в XAMPP: Config → PHP (php.ini)) измените и перезапустите Apache:

```ini
upload_max_filesize = 50M
post_max_size = 55M
max_execution_time = 120
```

### Сайт не в подпапке `musicstream`

`BASE_URL` определяется автоматически. Если сайт лежит в корне домена или автоопределение ошиблось, задайте путь вручную в начале `config/database.php`:

```php
define('BASE_URL', '/mymusic');   // или '' для корня
```

## Демо-данные

`install.php` создаёт 3 демо-трека. Аудио (WAV с короткими мелодиями) и SVG-обложки **генерируются кодом** — внешние файлы не нужны:

| Трек | Исполнитель | Жанр |
|------|-------------|------|
| Sunrise Arpeggio | Demo Artist | Electronic |
| Night Drive | Synth Ghost | Synthwave |
| Lazy Sunday | Chill Collective | Chill |

Реальные треки добавляются в админке: **Треки → Добавить трек** (MP3, OGG, WAV или M4A до 50 МБ; обложка JPG/PNG/GIF/WEBP до 5 МБ).

## База данных

| Таблица | Назначение |
|---------|------------|
| `users` | id, username (UNIQUE), email (UNIQUE), password, role ENUM(user, admin), avatar, is_banned, remember_token, created_at |
| `tracks` | id, title, artist, album, genre, duration, audio_file, cover, plays, uploader_id → users (SET NULL), created_at |
| `playlists` | id, name, description, cover, user_id → users (CASCADE), is_public, created_at |
| `playlist_tracks` | id, playlist_id → playlists (CASCADE), track_id → tracks (CASCADE), position, UNIQUE(playlist_id, track_id) |
| `likes` | id, user_id → users (CASCADE), track_id → tracks (CASCADE), UNIQUE(user_id, track_id) |

Поля `is_banned` и `remember_token` добавлены к минимальной схеме для функций бана и «запомнить меня».

## API (`api.php`)

Все ответы — JSON. Параметры принимаются через GET или POST. Изменяющие действия требуют активную сессию и CSRF-токен (заголовок `X-CSRF-Token` или параметр `csrf`; токен лежит в `<meta name="csrf-token">`).

| Запрос | Описание |
|--------|----------|
| `?action=like&track_id=X` | переключить лайк (нужен вход) |
| `?action=play&track_id=X` | +1 к прослушиваниям |
| `?action=search&q=text[&genre=G]` | поиск; в ответе массив `tracks` и готовый `html` |
| `?action=add_to_playlist&playlist_id=X&track_id=Y` | добавить трек в свой плейлист |
| `?action=remove_from_playlist&playlist_id=X&track_id=Y` | убрать трек из своего плейлиста |
| `?action=my_playlists[&track_id=Y]` | мои плейлисты (с отметкой, есть ли в них трек) |
| `?action=create_playlist&name=N[&track_id=Y]` | создать плейлист |

## Безопасность

- PDO + prepared statements везде (`ATTR_EMULATE_PREPARES = false`)
- `password_hash` / `password_verify`, автоматический rehash
- CSRF-токены во всех формах и изменяющих API-запросах; выход — по ссылке с токеном
- Экранирование вывода (`htmlspecialchars`) и безопасная сборка DOM в JS — защита от XSS
- Загрузки: белый список расширений, проверка типа (`getimagesize` / `finfo`), случайные имена файлов
- `uploads/.htaccess` запрещает выполнение скриптов; `config/` и `includes/` закрыты от прямого доступа
- Защита от перебора пароля (5 попыток → пауза 60 секунд), `session_regenerate_id` при входе
- Cookie сессии: `HttpOnly`, `SameSite=Lax`, `Secure` при HTTPS
- Каждая страница админки проверяет `role = 'admin'`; админ не может изменить свой аккаунт

> `.htaccess` работает на Apache (в XAMPP включено по умолчанию). Для Nginx перенесите правила в конфигурацию сервера: запретите исполнение PHP в `uploads/` и доступ к `config/` и `includes/`.

## Публикация на GitHub

```bash
cd musicstream
git init
git add .
git commit -m "MusicStream: initial commit"
git branch -M main
git remote add origin https://github.com/<ваш-логин>/musicstream.git
git push -u origin main
```

Загруженные пользователями файлы (`uploads/audio|covers|avatars`) исключены через `.gitignore`.

## Лицензия

MIT — используйте свободно.
