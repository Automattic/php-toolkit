<?php

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wp/' );
}

if ( ! function_exists( 'post_type_exists' ) ) {
	function post_type_exists( $post_type ) {
		return in_array( $post_type, array( 'post', 'page', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_theme', 'wp_global_styles' ), true );
	}
}

if ( ! function_exists( 'taxonomy_exists' ) ) {
	function taxonomy_exists( $taxonomy ) {
		unset( $taxonomy );

		return false;
	}
}

require_once dirname( __DIR__ ) . '/class-push-md-path-filter.php';
require_once dirname( __DIR__ ) . '/class-push-md-plugin.php';

class PathFilterTest extends TestCase {

	public function testSupportedPaths() {
		$supported = array(
			'post/hello-world.md',
			'page/about.md',
			'page/parent/child.md',
			'wp_template/single.html',
			'wp_template_part/header.html',
			'wp_navigation/main.html',
			'wp_theme/theme.json',
			'wp_global_styles/theme.json',
			'wp_guideline/skills/test/SKILL.md',
			'categories.md',
			'tags.md',
			'authors.md',
			'AGENTS.md',
			'CLAUDE.md',
			'README.md',
			'.agents/skills/SKILL.md',
			'.claude/skills/SKILL.md',
			'media/logo.png',
		);

		foreach ( $supported as $path ) {
			$this->assertTrue( Push_MD_Path_Filter::is_supported_path( $path ), "Expected path '{$path}' to be supported." );
			$this->assertFalse( Push_MD_Path_Filter::is_ignored_path( $path ), "Expected path '{$path}' to not be ignored." );
		}
	}

	public function testIgnoredPaths() {
		$ignored = array(
			'.gitignore',
			'work/draft.md',
			'work/notes.txt',
			'.icon/state.json',
			'.codex/config.json',
			'.obsidian/workspace.json',
			'.vscode/settings.json',
			'temp/scratch.md',
			'random.txt',
			'post/invalid/deep/post.md',
			'wp_template/invalid.md',
		);

		foreach ( $ignored as $path ) {
			$this->assertFalse( Push_MD_Path_Filter::is_supported_path( $path ), "Expected path '{$path}' to be unsupported." );
			$this->assertTrue( Push_MD_Path_Filter::is_ignored_path( $path ), "Expected path '{$path}' to be ignored." );
		}
	}

	public function testPushSummaryIncludesIgnoredPaths() {
		$push_summary = array(
			array(
				'action'    => 'updated',
				'post_id'   => 12,
				'post_type' => 'post',
				'status'    => 'publish',
				'title'     => 'Hello World',
				'url'       => 'http://example.org/hello-world/',
				'path'      => 'post/hello-world.md',
			),
			array(
				'action'    => 'ignored',
				'post_id'   => 0,
				'post_type' => '',
				'status'    => '',
				'title'     => '',
				'url'       => '',
				'path'      => 'work/draft.md',
			),
		);

		$reflection = new ReflectionClass( Push_MD_Plugin::class );
		$method     = $reflection->getMethod( 'format_push_summary_messages' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$messages = $method->invoke( null, $push_summary );

		$this->assertContains( 'Push MD applied 1 content change:', $messages );
		$this->assertContains( '- Updated post: http://example.org/hello-world/', $messages );
		$this->assertContains( '- Ignored unsupported path: work/draft.md', $messages );
	}

	public function testPushSummaryWithOnlyIgnoredPaths() {
		$push_summary = array(
			array(
				'action'    => 'ignored',
				'post_id'   => 0,
				'post_type' => '',
				'status'    => '',
				'title'     => '',
				'url'       => '',
				'path'      => 'work/scratch.txt',
			),
		);

		$reflection = new ReflectionClass( Push_MD_Plugin::class );
		$method     = $reflection->getMethod( 'format_push_summary_messages' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$messages = $method->invoke( null, $push_summary );

		$this->assertContains( 'Push MD received push (no WordPress content changes):', $messages );
		$this->assertContains( '- Ignored unsupported path: work/scratch.txt', $messages );
	}

	public function testSymlinkAndExecutableInIgnoredPathsAllowed() {
		$old_files = array();
		$new_files = array(
			'work/symlink_file' => array(
				'mode'    => '120000', // Symbolic link
				'content' => 'target',
			),
			'work/script.sh'    => array(
				'mode'    => '100755', // Executable file
				'content' => '#!/bin/sh',
			),
		);

		$reflection = new ReflectionClass( Push_MD_Plugin::class );
		$m_symlink  = $reflection->getMethod( 'reject_symlink_file_changes' );
		$m_exec     = $reflection->getMethod( 'reject_executable_file_changes' );
		if ( PHP_VERSION_ID < 80100 ) {
			$m_symlink->setAccessible( true );
			$m_exec->setAccessible( true );
		}

		$m_symlink->invoke( null, $old_files, $new_files );
		$m_exec->invoke( null, $old_files, $new_files );

		$this->assertTrue( true );
	}
}
