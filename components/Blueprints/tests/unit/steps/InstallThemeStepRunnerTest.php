<?php

namespace unit\steps;

use PHPUnitTestCase;
use WordPress\Blueprints\DataReference\DataReference;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runner\Step\InstallThemeStepRunner;
use WordPress\Blueprints\Runner\WordPressBoot\BootOptions;
use WordPress\Blueprints\Runner\WordPressBoot\WordPressBootManager;
use WordPress\Blueprints\Runtime\Runtime;
use WordPress\Blueprints\Steps\DataClass\InstallThemeStep;

use function WordPress\Filesystem\wp_join_paths;

class InstallThemeStepRunnerTest extends PHPUnitTestCase {
	/**
	 * @var string
	 */
	private $document_root;

	/**
	 * @var Runtime
	 */
	private $runtime;

	const THEME_STYLE_CSS_CONTENT = <<<'CSS'
    /*
    Theme Name: Test Theme
    Theme URI: https://example.com
    Author: Test
    Author URI: https://example.com
    Description: A test theme for InstallThemeStepRunner test
    Version: 1.0.0
    */
    body {
        font-family: sans-serif;
    }
    CSS;

	const THEME_INDEX_PHP_CONTENT = <<<'PHP'
    <?php
    /**
     * Main theme file
     * 
     * @package Test_Theme
     */

    // Simple theme initialization
    function test_theme_setup() {
        add_theme_support( 'title-tag' );
        add_theme_support( 'post-thumbnails' );
    }
    add_action( 'after_setup_theme', 'test_theme_setup' );
    PHP;

	/**
	 * @before
	 */
	public function setUp(): void {
		$this->document_root = wp_join_paths( sys_get_temp_dir(), 'test_theme_install_' . uniqid() );
		if ( ! is_dir( $this->document_root ) ) {
			mkdir( $this->document_root, 0777, true );
		}

		// Boot WordPress using WordPressBootManager
		$options = BootOptions::parse( [
			'siteUrl'      => 'https://example.com',
			'documentRoot' => $this->document_root,
		] );

		$this->runtime = WordPressBootManager::boot( $options );
	}

	/**
	 * @after
	 */
	public function tearDown(): void {
		// Clean up temp directory
		if ( is_dir( $this->document_root ) ) {
			$this->removeDirectory( $this->document_root );
		}
	}

	private function removeDirectory( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$objects = scandir( $dir );
		foreach ( $objects as $object ) {
			if ( $object == "." || $object == ".." ) {
				continue;
			}

			$path = $dir . DIRECTORY_SEPARATOR . $object;
			if ( is_dir( $path ) ) {
				$this->removeDirectory( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}

	public function testInstallThemeFromDirectoryWithActivation() {
		$this->runtime->getTargetFilesystem()->mkdir(
			'test-theme', [ 'recursive' => true ]
		);
		$this->runtime->getTargetFilesystem()->put_contents(
			'test-theme/style.css',
			self::THEME_STYLE_CSS_CONTENT
		);
		$this->runtime->getTargetFilesystem()->put_contents(
			'test-theme/index.php',
			self::THEME_INDEX_PHP_CONTENT
		);

		$step_runner = new InstallThemeStepRunner();
		$step_runner->setRuntime( $this->runtime );

		$step            = new InstallThemeStep();
		$step->themeData = DataReference::create( 'test-theme' );
		$step->activate  = true;

		$tracker = new Tracker();
		$step_runner->run( $step, $tracker );

		// Check if theme is installed
		$fs = $this->runtime->getTargetFilesystem();
		$this->assertTrue( $fs->exists( 'wp-content/themes/test-theme' ) );
		$this->assertTrue( $fs->exists( 'wp-content/themes/test-theme/style.css' ) );
		$this->assertTrue( $fs->exists( 'wp-content/themes/test-theme/index.php' ) );

		// Check if theme is activated
		$active_theme = $this->runtime->evalPhpInSubProcess(
			<<<'PHP'
            <?php
            require_once getenv('DOCROOT') . '/wp-load.php';
            echo get_option('stylesheet');
            PHP
		);

		$this->assertEquals( 'test-theme', trim( $active_theme ) );
	}

	public function testInstallThemeFromDirectoryWithoutActivation() {
		$this->runtime->getTargetFilesystem()->mkdir(
			'test-theme', [ 'recursive' => true ]
		);
		$this->runtime->getTargetFilesystem()->put_contents(
			'test-theme/style.css',
			self::THEME_STYLE_CSS_CONTENT
		);
		$this->runtime->getTargetFilesystem()->put_contents(
			'test-theme/index.php',
			self::THEME_INDEX_PHP_CONTENT
		);

		$step_runner = new InstallThemeStepRunner();
		$step_runner->setRuntime( $this->runtime );

		$step            = new InstallThemeStep();
		$step->themeData = DataReference::create( 'test-theme' );
		$step->activate  = false;

		$tracker = new Tracker();
		$step_runner->run( $step, $tracker );

		// Check if theme is installed
		$fs = $this->runtime->getTargetFilesystem();
		$this->assertTrue( $fs->exists( 'wp-content/themes/test-theme' ) );
		$this->assertTrue( $fs->exists( 'wp-content/themes/test-theme/style.css' ) );
		$this->assertTrue( $fs->exists( 'wp-content/themes/test-theme/index.php' ) );

		// Check that the default theme is still active (not our test theme)
		$active_theme = $this->runtime->evalPhpInSubProcess(
			<<<'PHP'
            <?php
            require_once getenv('DOCROOT') . '/wp-load.php';
            echo get_option('stylesheet');
            PHP
		);

		$this->assertNotEquals( 'test-theme', trim( $active_theme ) );
	}

	public function testInstallThemeFromZip() {
		$zip_file = wp_join_paths( $this->document_root, 'zipped-test-theme.zip' );
		$zip      = new \ZipArchive();
		if ( $zip->open( $zip_file, \ZipArchive::CREATE ) === true ) {
			$zip->addFromString( 'test-theme/style.css', self::THEME_STYLE_CSS_CONTENT );
			$zip->addFromString( 'test-theme/index.php', self::THEME_INDEX_PHP_CONTENT );
			$zip->close();
		}

		$step_runner = new InstallThemeStepRunner();
		$step_runner->setRuntime( $this->runtime );

		$step            = new InstallThemeStep();
		$step->themeData = DataReference::create( 'zipped-test-theme.zip' );
		$step->activate  = true;

		$tracker = new Tracker();
		$step_runner->run( $step, $tracker );

		// Check if theme is installed
		$fs = $this->runtime->getTargetFilesystem();
		$this->assertTrue( $fs->exists( 'wp-content/themes/test-theme' ) );
		$this->assertTrue( $fs->exists( 'wp-content/themes/test-theme/style.css' ) );
		$this->assertTrue( $fs->exists( 'wp-content/themes/test-theme/index.php' ) );

		// Check if theme is activated
		$active_theme = $this->runtime->evalPhpInSubProcess(
			<<<'PHP'
            <?php
            require_once getenv('DOCROOT') . '/wp-load.php';
            echo get_option('stylesheet');
            PHP
		);

		$this->assertEquals( 'test-theme', trim( $active_theme ) );
	}
}
