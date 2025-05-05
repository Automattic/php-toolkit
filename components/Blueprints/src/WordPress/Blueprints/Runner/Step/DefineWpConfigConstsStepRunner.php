<?php

namespace WordPress\Blueprints\Runner\Step;

use WordPress\Blueprints\Model\DataClass\DefineWpConfigConstsStep;

class DefineWpConfigConstsStepRunner extends BaseStepRunner {

	/**
	 * @param \WordPress\Blueprints\Model\DataClass\DefineWpConfigConstsStep $input
	 */
	function run( $input ) {
		$functions = file_get_contents( __DIR__ . '/DefineWpConfigConsts/functions.php' );

		return $this->getRuntime()->evalPhpInSubProcess(
			"$functions ?>" . '<?php
    $wp_config_path = getenv("DOCROOT") . "/wp-config.php";
    
    if (!file_exists($wp_config_path)) {
        error_log("Blueprint Error: wp-config.php file not found at " . $wp_config_path);
        exit(1);
    }
    
    if (!is_readable($wp_config_path) || !is_writable($wp_config_path)) {
        error_log("Blueprint Error: wp-config.php is not readable or writable at " . $wp_config_path);
        exit(1);
    }
    
	$consts = json_decode(getenv("CONSTS"), true);
	$wp_config = file_get_contents($wp_config_path);
	$new_wp_config = rewrite_wp_config_to_define_constants($wp_config, $consts);
	file_put_contents($wp_config_path, $new_wp_config);
',
			array(
				'CONSTS' => json_encode( $input->consts ),
			)
		);
	}

	public function getDefaultCaption( $input ) {
		return 'Defining wp-config constants';
	}
}
