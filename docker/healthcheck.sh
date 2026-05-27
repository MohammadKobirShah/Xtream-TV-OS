#!/usr/bin/env bash
# XtreamTV — Healthcheck
# Uses PORT (Railway) or falls back to 80 (Docker)
PORT="${PORT:-80}"
exec curl -fsSL "http://localhost:$PORT/xtreamtv/login.php" -o /dev/null
