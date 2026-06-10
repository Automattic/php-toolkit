<?php

$push_md_target_dir = isset( $argv[1] ) ? $argv[1] : getenv( 'PMD_BUNDLE_TARGET' );
if ( ! is_string( $push_md_target_dir ) || '' === $push_md_target_dir ) {
	fwrite( STDERR, "Usage: php bin/scope-push-md-bundle.php <bundle-dir>\n" );
	exit( 1 );
}

$push_md_target_dir = rtrim( $push_md_target_dir, '/' );
if ( ! is_dir( $push_md_target_dir ) ) {
	fwrite( STDERR, "Missing Push MD bundle directory: {$push_md_target_dir}\n" );
	exit( 1 );
}

function push_md_scope_root_prefix() {
	return 'PushMDVendor';
}

function push_md_scope_roots() {
	return array(
		'Composer'  => true,
		'Dflydev'   => true,
		'League'    => true,
		'Nette'     => true,
		'Psr'       => true,
		'Symfony'   => true,
		'WordPress' => true,
	);
}

function push_md_scope_global_symbols() {
	return array(
		'Attribute'           => true,
		'PhpToken'            => true,
		'Stringable'          => true,
		'UnhandledMatchError' => true,
		'ValueError'          => true,
	);
}

function push_md_scope_qualified_name( $name ) {
	$scope_root = push_md_scope_root_prefix();
	$leading    = '';

	if ( isset( $name[0] ) && '\\' === $name[0] ) {
		$leading = '\\';
		$name    = substr( $name, 1 );
	}

	if ( '' === $name ) {
		return $leading . $name;
	}

	$parts = explode( '\\', $name );
	if ( $scope_root === $parts[0] ) {
		return $leading . $name;
	}

	$roots          = push_md_scope_roots();
	$global_symbols = push_md_scope_global_symbols();
	if ( isset( $roots[ $parts[0] ] ) || ( 1 === count( $parts ) && isset( $global_symbols[ $parts[0] ] ) ) ) {
		return $leading . $scope_root . '\\' . $name;
	}

	return $leading . $name;
}

function push_md_token_is_use_context( $tokens, $index ) {
	for ( $i = $index - 1; $i >= 0; --$i ) {
		$token = $tokens[ $i ];
		if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		if ( is_array( $token ) && T_AS === $token[0] ) {
			continue;
		}
		if ( ',' === $token ) {
			continue;
		}
		if ( is_array( $token ) && T_USE === $token[0] ) {
			return true;
		}
		if ( in_array( $token, array( ';', '{', '}' ), true ) ) {
			return false;
		}
		if ( is_array( $token ) && in_array( $token[0], array( T_CLASS, T_FUNCTION, T_NAMESPACE, T_TRAIT, T_INTERFACE ), true ) ) {
			return false;
		}
	}

	return false;
}

function push_md_token_is_namespace_declaration_root( $tokens, $index ) {
	for ( $i = $index - 1; $i >= 0; --$i ) {
		$token = $tokens[ $i ];
		if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}

		return is_array( $token ) && T_NAMESPACE === $token[0];
	}

	return false;
}

function push_md_token_name_ids() {
	$ids = array();
	foreach ( array( 'T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED' ) as $constant ) {
		if ( defined( $constant ) ) {
			$ids[] = constant( $constant );
		}
	}

	return $ids;
}

function push_md_prepare_php80_stub( $code, $relative_path ) {
	if ( ! preg_match( '#php-toolkit/components/Markdown/vendor-patched/symfony/polyfill-php80/Resources/stubs/(Attribute|PhpToken|Stringable|UnhandledMatchError|ValueError)\.php$#', $relative_path ) ) {
		return $code;
	}

	if ( false === strpos( $code, 'namespace ' . push_md_scope_root_prefix() . ';' ) ) {
		$code = preg_replace( '/\A<\?php\s*/', "<?php\n\nnamespace " . push_md_scope_root_prefix() . ";\n\n", $code, 1 );
	}

	$code = str_replace( 'extends Error', 'extends \\Error', $code );

	return $code;
}

function push_md_scope_class_name_string_literals( $code ) {
	return str_replace(
		array(
			"'Composer\\\\Autoload\\\\ClassLoader'",
			'"Composer\\\\Autoload\\\\ClassLoader"',
		),
		array(
			"'" . push_md_scope_root_prefix() . "\\\\Composer\\\\Autoload\\\\ClassLoader'",
			'"' . push_md_scope_root_prefix() . '\\\\Composer\\\\Autoload\\\\ClassLoader"',
		),
		$code
	);
}

function push_md_scope_php_code( $code, $relative_path ) {
	$code           = push_md_prepare_php80_stub( $code, $relative_path );
	$name_token_ids = push_md_token_name_ids();
	$global_symbols = push_md_scope_global_symbols();
	$tokens         = token_get_all( $code );
	$rewritten      = '';

	foreach ( $tokens as $index => $token ) {
		if ( ! is_array( $token ) ) {
			$rewritten .= $token;
			continue;
		}

		if ( in_array( $token[0], $name_token_ids, true ) ) {
			$rewritten .= push_md_scope_qualified_name( $token[1] );
			continue;
		}

		if (
			T_STRING === $token[0] &&
			(
				push_md_token_is_namespace_declaration_root( $tokens, $index ) ||
				push_md_token_is_use_context( $tokens, $index )
			) &&
			push_md_scope_qualified_name( $token[1] ) !== $token[1]
		) {
			$rewritten .= push_md_scope_qualified_name( $token[1] );
			continue;
		}

		$rewritten .= $token[1];
	}

	return push_md_scope_class_name_string_literals( $rewritten );
}

function push_md_normalize_path( $path ) {
	return str_replace( '\\', '/', $path );
}

$push_md_iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator(
		$push_md_target_dir,
		FilesystemIterator::SKIP_DOTS
	)
);

foreach ( $push_md_iterator as $push_md_file ) {
	if ( 'php' !== strtolower( $push_md_file->getExtension() ) ) {
		continue;
	}

	$push_md_path          = $push_md_file->getPathname();
	$push_md_relative_path = push_md_normalize_path( substr( $push_md_path, strlen( $push_md_target_dir ) + 1 ) );
	$push_md_code          = file_get_contents( $push_md_path );
	if ( false === $push_md_code ) {
		continue;
	}

	$push_md_scoped_code = push_md_scope_php_code( $push_md_code, $push_md_relative_path );
	if ( $push_md_code !== $push_md_scoped_code ) {
		file_put_contents( $push_md_path, $push_md_scoped_code );
	}
}
