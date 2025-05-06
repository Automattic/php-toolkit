<?php

namespace WordPress\Blueprints\Runner\WordPressBoot;

use WordPress\Blueprints\Resources\Model\DataReference;
use WordPress\Blueprints\Runtime\Runtime;

class BootOptions {
	public string $siteUrl;
	public Runtime $runtime;
	public ?DataReference $wordPressZip = null;
	public ?DataReference $sqliteIntegrationPluginZip = null;

	public static function parse(array $options): self {
		$required = ['siteUrl', 'runtime'];
		foreach ($required as $key) {
			if (!array_key_exists($key, $options)) {
				throw new \InvalidArgumentException("Missing required option: {$key}");
			}
		}
		$instance = new self();
		$instance->siteUrl = $options['siteUrl'];
		$instance->runtime = $options['runtime'];

		if (!isset($options['wordPressZip'])) {
			$instance->wordPressZip = DataReference::create(self::resolveWordPressRelease('latest')['releaseUrl']);
		} else if(is_string($options['wordPressZip'])) {
			$instance->wordPressZip = DataReference::create($options['wordPressZip']);
		} else {
			throw new \InvalidArgumentException('The wordPressZip option must be a DataReference but was ' . gettype($options['wordPressZip']));
		}

		if(!isset($options['sqliteIntegrationPluginZip'])) {
			$instance->sqliteIntegrationPluginZip = DataReference::create('https://downloads.wordpress.org/plugin/sqlite-database-integration.zip');
		} else if(is_string($options['sqliteIntegrationPluginZip'])) {
			$instance->sqliteIntegrationPluginZip = DataReference::create($options['sqliteIntegrationPluginZip']);
		} else {
			throw new \InvalidArgumentException('The sqliteIntegrationPluginZip option must be a DataReference but was ' . gettype($options['sqliteIntegrationPluginZip']));
		}
		
		return $instance;
	}

	/**
	 * Resolves a specific WordPress release URL and version string based on
	 * a version query string such as "latest", "beta", or "6.6".
	 *
	 * Examples:
	 * ```php
	 * $result = resolveWordPressRelease('latest');
	 * // becomes ['releaseUrl' => 'https://wordpress.org/wordpress-6.6.2.zip', 'version' => '6.6.2']
	 *
	 * $result = resolveWordPressRelease('beta');
	 * // becomes ['releaseUrl' => 'https://wordpress.org/wordpress-6.6.2-RC1.zip', 'version' => '6.6.2-RC1']
	 *
	 * $result = resolveWordPressRelease('6.6');
	 * // becomes ['releaseUrl' => 'https://wordpress.org/wordpress-6.6.2.zip', 'version' => '6.6.2']
	 * ```
	 *
	 * @param string $versionQuery The WordPress version query string to resolve.
	 * @return array The resolved WordPress release URL and version string.
	 */
	static public function resolveWordPressRelease($versionQuery = 'latest')
	{
		if (
			str_starts_with($versionQuery, 'https://') ||
			str_starts_with($versionQuery, 'http://')
		) {
			$sha1 = substr(sha1($versionQuery), 0, 8);
			return [
				'releaseUrl' => $versionQuery,
				'version' => 'custom-' . $sha1,
				'source' => 'inferred',
			];
		} else if ($versionQuery === 'trunk' || $versionQuery === 'nightly') {
			return [
				'releaseUrl' => 'https://wordpress.org/nightly-builds/wordpress-latest.zip',
				'version' => 'nightly-' . date('Y-m-d'),
				'source' => 'inferred',
			];
		}

		$response = file_get_contents('https://api.wordpress.org/core/version-check/1.7/?channel=beta');
		$latestVersions = json_decode($response, true);

		$latestVersions = array_filter($latestVersions['offers'], function($v) {
			return $v['response'] === 'autoupdate';
		});

		foreach ($latestVersions as $apiVersion) {
			if ($versionQuery === 'beta' && strpos($apiVersion['version'], 'beta') !== false) {
				return [
					'releaseUrl' => $apiVersion['download'],
					'version' => $apiVersion['version'],
					'source' => 'api',
				];
			} else if (
				$versionQuery === 'latest' &&
				strpos($apiVersion['version'], 'beta') === false
			) {
				// The first non-beta item in the list is the latest version.
				return [
					'releaseUrl' => $apiVersion['download'],
					'version' => $apiVersion['version'],
					'source' => 'api',
				];
			} else if (
				substr($apiVersion['version'], 0, strlen($versionQuery)) ===
				$versionQuery
			) {
				return [
					'releaseUrl' => $apiVersion['download'],
					'version' => $apiVersion['version'],
					'source' => 'api',
				];
			}
		}

		return [
			'releaseUrl' => "https://wordpress.org/wordpress-{$versionQuery}.zip",
			'version' => $versionQuery,
			'source' => 'inferred',
		];
	}
}
