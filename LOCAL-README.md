# Technosphere root updater

This folder now uses a single root `index.php` as the entry point.

## What it does

- checks `stevenwilkins/Technosphere` on GitHub when the app entry is requested
- skips the update when the latest commit SHA matches the last deployed SHA
- downloads and mirrors the repo into `app/` only when the commit changed
- preserves local save files:
  - `app/technosphere.sqlite`
  - `app/technosphere_fallback.json`
- then serves the requested file from `app/` or falls back to `app/index.php`

## Run locally

From this folder:

```bash
php -S localhost:8000 index.php
```

Then open:

```text
http://localhost:8000/
```

The router form above is recommended because it lets the root `index.php` serve future files that may appear in the repo under `app/`.

## Optional CLI update check

```bash
php index.php update
```

## Files

- `index.php` — root updater and request router
- `app/` — synced copy of the GitHub repo
- `.technosphere-updater.json` — last deployed commit metadata
- `.technosphere-updater.lock` — update lock file
