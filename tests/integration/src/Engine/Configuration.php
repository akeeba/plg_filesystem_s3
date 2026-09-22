<?php
/*
 * @package   PlgFilesystemS3
 * @copyright Copyright (c)2026 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\Plugin\Filesystem\S3\IntegrationTest\Engine;

defined('_JEXEC') or die;

use RuntimeException;

/**
 * The end-to-end suite's view of the provisioned site.
 *
 * Reads tests/integration/config.php, which docker/run.sh regenerates on every run. When that file is
 * absent — a fresh checkout, or someone running PHPUnit before ever running run.sh — it falls back to
 * config.dist.php, which carries the same defaults as docker/env.dist. Every setting has a committed
 * default, so a fresh clone can run the suite without configuring anything.
 */
final class Configuration
{
	private static ?self $instance = null;

	private array $data;

	private string $sourceFile;

	private function __construct(array $data, string $sourceFile)
	{
		$this->data       = $data;
		$this->sourceFile = $sourceFile;
	}

	public static function getInstance(): self
	{
		if (self::$instance !== null)
		{
			return self::$instance;
		}

		$root      = \dirname(__DIR__, 2);
		$generated = $root . '/config.php';
		$template  = $root . '/config.dist.php';
		$file      = is_file($generated) ? $generated : $template;

		if (!is_file($file))
		{
			throw new RuntimeException(
				sprintf('Neither %s nor %s exists. Run tests/integration/docker/run.sh.', $generated, $template)
			);
		}

		$data = require $file;

		if (!is_array($data))
		{
			throw new RuntimeException(sprintf('%s did not return a configuration array.', $file));
		}

		return self::$instance = new self($data, $file);
	}

	/**
	 * Read a configuration value by dotted path, e.g. 'db.host'.
	 */
	public function get(string $path, $default = null)
	{
		$value = $this->data;

		foreach (explode('.', $path) as $segment)
		{
			if (!is_array($value) || !array_key_exists($segment, $value))
			{
				return $default;
			}

			$value = $value[$segment];
		}

		return $value;
	}

	/**
	 * The base URL of the Apache-served site, as seen from the host.
	 */
	public function getSiteUrl(): string
	{
		return rtrim((string) $this->get('site.url', 'http://localhost:8190'), '/');
	}

	/**
	 * Absolute path to the provisioned site's document root on the host (a bind mount).
	 */
	public function getSiteRoot(): string
	{
		return (string) $this->get('site.root', \dirname(__DIR__, 2) . '/docker/www');
	}

	public function getJoomlaVersion(): string
	{
		return (string) $this->get('joomlaVersion', '0.0.0');
	}

	public function getDbPrefix(): string
	{
		return (string) $this->get('db.prefix', 'e2e_');
	}

	public function getDbName(): string
	{
		return (string) $this->get('db.name', 's3fse2e');
	}

	/**
	 * The password shared by every provisioned non-admin account.
	 */
	public function getUserPassword(): string
	{
		return (string) $this->get('users.password', 'test');
	}

	/**
	 * The Super User's credentials, as [username, password].
	 *
	 * @return  array{0: string, 1: string}
	 */
	public function getAdminCredentials(): array
	{
		return [
			(string) $this->get('users.adminUsername', 'admin'),
			(string) $this->get('users.adminPassword', 'test'),
		];
	}

	/**
	 * The S3-compatible server (MinIO) and the bucket the connections point at.
	 *
	 * `publicUrl` is MinIO as seen from the HOST, where anonymous read-only access is enabled for
	 * inspection. `internalEndpoint` is MinIO as the SITE sees it, inside the compose network; it is
	 * what every connection is configured with, and so what every URL the plugin hands out points at.
	 *
	 * @return  array{publicUrl: string, internalEndpoint: string, bucket: string, accessKey: string, secretKey: string}
	 */
	public function getS3(): array
	{
		return [
			'publicUrl'        => rtrim((string) $this->get('s3.publicUrl', 'http://localhost:9190'), '/'),
			'internalEndpoint' => (string) $this->get('s3.internalEndpoint', 'http://minio:9000'),
			'bucket'           => (string) $this->get('s3.bucket', 's3fs-e2e'),
			'accessKey'        => (string) $this->get('s3.accessKey', 's3fse2eaccess'),
			'secretKey'        => (string) $this->get('s3.secretKey', 's3fse2esecret'),
		];
	}

	/**
	 * @return  array{bin: string, file: string, php: string}
	 */
	public function getDocker(): array
	{
		return [
			'bin'  => (string) $this->get('docker.composeBin', 'docker compose'),
			'file' => (string) $this->get('docker.composeFile', \dirname(__DIR__, 2) . '/docker/docker-compose.yml'),
			'php'  => (string) $this->get('docker.phpService', 'php'),
		];
	}

	/**
	 * Where the configuration was loaded from. Used in diagnostics.
	 */
	public function getSourceFile(): string
	{
		return $this->sourceFile;
	}
}
