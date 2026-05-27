# Deploy XtreamTV on Railway — Step by Step

> **Beginner-friendly guide** — No server knowledge needed.
> Everything runs in your browser via Railway's dashboard.

---

## What you'll get

| Thing | URL example |
|-------|-------------|
| Admin panel | `https://xtreamtv.up.railway.app/xtreamtv/` |
| Playlist/stream URL (via Cloudflare CDN) | `https://random-name.trycloudflare.com/live/...` |
| Tunnel status endpoint | `https://xtreamtv.up.railway.app/xtreamtv/tunnel.php` |

---

## Step 1 — Fork the repo on GitHub

1. Go to [github.com/MohammadKobirShah/Xtream-TV-OS](https://github.com/MohammadKobirShah/Xtream-TV-OS)
2. Click the **Fork** button (top-right)
3. GitHub creates a copy under **your own account**

> You need your own fork so Railway can access the code.

---

## Step 2 — Create a Railway account

1. Go to [railway.app](https://railway.app)
2. Sign up with GitHub (or email)
3. No credit card needed for the free tier

---

## Step 3 — Deploy from GitHub

1. In Railway dashboard, click **New Project**
2. Select **Deploy from GitHub repo**
3. Install Railway's GitHub app (if prompted) — select your fork
4. Click **Start Deployment**

Railway will:
- Read the `Dockerfile`
- Build the Docker image
- Start the container

You'll see build logs streaming in real time.

---

## Step 4 — Add the PORT variable

Railway needs to know which port your app runs on.

1. In Railway dashboard, click your project
2. Go to the **Variables** tab
3. Click **New Variable**
4. Set:
   - **Variable name**: `PORT`
   - **Variable value**: `8080`
5. Click **Add**

The container will restart automatically. The entrypoint script detects this variable and tells Apache to listen on port 8080.

---

## Step 5 — Check startup logs

1. Go to the **Deployments** tab
2. Click the latest deployment
3. Scroll through the logs

You should see something like:

```
╔══════════════════════════════════════════════════════╗
║   ⚡ XtreamTV IPTV OS — Starting Up                 ║
║   Platform  : Railway                                ║
║   Port      : 8080                                   ║
╚══════════════════════════════════════════════════════╝

  [→] First run detected — running auto-installer...
  [✓] SQLite database created
  [✓] All database tables created successfully
  [✓] Default settings seeded
  [✓] Installation Complete!
```

If you see error messages instead (like "Failed to create directory"), skip to **Troubleshooting** below.

---

## Step 6 — Access your admin panel

Railway gives your project a public URL automatically.

1. In the **Deployments** tab, click the **Settings** tab
2. Look for **Public URL** — it looks like `https://your-project.up.railway.app`
3. Open that URL in your browser, and add `/xtreamtv/` at the end

```
https://your-project.up.railway.app/xtreamtv/
```

**Default login:**
- Username: `admin`
- Password: `admin123`

---

## Step 7 — Add a persistent volume (recommended)

Without this step, your database **resets every time you redeploy** (like a new server each time). Railway containers have temporary storage by default.

To keep your data permanent:

1. In Railway dashboard, click your project
2. Click the **Volumes** tab
3. Click **Create Volume**
4. Set:
   - **Mount Path**: `/var/www/html/xtreamtv/storage`
   - **Name**: `xtreamtv-storage`
5. Click **Create**

Now your SQLite database, cache, and EPG data survive restarts and redeploys.

---

## Step 8 — Get your Cloudflare tunnel URL

Your admin panel is served through Railway's URL, but **playlist and stream URLs go through Cloudflare's CDN** (automatic DDoS protection, caching, and global edge delivery).

To get the tunnel URL:

1. Open this in your browser:

```
https://your-project.up.railway.app/xtreamtv/tunnel.php
```

2. You'll get a JSON response like:

```json
{
    "tunnel_url": "https://random-name.trycloudflare.com",
    "status": "active",
    "platform": "railway",
    "port": "8080",
    "running": true
}
```

The `tunnel_url` is what you use for playlists and streams.

> **Note:** The tunnel URL changes each time the container restarts.
> Check `/xtreamtv/tunnel.php` after each deploy to get the current URL.

---

## Update your code (after changes)

Whenever you push changes to your GitHub fork:

1. In Railway dashboard, go to your project
2. Click **Deployments**
3. Click **Redeploy**

Railway will rebuild and restart with your new code.

---

## Troubleshooting

### "Database unavailable" error

The installer didn't run or failed. Two common causes:

**A) The PORT variable is missing** — Go to Variables tab and add `PORT=8080`, then Redeploy.

**B) File permission issue** — Run the installer manually via the Railway dashboard:

```
1. Go to your project → Variables tab
2. Click "Open Shell" button
3. Type this command and press Enter:

   php /var/www/html/xtreamtv/install.php --cli
   
4. You'll see the installer output. Share it with me if there are errors.
```

### Container keeps restarting (crash loop)

Check the **Deploy Logs** for the actual error message. Common causes:

- Missing `PORT` variable
- Port 8080 already in use (change to 8000 or another number)
- Out of memory (upgrade Railway plan)

### Tunnel URL not showing

The tunnel can take 10–30 seconds to connect after the container starts.
Check `/xtreamtv/tunnel.php` — if `status` is `"starting"`, wait a moment and refresh.

If status is `"error"`, cloudflared might not have internet access. Railway containers have outbound access by default.

---

## Need help?

Open an issue at [github.com/MohammadKobirShah/Xtream-TV-OS/issues](https://github.com/MohammadKobirShah/Xtream-TV-OS/issues)

---

*Powered by Kobir Shah — XtreamTV v2.0.1*
