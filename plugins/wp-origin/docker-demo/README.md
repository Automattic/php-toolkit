# WP Origin demo (Docker)

A self-contained WordPress install pre-loaded with 120 posts and the
WP Origin plugin **deactivated**, so you can press "Activate" yourself
and watch the async seeder build the initial import.

## Run it

From the repo root:

```bash
composer install                                  # toolkit vendor/ must exist
cd plugins/wp-origin/docker-demo
docker compose up
```

The `init` container exits as soon as WordPress is installed and
seeded; `wp` and `db` keep running. When you see the "WP Origin demo
is ready" banner, open:

- <http://localhost:8080/wp-admin/plugins.php>
  Log in as `admin` / `admin` and click **Activate** on WP Origin.
- <http://localhost:8080/wp-admin/tools.php?page=wp-origin>
  Watch the progress bar.

## What you'll see

The demo drops a small mu-plugin (`wp-origin-demo-budget.php`) that
shrinks the seeder's batch size to 5 posts and its time budget to 0
seconds, so each cron tick imports one batch and reschedules itself.
On 120 posts that's ~24 visible ticks before the seeder squashes the
side branch into a single "Initial import from WordPress" commit on
trunk and flips the state to `done`.

Once `state === done`, generate an Application Password from
<http://localhost:8080/wp-admin/profile.php> and clone:

```bash
git clone http://admin:<app-password>@localhost:8080/wp-json/git/v1/md.git wp-origin
```

## Tearing down

```bash
docker compose down -v
```

Removes containers, volumes, and the seeded WordPress install.

## Notes

- The plugin source is mounted read-only from the host (`../..`),
  so edits to `plugins/wp-origin/*.php` take effect on the next
  request.
- Remove `wp-content/mu-plugins/wp-origin-demo-budget.php` (via
  `docker compose exec wp rm …`) to see production-default seeder
  speed instead.
