# Technosphere auto-update launcher

This wrapper keeps the game files in `app/` synced to:

- repo: `stevenwilkins/Technosphere`
- branch: `main`

## Run

```bash
php start.php
```

That will:

1. check the latest commit on GitHub
2. download the repo archive only when the deployed commit differs
3. update the files in `app/`
4. preserve local save files:
   - `app/technosphere.sqlite`
   - `app/technosphere_fallback.json`
5. start the PHP built-in server on `localhost:8000`

Open:

```text
http://localhost:8000/index.php
```

## Other usage

Update only, do not start the server:

```bash
php start.php --update-only
```

Use a different bind address:

```bash
php start.php 127.0.0.1:9000
```

## Notes

- This trusts the contents of the public GitHub repository on every run.
- If GitHub is unreachable, it keeps serving the local copy already in `app/`.
- `ZipArchive` is required to apply updates automatically.
