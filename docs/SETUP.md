# Bible Compare – Setup Guide
## Windows 11 with Docker Desktop

---

## Prerequisites

### 1. Install Docker Desktop

1. Download Docker Desktop from https://www.docker.com/products/docker-desktop/
2. Run the installer. Accept the default settings.
3. When prompted, **enable WSL 2** (Windows Subsystem for Linux 2). Docker Desktop will offer to install it automatically if it is not present. Let it do so.
4. Restart your computer when asked.
5. After restarting, open Docker Desktop. Wait until the whale icon in the system tray stops animating — this means the Docker engine is running.
6. Open a terminal (**PowerShell** or **Windows Terminal**) and verify:
   ```powershell
   docker --version
   docker compose version
   ```
   Both commands should print version numbers.

### 2. Install Git for Windows

Download from https://git-scm.com/download/win and install with default options. After installation, verify:
```powershell
git --version
```

### 3. (Optional but recommended) Windows Terminal

Download **Windows Terminal** from the Microsoft Store. It provides a better experience than the default PowerShell window.

---

## Step 1 — Get the project files

Open Windows Terminal (or PowerShell) and navigate to where you want to place the project. Then clone or copy the project into a folder:

```powershell
# If using Git:
git clone <your-repo-url> bible-compare
cd bible-compare

# Or if you downloaded the zip, extract it and navigate into it:
cd C:\path\to\bible-compare
```

Verify you can see the main files:
```powershell
ls
# Should show: docker-compose.yml, .env, app/, ingest/, db/
```

---

## Step 2 — Configure environment variables

The `.env` file at the project root contains all configuration. Open it in any text editor:

```powershell
notepad .env
```

The defaults work for local development. For a first run you do not need to change anything. The file looks like this:

```
DB_NAME=bible_compare
DB_USER=bible
DB_PASSWORD=changeme
APP_SECRET=change_me_to_a_real_32_char_secret_value
REMEMBER_ME_SECRET=change_me_to_a_real_32_char_secret_value
SERVER_NAME=localhost, localhost:80
```

> **Security note:** Before exposing this to a network, change `DB_PASSWORD` and `APP_SECRET` to strong random values. You can generate a secret with:
> ```powershell
> python -c "import secrets; print(secrets.token_hex(32))"
> ```

---

## Step 3 — Install Composer dependencies

The Symfony `vendor/` directory is not included in the repository. You need to install PHP dependencies. Because PHP is inside Docker, you use the container to run Composer:

```powershell
# Build the dev image first (this downloads PHP, FrankenPHP, Composer)
docker compose build app

# Install dependencies using the container
docker compose run --rm app composer install
```

This will take a few minutes on the first run as it downloads packages. You will see output like `Installing symfony/framework-bundle`.

---

## Step 4 — Start the stack

`docker-compose.override.yml` (the dev-only port mappings and live code reload)
is not tracked in git — Docker Compose auto-loads any file with that exact
name, which is convenient locally but dangerous if it ever ends up on a
server. Create your own copy from the template first:

```powershell
cp docker-compose.override.yml.dist docker-compose.override.yml
```

```powershell
docker compose up -d
```

This starts two services in the background:
- `bible_postgres` — the PostgreSQL 16 database
- `bible_app` — the Symfony / FrankenPHP web server

Watch the logs during first startup:
```powershell
docker compose logs -f
```

You should see PostgreSQL initialise and run the schema (`01-schema.sql`), followed by FrankenPHP starting up. Press `Ctrl+C` to stop following logs (the containers keep running).

Verify both containers are healthy:
```powershell
docker compose ps
```

Both should show `running` or `healthy` status.

---

## Step 5 — Verify the web application

Open your browser and navigate to:

```
https://localhost
```

> **Important:** FrankenPHP uses a self-signed TLS certificate for localhost. Your browser will show a security warning. This is expected for local development.
>
> - **Chrome/Edge:** Click "Advanced" → "Proceed to localhost (unsafe)"
> - **Firefox:** Click "Advanced" → "Accept the Risk and Continue"

You should see the Bible Comparison home page with a list of books (but no data yet — the verse content comes in Step 6).

---

## Step 6 — Run the ingest pipeline

The ingest pipeline downloads the source texts and populates the database. This is the most time-consuming step.

```powershell
docker compose --profile ingest run --rm ingest
```

The pipeline runs seven steps in sequence:

| Step | Description | Approximate time |
|------|-------------|-----------------|
| 1 | Download source repositories (git clone) | 2–10 min (depends on internet speed) |
| 2 | Parse Hebrew OT (TAHOT) | 3–5 min |
| 3 | Parse Greek NT (Elzevir) | 1–2 min |
| 4 | Parse Statenvertaling XML | 2–4 min |
| 5 | Pivot alignment (ESV → Dutch) | 5–15 min |
| 6 | Heuristic alignment (fallback) | 3–8 min |
| 7 | Parse Strong's dictionary | 1–3 min |

You will see progress bars for each step. When it finishes you should see:

```
══════════════════════════════════════════════════════════════
  ✓ Ingest pipeline complete.
══════════════════════════════════════════════════════════════
```

### Running individual steps

You can re-run a single step without running the whole pipeline:

```powershell
# Re-run only the Elzevir parser
docker compose --profile ingest run --rm ingest python parse_elzevir.py

# Re-run only the heuristic alignment
docker compose --profile ingest run --rm ingest python align_heuristic.py
```

---

## Step 7 — Verify the data

Connect to the database and check the row counts:

```powershell
docker compose exec postgres psql -U bible -d bible_compare -c "
SELECT 'hebrew_words'      AS tbl, COUNT(*) FROM hebrew_words
UNION ALL
SELECT 'greek_words',               COUNT(*) FROM greek_words
UNION ALL
SELECT 'translation_verses',        COUNT(*) FROM translation_verses
UNION ALL
SELECT 'translation_words',         COUNT(*) FROM translation_words
UNION ALL
SELECT 'word_links',                COUNT(*) FROM word_links;
"
```

Expected approximate row counts:

| Table | Expected rows |
|-------|--------------|
| `hebrew_words` | ~300,000–310,000 |
| `greek_words` | ~130,000–141,000 |
| `translation_verses` | ~31,100 |
| `translation_words` | ~790,000 |
| `word_links` | depends on alignment quality |

Spot-check a verse:
```powershell
docker compose exec postgres psql -U bible -d bible_compare -c "
SELECT word_position, word_text, strongs, morph_code
FROM hebrew_words WHERE book_id = 1 AND chapter = 1 AND verse = 1
ORDER BY word_position;
"
```

---

## Step 8 — Browse the application

Refresh `https://localhost` in your browser. You should now see:
- The home page with Old and New Testament book lists
- Coverage statistics showing how many words have been linked

Navigate to a verse: click **Genesis** → **Hoofdstuk 1** → **1:1**

You will see the comparison view with:
- Hebrew words on the left (right-to-left, with transliteration and Strong's numbers)
- Statenvertaling on the right, with Dutch words colour-coded by alignment method
- Click any Hebrew word to highlight its Dutch equivalent(s)
- Click any Dutch word to highlight its source word(s)

---

## Development workflow

### Rebuild the app container after code changes

```powershell
docker compose build app
docker compose up -d app
```

### View application logs

```powershell
docker compose logs -f app
```

### Run Symfony console commands

```powershell
# Clear cache
docker compose exec app php bin/console cache:clear

# List all routes
docker compose exec app php bin/console debug:router

# Check Doctrine mapping
docker compose exec app php bin/console doctrine:mapping:info
```

### Connect to the database with a GUI tool

The PostgreSQL port is exposed on `localhost:5432`. Connect with:
- **DBeaver** (free): https://dbeaver.io
- **TablePlus** (commercial, has free tier): https://tableplus.com
- **pgAdmin**: https://www.pgadmin.org

Connection settings:
| Setting | Value |
|---------|-------|
| Host | `localhost` |
| Port | `5432` |
| Database | `bible_compare` |
| User | `bible` |
| Password | `changeme` |

### Stop the stack

```powershell
docker compose down
```

Your data is preserved in the `postgres_data` Docker volume. To start again:
```powershell
docker compose up -d
```

### Completely reset the database (start fresh)

This deletes all data:
```powershell
docker compose down -v   # -v removes volumes
docker compose up -d
# Then re-run the ingest pipeline
docker compose --profile ingest run --rm ingest
```

---

## Production deployment

For deploying to a server:

1. Copy the project files to the server.
2. Set `SERVER_NAME` in `.env` to your real domain: `SERVER_NAME=example.com`
3. Set strong values for `DB_PASSWORD` and `APP_SECRET`.
4. In `docker-compose.yml`, remove the `override` file and set the app target to `prod`.
5. Run `docker compose up -d`.

FrankenPHP/Caddy will automatically obtain a Let's Encrypt certificate for your domain. No additional TLS configuration is required.

---

## Troubleshooting

### "port is already allocated" error

Port 80, 443, or 5432 is in use by another process. Either stop the other process, or change the port mapping in `docker-compose.override.yml`.

### Ingest fails with "connection refused"

The ingest container started before the database was fully ready. Wait 30 seconds and try again:
```powershell
docker compose --profile ingest run --rm ingest
```

### Ingest fails with "No TAHOT file found"

The STEPBible repository clone may have failed (network timeout or rate limit). Re-run just the fetch step:
```powershell
docker compose --profile ingest run --rm ingest python fetch_sources.py
```

### Browser shows "ERR_SSL_PROTOCOL_ERROR"

Navigate to `http://localhost` (HTTP) instead of HTTPS, or trust the Caddy root certificate. Caddy stores it in the container; to extract and trust it on Windows, run:
```powershell
docker compose exec app caddy trust
```

### The page loads but shows no books

The `books` seed data in `schema.sql` only runs when PostgreSQL initialises for the first time (i.e., on an empty volume). If you started with existing data and then changed the schema, reset the volume:
```powershell
docker compose down -v && docker compose up -d
```

### Composer install fails inside Docker

On Windows with WSL 2, ensure the project is stored inside the WSL filesystem rather than `/mnt/c/...` for best performance and compatibility. Move the project to `~/projects/bible-compare` inside WSL and run Docker from there.
