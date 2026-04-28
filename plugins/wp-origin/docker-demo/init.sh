#!/usr/bin/env bash
# One-shot installer that fires inside the wordpress:cli container.
# Waits for the wp service to populate /var/www/html, installs
# WordPress, generates 120 posts so initial-import seeding visibly
# spans many cron ticks, drops the WP Origin plugin source into place,
# and installs the small-budget mu-plugin so each batch is one cron
# tick (otherwise the host is fast enough to import 120 posts in a
# single tick and the progress bar barely moves).
#
# The plugin is INTENTIONALLY left deactivated. Open
# http://${WP_ORIGIN_DEMO_HOST:-localhost:8090}/wp-admin/plugins.php, click "Activate" on
# WP Origin, then watch the progress bar at
# http://${WP_ORIGIN_DEMO_HOST:-localhost:8090}/wp-admin/tools.php?page=wp-origin.
set -euo pipefail

cd /var/www/html

for _ in $(seq 1 60); do
	if [ -f wp-load.php ]; then
		break
	fi
	sleep 1
done

if ! wp core is-installed 2>/dev/null; then
	wp core install \
		--url=http://${WP_ORIGIN_DEMO_HOST:-localhost:8090} \
		--title="WP Origin Demo" \
		--admin_user=admin \
		--admin_password=admin \
		--admin_email=admin@example.com \
		--skip-email
	wp option update permalink_structure '/%postname%/'
	wp rewrite flush --hard

	wp post generate \
		--count=120 \
		--post_type=post \
		--post_status=publish \
		--post_author=1
fi

# Mount toolkit components and the plugin into wp-content. Symlinks
# (not copies) so the user can keep editing code on the host and see
# changes immediately.
if [ ! -e wp-content/plugins/wp-origin ]; then
	ln -s /srv/php-toolkit/plugins/wp-origin wp-content/plugins/wp-origin
fi
if [ ! -e wp-content/components ]; then
	ln -s /srv/php-toolkit/components wp-content/components
fi
if [ ! -e wp-content/vendor ]; then
	ln -s /srv/php-toolkit/vendor wp-content/vendor
fi

# Force the seeder to span many cron ticks regardless of how fast the
# host CPU is, so the progress bar is visibly active. Remove this file
# to see production-default behaviour.
mkdir -p wp-content/mu-plugins
cp /srv/php-toolkit/plugins/wp-origin/Tests/ci-mu-test-helper.php \
	wp-content/mu-plugins/wp-origin-demo-budget.php

# Ensure WP Origin is NOT activated yet — the user will press Activate.
wp plugin deactivate wp-origin --quiet 2>/dev/null || true

POST_COUNT=$(wp post list --post_type=post --post_status=publish --format=count)

cat <<EOF

============================================================
  WP Origin demo is ready.

  URL:    http://${WP_ORIGIN_DEMO_HOST:-localhost:8090}/wp-admin/
  user:   admin
  pass:   admin
  posts:  ${POST_COUNT} (plus default page)

  1. Visit /wp-admin/plugins.php and click Activate on WP Origin.
  2. Watch progress at /wp-admin/tools.php?page=wp-origin.
  3. Once the bar reaches 100%, clone with:
        git clone http://admin:<app-password>@${WP_ORIGIN_DEMO_HOST:-localhost:8090}/wp-json/git/v1/md.git wp-origin

     Generate the app password from /wp-admin/profile.php first.
============================================================

EOF
