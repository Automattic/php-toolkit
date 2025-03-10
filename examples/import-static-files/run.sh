#!/bin/bash

# A subset of Gutenberg docs
# bun index.js \
# 	git https://github.com/WordPress/gutenberg.git \
# 	--branch=trunk \
# 	--path-in-repo=docs/how-to-guides/data-basics/ \
# 	--media-url=https://developer.wordpress.org/files/ \
# 	--media-url=https://raw.githubusercontent.com/WordPress/gutenberg/HEAD/docs/ \
# 	--source-site-url=https://developer.wordpress.org/block-editor/how-to-guides/data-basics/ \
# 	--additional-site-urls=https://developer.wordpress.org/docs/how-to-guides/data-basics/

# The entire Gutenberg documentation
bun index.js \
	git https://github.com/WordPress/gutenberg.git \
	--branch=trunk \
	--path-in-repo=docs/ \
	--media-url=https://developer.wordpress.org/files/ \
	--media-url=https://raw.githubusercontent.com/WordPress/gutenberg/HEAD/docs/ \
	--source-site-url=https://developer.wordpress.org/block-editor/ \
	--additional-site-urls=https://developer.wordpress.org/docs/

# Playground docs – Blueprints tutorial
# The URL structure is broken – we would need an MDX-specific plugin to
# correctly map the URLs.
# bun index.js \
# 	git https://github.com/WordPress/wordpress-playground.git \
# 	--branch=trunk \
# 	--path-in-repo=packages/docs/site/docs/blueprints/ \
# 	--media-url=https://wordpress.github.io/wordpress-playground/ \
# 	--source-site-url=https://wordpress.github.io/

# Laravel docs
# It imports fine but there are issues we'd need a dedicated
# Laravel plugin to resolve:
# * interpolate the {{version}} markers in the URLs
# * Render anchors where empty <a name=""> tags are found
# * Process other custom syntax used in the Laravel docs
# bun index.js \
# 	git https://github.com/laravel/docs.git \
# 	--path-in-repo=/ \
# 	--branch=12.x \
# 	--media-url=https://laravel.com/docs/ \
# 	--source-site-url=https://laravel.com/docs/

# CPython internal docs
# Imports mostly fine, links to other markdown pages continue to work.
# There are links to code files, e.g. /Include/internal/pycore_pyarena.h
# Right now we preserve them as they are, but we could optionally
# rewrite them to point to the GitHub version of the file.
#
# This markdown syntax could come through as a formatted paragraph block:
#
#    [!NOTE]
# 
#    Many of these changes require re-generating some of the derived files. If things mysteriously don’t work, it may help to run make clean.
# bun index.js \
# 	git https://github.com/python/cpython.git \
# 	--branch=main \
# 	--path-in-repo=InternalDocs/ \
	# --source-site-url=https://docs.python.org/internal/

# Fullstack GraphQL book 
# bun index.js \
# 	git https://github.com/GraphQLCollege/fullstack-graphql.git \
# 	--branch=master \
# 	--path-in-repo=manuscript/ \
# 	--source-site-url=https://www.graphqladmin.com/books/fullstack-graphql/

# CPP WASM book
# The URL structure and images come through fine.
# The table of contents in the main README file gets corrupted – MarkdownConsumer will need an update.
# Otherwise it looks good.
# bun index.js \
# 	git https://github.com/3dgen/cppwasm-book.git \
# 	--branch=master \
# 	--path-in-repo=en/ \
# 	--source-site-url=https://raw.githubusercontent.com/3dgen/cppwasm-book/refs/heads/master/en/

# This repo is useful for sourcing GitHub-based content:
# https://github.com/EbookFoundation/free-programming-books/blob/main/books/free-programming-books-langs.md#graphs

