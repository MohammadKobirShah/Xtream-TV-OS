# ============================================================
#  XtreamTV IPTV OS — Production Dockerfile
# ============================================================
#  Developer   : Kobir Shah
#  Base Image  : php:8.2-apache (Debian Bookworm slim)
#  Build       : docker build -t xtreamtv .
#  Run         : docker compose up -d --build
# ============================================================

FROM php:8.2-apache

# ── Image Metadata ───────────────────────────────────────────
LABEL maintainer="Kobir Shah" \
      developer="Kobir Shah" \
      version="2.0.0" \
      description="XtreamTV IPTV OS — Elite PHP IPTV Proxy Panel" \
      org.opencontainers.image.title="XtreamTV IPTV OS" \
      org.opencontainers.image.description="Production-grade IPTV proxy panel by Kobir Shah" \
      org.opencontainers.image.authors="Kobir Shah" \
      org.opencontainers.image.version="2.0.0"

# ── Build-time args (override with --build-arg if needed) ────
ARG DEBIAN_FRONTEND=noninteractive
ARG APP_VERSION=2.0.0

# ── System dependencies (single RUN layer = smaller image) ───
RUN apt-get update && apt-get install -y --no-install-recommends \
        # SQLite
        libsqlite3-dev \
        # cURL + SSL (for stream proxying)
        libcurl4-openssl-dev \
        libssl-dev \
        # XML (EPG / XMLTV parsing)
        libxml2-dev \
        # ZIP (uploads)
        libzip-dev \
        zlib1g-dev \
        # FFmpeg (optional stream processing)
        ffmpeg \
        # Utilities
        curl \
        unzip \
        ca-certificates \
        procps \
    # ── PHP extensions ──────────────────────────────────────
    && docker-php-ext-install -j"$(nproc)" \
        pdo \
        pdo_sqlite \
        curl \
        xml \
        zip \
        opcache \
    # ── Apache modules ──────────────────────────────────────
    && a2enmod \
        rewrite \
        headers \
        deflate \
        expires \
        ssl \
    # ── Clean up apt cache (keeps image lean) ───────────────
    && apt-get clean \
    && rm -rf \
        /var/lib/apt/lists/* \
        /tmp/* \
        /var/tmp/* \
        /usr/share/doc/* \
        /usr/share/man/*

# ── PHP Runtime Configuration ────────────────────────────────
COPY docker/php/custom.ini /usr/local/etc/php/conf.d/xtreamtv.ini

# ── Apache Virtual Host ──────────────────────────────────────
COPY docker/apache/xtreamtv.conf /etc/apache2/sites-enabled/000-default.conf
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# ── Working Directory ────────────────────────────────────────
WORKDIR /var/www/html

# ── Copy Application Source ──────────────────────────────────
COPY xtreamtv/ ./xtreamtv/

# ── Create Storage Directories + Permissions ─────────────────
RUN mkdir -p \
        xtreamtv/storage \
        xtreamtv/storage/cache \
        xtreamtv/storage/logs \
        xtreamtv/storage/epg \
        xtreamtv/storage/sessions \
    # Block direct HTTP access to storage
    && printf "Order allow,deny\nDeny from all\n" > xtreamtv/storage/.htaccess \
    # www-data owns the storage tree
    && chown -R www-data:www-data xtreamtv/storage \
    && chmod -R 750 xtreamtv/storage \
    # App files readable by Apache
    && chown -R www-data:www-data xtreamtv \
    && find xtreamtv -type f -name "*.php" -exec chmod 644 {} \; \
    && find xtreamtv -type d -exec chmod 755 {} \;

# ── Entrypoint Script ─────────────────────────────────────────
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# ── Expose Ports ─────────────────────────────────────────────
EXPOSE 80
EXPOSE 443

# ── Health Check ─────────────────────────────────────────────
HEALTHCHECK \
    --interval=30s \
    --timeout=10s \
    --start-period=60s \
    --retries=3 \
    CMD curl -fsSL http://localhost/xtreamtv/login.php -o /dev/null || exit 1

# ── Runtime Environment Variables ────────────────────────────
ENV DEVELOPER="Kobir Shah" \
    APP_NAME="XtreamTV" \
    APP_VERSION="2.0.0" \
    DEVELOPER_CREDIT="Powered by Kobir Shah" \
    APACHE_DOCUMENT_ROOT="/var/www/html" \
    TZ="UTC"

# ── Start ────────────────────────────────────────────────────
ENTRYPOINT ["/entrypoint.sh"]
