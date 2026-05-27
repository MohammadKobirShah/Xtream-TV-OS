<div align="center">

<!-- LOGO / BANNER -->
<img src="https://capsule-render.vercel.app/api?type=waving&color=0:00b4ff,50:a855f7,100:06b6d4&height=200&section=header&text=XtreamTV%20IPTV%20OS&fontSize=52&fontColor=ffffff&fontAlignY=38&desc=Elite%20PHP%20IPTV%20Proxy%20Panel%20%E2%80%94%20by%20Kobir%20Shah&descAlignY=58&descSize=17&animation=fadeIn" width="100%" alt="XtreamTV Banner"/>

<br/>

<!-- BADGES ROW 1 -->
[![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://php.net)
[![Docker](https://img.shields.io/badge/Docker-Ready-2496ED?style=for-the-badge&logo=docker&logoColor=white)](https://docker.com)
[![SQLite](https://img.shields.io/badge/SQLite-WAL_Mode-003B57?style=for-the-badge&logo=sqlite&logoColor=white)](https://sqlite.org)
[![Cloudflare](https://img.shields.io/badge/Cloudflare-Tunnel-F38020?style=for-the-badge&logo=cloudflare&logoColor=white)](https://cloudflare.com)

<!-- BADGES ROW 2 -->
[![FFmpeg](https://img.shields.io/badge/FFmpeg-Restream-007808?style=for-the-badge&logo=ffmpeg&logoColor=white)](https://ffmpeg.org)
[![License](https://img.shields.io/badge/License-Proprietary-a855f7?style=for-the-badge&logo=opensourceinitiative&logoColor=white)](#)
[![Version](https://img.shields.io/badge/Version-2.0.0-00b4ff?style=for-the-badge&logo=semver&logoColor=white)](#)
[![Developer](https://img.shields.io/badge/Developer-Kobir_Shah-ec4899?style=for-the-badge&logo=github&logoColor=white)](https://github.com)

<br/>

```
𝗣𝗼𝘄𝗲𝗿𝗲𝗱 𝗯𝘆 𝗞𝗼𝗯𝗶𝗿 𝗦𝗵𝗮𝗵  ·  𝗣𝗛𝗣 𝟴.𝟮  ·  𝗦𝗤𝗟𝗶𝘁𝗲  ·  𝗗𝗼𝗰𝗸𝗲𝗿  ·  𝗖𝗹𝗼𝘂𝗱𝗳𝗹𝗮𝗿𝗲  ·  𝗙𝗙𝗺𝗽𝗲𝗴
```

</div>

---

<div align="center">

### 𝗤𝘂𝗶𝗰𝗸 𝗡𝗮𝘃𝗶𝗴𝗮𝘁𝗶𝗼𝗻

[𝗙𝗲𝗮𝘁𝘂𝗿𝗲𝘀](#-features) • [𝗔𝗿𝗰𝗵𝗶𝘁𝗲𝗰𝘁𝘂𝗿𝗲](#-architecture) • [𝗗𝗲𝗽𝗹𝗼𝘆](#-deployment) • [𝗔𝗣𝗜](#-xtream-codes-api) • [𝗙𝗙𝗺𝗽𝗲𝗴](#-ffmpeg-restream-engine) • [𝗦𝗲𝗰𝘂𝗿𝗶𝘁𝘆](#-security) • [𝗦𝗰𝗿𝗲𝗲𝗻𝘀𝗵𝗼𝘁𝘀](#-ui-screenshots) • [𝗖𝗵𝗮𝗻𝗴𝗲𝗹𝗼𝗴](#-changelog)

</div>

---

## 𝗪𝗵𝗮𝘁 𝗶𝘀 𝗫𝘁𝗿𝗲𝗮𝗺𝗧𝗩?

**XtreamTV** is a production-grade, self-hosted **IPTV Proxy OS** built with pure PHP 8.2 — no heavy frameworks, no bloat. It parses M3U playlists, proxies live streams, emulates the **Xtream Codes API**, integrates **XMLTV EPG**, and ships a **cinematic dark glassmorphism UI**. Deploy it in 60 seconds with Docker and expose it to the world via **Cloudflare Tunnel**.

> 𝗕𝘂𝗶𝗹𝘁 𝗳𝗿𝗼𝗺 𝘀𝗰𝗿𝗮𝘁𝗰𝗵 𝗯𝘆 **𝗞𝗼𝗯𝗶𝗿 𝗦𝗵𝗮𝗵** — every file, every line, every feature.

---

## ✨ Features

<table>
<tr>
<td width="50%">

### 🔥 𝗖𝗼𝗿𝗲 𝗘𝗻𝗴𝗶𝗻𝗲
- **Chunked M3U Parser** — cURL write-callback streaming, handles 500MB+ playlists with <8MB RAM
- **fpassthru() Proxy** — kernel-level stream pipe, zero PHP copy overhead, perfect for 4K
- **FFmpeg Restream** — 3 modes (OFF/ON/AUTO), 3 quality presets, geo-block bypass
- **HLS Manifest Rewriter** — rewrites .m3u8 segment URLs through proxy to mask origin
- **SSRF Protection** — URL scheme whitelist + private IP range blocking via DNS resolution

</td>
<td width="50%">

### 📡 𝗔𝗣𝗜 & 𝗜𝗻𝘁𝗲𝗴𝗿𝗮𝘁𝗶𝗼𝗻
- **Xtream Codes API** — full emulation, compatible with TiviMate, IPTV Smarters, GSE, Kodi
- **EPG / XMLTV** — XMLReader stream parser, current/next program, progress bar, batch API
- **M3U Export** — proxied URLs, all playlists combined or single, streamed output
- **Direct Stream Routes** — `/live/{user}/{pass}/{id}.ts` Xtream-format URLs
- **Cloudflare Tunnel** — token from `.env`, auto HTTPS, no port forwarding

</td>
</tr>
<tr>
<td width="50%">

### 🎨 𝗨𝗜 & 𝗣𝗹𝗮𝘆𝗲𝗿
- **Dark Glassmorphism UI** — deep black, neon blue/purple, frosted glass cards
- **Cinematic Player** — HLS.js, Picture-in-Picture, auto-reconnect, EPG overlay
- **SPA Channel Switching** — switch channels without page reload, history API
- **Admin Settings Panel** — FFmpeg toggle, quality presets, per-channel overrides
- **Responsive Design** — sidebar collapses, touch-friendly on mobile

</td>
<td width="50%">

### 🛡️ 𝗦𝗲𝗰𝘂𝗿𝗶𝘁𝘆 & 𝗣𝗲𝗿𝗳𝗼𝗿𝗺𝗮𝗻𝗰𝗲
- **Argon2ID** password hashing with automatic rehash on upgrade
- **CSRF** per-session 64-byte tokens with `hash_equals()` compare
- **SQLite Triple Lockdown** — RewriteRule + FilesMatch + storage/.htaccess
- **Rate Limiting** — sliding window, session-based, per-IP
- **WAL Mode SQLite** — 64MB cache, concurrent reads, zero-config

</td>
</tr>
</table>

---

## 🏗️ Architecture

```
𝗫𝘁𝗿𝗲𝗮𝗺𝗧𝗩 𝗔𝗿𝗰𝗵𝗶𝘁𝗲𝗰𝘁𝘂𝗿𝗲 — 𝗞𝗼𝗯𝗶𝗿 𝗦𝗵𝗮𝗵
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  ┌─────────────────────────────────────────────────┐
  │              IPTV CLIENT APPS                    │
  │   TiviMate · IPTV Smarters · GSE · VLC · Kodi   │
  └──────────────┬──────────────────────────────────┘
                 │  Xtream Codes API / M3U / HLS
                 ▼
  ┌─────────────────────────────────────────────────┐
  │         CLOUDFLARE TUNNEL (Zero Trust)           │
  │         Auto HTTPS · No port forwarding          │
  └──────────────┬──────────────────────────────────┘
                 │
                 ▼
  ┌─────────────────────────────────────────────────┐
  │            XTREAMTV PHP 8.2 ENGINE               │
  │  ┌──────────┐ ┌──────────┐ ┌─────────────────┐  │
  │  │ M3UEngine│ │EPGEngine │ │  XtreamAPI      │  │
  │  │  Parser  │ │  XMLTV   │ │  Xtream Codes   │  │
  │  └──────────┘ └──────────┘ └─────────────────┘  │
  │  ┌──────────────────────────────────────────┐    │
  │  │          PROXY ENGINE                    │    │
  │  │  StreamPassthru (fpassthru) · FFmpegProxy│    │
  │  │  SSRF Guard · UA Spoof · Header Masking  │    │
  │  └──────────────────────────────────────────┘    │
  │  ┌──────────┐ ┌──────────┐ ┌─────────────────┐  │
  │  │   Auth   │ │ Security │ │    Database      │  │
  │  │ Argon2ID │ │CSRF/SSRF │ │ SQLite WAL Mode  │  │
  │  └──────────┘ └──────────┘ └─────────────────┘  │
  └──────────────┬──────────────────────────────────┘
                 │
                 ▼
  ┌─────────────────────────────────────────────────┐
  │           UPSTREAM IPTV PROVIDERS                │
  │      HLS · TS · RTMP · RTSP · M3U8              │
  └─────────────────────────────────────────────────┘
```

---

## 📁 File Structure

```
📦 xtreamtv-iptv-os/
│
├── 🐳  docker-compose.yml          ← 3 containers: app + redis + cloudflared
├── 🐳  Dockerfile                  ← PHP 8.2-Apache, ffmpeg, pdo_sqlite
├── 🔑  .env                        ← secrets (gitignored — NEVER commit)
├── 📄  .env.example                ← safe template
├── 🚫  .gitignore / .dockerignore
│
├── 📁  docker/
│   ├── 📁  apache/
│   │   └── xtreamtv.conf           ← VHost, X-Developer headers, URL routing
│   ├── 📁  php/
│   │   └── custom.ini              ← 512M memory, OPcache, secure sessions
│   └── entrypoint.sh               ← boot script, auto-installer
│
└── 📁  xtreamtv/                   ← PHP application root
    │
    ├── ⚙️   config.php              ← constants, APP_VERSION, DEVELOPER_CREDIT
    ├── 🔐  auth.php                 ← Auth class: session, token, CSRF, rate-limit
    ├── ⚡  engine.php               ← M3UEngine: parser + proxy + cache
    ├── 📅  epg.php                  ← EPGEngine: XMLTV download + parse + query
    │
    ├── 🌐  index.php                ← Dashboard (stats, playlists, active streams)
    ├── 🔑  login.php                ← Rate-limited, CSRF-protected auth page
    ├── 📺  player.php               ← Cinematic HLS.js player + EPG overlay
    ├── 📡  playlists.php            ← M3U manager (URL import + file upload)
    ├── 📋  channels.php             ← Channel browser (search, filter, paginate)
    ├── 👥  users.php                ← User management (admin only)
    ├── ⚙️   settings.php            ← FFmpeg admin panel + per-channel overrides
    ├── 📋  logs.php                 ← Access log viewer
    ├── 🔧  install.php             ← One-shot DB installer (delete after setup)
    │
    ├── 🔌  api.php                  ← Xtream Codes API emulation
    ├── 📡  proxy.php                ← Universal stream proxy endpoint
    ├── 📅  epg_api.php              ← EPG REST API endpoint
    ├── 🔗  live_stream.php          ← /live/{user}/{pass}/{id}.ts route
    ├── 🔗  player_api.php           ← /player_api.php Xtream alias
    ├── 🔒  logout.php              ← Session destroy + log entry
    │
    ├── 🔒  .htaccess               ← Security: SQLite lock + routing + CSP headers
    │
    └── 📁  src/                    ← PHP class library
        ├── Database.php            ← SQLite PDO singleton, WAL, auto-migration
        ├── Security.php            ← CSRF, XSS, SSRF protection, session hardening
        ├── Auth.php                ← (via auth.php) session + token auth
        ├── StreamPassthru.php      ← fpassthru() 4K stream pipe ✨
        ├── FFmpegProxy.php         ← FFmpeg restream engine ✨
        ├── M3UParser.php           ← M3U line parser utilities
        ├── StreamProxy.php         ← Legacy cURL proxy helpers
        ├── UserManager.php         ← User CRUD + token management
        ├── View.php                ← Layout engine, glassmorphism UI
        └── XtreamAPI.php           ← Xtream Codes API response builder
```

---

## 🚀 Deployment

### 𝗣𝗿𝗲𝗿𝗲𝗾𝘂𝗶𝘀𝗶𝘁𝗲𝘀

| Requirement | Version | Notes |
|---|---|---|
| [Docker Desktop](https://docker.com/products/docker-desktop) | 25.x+ | Includes `docker compose` v2 |
| Cloudflare Account | Free tier | For tunnel (optional but recommended) |
| Port 8080 | Open | Local access only if no tunnel |

### 𝗤𝘂𝗶𝗰𝗸 𝗦𝘁𝗮𝗿𝘁 — 𝟯 𝗖𝗼𝗺𝗺𝗮𝗻𝗱𝘀

```bash
# 1. Copy environment template
cp .env.example .env

# 2. Add your Cloudflare tunnel token to .env
#    CLOUDFLARE_TUNNEL_TOKEN=eyJhIjoiMDI2...

# 3. Build and launch all 3 containers
docker compose up -d --build
```

> ✅ **First boot:** ~60 seconds (installs PHP extensions)
> ⚡ **Subsequent starts:** instant

### 𝗗𝗼𝗰𝗸𝗲𝗿 𝗦𝗲𝗿𝘃𝗶𝗰𝗲𝘀

| Container | Image | Role | Port |
|---|---|---|---|
| `kobir_iptv_os` | Built from `Dockerfile` | PHP 8.2 + Apache | `8080` |
| `kobir_iptv_redis` | `redis:7-alpine` | Cache / sessions | internal |
| `kobir_iptv_cloudflared` | `cloudflare/cloudflared:latest` | Public HTTPS tunnel | — |

### 𝗘𝗻𝘃𝗶𝗿𝗼𝗻𝗺𝗲𝗻𝘁 𝗩𝗮𝗿𝗶𝗮𝗯𝗹𝗲𝘀

```env
# .env — NEVER commit this file
APP_VERSION=2.0.0
HTTP_PORT=8080
TZ=UTC
PHP_MEMORY_LIMIT=512M

# Cloudflare Zero Trust tunnel token
CLOUDFLARE_TUNNEL_TOKEN=your_token_here
```

> ⚠️ The token is injected via `${CLOUDFLARE_TUNNEL_TOKEN}` in `docker-compose.yml`. It is never hardcoded.

---

## 🔐 Default Credentials

> ⚠️ **Change these immediately after first login.**

| Field | Value |
|---|---|
| 🌐 Panel URL | `http://localhost:8080/xtreamtv/` |
| 👤 Username | `admin` |
| 🔑 Password | `admin123` |
| ⚙️ Settings | `http://localhost:8080/xtreamtv/settings.php` |
| 📺 Player | `http://localhost:8080/xtreamtv/player.php` |
| 🔌 Xtream API | `http://localhost:8080/xtreamtv/api.php` |

---

## 🔌 Xtream Codes API

XtreamTV fully emulates the Xtream Codes API. Connect any compatible app instantly.

### 𝗧𝗶𝘃𝗶𝗠𝗮𝘁𝗲 / 𝗜𝗣𝗧𝗩 𝗦𝗺𝗮𝗿𝘁𝗲𝗿𝘀 / 𝗚𝗦𝗘

```
Connection Type : Xtream Codes API
Server URL      : http://localhost:8080/xtreamtv
Username        : admin
Password        : (API Token from panel)
```

### 𝗩𝗟𝗖 / 𝗞𝗼𝗱𝗶 (𝗠𝟯𝗨 𝗨𝗥𝗟)

```
http://localhost:8080/xtreamtv/proxy.php?action=m3u&t=YOUR_TOKEN
```

### 𝗔𝗣𝗜 𝗘𝗻𝗱𝗽𝗼𝗶𝗻𝘁𝘀

| Endpoint | Method | Description |
|---|---|---|
| `/api.php?username=X&password=X` | `GET` | User info + server info |
| `/api.php?...&action=get_live_categories` | `GET` | Live channel categories |
| `/api.php?...&action=get_live_streams` | `GET` | All live channels (Xtream format) |
| `/api.php?...&action=get_vod_streams` | `GET` | VOD streams |
| `/api.php?...&action=get_short_epg&stream_id=N` | `GET` | Current + next EPG |
| `/proxy.php?action=m3u&t=TOKEN` | `GET` | Full M3U playlist |
| `/proxy.php?id=ID&t=TOKEN` | `GET` | Proxy single channel |
| `/proxy.php?id=ID&t=TOKEN&mode=on` | `GET` | Channel via FFmpeg |
| `/live/{user}/{pass}/{id}.ts` | `GET` | Direct TS stream (Xtream format) |
| `/epg_api.php?tvg_id=X&t=TOKEN` | `GET` | Current + next program |
| `/epg_api.php?action=batch&tvg_ids=X,Y&t=TOKEN` | `GET` | Batch EPG (max 50) |
| `/epg_api.php?action=schedule&tvg_id=X&hours=6` | `GET` | N-hour schedule |

> 🔑 Every API response includes `"credit": "Kobir Shah"` in the body and `X-Developer: Kobir Shah` in HTTP headers.

---

## ⚡ FFmpeg Restream Engine

Controlled from **Settings → FFmpeg Global Mode**. Default is **OFF** for maximum performance.

### 𝟯 𝗠𝗼𝗱𝗲𝘀

| Mode | Icon | Behaviour | Best For |
|---|---|---|---|
| **OFF** | 🟢 | `fpassthru()` pipe — kernel-level, zero CPU | 99% of streams, 4K, low-latency |
| **ON** | 🔴 | Every stream through FFmpeg — remux, reconnect, UA spoof | Broken streams, geo-blocked content |
| **AUTO** | 🟡 | Probe upstream → passthru if OK → FFmpeg fallback | Unknown stream quality |

### 𝗤𝘂𝗮𝗹𝗶𝘁𝘆 𝗣𝗿𝗲𝘀𝗲𝘁𝘀

| Preset | Resolution | Codec | Use Case |
|---|---|---|---|
| `passthru` | Original | Copy (no re-encode) | 4K, fast, zero CPU |
| `hd` | 1280×720 | H264 CRF23 + AAC 128k | Balanced quality |
| `sd` | 854×480 | H264 CRF28 + AAC 96k | Low bandwidth |

### 𝗖𝗼𝗻𝘁𝗿𝗼𝗹 𝗣𝗿𝗶𝗼𝗿𝗶𝘁𝘆 𝗖𝗵𝗮𝗶𝗻

```
URL Param (?mode=) → Per-Channel DB → Global Setting (admin panel)
```

```bash
# Force FFmpeg for a single stream (testing)
proxy.php?id=1&t=TOKEN&mode=on

# Force passthru for a single stream
proxy.php?id=1&t=TOKEN&mode=off

# Let admin panel decide (default)
proxy.php?id=1&t=TOKEN
```

---

## 🛡️ Security

### 𝗦𝗲𝗰𝘂𝗿𝗶𝘁𝘆 𝗦𝘁𝗮𝗰𝗸

| Layer | Implementation |
|---|---|
| **CSRF** | Per-session 64-byte tokens, `hash_equals()` constant-time compare |
| **XSS** | `htmlspecialchars()` on all output, strict Content Security Policy |
| **SSRF** | URL scheme whitelist + private IP blocking via `gethostbyname()` |
| **SQL Injection** | 100% PDO prepared statements, zero string interpolation |
| **Passwords** | Argon2ID hashing, automatic rehash on algorithm upgrade |
| **Sessions** | `session_regenerate_id()` on login, HttpOnly + SameSite=Strict |
| **Rate Limiting** | Sliding window, 5 attempts/5min per IP, session-based |
| **SQLite** | Triple lockdown: RewriteRule + FilesMatch + `storage/.htaccess` |
| **Headers** | X-Frame-Options, X-Content-Type-Options, Referrer-Policy, CSP |
| **Shell** | `escapeshellarg()` + `escapeshellcmd()` on all FFmpeg commands |

### 𝗦𝗤𝗟𝗶𝘁𝗲 𝗧𝗿𝗶𝗽𝗹𝗲 𝗟𝗼𝗰𝗸𝗱𝗼𝘄𝗻

```apache
# Layer 1 — mod_rewrite (xtreamtv/.htaccess)
RewriteRule ^storage/database\.sqlite$ - [F,L,NC]
RewriteRule ^storage/.*\.sqlite$       - [F,L,NC]
RewriteRule ^storage/                  - [F,L,NC]

# Layer 2 — FilesMatch (xtreamtv/.htaccess)
<FilesMatch "\.(sqlite|sqlite3|db)$">
    Require all denied
</FilesMatch>

# Layer 3 — Nuclear (xtreamtv/storage/.htaccess)
Require all denied
```

### 𝗣𝗿𝗼𝗱𝘂𝗰𝘁𝗶𝗼𝗻 𝗖𝗵𝗲𝗰𝗸𝗹𝗶𝘀𝘁

- [ ] Change default password (`admin123`) immediately
- [ ] Delete `install.php` after first setup
- [ ] Keep `.env` in `.gitignore` — never commit Cloudflare token
- [ ] Use Cloudflare Tunnel instead of exposing port 8080 directly
- [ ] Rotate API tokens periodically from Users panel
- [ ] Leave FFmpeg mode **OFF** unless you specifically need geo-bypass

---

## 🗄️ Database Schema

> SQLite with WAL mode, 64MB cache, automatic migrations on boot.

```sql
users           — Accounts, Argon2ID passwords, API tokens, stream limits, expiry
playlists       — M3U sources (URL or uploaded file), per-user, EPG URL
channels        — Parsed streams: tvg_id, logo, group, stream_url, ffmpeg_mode
settings        — Key-value admin config (ffmpeg_mode, ffmpeg_quality, etc.)
epg_programs    — XMLTV programme data: title, times, description, category
stream_sessions — Active stream tracking, byte counting, last_ping
access_log      — Full audit log: login, logout, stream_start, actions
```

**Auto-migration:** The `Database::migrate()` method runs on every boot. It uses `IF NOT EXISTS` and `ALTER TABLE` checks so existing databases upgrade safely without data loss.

---

## ⚙️ Configuration

All settings are stored in the `settings` table and managed from **Settings → Admin Panel**.

| Key | Default | Description |
|---|---|---|
| `ffmpeg_mode` | `off` | Global FFmpeg mode: `off` \| `on` \| `auto` |
| `ffmpeg_quality` | `passthru` | FFmpeg quality: `passthru` \| `hd` \| `sd` |
| `proxy_useragent` | SmartTV UA | User-agent spoofed on upstream requests |
| `max_cache_age` | `3600` | M3U JSON cache TTL in seconds |
| `epg_cache_hours` | `12` | XMLTV cache lifetime in hours |
| `site_name` | `XtreamTV IPTV OS` | Panel title |
| `allow_register` | `0` | Public user registration (0=disabled) |

Per-channel override: `channels.ffmpeg_mode` = `inherit` \| `off` \| `on` \| `auto`

---

## 📺 UI Screenshots

> *Built with deep black `#050508`, neon blue `#00b4ff`, purple `#a855f7`, and glassmorphism effects.*

```
╔═══════════════════════════════════════════════════════════════╗
║  ⚡ XtreamTV                               [admin] v2.0.0    ║
╠═══════════╦═══════════════════════════════════════════════════╣
║           ║  ⚡ Dashboard                    [📺 Open Player]║
║  ⚡ Dash  ║  ┌──────┐ ┌──────┐ ┌──────┐ ┌──────┐ ┌──────┐  ║
║  📡 Lists ║  │  8   │ │14.8K │ │  7   │ │ 2.4k │ │ 21d  │  ║
║  📺 Chan  ║  │Plylst│ │ Chan │ │LIVE🔴│ │ Logs │ │Uptme │  ║
║  👥 Users ║  └──────┘ └──────┘ └──────┘ └──────┘ └──────┘  ║
║  🔌 API   ║                                                   ║
║  ⚙️ Sett  ║  🔌 Xtream API Credentials                       ║
║  📋 Logs  ║  ┌──────────────┬──────────┬──────────────────┐  ║
║           ║  │ Server URL   │ Username │ API Token        │  ║
║  [admin]  ║  │ localhost:.. │ admin    │ a3f9c2d1e4b8...  │  ║
║  [logout] ║  └──────────────┴──────────┴──────────────────┘  ║
╠═══════════╩═══════════════════════════════════════════════════╣
║  © 2026 IPTV OS │ Designed & Developed by Kobir Shah          ║
╚═══════════════════════════════════════════════════════════════╝
```

**Pages included:**
- 🏠 **Dashboard** — stats, active streams, API credentials, quick actions
- 📺 **Cinematic Player** — HLS.js theatre mode, EPG overlay, channel sidebar
- 📡 **Playlists** — URL import, file upload, sync, M3U export
- 📋 **Channels** — search, group filter, per-channel proxy URLs, FFmpeg toggle
- 👥 **Users** — create, edit, disable, token regeneration, expiry dates
- ⚙️ **Settings** — FFmpeg global mode, quality presets, per-channel table
- 📋 **Logs** — paginated audit log, search, clear

---

## 🐳 Docker Management

```bash
# ─── LAUNCH ─────────────────────────────────────────────────
docker compose up -d --build      # first run / after code change
docker compose up -d              # normal start

# ─── MONITOR ────────────────────────────────────────────────
docker compose logs -f            # all containers live
docker compose logs -f cloudflared # tunnel status + public URL
docker compose ps                 # health check status
docker stats kobir_iptv_os        # CPU / RAM / network

# ─── SHELL ──────────────────────────────────────────────────
docker exec -it kobir_iptv_os bash

# ─── BACKUP ─────────────────────────────────────────────────
docker cp kobir_iptv_os:/var/www/html/xtreamtv/storage/database.sqlite \
  ./backup_$(date +%Y%m%d_%H%M).sqlite

# ─── ROTATE CLOUDFLARE TOKEN ────────────────────────────────
# Edit .env → update CLOUDFLARE_TUNNEL_TOKEN
docker compose restart cloudflared

# ─── STOP ───────────────────────────────────────────────────
docker compose down               # preserves volumes (data safe)
docker compose down -v            # ⚠️ deletes ALL data
```

---

## 📦 Tech Stack

<div align="center">

| Layer | Technology | Purpose |
|---|---|---|
| **Language** | PHP 8.2+ | Core application, OOP, no framework |
| **Database** | SQLite (WAL) | Zero-config, persistent via Docker volume |
| **Proxy** | cURL + fpassthru | Stream forwarding, 4K compatible |
| **Restream** | FFmpeg | Geo-bypass, broken stream healing |
| **Web Server** | Apache 2.4 | mod_rewrite, security headers |
| **Container** | Docker + Compose | Isolated, reproducible deployment |
| **Tunnel** | Cloudflare Zero Trust | Auto HTTPS, no port forwarding |
| **Cache** | Redis 7 | Optional session/rate-limit scaling |
| **Player** | HLS.js | Cross-browser HLS streaming |
| **Auth** | Argon2ID + sessions | Password hashing, token auth |

</div>

---

## 📜 Changelog

### 𝘃𝟮.𝟬.𝟬 — 𝗙𝘂𝗹𝗹 𝗥𝗲𝗹𝗲𝗮𝘀𝗲

| Type | Change | File |
|---|---|---|
| ✨ NEW | `StreamPassthru.php` — fpassthru() engine, zero-overhead 4K pipe | `src/StreamPassthru.php` |
| ✨ NEW | `FFmpegProxy.php` — full FFmpeg restream, 3 modes, proc_open | `src/FFmpegProxy.php` |
| ✨ NEW | `settings.php` — FFmpeg admin panel, per-channel overrides | `settings.php` |
| ✨ NEW | `Database::setting()` / `setSetting()` UPSERT helpers | `src/Database.php` |
| ✨ NEW | Cloudflare Tunnel via `.env` token (never hardcoded) | `docker-compose.yml` |
| 🐛 FIX | `DEVELOPER_CREDIT` constant missing — used in 51 files | `config.php` |
| 🐛 FIX | `APP_VERSION` mismatch `1.0.0` vs `2.0.0` | `config.php` |
| 🐛 FIX | `self::formatProgram()` fatal — no class context in procedural file | `epg_api.php` |
| 🐛 FIX | `formatProgram()` called before definition — hoisted | `epg_api.php` |
| 🐛 FIX | `StreamPassthru` `require_once` missing in `proxy.php` | `proxy.php` |
| 🐛 FIX | FFmpeg mode hardcoded — now reads `Database::setting()` | `proxy.php` |
| 🐛 FIX | `channels.ffmpeg_mode` column missing — added + ALTER migration | `src/Database.php` |
| 🐛 FIX | Settings nav item missing from sidebar | `src/View.php` |
| 🐛 FIX | Triple SQLite lockdown — 3 independent layers | `.htaccess` |
| 🔄 UPD | Dockerfile wired to `docker-compose.yml` via `build:` context | `docker-compose.yml` |

### 𝘃𝟭.𝟬.𝟬 — 𝗜𝗻𝗶𝘁𝗶𝗮𝗹 𝗥𝗲𝗹𝗲𝗮𝘀𝗲
- Full IPTV Proxy OS foundation
- M3U parser, stream proxy, Xtream Codes API
- EPG / XMLTV integration
- Cinematic player with HLS.js
- Multi-user authentication
- Docker + Cloudflare Tunnel deployment

---

## 🤝 Contributing

This is a proprietary project by **Kobir Shah**. The developer credit is hardcoded throughout:

- Every UI page footer: *© 2026 IPTV OS | Designed & Developed by Kobir Shah*
- All API JSON responses: `"credit": "Kobir Shah"`
- HTTP response headers: `X-Developer: Kobir Shah`
- PHP error logs: `[XtreamTV]... — Kobir Shah`
- Browser console: `✦ Developed by Kobir Shah ✦`
- Docker labels: `com.xtreamtv.developer=Kobir Shah`

> **130+ appearances** of developer credit across all 37 files.

Removing or altering the developer credit is a violation of the license terms.

---

## 📄 License

```
Copyright © 2026 Kobir Shah. All Rights Reserved.

This software is proprietary and confidential.
Unauthorized copying, modification, distribution, or removal
of developer credits is strictly prohibited.

Developed by: Kobir Shah
Project:      XtreamTV IPTV OS v2.0.0
```

---

<div align="center">

<img src="https://capsule-render.vercel.app/api?type=waving&color=0:00b4ff,50:a855f7,100:06b6d4&height=120&section=footer&animation=fadeIn" width="100%" alt="Footer"/>

**𝗕𝘂𝗶𝗹𝘁 𝘄𝗶𝘁𝗵 ❤️ 𝗯𝘆 𝗞𝗼𝗯𝗶𝗿 𝗦𝗵𝗮𝗵**

*XtreamTV IPTV OS v2.0.0 — Production-grade PHP IPTV Proxy Panel*

[![GitHub](https://img.shields.io/badge/GitHub-Kobir_Shah-181717?style=flat-square&logo=github)](https://github.com)
[![PHP](https://img.shields.io/badge/PHP-8.2-777BB4?style=flat-square&logo=php&logoColor=white)](https://php.net)
[![Docker](https://img.shields.io/badge/Docker-2496ED?style=flat-square&logo=docker&logoColor=white)](https://docker.com)
[![Cloudflare](https://img.shields.io/badge/Cloudflare-F38020?style=flat-square&logo=cloudflare&logoColor=white)](https://cloudflare.com)

```
𝗣𝗼𝘄𝗲𝗿𝗲𝗱 𝗯𝘆 𝗞𝗼𝗯𝗶𝗿 𝗦𝗵𝗮𝗵  ·  𝗔𝗹𝗹 𝗥𝗶𝗴𝗵𝘁𝘀 𝗥𝗲𝘀𝗲𝗿𝘃𝗲𝗱  ·  © 𝟮𝟬𝟮𝟲
```

</div>
