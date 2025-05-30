<?php

namespace WordPress\Blueprints\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPress\Blueprints\DataReference\AbsoluteLocalPath;
use WordPress\Blueprints\DataReference\ExecutionContextPath;
use WordPress\Blueprints\DataReference\WordPressOrgPlugin;
use WordPress\Blueprints\DataReference\WordPressOrgTheme;
use WordPress\Blueprints\Exception\BlueprintExecutionException;
use WordPress\Blueprints\Runner;
use WordPress\Blueprints\RunnerConfiguration;
use WordPress\Filesystem\LocalFilesystem;

use function WordPress\Filesystem\wp_join_unix_paths;
use function WordPress\Filesystem\wp_unix_sys_get_temp_dir;

class RunnerDirectoryValidationTest extends TestCase {
	/**
	 * @var string
	 */
	private $execution_context_path;

	/**
	 * @var Runner
	 */
	private $runner;

	/**
	 * @before
	 */
	public function setUp(): void {
		$tmp_dir = wp_unix_sys_get_temp_dir();
		$this->execution_context_path = wp_join_unix_paths( $tmp_dir, 'test_' . uniqid() );
		
		// Create execution context directory
		mkdir( $this->execution_context_path, 0777, true );
		
		// Create a minimal blueprint.json
		file_put_contents(
			wp_join_unix_paths( $this->execution_context_path, 'blueprint.json' ),
			json_encode( [ "version" => 2 ] )
		);

		$config = ( new RunnerConfiguration() )
			->setExecutionMode( 'create-new-site' )
			->setTargetSiteRoot( wp_join_unix_paths( $tmp_dir, 'test_site_' . uniqid() ) )
			->setBlueprint( new AbsoluteLocalPath( wp_join_unix_paths( $this->execution_context_path, 'blueprint.json' ) ) )
			->setDatabaseEngine( 'sqlite' )
			->setTargetSiteUrl( 'http://127.0.0.1:2456' );

		$this->runner = new Runner( $config );
	}

	/**
	 * @after
	 */
	public function tearDown(): void {
		// Clean up temp directory
		if ( is_dir( $this->execution_context_path ) ) {
			$this->removeDirectory( $this->execution_context_path );
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

	public function testPluginMustBeInPluginsDirectory() {
		$this->expectException( BlueprintExecutionException::class );
		$this->expectExceptionMessage( 'Plugin resources must be located in the wp-content/plugins directory' );

		// Create a plugin file in the wrong location
		file_put_contents(
			wp_join_unix_paths( $this->execution_context_path, 'invalid-plugin.php' ),
			'<?php /* Plugin Name: Invalid Plugin */'
		);

		// Use reflection to access the private createPluginDataReference method
		$reflection = new \ReflectionClass( $this->runner );
		$method = $reflection->getMethod( 'createPluginDataReference' );
		$method->setAccessible( true );

		// This should throw an exception
		$method->invoke( $this->runner, './invalid-plugin.php' );
	}

	public function testPluginInPluginsDirectoryIsValid() {
		// Create wp-content/plugins directory
		mkdir( wp_join_unix_paths( $this->execution_context_path, 'wp-content', 'plugins' ), 0777, true );
		
		// Create a plugin file in the correct location
		file_put_contents(
			wp_join_unix_paths( $this->execution_context_path, 'wp-content', 'plugins', 'valid-plugin.php' ),
			'<?php /* Plugin Name: Valid Plugin */'
		);

		// Use reflection to access the private createPluginDataReference method
		$reflection = new \ReflectionClass( $this->runner );
		$method = $reflection->getMethod( 'createPluginDataReference' );
		$method->setAccessible( true );

		// This should not throw an exception
		$reference = $method->invoke( $this->runner, './wp-content/plugins/valid-plugin.php' );
		$this->assertInstanceOf( ExecutionContextPath::class, $reference );
	}

	public function testThemeMustBeInThemesDirectory() {
		$this->expectException( BlueprintExecutionException::class );
		$this->expectExceptionMessage( 'Theme resources must be located in the wp-content/themes directory' );

		// Create a theme file in the wrong location
		file_put_contents(
			wp_join_unix_paths( $this->execution_context_path, 'invalid-theme.zip' ),
			'fake theme content'
		);

		// Use reflection to access the private createThemeDataReference method
		$reflection = new \ReflectionClass( $this->runner );
		$method = $reflection->getMethod( 'createThemeDataReference' );
		$method->setAccessible( true );

		// This should throw an exception
		$method->invoke( $this->runner, './invalid-theme.zip' );
	}

	public function testThemeInThemesDirectoryIsValid() {
		// Create wp-content/themes directory
		mkdir( wp_join_unix_paths( $this->execution_context_path, 'wp-content', 'themes' ), 0777, true );
		
		// Create a theme file in the correct location
		file_put_contents(
			wp_join_unix_paths( $this->execution_context_path, 'wp-content', 'themes', 'valid-theme.zip' ),
			'fake theme content'
		);

		// Use reflection to access the private createThemeDataReference method
		$reflection = new \ReflectionClass( $this->runner );
		$method = $reflection->getMethod( 'createThemeDataReference' );
		$method->setAccessible( true );

		// This should not throw an exception
		$reference = $method->invoke( $this->runner, './wp-content/themes/valid-theme.zip' );
		$this->assertInstanceOf( ExecutionContextPath::class, $reference );
	}

	/**
	 * @dataProvider invalidTranslationFileProvider
	 */
	public function testTranslationFilesMustHaveCorrectExtensions($filename) {
		$this->expectException( BlueprintExecutionException::class );
		$this->expectExceptionMessage( 'Translation files must have .po, .mo, or .zip extensions' );

		// Create wp-content/languages directory
		mkdir( wp_join_unix_paths( $this->execution_context_path, 'wp-content', 'languages' ), 0777, true );
		
		// Create a translation file with wrong extension
		file_put_contents(
			wp_join_unix_paths( $this->execution_context_path, 'wp-content', 'languages', $filename ),
			'invalid translation file'
		);

		// Use reflection to access the private createGeneralDataReference method
		$reflection = new \ReflectionClass( $this->runner );
		$method = $reflection->getMethod( 'createGeneralDataReference' );
		$method->setAccessible( true );

		// This should throw an exception
		$method->invoke( $this->runner, './wp-content/languages/' . $filename );
	}

	public function invalidTranslationFileProvider() {
		return [
			['invalid.txt'],
			['invalid.doc'],
			['invalid'],
		];
	}

	/**
	 * @dataProvider validTranslationFileProvider
	 */
	public function testValidTranslationFilesAreAccepted($filename) {
		// Create wp-content/languages directory
		mkdir( wp_join_unix_paths( $this->execution_context_path, 'wp-content', 'languages' ), 0777, true );
		
		// Create a translation file with valid extension
		file_put_contents(
			wp_join_unix_paths( $this->execution_context_path, 'wp-content', 'languages', $filename ),
			'valid translation file'
		);

		// Use reflection to access the private createGeneralDataReference method
		$reflection = new \ReflectionClass( $this->runner );
		$method = $reflection->getMethod( 'createGeneralDataReference' );
		$method->setAccessible( true );

		// This should not throw an exception
		$reference = $method->invoke( $this->runner, './wp-content/languages/' . $filename );
		$this->assertInstanceOf( ExecutionContextPath::class, $reference );
	}

	public function validTranslationFileProvider() {
		return [
			['valid.po'],
			['valid.mo'],
			['valid.zip'],
		];
	}

	/**
	 * @dataProvider invalidFontFileProvider
	 */
	public function testFontFilesMustHaveCorrectExtensions($filename) {
		$this->expectException( BlueprintExecutionException::class );
		$this->expectExceptionMessage( 'Font files must have .woff2, .woff, .ttf, or .otf extensions' );

		// Create wp-content/uploads/fonts directory
		mkdir( wp_join_unix_paths( $this->execution_context_path, 'wp-content', 'uploads', 'fonts' ), 0777, true );
		
		// Create a font file with wrong extension
		file_put_contents(
			wp_join_unix_paths( $this->execution_context_path, 'wp-content', 'uploads', 'fonts', $filename ),
			'invalid font file'
		);

		// Use reflection to access the private createGeneralDataReference method
		$reflection = new \ReflectionClass( $this->runner );
		$method = $reflection->getMethod( 'createGeneralDataReference' );
		$method->setAccessible( true );

		// This should throw an exception
		$method->invoke( $this->runner, './wp-content/uploads/fonts/' . $filename );
	}

	public function invalidFontFileProvider() {
		return [
			['invalid.txt'],
			['invalid.eot'],
			['invalid'],
		];
	}

	/**
	 * @dataProvider validFontFileProvider
	 */
	public function testValidFontFilesAreAccepted($filename) {
		// Create wp-content/uploads/fonts directory
		mkdir( wp_join_unix_paths( $this->execution_context_path, 'wp-content', 'uploads', 'fonts' ), 0777, true );
		
		// Create a font file with valid extension
		file_put_contents(
			wp_join_unix_paths( $this->execution_context_path, 'wp-content', 'uploads', 'fonts', $filename ),
			'valid font file'
		);

		// Use reflection to access the private createDataReference method
		$reflection = new \ReflectionClass( $this->runner );
		$method = $reflection->getMethod( 'createDataReference' );
		$method->setAccessible( true );

		// This should not throw an exception
		$reference = $method->invoke( $this->runner, './wp-content/uploads/fonts/' . $filename, [ ExecutionContextPath::class ] );
		$this->assertInstanceOf( ExecutionContextPath::class, $reference );
	}

	public function validFontFileProvider() {
		return [
			['valid.woff2'],
			['valid.woff'],
			['valid.ttf'],
			['valid.otf'],
		];
	}

	public function testContentFilesSqlAndXmlAreAccepted() {
		// Create wp-content/content directory
		mkdir( wp_join_unix_paths( $this->execution_context_path, 'wp-content', 'content' ), 0777, true );
		
		$validFiles = [ 'dump.sql', 'export.xml', 'content.wxr' ];
		
		foreach ( $validFiles as $filename ) {
			// Create a content file
			file_put_contents(
				wp_join_unix_paths( $this->execution_context_path, 'wp-content', 'content', $filename ),
				'valid content file'
			);

			// Use reflection to access the private createDataReference method
			$reflection = new \ReflectionClass( $this->runner );
			$method = $reflection->getMethod( 'createDataReference' );
			$method->setAccessible( true );

			// This should not throw an exception
			$reference = $method->invoke( $this->runner, './wp-content/content/' . $filename, [ ExecutionContextPath::class ] );
			$this->assertInstanceOf( ExecutionContextPath::class, $reference );
		}
	}

	public function testPostContentFilesMustHaveCorrectExtensions() {
		$this->expectException( BlueprintExecutionException::class );
		$this->expectExceptionMessage( 'Post content files must have .html, .md, .txt extensions or be named post-type.json' );

		// Create wp-content/content/posts/articles directory
		mkdir( wp_join_unix_paths( $this->execution_context_path, 'wp-content', 'content', 'posts', 'articles' ), 0777, true );
		
		// Create a post content file with wrong extension
		file_put_contents(
			wp_join_unix_paths( $this->execution_context_path, 'wp-content', 'content', 'posts', 'articles', 'invalid.pdf' ),
			'invalid post content file'
		);

		// Use reflection to access the private createDataReference method
		$reflection = new \ReflectionClass( $this->runner );
		$method = $reflection->getMethod( 'createDataReference' );
		$method->setAccessible( true );

		// This should throw an exception
		$method->invoke( $this->runner, './wp-content/content/posts/articles/invalid.pdf', [ ExecutionContextPath::class ] );
	}

	public function testValidPostContentFilesAreAccepted() {
		// Create wp-content/content/posts/articles directory
		mkdir( wp_join_unix_paths( $this->execution_context_path, 'wp-content', 'content', 'posts', 'articles' ), 0777, true );
		
		$validFiles = [ 'post.html', 'article.md', 'content.txt', 'post-type.json' ];
		
		foreach ( $validFiles as $filename ) {
			// Create a post content file
			file_put_contents(
				wp_join_unix_paths( $this->execution_context_path, 'wp-content', 'content', 'posts', 'articles', $filename ),
				'valid post content file'
			);

			// Use reflection to access the private createDataReference method
			$reflection = new \ReflectionClass( $this->runner );
			$method = $reflection->getMethod( 'createDataReference' );
			$method->setAccessible( true );

			// This should not throw an exception
			$reference = $method->invoke( $this->runner, './wp-content/content/posts/articles/' . $filename, [ ExecutionContextPath::class ] );
			$this->assertInstanceOf( ExecutionContextPath::class, $reference );
		}
	}

	public function testMediaFilesInUploadsAreAccepted() {
		// Create wp-content/uploads directory
		mkdir( wp_join_unix_paths( $this->execution_context_path, 'wp-content', 'uploads', '2024', '01' ), 0777, true );
		
		// Create various media files
		$mediaFiles = [ 'image.jpg', 'video.mp4', 'audio.mp3', 'document.pdf' ];
		
		foreach ( $mediaFiles as $filename ) {
			file_put_contents(
				wp_join_unix_paths( $this->execution_context_path, 'wp-content', 'uploads', '2024', '01', $filename ),
				'media file content'
			);

			// Use reflection to access the private createDataReference method
			$reflection = new \ReflectionClass( $this->runner );
			$method = $reflection->getMethod( 'createDataReference' );
			$method->setAccessible( true );

			// This should not throw an exception
			$reference = $method->invoke( $this->runner, './wp-content/uploads/2024/01/' . $filename, [ ExecutionContextPath::class ] );
			$this->assertInstanceOf( ExecutionContextPath::class, $reference );
		}
	}

	public function testRootLevelFilesAreAccepted() {
		// Create a root level file (like blueprint.json)
		file_put_contents(
			wp_join_unix_paths( $this->execution_context_path, 'config.json' ),
			'{"config": "value"}'
		);

		// Use reflection to access the private createDataReference method
		$reflection = new \ReflectionClass( $this->runner );
		$method = $reflection->getMethod( 'createDataReference' );
		$method->setAccessible( true );

		// This should not throw an exception
		$reference = $method->invoke( $this->runner, './config.json', [ ExecutionContextPath::class ] );
		$this->assertInstanceOf( ExecutionContextPath::class, $reference );
	}
} 