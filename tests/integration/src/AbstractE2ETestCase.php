<?php
/*
 * @package   PlgFilesystemS3
 * @copyright Copyright (c)2026 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

namespace Akeeba\Plugin\Filesystem\S3\IntegrationTest;

defined('_JEXEC') or die;

use Akeeba\Plugin\Filesystem\S3\IntegrationTest\Engine\Bucket;
use Akeeba\Plugin\Filesystem\S3\IntegrationTest\Engine\Configuration;
use Akeeba\Plugin\Filesystem\S3\IntegrationTest\Engine\ContainerCli;
use Akeeba\Plugin\Filesystem\S3\IntegrationTest\Engine\JoomlaSession;
use Akeeba\Plugin\Filesystem\S3\IntegrationTest\Engine\MediaApi;
use Akeeba\Plugin\Filesystem\S3\IntegrationTest\Engine\Response;
use Akeeba\Plugin\Filesystem\S3\IntegrationTest\Engine\Surfer;
use PHPUnit\Framework\TestCase;

/**
 * Base class for the end-to-end tests.
 *
 * Every test drives the plugin through Joomla's own Media Manager API over real HTTP, with a real
 * back-end session, against a real S3-compatible server. Effects are then checked in the bucket itself,
 * through MinIO's anonymous read-only view — never through the plugin's own account of what it did.
 *
 * Isolation: each test that writes gets its own scratch folder in the bucket ({@see scratch()}), removed
 * once the class is done. Tests may change the plugin's parameters; tearDown() always puts back the
 * provisioned ones and clears the plugin's caches, so no test can leak into the next.
 */
abstract class AbstractE2ETestCase extends TestCase
{
	/**
	 * Product bugs this suite has found and not yet seen fixed, keyed by a short id. Numbers refer to
	 * known-issues.md.
	 *
	 * A test blocked by one calls knownBug('<id>') and is SKIPPED with the diagnosis below, rather than
	 * asserting today's broken behaviour as correct. This list is the one switch:
	 *
	 * - When a bug is fixed, delete its entry. Every test that references it then runs again — and a
	 *   leftover knownBug() call naming a deleted id FAILS, so none can linger unnoticed.
	 * - To check whether a bug is still there without editing anything, run the suite with
	 *   S3FS_E2E_IGNORE_KNOWN_BUGS=1: nothing is skipped, and each test shows the real behaviour.
	 */
	protected const KNOWN_BUGS = [
		'search-pagination'    => '(known-issues.md #1) search() pages with a marker taken from the FILTERED results; past '
			. '1,000 objects it re-fetches the same page forever.',
		'v4-url-virtual-host'  => '(known-issues.md #2) With v4 signatures getUrl() moves the bucket into the hostname '
			. '(bucket.endpoint), ignoring path-style access; the URL does not resolve.',
		'delete-no-placeholder' => '(known-issues.md #3) delete() HEADs the folder placeholder first and 404s when the '
			. 'folder was created outside Joomla (no placeholder object).',
		'url-forced-https'     => '(known-issues.md #5) getUrl() always asks akeeba/s3 for an https:// URL, so a '
			. 'plain-HTTP custom endpoint gets URLs it cannot serve.',
		'search-exact-name'    => '(known-issues.md #6) search() uses fnmatch($needle, $name): exact whole-name '
			. 'matches only, unlike the local adapter\'s *needle* substring search.',
		'thumb-marker-warning' => '(known-issues.md #8) Preview writes its failed-download marker before creating the '
			. 'cache folder: a PHP warning, and the failure is never remembered.',
		'extension-case'       => '(known-issues.md #9) makeSafeName() does not lowercase the file extension.',
	];

	protected static Configuration $config;

	protected static SiteProvisioner $fixtures;

	/**
	 * Scratch prefixes created by this class, removed in tearDownAfterClass().
	 *
	 * @var string[]
	 */
	private static array $scratchPrefixes = [];

	/**
	 * Surfers created during a test, keyed by role, so each test starts from clean sessions.
	 *
	 * @var array<string, Surfer>
	 */
	protected array $surfers = [];

	private ?string $scratch = null;

	private int $phpErrorLogOffset = 0;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		static::$config   = Configuration::getInstance();
		static::$fixtures = SiteProvisioner::getInstance();
	}

	public static function tearDownAfterClass(): void
	{
		if (self::$scratchPrefixes !== [])
		{
			$bucket = new Bucket(static::$config);
			$args   = ['rm', '--recursive', '--force'];

			foreach (self::$scratchPrefixes as $prefix)
			{
				$args[] = 'e2e/' . $bucket->getName() . '/' . $prefix . '/';
			}

			(new ContainerCli(static::$config))->mc($args);

			self::$scratchPrefixes = [];
		}

		parent::tearDownAfterClass();
	}

	protected function setUp(): void
	{
		parent::setUp();

		$this->phpErrorLogOffset = $this->phpErrorLogSize();
	}

	protected function tearDown(): void
	{
		$this->surfers = [];
		$this->scratch = null;

		if (static::$fixtures->readPluginParams() !== static::$fixtures->provisionedParams())
		{
			static::$fixtures->writePluginParams(static::$fixtures->provisionedParams());
		}

		static::$fixtures->clearPluginCaches();

		parent::tearDown();
	}

	/**
	 * Skip this test because of a known product bug. See KNOWN_BUGS.
	 */
	protected function knownBug(string $id): void
	{
		if (!\array_key_exists($id, static::KNOWN_BUGS))
		{
			$this->fail(
				"knownBug('$id') names a bug that is no longer in KNOWN_BUGS. If it was fixed, remove this call."
			);
		}

		if (getenv('S3FS_E2E_IGNORE_KNOWN_BUGS'))
		{
			return;
		}

		$this->markTestSkipped("Known product bug [$id]: " . static::KNOWN_BUGS[$id]);
	}

	// -----------------------------------------------------------------------
	// Actors
	// -----------------------------------------------------------------------

	protected function guest(): Surfer
	{
		return $this->surfers['__guest'] ??= new Surfer(static::$config->getSiteUrl());
	}

	/**
	 * The Super User, logged into the back-end.
	 */
	protected function admin(): Surfer
	{
		if (!isset($this->surfers['admin']))
		{
			[$username, $password]   = static::$config->getAdminCredentials();
			$this->surfers['admin'] = $this->login($username, $password);
		}

		return $this->surfers['admin'];
	}

	/**
	 * The restricted account: may open the Media Manager, may not change anything in it.
	 */
	protected function viewer(): Surfer
	{
		return $this->surfers['viewer'] ??= $this->login(
			SiteProvisioner::VIEWER_USERNAME, static::$config->getUserPassword()
		);
	}

	protected function media(?Surfer $surfer = null): MediaApi
	{
		return new MediaApi($surfer ?? $this->admin());
	}

	protected function bucket(): Bucket
	{
		return new Bucket(static::$config);
	}

	// -----------------------------------------------------------------------
	// Fixtures
	// -----------------------------------------------------------------------

	/**
	 * This test's own, empty, scratch folder at the bucket root, e.g. `t-5f3a…`. No placeholder object
	 * is created for it; the first object written under it brings it into existence.
	 */
	protected function scratch(): string
	{
		if ($this->scratch === null)
		{
			$this->scratch           = 't-' . bin2hex(random_bytes(6));
			self::$scratchPrefixes[] = $this->scratch;
		}

		return $this->scratch;
	}

	/**
	 * Change some of the plugin's top-level parameters for this test only. tearDown() restores them.
	 */
	protected function setPluginParams(array $params): void
	{
		$current = json_decode(static::$fixtures->readPluginParams(), true);

		static::$fixtures->writePluginParams(json_encode(array_merge($current, $params), JSON_UNESCAPED_SLASHES));
	}

	// -----------------------------------------------------------------------
	// Assertions
	// -----------------------------------------------------------------------

	/**
	 * Assert the Media Manager API succeeded, and return its `data`.
	 */
	protected function assertApiSuccess(Response $response, string $message = ''): mixed
	{
		$json = $response->json();

		$this->assertSame(200, $response->code, trim($message . "\n" . $response->summary()));
		$this->assertIsArray($json, "Not a JSON response.\n" . $response->summary());
		$this->assertTrue($json['success'] ?? false, trim($message . "\n" . $response->summary()));

		return $json['data'];
	}

	/**
	 * Assert the Media Manager API refused the request with this HTTP status, and handed out no data.
	 */
	protected function assertApiRefused(int $status, Response $response, string $message = ''): void
	{
		$this->assertSame($status, $response->code, trim($message . "\n" . $response->summary()));

		$json = $response->json();

		$this->assertIsArray($json, "The refusal is not the API's JSON error.\n" . $response->summary());
		$this->assertFalse($json['success'] ?? true, $response->summary());
		$this->assertEmpty($json['data'] ?? null, 'A refused request must not return data.');
	}

	/**
	 * Names in a Media Manager listing, sorted.
	 *
	 * @return string[]
	 */
	protected function names(array $listing): array
	{
		$names = array_column($listing, 'name');
		sort($names);

		return $names;
	}

	/**
	 * The listing entry with this name.
	 */
	protected function entry(array $listing, string $name): array
	{
		foreach ($listing as $item)
		{
			if (($item['name'] ?? null) === $name)
			{
				return $item;
			}
		}

		$this->fail(sprintf("'%s' is not in the listing: %s", $name, implode(', ', $this->names($listing))));
	}

	/**
	 * Fail if a request during this test raised a PHP error in the plugin's own code.
	 */
	protected function assertNoNewPluginPhpErrors(): void
	{
		$log = static::$config->getSiteRoot() . '/php-errors.log';

		if (!is_file($log))
		{
			$this->addToAssertionCount(1);

			return;
		}

		$new    = (string) file_get_contents($log, false, null, $this->phpErrorLogOffset);
		$plugin = array_filter(
			explode("\n", $new),
			static fn(string $line): bool => str_contains($line, 'plugins/filesystem/s3/')
		);

		$this->assertSame([], array_values($plugin), 'The plugin raised PHP errors during this test.');
	}

	private function login(string $username, string $password): Surfer
	{
		$surfer = new Surfer(static::$config->getSiteUrl());

		(new JoomlaSession())->loginBackend($surfer, $username, $password);

		return $surfer;
	}

	private function phpErrorLogSize(): int
	{
		$log = static::$config->getSiteRoot() . '/php-errors.log';

		clearstatcache(true, $log);

		return is_file($log) ? (int) filesize($log) : 0;
	}
}
