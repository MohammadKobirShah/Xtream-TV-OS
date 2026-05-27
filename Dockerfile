# ============================================================
#  XtreamTV IPTV OS — Production Dockerfile
#  Compatible with: Docker Compose (localhost) & Railway
# ============================================================
#  Developer   : Kobir Shah
#  Base Image  : php:8.2-apache (Debian Bookworm slim)
#  Build       : docker build -t xtreamtv .
#  Run         : docker compose up -d --build
#  Railway     : PORT env var sets Apache listen port
# ============================================================

FROM php:8.2-apache

# ── Image Metadata ───────────────────────────────────────────
LABEL maintainer="Kobir Shah" \
      developer="Kobir Shah" \
      version="2.0.1" \
      description="XtreamTV IPTV OS — Elite PHP IPTV Proxy Panel" \
      org.opencontainers.image.title="XtreamTV IPTV OS" \
      org.opencontainers.image.description="Production-grade IPTV proxy panel by Kobir Shah" \
      org.opencontainers.image.authors="Kobir Shah" \
      org.opencontainers.image.version="2.0.1"

# ── Build-time args (override with --build-arg if needed) ────
ARG DEBIAN_FRONTEND=noninteractive
ARG APP_VERSION=2.0.1

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
    # Explicitly enable mpm_prefork (required for mod_php) and disable
    # event/worker which conflict. Debian Bookworm's php:8.2-apache
    # may ship with multiple MPMs enabled by default in some tags.
    && a2dismod -f mpm_event mpm_worker 2>/dev/null || true; \
    a2enmod mpm_prefork 2>/dev/null || true; \
    a2enmod \
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

# ── Cloudflared (for Railway auto-tunnel) ────────────────────
# Single static binary — works on any architecture.
# Non-fatal: localhost builds are fine without it.
RUN ARCH=$(dpkg --print-architecture) \
    && curl -fsSL "https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-${ARCH}" \
            -o /usr/local/bin/cloudflared 2>/dev/null \
    && chmod +x /usr/local/bin/cloudflared \
    || echo "  [i] cloudflared binary skipped (not required for localhost)"

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
EXPOSE 8080
EXPOSE 8000

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
    APP_VERSION="2.0.1" \
    DEVELOPER_CREDIT="Powered by Kobir Shah" \
    APACHE_DOCUMENT_ROOT="/var/www/html" \
    TZ="UTC"

# ── Start ────────────────────────────────────────────────────
ENTRYPOINT ["/entrypoint.sh"]
