# ⚡ XtreamTV — Elite IPTV Proxy OS

> **Production-grade IPTV proxy panel with Xtream Codes API compatibility.**  
> Built with maximum performance, security, and a $500-tier premium dark UI.

**Developer:** Kobir Shah  
**Version:** 1.0.0  
**Engine:** PHP 8.2 | SQLite (WAL mode) | Vanilla OOP | No bloated frameworks

---

## 🏗️ Architecture

```
xtreamtv/
├── config.php              ← Bootstrap, constants, session config
├── index.php               ← Dashboard
├── login.php               ← Secure login (rate-limited, CSRF-protected)
├── logout.php              ← Session destruction
├── playlists.php           ← M3U playlist manager (URL + file upload)
├── channels.php            ← Channel browser, search, filter, manage
├── users.php               ← User management (Admin only)
├── api_info.php            ← API credentials + setup guide
├── logs.php                ← Access log viewer (Admin only)
├── proxy.php               ← Universal proxy endpoint (streams + M3U)
├── player_api.php          ← Xtream Codes API (TiviMate / IPTV Smarters)
├── live_stream.php         ← /live/{user}/{pass}/{id}.ts route handler
├── .htaccess               ← Apache security + URL routing
├── preview.html            ← Static UI preview (no PHP required)
└── src/
    ├── Database.php        ← SQLite PDO singleton, WAL, migrations
    ├── Security.php        ← CSRF, XSS, SSRF, rate-limiting, auth
    ├── M3UParser.php       ← Chunked M3U parser (streaming, no RAM bloat)
    ├── StreamProxy.php     ← cURL stream forwarder (chunked, memory-safe)
    ├── XtreamAPI.php       ← Xtream Codes API compatibility layer
    ├── UserManager.php     ← User CRUD, token management, stats
    └── View.php            ← Layout engine, glassmorphism dark UI
```

---

## 🚀 Installation

### Requirements
- PHP **8.2+** (with `curl`, `pdo_sqlite`, `fileinfo` extensions)
- Apache with `mod_rewrite` enabled
- Write permission on `storage/` directory

### Steps

```bash
# 1. Clone or upload to your web root
cp -r xtreamtv/ /var/www/html/xtreamtv/

# 2. Set permissions
chmod 750 /var/www/html/xtreamtv/storage/
chown -R www-data:www-data /var/www/html/xtreamtv/

# 3. Enable Apache mod_rewrite
a2enmod rewrite headers deflate
systemctl restart apache2

# 4. Ensure AllowOverride All in your VirtualHost
# In /etc/apache2/sites-available/000-default.conf:
# <Directory /var/www/html>
#     AllowOverride All
# </Directory>

# 5. Open in browser
# http://yourserver.com/xtreamtv/login.php
```

### Default Credentials
```
Username: admin
Password: admin123
```
> ⚠️ **Change these immediately** after first login via Users panel.

---

## 🔌 Connecting IPTV Apps

### TiviMate / IPTV Smarters Pro
Choose **Xtream Codes API**:
| Field    | Value                               |
|----------|-------------------------------------|
| Server   | `http://yourserver.com/xtreamtv`    |
| Username | Your XtreamTV username              |
| Password | Your API token (from API Info page) |

### VLC / Kodi (M3U URL)
```
http://yourserver.com/xtreamtv/proxy.php?action=m3u&t=YOUR_API_TOKEN
```

### Direct Stream URL
```
http://yourserver.com/xtreamtv/proxy.php?id=CHANNEL_ID&t=YOUR_API_TOKEN
```

### Xtream Codes Format
```
http://yourserver.com/xtreamtv/live/{username}/{token}/{channel_id}.ts
```

---

## 🛡️ Security Features

| Feature | Implementation |
|---------|---------------|
| **CSRF Protection** | Per-session token with `hash_equals` comparison |
| **XSS Prevention** | `htmlspecialchars` on all output, strict CSP headers |
| **SSRF Blocking** | URL scheme whitelist + private IP range filtering via DNS resolution |
| **Brute Force** | Rate limiting: 5 attempts / 5 min per IP (session-based) |
| **SQL Injection** | 100% PDO prepared statements, no raw interpolation |
| **Session Security** | Regeneration on login, HttpOnly + SameSite=Strict cookies |
| **Password Hashing** | Argon2ID (PHP's most secure algorithm) |
| **Path Traversal** | `.htaccess` blocks `src/` and `storage/` directories |
| **Headers** | X-Frame-Options, X-Content-Type-Options, Referrer-Policy |

---

## ⚡ Performance Features

| Feature | Detail |
|---------|--------|
| **Streaming Parser** | M3U parsed via cURL write callback — no full playlist in RAM |
| **Batch DB Inserts** | Channels inserted in 500-row WAL transactions |
| **SQLite WAL Mode** | Write-Ahead Logging for concurrent reads during writes |
| **64MB DB Cache** | `PRAGMA cache_size=-64000` for in-memory query caching |
| **Stream Chunking** | cURL write function pipes 1MB chunks direct to client |
| **Output Buffering Off** | Zero latency streaming — `ob_end_clean()` before pipe |
| **Client Disconnect** | `connection_aborted()` check stops proxy immediately |
| **No Framework** | Pure PHP OOP — zero dependency overhead |

---

## 📡 API Reference

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/player_api.php?username=X&password=X` | GET | User info + server info |
| `/player_api.php?...&action=get_live_categories` | GET | Channel categories list |
| `/player_api.php?...&action=get_live_streams` | GET | All channels (Xtream format) |
| `/proxy.php?action=m3u&t=TOKEN` | GET | Full M3U playlist download |
| `/proxy.php?id=ID&t=TOKEN` | GET | Proxy a specific channel |
| `/live/{user}/{pass}/{id}.ts` | GET | Direct TS stream |
| `/live/{user}/{pass}/{id}.m3u8` | GET | Direct HLS stream |

---

## 🗄️ Database Schema

```sql
users           — Accounts, tokens, stream limits, expiry
playlists       — M3U sources (URL or file), per-user
channels        — Individual streams, grouped, with metadata
stream_sessions — Active stream tracking, byte counting
access_log      — All actions logged for audit
```

---

## 📄 License & Credit

**Developer:** Kobir Shah  
**Copyright:** © 2026 Kobir Shah. All Rights Reserved.

This software is proprietary. The developer credit (`Kobir Shah`) is hardcoded throughout:
- Every UI page footer
- All API JSON responses (`"credit": "Kobir Shah"`)
- PHP error logs
- HTTP response headers (`X-Powered-By: XtreamTV by Kobir Shah`)
- Browser console logs on every page load

Removing or altering the developer credit is a violation of the license.
