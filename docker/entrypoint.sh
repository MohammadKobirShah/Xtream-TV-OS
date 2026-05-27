#!/usr/bin/env bash
# ============================================================
#  XtreamTV IPTV OS — Docker Entrypoint Script
#  Developer: Kobir Shah
#  Runs inside the container on every start
# ============================================================

set -e

echo ""
echo "╔══════════════════════════════════════════════════════╗"
echo "║   ⚡ XtreamTV IPTV OS — Starting Up                 ║"
echo "║   Developer : Kobir Shah                            ║"
echo "║   Version   : 2.0.1                                 ║"
echo "║   Access    : http://localhost:8080/xtreamtv/        ║"
echo "╚══════════════════════════════════════════════════════╝"
echo ""

# ── Create required directories ─────────────────────────────
STORAGE="/var/www/html/xtreamtv/storage"
for dir in "$STORAGE" "$STORAGE/cache" "$STORAGE/logs" "$STORAGE/epg" "$STORAGE/sessions"; do
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
    php /var/www/html/xtreamtv/install.php --cli 2>/dev/null || true
    echo "  [✓] Database initialized"
fi

echo ""
echo "  🔐 Default login: admin / admin123"
echo "  🌐 Panel URL:     http://localhost:8080/xtreamtv/"
echo "  ✦  Powered by Kobir Shah"
echo ""

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
