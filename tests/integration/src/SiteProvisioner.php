<?php
/*
 * @package   PlgFilesystemS3
 * @copyright Copyright (c)2026 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\Plugin\Filesystem\S3\IntegrationTest;

defined('_JEXEC') or die;

use Akeeba\Plugin\Filesystem\S3\IntegrationTest\Engine\Configuration;
use Akeeba\Plugin\Filesystem\S3\IntegrationTest\Engine\ContainerCli;
use Akeeba\Plugin\Filesystem\S3\IntegrationTest\Engine\Database;
use RuntimeException;

/**
 * Puts the site and the bucket into the known state every test starts from.
 *
 * Fixtures are the one place this suite writes behind the application's back: plugin parameters and a
 * restricted user straight into the database, seed objects straight into the bucket with `mc`. The
 * behaviour under test is then always driven through the Media Manager over HTTP.
 *
 * Idempotent: run it again (php tests/integration/provision.php) to put everything back.
 */
final class SiteProvisioner
{
	/**
	 * Adapter ids as the Media Manager addresses them: `s3-<connection label>`.
	 */
	public const ADAPTER_V2       = 's3-v2path';
	public const ADAPTER_V4       = 's3-v4path';
	public const ADAPTER_NESTED   = 's3-nested';
	public const ADAPTER_CDN      = 's3-cdn';
	public const ADAPTER_CACHED   = 's3-cached';
	public const ADAPTER_BAD      = 's3-badsecret';
	public const ADAPTER_NOBUCKET = 's3-nobucket';

	/**
	 * The `nested` connection's Directory option.
	 */
	public const NESTED_DIRECTORY = 'nested/root';

	/**
	 * The restricted back-end account: may log in and open the Media Manager, may not create, edit or
	 * delete anything in it.
	 */
	public const VIEWER_USERNAME = 'viewer';

	private const VIEWER_GROUP = 'E2E Media Viewer';

	/**
	 * A 64×48 PNG (red, with a blue rectangle).
	 */
	public const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAEAAAAAwCAIAAAAuKetIAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAXUlEQVRo3u3YsQ3AIAwAQUAMwzAUjErhITJWNqBASmHlvnVhndy5PmOUzLWSPAAAAACAfwP6eTxXfLc79nQBAAAAAAAAAAAAAAAAAIDbqvc6AAAAAAAAAAAAAEDWXq0yBZHd7OFLAAAAAElFTkSuQmCC';

	private static ?self $instance = null;

	private Configuration $config;

	public function __construct(?Configuration $config = null)
	{
		$this->config = $config ?? Configuration::getInstance();
	}

	public static function getInstance(): self
	{
		return self::$instance ??= new self();
	}

	/**
	 * The objects seeded into the bucket, key => content. Folders are deliberately NOT given placeholder
	 * objects: this is what a bucket filled by the AWS CLI, CyberDuck or `mc` looks like.
	 *
	 * @return  array<string, string>
	 */
	public function seedObjects(): array
	{
		return [
			'fixtures/hello.txt'            => "Hello, S3!\n",
			'fixtures/pixel.png'            => base64_decode(self::PNG_BASE64),
			'fixtures/docs/readme.txt'      => "Read me.\n",
			'nested/outside.txt'            => "Outside the nested connection's directory.\n",
			'nested/root/inside.txt'        => "Inside the nested connection's directory.\n",
			'nested/root/sub/deep.txt'      => "Deeper inside.\n",
		];
	}

	/**
	 * @return  array{connections: int, objects: int, joomla: string, php: string}
	 */
	public function provision(): array
	{
		$this->configurePlugin();
		$this->provisionViewer();
		$this->seedBucket();
		$this->clearPluginCaches();

		return [
			'connections' => \count($this->connections()),
			'objects'     => \count($this->seedObjects()),
			'joomla'      => $this->config->getJoomlaVersion(),
			'php'         => (string) $this->config->get('phpVersion', '?'),
		];
	}

	/**
	 * The plugin's parameters as provisioned, exactly as stored in #__extensions.params.
	 */
	public function provisionedParams(): string
	{
		return json_encode(
			[
				'connections'        => (object) $this->connections(),
				'preview'            => 'always',
				'previewExtensions'  => 'png,gif,jpg,jpeg,bmp,webp,pdf,svg',
				'lambdaResize'       => '0',
				'cache_thumbnails'   => '0',
				'max_thumbnail_time' => '30',
				'resizedDimension'   => '200',
			],
			JSON_UNESCAPED_SLASHES
		);
	}

	public function readPluginParams(): string
	{
		return (string) (new Database($this->config))->value(
			"SELECT `params` FROM `#__extensions` WHERE `type` = 'plugin' AND `folder` = 'filesystem' AND `element` = 's3'"
		);
	}

	public function writePluginParams(string $params): void
	{
		(new Database($this->config))->query(
			"UPDATE `#__extensions` SET `params` = :params WHERE `type` = 'plugin' AND `folder` = 'filesystem' AND `element` = 's3'",
			['params' => $params]
		);
	}

	/**
	 * Delete the plugin's S3 response cache and local thumbnails from the site.
	 *
	 * The web root is a bind mount owned by the host user (Dockerfile.php remaps www-data), so the host
	 * can simply delete them.
	 */
	public function clearPluginCaches(): void
	{
		$root = $this->config->getSiteRoot();

		foreach (['/administrator/cache/plg_filesystem_s3', '/cache/plg_filesystem_s3', '/media/plg_filesystem_s3'] as $dir)
		{
			$this->removeTree($root . $dir);
		}
	}

	/**
	 * One connection per S3 flavour under test, keyed as Joomla's subform saves them.
	 *
	 * @return  array<string, array<string, string>>
	 */
	private function connections(): array
	{
		$s3 = $this->config->getS3();

		$base = [
			'type'              => 'custom',
			'customendpoint'    => $s3['internalEndpoint'],
			'accesskey'         => $s3['accessKey'],
			'secretkey'         => $s3['secretKey'],
			'cdn_url'           => '',
			'acl'               => 'public-read',
			'dualstack'         => '0',
			'bucket'            => $s3['bucket'],
			'signature'         => 'v2',
			'region'            => 'us-east-1',
			'custom_region'     => '',
			'pathaccess'        => 'path',
			'directory'         => '',
			'storage_class'     => 'STANDARD',
			'useHTTPDateHeader' => '0',
			'caching'           => '0',
			'cache_time'        => '300',
		];

		$connections = [
			// The workhorse: path-style, v2 signatures.
			['label' => 'v2path'],
			// The same bucket with v4 signatures — the form's default.
			['label' => 'v4path', 'signature' => 'v4'],
			// A connection rooted in a sub-directory of the bucket.
			['label' => 'nested', 'directory' => self::NESTED_DIRECTORY],
			// A "CDN" in front of the bucket. MinIO serves the public bucket itself, so the CDN URL is
			// simply the bucket's anonymous URL — which the site can reach, unlike the plugin's own
			// https:// S3 URLs (known-issues.md).
			['label' => 'cdn', 'type' => 'customcdn', 'cdn_url' => $s3['internalEndpoint'] . '/' . $s3['bucket']],
			// The S3 response cache on.
			['label' => 'cached', 'caching' => '1', 'cache_time' => '300'],
			// Wrong credentials: must fail loudly, and only for itself.
			['label' => 'badsecret', 'secretkey' => 'this-is-not-the-secret'],
			// No bucket: the adapter refuses to construct, and the plugin drops the connection.
			['label' => 'nobucket', 'bucket' => ''],
		];

		$result = [];

		foreach ($connections as $i => $connection)
		{
			$result['connections' . $i] = array_merge($base, $connection);
		}

		return $result;
	}

	private function configurePlugin(): void
	{
		$db = new Database($this->config);

		$db->query(
			"UPDATE `#__extensions` SET `enabled` = 1, `params` = :params WHERE `type` = 'plugin' AND `folder` = 'filesystem' AND `element` = 's3'",
			['params' => $this->provisionedParams()]
		);

		if ((int) $db->value("SELECT `enabled` FROM `#__extensions` WHERE `type` = 'plugin' AND `folder` = 'filesystem' AND `element` = 's3'") !== 1)
		{
			throw new RuntimeException('plg_filesystem_s3 is not installed; cannot configure it.');
		}
	}

	/**
	 * A back-end user who may open the Media Manager but not change anything in it.
	 *
	 * Their group is a child of Registered, granted only core.login.admin (root asset) and core.manage
	 * (com_media asset). Everything else — core.create, core.edit, core.delete — is simply not granted.
	 */
	private function provisionViewer(): void
	{
		$db      = new Database($this->config);
		$groupId = (int) $db->value('SELECT `id` FROM `#__usergroups` WHERE `title` = :t', ['t' => self::VIEWER_GROUP]);

		if ($groupId === 0)
		{
			// Insert as the last child of Registered (id 2), keeping the nested set valid: Joomla resolves
			// inherited permissions through lft/rgt.
			$rgt = (int) $db->value('SELECT `rgt` FROM `#__usergroups` WHERE `id` = 2');

			$db->query('UPDATE `#__usergroups` SET `rgt` = `rgt` + 2 WHERE `rgt` >= :r', ['r' => $rgt]);
			$db->query('UPDATE `#__usergroups` SET `lft` = `lft` + 2 WHERE `lft` > :r', ['r' => $rgt]);

			$groupId = $db->insert(
				'#__usergroups',
				['parent_id' => 2, 'lft' => $rgt, 'rgt' => $rgt + 1, 'title' => self::VIEWER_GROUP]
			);
		}

		$this->grant('root.1', 'core.login.admin', $groupId);
		$this->grant('com_media', 'core.manage', $groupId);

		$userId = (int) $db->value('SELECT `id` FROM `#__users` WHERE `username` = :u', ['u' => self::VIEWER_USERNAME]);
		$hash   = password_hash($this->config->getUserPassword(), PASSWORD_BCRYPT);

		if ($userId === 0)
		{
			$userId = $db->insert(
				'#__users',
				[
					'name'         => 'E2E Media Viewer',
					'username'     => self::VIEWER_USERNAME,
					'email'        => 'viewer@example.test',
					'password'     => $hash,
					'block'        => 0,
					'sendEmail'    => 0,
					'registerDate' => gmdate('Y-m-d H:i:s'),
					'activation'   => '',
					'params'       => '{}',
					'resetCount'   => 0,
					'otpKey'       => '',
					'otep'         => '',
					'requireReset' => 0,
				]
			);
		}
		else
		{
			$db->query('UPDATE `#__users` SET `password` = :p, `block` = 0 WHERE `id` = :id', ['p' => $hash, 'id' => $userId]);
		}

		$db->query('DELETE FROM `#__user_usergroup_map` WHERE `user_id` = :u', ['u' => $userId]);
		$db->insert('#__user_usergroup_map', ['user_id' => $userId, 'group_id' => $groupId]);
	}

	private function grant(string $assetName, string $action, int $groupId): void
	{
		$db    = new Database($this->config);
		$rules = json_decode((string) $db->value('SELECT `rules` FROM `#__assets` WHERE `name` = :n', ['n' => $assetName]), true);

		if (!\is_array($rules))
		{
			throw new RuntimeException("Asset $assetName not found.");
		}

		$rules[$action]                   = \is_array($rules[$action] ?? null) ? $rules[$action] : [];
		$rules[$action][(string) $groupId] = 1;

		$db->query(
			'UPDATE `#__assets` SET `rules` = :r WHERE `name` = :n',
			['r' => json_encode($rules), 'n' => $assetName]
		);
	}

	/**
	 * Empty the bucket and mirror the seed objects into it, in one `mc` container run.
	 */
	private function seedBucket(): void
	{
		$seedDir = \dirname(__DIR__) . '/docker/seed';

		$this->removeTree($seedDir);

		foreach ($this->seedObjects() as $key => $content)
		{
			@mkdir(\dirname($seedDir . '/' . $key), 0777, true);
			file_put_contents($seedDir . '/' . $key, $content);
		}

		$bucket = $this->config->getS3()['bucket'];

		(new ContainerCli($this->config))->mc(
			[
				'--quiet',
				'mirror',
				'--overwrite',
				'--remove',
				'/seed',
				'e2e/' . $bucket,
			]
		);
	}

	private function removeTree(string $dir): void
	{
		if (!is_dir($dir))
		{
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($iterator as $file)
		{
			$file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
		}

		rmdir($dir);
	}
}
