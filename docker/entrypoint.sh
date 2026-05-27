#!/usr/bin/env bash
# ============================================================
#  XtreamTV IPTV OS — Docker Entrypoint Script
#  Developer: Kobir Shah
#  Runs inside the container on every start
#  Compatible with: Docker Compose (localhost) & Railway
# ============================================================

set -e

# ── Detect Platform ──────────────────────────────────────────
# Railway sets PORT dynamically; use it, otherwise default to 80
LISTEN_PORT="${PORT:-80}"
if [ "$PORT" != "" ]; then
    PLATFORM="Railway"
else
    PLATFORM="Docker"
fi

# ── Print platform banner ────────────────────────────────────
echo ""
echo "╔══════════════════════════════════════════════════════╗"
echo "║   ⚡ XtreamTV IPTV OS — Starting Up                 ║"
echo "║   Developer : Kobir Shah                            ║"
echo "║   Version   : 2.0.1                                 ║"
printf "║   Platform  : %-37s║\n" "$PLATFORM"
if [ "$PORT" != "" ]; then
    printf "║   Port      : %-37s║\n" "$PORT"
    echo "╚══════════════════════════════════════════════════════╝"
    echo ""
    echo "  🌐 Railway detected — binding Apache to PORT=$PORT"
    echo ""
else
    echo "╚══════════════════════════════════════════════════════╝"
    echo ""
fi

# ── Create required directories ─────────────────────────────
STORAGE="/var/www/html/xtreamtv/storage"
for dir in "$STORAGE" "$STORAGE/cache" "$STORAGE/logs" "$STORAGE/epg"; do
    if [ ! -d "$dir" ]; then
        mkdir -p "$dir"
        echo "  [✓] Created: $dir"
    fi
done

# ── Set permissions ─────────────────────────────────────────
chown -R www-data:www-data /var/www/html/xtreamtv/storage 2>/dev/null || true
chmod -R 750 /var/www/html/xtreamtv/storage 2>/dev/null || true

# ── Protect storage from web access ─────────────────────────
HTACCESS="$STORAGE/.htaccess"
if [ ! -f "$HTACCESS" ]; then
    echo "Order allow,deny" > "$HTACCESS"
    echo "Deny from all"   >> "$HTACCESS"
    echo "  [✓] Storage .htaccess written"
fi

# ── Run installer if DB doesn't exist ───────────────────────
DB="$STORAGE/database.sqlite"
if [ ! -f "$DB" ]; then
    echo "  [→] First run detected — running auto-installer..."
    php /var/www/html/xtreamtv/install.php --cli || true
    if [ -f "$DB" ]; then
        echo "  [✓] Database initialized"
    else
        echo "  [!] Database not created — checking storage permissions..."
        ls -la "$STORAGE" 2>/dev/null
    fi
fi

echo ""
# ── Schema version detection ──────────────────────────────
SCHEMA_VERSION="2.0.1"
if [ -f "$DB" ]; then
    STORED_VER=$(php -r "
        try {
            \$pdo = new PDO('sqlite:$DB');
            \$row = \$pdo->query(\"SELECT value FROM settings WHERE key='site_version'\")->fetch(PDO::FETCH_COLUMN);
            echo \$row ?: '0';
        } catch (Exception \$e) { echo '0'; }
    " 2>/dev/null || echo "0")
    if [ "$STORED_VER" != "$SCHEMA_VERSION" ]; then
        echo "  [→] Schema version mismatch ($STORED_VER → $SCHEMA_VERSION) — re-running installer..."
        rm -f "$DB"
        php /var/www/html/xtreamtv/install.php --cli || true
        if [ -f "$DB" ]; then
            echo "  [✓] Database re-initialized with new schema"
        fi
    fi
fi

if [ "$PORT" != "" ]; then
    echo "  🌐 Panel URL:     http://0.0.0.0:$PORT/xtreamtv/"
    echo "  ✦  Powered by Kobir Shah"
    echo ""
else
    echo "  🌐 Panel URL:     http://localhost:8080/xtreamtv/"
    echo "  ✦  Powered by Kobir Shah"
    echo ""
fi

# ── Bind Apache to the correct port ─────────────────────────
# Railway sets $PORT; update Apache configs to match
if [ "$PORT" != "" ] && [ "$PORT" != "80" ]; then
    sed -i "s/^Listen 80$/Listen $PORT/" /etc/apache2/ports.conf
    sed -i "s/<VirtualHost \*:80>/<VirtualHost *:$PORT>/" /etc/apache2/sites-enabled/000-default.conf
    echo "  [✓] Apache configured to listen on port $PORT"
fi

# ── Railway: enforce Cloudflare CDN for stream paths ────────
# Block playlist/live/movie/series access unless request comes
# through Cloudflare tunnel (identified by Cf-Ray header).
if [ "$PORT" != "" ]; then
    cat > /etc/apache2/conf-enabled/cloudflare-enforce.conf << 'CFEOF'
# ── Cloudflare CDN Enforce ─────────────────────────────────
# Requests without Cf-Ray header are direct (not via Cloudflare tunnel).
# Stream/playlist endpoints are blocked — CF CDN only.
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{HTTP:CF-Ray} !^.+$
    RewriteRule ^/xtreamtv/(live|movie|series|player_api|get\.php) - [F,L]
</IfModule>
CFEOF
    echo "  [✓] Cloudflare CDN enforced for stream/playlist paths"
fi

# ── Railway: start Cloudflare Quick Tunnel ──────────────────
if [ "$PORT" != "" ] && command -v cloudflared &>/dev/null; then
    CLOUDLOG="/tmp/cloudflared.log"
    TUNNEL_FILE="/tmp/tunnel-url.txt"

    cloudflared tunnel --url "http://localhost:$PORT" > "$CLOUDLOG" 2>&1 &
    CLOUDFLARED_PID=$!
    echo "$CLOUDFLARED_PID" > /tmp/cloudflared.pid

    # Background monitor: watches log and writes tunnel URL to file
    (
        for i in $(seq 1 120); do
            URL=$(grep -oE 'https?://[a-z0-9-]+\.trycloudflare\.com' "$CLOUDLOG" 2>/dev/null | head -1) || true
            if [ -n "$URL" ]; then
                echo "$URL" > "$TUNNEL_FILE"
                chmod 644 "$TUNNEL_FILE"
                break
            fi
            if ! kill -0 "$CLOUDFLARED_PID" 2>/dev/null; then
                break
            fi
            sleep 2
        done
    ) &

    # Show tunnel URL in startup logs (poll up to 30s)
    for i in $(seq 1 15); do
        URL=$(grep -oE 'https?://[a-z0-9-]+\.trycloudflare\.com' "$CLOUDLOG" 2>/dev/null | head -1) || true
        if [ -n "$URL" ]; then
            echo ""
            echo "╔══════════════════════════════════════════════════════╗"
            echo "║   🌐 Cloudflare Quick Tunnel Active                 ║"
            printf "║   %-43s║\n" "$URL"
            echo "║                                                   ║"
            echo "║   Use this URL for playlist & stream access        ║"
            echo "╚══════════════════════════════════════════════════════╝"
            echo ""
            break
        fi
        if ! kill -0 "$CLOUDFLARED_PID" 2>/dev/null; then
            echo "  [!] cloudflared exited — check $CLOUDLOG"
            break
        fi
        sleep 2
    done

    if [ -z "$URL" ]; then
        echo "  [→] Cloudflare tunnel URL pending — check /xtreamtv/tunnel.php"
    fi
fi

# ── Fix MPM conflict before starting Apache ────────────────
# php:8.2-apache requires mpm_prefork (mod_php is incompatible with event/worker)
for mpm in mpm_event mpm_worker; do
    if [ -f "/etc/apache2/mods-enabled/${mpm}.load" ]; then
        a2dismod -f "$mpm" 2>/dev/null || true
        echo "  [✓] Disabled conflicting MPM: $mpm"
    fi
done
# Ensure mpm_prefork is enabled
if [ ! -f "/etc/apache2/mods-enabled/mpm_prefork.load" ]; then
    a2enmod mpm_prefork 2>/dev/null || true
    echo "  [✓] Enabled mpm_prefork"
fi

# ── Start Apache ─────────────────────────────────────────────
exec apache2-foreground
