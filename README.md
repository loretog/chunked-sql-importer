# Chunked SQL Importer

Import large `.sql` dump files into WordPress without browser timeouts or PHP execution limits. Uploads and execution run in small, resumable chunks with progress tracking and logging.

**Author:** Loreto G. Gabawa Jr.  
**Version:** 1.1.0  
**Requires:** WordPress 5.8+, PHP 7.4+

## Why this plugin?

Importing a large SQL file through phpMyAdmin, Adminer, or a single wp-admin request often fails because:

- **Upload phase:** `upload_max_filesize`, `post_max_size`, proxy timeouts
- **Execution phase:** `max_execution_time`, `memory_limit`, MySQL `max_allowed_packet`

Chunked SQL Importer splits the work into many short REST requests so each step finishes within normal server limits. You can pause, resume, and review failed statements.

## Features

- **Chunked browser upload** — large files are sent in 2 MB pieces
- **Server-path import** — drop a file in an inbox folder (ideal for Docker/local)
- **Streaming SQL parser** — reads the dump incrementally; handles quotes and comments
- **Queued execution** — runs statements in batches with a time budget per request
- **Pause / resume** — stop and continue imports without starting over
- **Progress UI** — parse and execute progress bars, live log
- **Error logging** — failed statements are recorded with MySQL error messages
- **Import options** — foreign key checks, skip DROP/CREATE, strip DEFINER, stop on error

## Installation

1. Copy the `chunked-sql-importer` folder to `wp-content/plugins/`
2. Activate **Chunked SQL Importer** under **Plugins**
3. Open **Tools → SQL Import**

On activation, the plugin creates:

- Database tables: `wp_csi_jobs`, `wp_csi_queue`, `wp_csi_log`
- Upload directories under `wp-content/uploads/sql-imports/`

## Usage

### Option A: Upload from your computer

1. Go to **Tools → SQL Import**
2. Choose a `.sql` file and click **Upload SQL file**
3. Wait for the upload progress bar to reach 100%
4. Click **Start Import** on the file in the list

### Option B: Copy to server inbox

1. Place your `.sql` file in:

   ```
   wp-content/uploads/sql-imports/inbox/
   ```

2. Click **Refresh file list**
3. Click **Start Import**

### During import

- **Pause** — stops after the current batch
- **Resume** — continues parsing or executing from where it left off
- **Import log** — shows batch summaries and per-statement errors

## Import options

| Option | Default | Description |
|--------|---------|-------------|
| Disable foreign key checks | On | Runs `SET FOREIGN_KEY_CHECKS = 0` during execution |
| Strip DEFINER clauses | On | Removes `DEFINER=` from views/routines/triggers |
| Stop on first SQL error | Off | Halts the job when a statement fails |
| Skip DROP statements | Off | Ignores `DROP TABLE`, etc. |
| Skip CREATE statements | Off | Ignores `CREATE TABLE`, etc. |

`LOCK TABLES` and `UNLOCK TABLES` are always skipped.

## How it works

```
Upload / inbox file
       ↓
Streaming parser (REST batches)
       ↓
Statement queue (wp_csi_queue)
       ↓
Batch executor (REST batches)
       ↓
Completed / failed job + log
```

1. **Parse** — reads the SQL file from a byte offset, splits on `;` outside strings/comments, enqueues each statement
2. **Execute** — runs pending statements in batches (~50 statements or ~20 seconds per request)
3. **Track** — job state, counters, and logs persist in the database

## Server recommendations

For large imports, increase these limits on your PHP and MySQL hosts:

```ini
; php.ini
upload_max_filesize = 8M
post_max_size = 8M
max_execution_time = 300
memory_limit = 512M
```

```ini
; my.cnf
max_allowed_packet = 256M
```

Each upload chunk is 2 MB, so `upload_max_filesize` only needs to exceed the chunk size—not the full dump size.

For Docker/nginx, also check `client_max_body_size` and proxy read timeouts if uploads still fail.

## Generate a test SQL file

A CLI script is included for testing uploads and imports:

```bash
php wp-content/plugins/chunked-sql-importer/bin/generate-test-sql.php 50
```

Arguments:

```bash
php bin/generate-test-sql.php [target-mb] [output-file]
```

Examples:

```bash
php bin/generate-test-sql.php 50
php bin/generate-test-sql.php 100
php bin/generate-test-sql.php 200 ../../uploads/sql-imports/inbox/my-test.sql
```

The generated file creates a `csi_import_test_rows` table with many `INSERT` rows. Use only on development/staging databases.

## REST API

All endpoints require `manage_options` and a valid REST nonce.

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/wp-json/csi/v1/files` | List inbox files |
| POST | `/wp-json/csi/v1/files/upload` | Upload one chunk (multipart) |
| GET | `/wp-json/csi/v1/jobs` | List import jobs |
| POST | `/wp-json/csi/v1/jobs` | Create job from inbox file |
| GET | `/wp-json/csi/v1/jobs/{id}` | Job status |
| POST | `/wp-json/csi/v1/jobs/{id}/parse` | Parse next batch |
| POST | `/wp-json/csi/v1/jobs/{id}/run` | Execute next batch |
| POST | `/wp-json/csi/v1/jobs/{id}/pause` | Pause job |
| POST | `/wp-json/csi/v1/jobs/{id}/resume` | Resume job |
| GET | `/wp-json/csi/v1/jobs/{id}/log` | Recent log entries |

## Security

- Only users with `manage_options` can access the importer
- Upload and inbox directories are protected with `.htaccess` (`Deny from all`)
- This plugin executes **arbitrary SQL** — always back up your database before importing

## Troubleshooting

### Upload fails or times out

- Confirm `upload_max_filesize` and `post_max_size` are larger than 2 MB
- Check nginx/Apache `client_max_body_size` or proxy timeouts
- For very large files, copy directly to the inbox folder instead of uploading

### Import stuck on “Parsing” or “Executing”

- Open browser DevTools → Network and look for failed REST requests
- Check the import log for MySQL errors
- Resume the job from **Recent jobs** → **View**

### Duplicate data after re-import

- Standard `INSERT` dumps will duplicate rows if imported twice
- Use a fresh database, or a dump with `TRUNCATE` / `DROP` (unless you enabled skip options)

### `DEFINER` or permission errors

- Enable **Strip DEFINER clauses** (on by default)

## Plugin structure

```
chunked-sql-importer/
├── chunked-sql-importer.php   # Bootstrap
├── admin/
│   ├── class-admin-page.php
│   ├── css/import-ui.css
│   └── js/import-ui.js
├── bin/
│   └── generate-test-sql.php
├── includes/
│   ├── class-activator.php
│   ├── class-executor.php
│   ├── class-job-manager.php
│   ├── class-logger.php
│   ├── class-plugin.php
│   ├── class-queue-repository.php
│   ├── class-rest-controller.php
│   ├── class-sql-parser.php
│   └── class-upload-handler.php
└── README.md
```

## License

GPL-2.0-or-later

## Links

- [GitHub repository](https://github.com/loretog/chunked-sql-importer)
