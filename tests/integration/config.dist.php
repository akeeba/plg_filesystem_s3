<?php
/*
 * @package   PlgFilesystemS3
 * @copyright Copyright (c)2026 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

defined('_JEXEC') or die;

/**
 * Defaults for the end-to-end suite.
 *
 * docker/run.sh writes a config.php next to this file with the values it actually provisioned; this
 * template is what the suite falls back to when that file is absent. Keep it in step with
 * docker/env.dist — between the two, a fresh clone can run the suite without configuring anything.
 *
 * To point the suite at a site you provisioned some other way, copy this file to config.php (which is
 * git-ignored) and edit it.
 */
return [
	'site'    => [
		// The Apache front-end, as seen from the host running PHPUnit.
		'url'  => 'http://localhost:8190',
		'root' => __DIR__ . '/docker/www',
	],
	'db'      => [
		'host'   => '127.0.0.1',
		'port'   => 33319,
		'name'   => 's3fse2e',
		'user'   => 's3fse2e',
		'pass'   => 's3fse2e',
		'prefix' => 'e2e_',
	],
	'docker'  => [
		'composeBin'  => 'docker compose',
		'composeFile' => __DIR__ . '/docker/docker-compose.yml',
		'phpService'  => 'php',
	],
	'users'   => [
		'adminUsername' => 'admin',
		'adminPassword' => 'test',
		'password'      => 'test',
	],
	's3'      => [
		// MinIO as the host sees it (anonymous, read-only) and as the site sees it.
		'publicUrl'        => 'http://localhost:9190',
		'internalEndpoint' => 'http://minio:9000',
		'bucket'           => 's3fs-e2e',
		'accessKey'        => 's3fse2eaccess',
		'secretKey'        => 's3fse2esecret',
	],
	// Overwritten by run.sh with what it actually resolved and installed.
	'joomlaVersion' => '0.0.0',
	'phpVersion'    => '0.0',
];
