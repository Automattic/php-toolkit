<?php

namespace unit\steps;

use PHPUnitTestCase;
use WordPress\Blueprints\Model\DataClass\DefineWpConfigConstsStep;
use WordPress\Blueprints\Model\DataClass\InstallPluginStep;
use WordPress\Blueprints\Model\DataClass\MkdirStep;
use WordPress\Blueprints\Model\DataClass\RunPHPStep;
use WordPress\Blueprints\Model\DataClass\SetSiteOptionsStep;
use WordPress\Blueprints\Model\DataClass\WordPressPluginDirectoryReference;
use WordPress\Blueprints\Model\DataClass\WordPressThemeDirectoryReference;
use WordPress\Blueprints\Model\DataClass\WriteFileStep;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runner\Step\DefineWpConfigConstsStepRunner;
use WordPress\Blueprints\Runner\Step\InstallPluginStepRunner;
use WordPress\Blueprints\Runner\Step\MkdirStepRunner;
use WordPress\Blueprints\Runner\Step\RunPHPStepRunner;
use WordPress\Blueprints\Runner\Step\SetSiteOptionsStepRunner;
use WordPress\Blueprints\Runner\Step\WriteFileStepRunner;
use WordPress\Blueprints\Runner\WordPressBoot\BootOptions;
use WordPress\Blueprints\Runner\WordPressBoot\WordPressBootManager;

use function WordPress\Filesystem\wp_join_paths;

class SetupWooWithRoughRunnersTest extends PHPUnitTestCase {

    public function testSetupWoo() {
        $document_root = wp_join_paths(sys_get_temp_dir(), 'test_set_options_' . uniqid());
		echo $document_root;
        if (!is_dir($document_root)) {
            mkdir($document_root, 0777, true);
        }
		
        // Boot WordPress using WordPressBootManager
        $options = BootOptions::parse([
            'siteUrl'     => 'https://example.com',
            'documentRoot' => $document_root,
        ]);

        $runtime = WordPressBootManager::boot($options);

		$steps = [];
		$step = new InstallPluginStep();
		$step->pluginData = (new WordPressPluginDirectoryReference())->setSlug('woocommerce');
		$step->activate = true;
		$steps[] = [new InstallPluginStepRunner(), $step];

		$step = new InstallPluginStep();
		$step->pluginData = (new WordPressPluginDirectoryReference())->setSlug('activitypub');
		$step->activate = true;
		$steps[] = [new InstallPluginStepRunner(), $step];

		$step = new InstallPluginStep();
		$step->pluginData = (new WordPressPluginDirectoryReference())->setSlug('friends');
		$step->activate = true;
		$steps[] = [new InstallPluginStepRunner(), $step];

		$step = new RunPHPStep();
		$step->code = "<?php require_once getenv('DOCROOT') . '/wp-load.php';\nif ( class_exists('Friends\\Import')) {\nFriends\\Import::opml(\"<?xml version=\\\"1.0\\\" encoding=\\\"utf-8\\\"?><opml version=\\\"2.0\\\">\n<head>\n<title>Subscriptions</title>\n</head>\n<body>\n<outline text=\\\"Subscriptions\\\" title=\\\"Subscriptions\\\">\n<outline type=\\\"rss\\\" text=\\\"Alex Kirk\\\" title=\\\"Alex Kirk\\\" xmlUrl=\\\"https://alex.kirk.at/feed/\\\" htmlUrl=\\\"https://alex.kirk.at/feed/\\\" />\n<outline type=\\\"rss\\\" text=\\\"Adam Zieliński\\\" title=\\\"Adam Zieli\u0144ski\\\" xmlUrl=\\\"https://adamadam.blog/feed/\\\" htmlUrl=\\\"https://adamadam.blog/feed/\\\" />\n</outline>\n</body>\n</opml>\");\n}";
		$steps[] = [new RunPHPStepRunner(), $step];

		$step = new DefineWpConfigConstsStep();
		$step->setConsts([
			'WP_HOME' => 'http://127.0.0.1:5329',
			'WP_SITEURL' => 'http://127.0.0.1:5329',
			'PLAYGROUND_AUTO_LOGIN_AS_USER' => 'admin',
		]);
		$steps[] = [new DefineWpConfigConstsStepRunner(), $step];

		$step = new MkdirStep();
		$step->path = 'wp-content/mu-plugins';
		$steps[] = [new MkdirStepRunner(), $step];

		$step = new WriteFileStep();
		$step->setPath('wp-content/mu-plugins/autologin.php');
		$step->setData(file_get_contents(__DIR__ . '/plugins/autologin.php'));
		$steps[] = [new WriteFileStepRunner(), $step];

		$step = new SetSiteOptionsStep();
		$step->setOptions([
			'woocommerce_onboarding_profile' => [
				'skipped' => true,
			],
		]);
		$steps[] = [new SetSiteOptionsStepRunner(), $step];

		$tracker = new Tracker();
		foreach ($steps as list($step_runner, $step)) {
			$step_runner->setRuntime($runtime);
			$step_runner->run($step, $tracker);
		}
    }

} 