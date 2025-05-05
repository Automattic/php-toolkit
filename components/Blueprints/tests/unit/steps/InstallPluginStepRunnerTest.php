<?php

namespace unit\steps;

use PHPUnitTestCase;
use WordPress\Blueprints\Model\DataClass\InstallPluginStep;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Resources\Model\DataReference;
use WordPress\Blueprints\Runner\Step\InstallPluginStepRunner;
use WordPress\Blueprints\Runner\WordPressBoot\BootOptions;
use WordPress\Blueprints\Runner\WordPressBoot\WordPressBootManager;
use WordPress\Blueprints\Runtime\Runtime;

use function WordPress\Filesystem\wp_join_paths;

class InstallPluginStepRunnerTest extends PHPUnitTestCase {
    /**
     * @var string
     */
    private $document_root;

    /**
     * @var Runtime
     */
    private $runtime;

    /**
     * @before
     */
    public function setUp(): void {
        $this->document_root = wp_join_paths(sys_get_temp_dir(), 'test_plugin_install_' . uniqid());
        if (!is_dir($this->document_root)) {
            mkdir($this->document_root, 0777, true);
        }

        // Create a test plugin zip file
        $this->createTestPlugin();

        // Boot WordPress using WordPressBootManager
        $options = BootOptions::parse([
            'siteUrl'     => 'https://example.com',
            'documentRoot' => $this->document_root,
        ]);

        $this->runtime = WordPressBootManager::boot($options);
    }

    /**
     * @after
     */
    public function tearDown(): void {
        // Clean up temp directory
        if (is_dir($this->document_root)) {
            $this->removeDirectory($this->document_root);
        }
    }

    private function removeDirectory($dir) {
        if (!is_dir($dir)) {
            return;
        }
        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object == "." || $object == "..") continue;
            
            $path = $dir . DIRECTORY_SEPARATOR . $object;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function createTestPlugin() {
        // Create a simple test plugin zip file in the document root
        $plugin_dir = wp_join_paths($this->document_root, 'test-plugin');
        if (!is_dir($plugin_dir)) {
            mkdir($plugin_dir, 0777, true);
        }

        // Create a basic plugin file
        $plugin_file_content = <<<'PHP'
        <?php
        /**
         * Plugin Name: Test Plugin
         * Description: A test plugin for InstallPluginStepRunner test
         * Version: 1.0.0
         * Author: Test
         */
        
        // Simple plugin that does nothing
        function test_plugin_init() {
            // This function is just for testing
        }
        add_action('init', 'test_plugin_init');
        PHP;

		file_put_contents(wp_join_paths($plugin_dir, 'test-plugin.php'), $plugin_file_content);
    }

    public function testInstallPluginWithActivation() {
        $step_runner = new InstallPluginStepRunner();
        $step_runner->setRuntime($this->runtime);

        $step = new InstallPluginStep();
        $step->pluginData = DataReference::create('test-plugin/test-plugin.php');
        $step->activate = true;

        $tracker = new Tracker();
        $step_runner->run($step, $tracker);

        // Check if plugin is installed
		$fs = $this->runtime->getTargetFilesystem();
        $this->assertTrue($fs->exists('wp-content/plugins/test-plugin'));
        $this->assertTrue($fs->exists('wp-content/plugins/test-plugin/test-plugin.php'));

        // Check if plugin is activated
        $active_plugins = $this->runtime->evalPhpInSubProcess(
            <<<'PHP'
            <?php
            require_once getenv('DOCROOT') . '/wp-load.php';
            echo json_encode(get_option('active_plugins'));
            PHP
        );

        $active_plugins = json_decode($active_plugins, true);
        $this->assertContains('test-plugin/test-plugin.php', $active_plugins);
    }

    public function testInstallPluginWithoutActivation() {
        $step_runner = new InstallPluginStepRunner();
        $step_runner->setRuntime($this->runtime);

        $step = new InstallPluginStep();
        $step->pluginData = DataReference::create('test-plugin/test-plugin.php');
        $step->activate = false;

        $tracker = new Tracker();
        $step_runner->run($step, $tracker);

        // Check if plugin is installed
		$fs = $this->runtime->getTargetFilesystem();
        $this->assertTrue($fs->exists('wp-content/plugins/test-plugin'));
        $this->assertTrue($fs->exists('wp-content/plugins/test-plugin/test-plugin.php'));
		$inactive_plugins = $this->runtime->evalPhpInSubProcess(
			<<<'PHP'
			<?php
			require_once getenv('DOCROOT') . '/wp-load.php';

			// Get all installed plugins
			$all_plugins = get_plugins();
			// Get active plugins
			$active_plugins = get_option('active_plugins');
			// Filter to get only inactive plugins
			$inactive_plugins = array_diff(array_keys($all_plugins), $active_plugins);
			echo json_encode($inactive_plugins);
			PHP
		);
		$inactive_plugins = json_decode($inactive_plugins, true);
		$this->assertContains('test-plugin/test-plugin.php', $inactive_plugins);

        // Check if plugin is activated
        $active_plugins = $this->runtime->evalPhpInSubProcess(
            <<<'PHP'
            <?php
            require_once getenv('DOCROOT') . '/wp-load.php';
            echo json_encode(get_option('active_plugins'));
            PHP
        );

        $active_plugins = json_decode($active_plugins, true);
        $this->assertNotContains('test-plugin/test-plugin.php', $active_plugins);
    }

} 