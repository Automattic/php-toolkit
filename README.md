## WordPress components and Data Liberation

Standalone libraries meant for eventual inclusion in WordPress core.

This repository is what you, as a WordPress developer, have been dreaming
of. Really. Here's why:

* XMLProcessor – stream-parse XML files on any PHP installation (no libxml2 required).
* Git – a pure PHP implementation of Git client and server.
* HttpClient – a streaming, non-blocking, concurrent HTTP client library with no curl dependency.
* Zip – stream-parse and stream-write ZIP files with no libzip dependency.
* Data Liberation – generic streaming data importers to WordPress. Supports WXR, zipped markdown, remote git repos, rewriting URLs, and more.
* ByteStream – composable byte streaming utilities – readers, writers, filters.
* Markdown – convert between markdown and block markup with no dependencies.
* Filesystem – single API for working with local files, Git, Google drive, memory, etc.

This project aims to modernize WordPress's data handling capabilities and power
the Data Liberation project. See [the rationale](RATIONALE.md) and [the plan](PLAN.md)
for more details.

### Play with it

Here's a very rough process you can use to start WordPress with a plugin that
uses most of the components shipped with this project.

1. Create a file plugins/static-files-editor/secrets.php with the following content:

```php
<?php
define('GIT_REPO_URL', 'https://github.com/woocommerce/woocommerce.git');
define('GIT_BRANCH', 'trunk');
define('GIT_DIRECTORY_ROOT', '/docs');

define('GIT_USER_NAME', 'Your name');
define('GIT_USER_EMAIL', 'your@email.com');
```

2. Run the following command to start WordPress with the plugin:

```sh
bash run.sh
```

3. Navigate to http://127.0.0.1:9400/wp-admin/edit.php?post_type=local_file&dump=1 to import the WooCommerce documentation.
4. You're done!

The UI build process is not configured yet so this won't get the static files editor UI right now. This part is TBD.

You can also clone your local site as follows:

```sh
mkdir local-repo
git init
git remote add wp http://localhost:9400/wp-content/plugins/git-repo/git-repo.php\?
git ls-remote wp
```

### Development

#### Testing

To run the PHPUnit test suite, run:

```sh
composer test
```

#### Linting

To run the PHP_CodeSniffer linting suite, run:

```sh
composer lint
```

To fix the linting errors, run:

```sh
composer lint-fix
```

#### Composer

The root composer.json file is an amalgamation of composer.base.json all 
component composer.json files. To regenerate it, run:

```sh
bin/regenerate_composer.json.php
```

This will merge all the package-specific dependencies and the autoload rules into
the root composer.json file.
