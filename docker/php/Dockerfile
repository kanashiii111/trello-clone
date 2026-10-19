FROM php:8.5-fpm

# UID/GID хостового пользователя — чтобы www-data внутри контейнера совпадал
# с владельцем bind mount на хосте (актуально для Linux, на macOS/Docker Desktop
# не критично, но не мешает)
ARG UID=1000
ARG GID=1000

# Системные библиотеки для расширений (pdo_pgsql/intl/zip) и libfcgi-bin —
# он даёт бинарник cgi-fcgi, которым healthcheck дёргает php-fpm за /ping.
# opcache начиная с PHP 8.5 вкомпилирован в ядро и отдельной сборки не требует
# (docker-php-ext-install opcache падает с "cannot stat 'modules/*'" — модуля больше нет).
# Кэш apt чистим в этом же RUN, чтобы он не оседал отдельным слоем образа
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev \
        libicu-dev \
        libzip-dev \
        libfcgi-bin \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql \
    && docker-php-ext-install -j"$(nproc)" intl \
    && docker-php-ext-install -j"$(nproc)" zip \
    && rm -rf /var/lib/apt/lists/*

# APCu — общая память, которую видят все воркеры php-fpm. Нужна для метрик приложения:
# PHP устроен как shared-nothing — каждый запрос начинается с чистого листа, а воркеры
# пула — отдельные процессы. Счётчик в обычной переменной умрёт в конце запроса,
# а Prometheus, придя за /metrics.php, попадёт в случайный воркер. Значит, счётчики
# надо копить вне процесса: APCu (память мастер-процесса fpm) — самый простой вариант
# для одного контейнера; при нескольких репликах php берут Redis.
# Версию фиксируем, чтобы сборка была воспроизводимой. Компилятор и phpize уже есть
# в Debian-варианте официального образа (PHPIZE_DEPS)
RUN pecl install apcu-5.1.28 \
    && docker-php-ext-enable apcu \
    && rm -rf /tmp/pear

# Composer берём готовым бинарником из официального образа —
# без curl | sh и без лишнего слоя с исходниками composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# www-data получает UID/GID хоста: файлы, которые php-fpm создаёт в volume
# (например app/vendor через composer), на хосте будут принадлежать
# текущему пользователю, а не случайному системному uid из образа.
# -o (non-unique) нужен, потому что хостовый GID часто уже занят другой системной
# группой в образе (например GID 20 — staff на macOS, а в Debian это уже dialout)
RUN groupmod -o -g "${GID}" www-data \
    && usermod -o -u "${UID}" -g "${GID}" www-data

# Код не копируем — он приезжает bind mount'ом из ./app в compose.yaml
WORKDIR /var/www/html
