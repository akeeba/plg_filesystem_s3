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
 * The test bucket, observed independently of the code under test.
 *
 * Object reads go straight to the S3 endpoint over plain HTTP from the host, anonymously (the nginx front
 * serves GET and HEAD of the objects of the public bucket, and refuses everything else) — no signing library,
 * least of all the one the plugin uses. That makes every "the object really is there / really is gone"
 * assertion independent of the plugin's own view, and every successful anonymous GET proof that the object is
 * publicly readable.
 *
 * Garage has no anonymous bucket listing, so listing, like writing (seeding, and changes made behind the
 * plugin's back), goes through the `aws` client with full credentials; see {@see ContainerCli::s3()}.
 */
class Bucket
{
	private Configuration $config;

	private string $publicUrl;

	private string $name;

	private string $internalEndpoint;

	public function __construct(?Configuration $config = null)
	{
		$this->config           = $config ?? Configuration::getInstance();
		$s3                     = $this->config->getS3();
		$this->publicUrl        = $s3['publicUrl'];
		$this->name             = $s3['bucket'];
		$this->internalEndpoint = $s3['internalEndpoint'];
	}

	public function getName(): string
	{
		return $this->name;
	}

	/**
	 * The anonymous, host-side URL of an object.
	 */
	public function url(string $key): string
	{
		return $this->publicUrl . '/' . $this->name . '/' . $this->encodeKey($key);
	}

	public function head(string $key): Response
	{
		return $this->surfer()->request('HEAD', $this->url($key));
	}

	public function get(string $key): Response
	{
		return $this->surfer()->get($this->url($key));
	}

	public function exists(string $key): bool
	{
		return $this->head($key)->code === 200;
	}

	/**
	 * Every key under a prefix, recursively, sorted.
	 *
	 * @return  string[]
	 */
	public function keys(string $prefix = ''): array
	{
		// The CLI pages through the listing itself. `null` (no Contents) means there is nothing under the prefix.
		$output = (new ContainerCli($this->config))->s3(
			['s3api', 'list-objects-v2', '--bucket', $this->name, '--prefix', $prefix, '--query', 'Contents[].Key', '--output', 'json']
		);
		$keys   = trim($output) === 'null' ? [] : json_decode($output, true);

		if (!\is_array($keys))
		{
			throw new RuntimeException("Could not list the bucket.\n" . $output);
		}

		sort($keys);

		return $keys;
	}

	/**
	 * Map a URL the plugin produced — which points at the S3 endpoint as the SITE sees it — onto the host's view,
	 * keeping the path and query untouched.
	 *
	 * Only the origin is replaced. The scheme is replaced too, deliberately: whether the plugin chose the
	 * right scheme is asserted separately (PublicUrlTest); here the question is whether the PATH names
	 * the object.
	 */
	public function toHostUrl(string $url): string
	{
		$internal = parse_url($this->internalEndpoint);
		$parts    = parse_url($url);

		if (($parts['host'] ?? null) !== $internal['host'] || ($parts['port'] ?? null) !== ($internal['port'] ?? null))
		{
			throw new RuntimeException(
				sprintf('%s does not point at the S3 server under test (%s).', $url, $this->internalEndpoint)
			);
		}

		return $this->publicUrl . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
	}

	/**
	 * Write an object behind the plugin's back.
	 */
	public function put(string $key, string $content): void
	{
		(new ContainerCli($this->config))->s3(['s3', 'cp', '--only-show-errors', '-', 's3://' . $this->name . '/' . $key], $content);
	}

	/**
	 * Write many objects under a prefix behind the plugin's back, in a single `aws s3 sync` run.
	 *
	 * The files are staged in docker/bulk/ (mounted into the s3cli container as /bulk) and removed again
	 * afterwards.
	 *
	 * @param   string                $prefix   The key prefix, e.g. 't-abc/big'.
	 * @param   array<string,string>  $objects  Relative key => content.
	 */
	public function putMany(string $prefix, array $objects): void
	{
		$prefix  = trim($prefix, '/');
		$staging = \dirname(__DIR__, 2) . '/docker/bulk/' . $prefix;

		try
		{
			foreach ($objects as $key => $content)
			{
				@mkdir(\dirname($staging . '/' . $key), 0777, true);
				file_put_contents($staging . '/' . $key, $content);
			}

			(new ContainerCli($this->config))->s3(
				['s3', 'sync', '--only-show-errors', '/bulk/' . $prefix, 's3://' . $this->name . '/' . $prefix]
			);
		}
		finally
		{
			exec('rm -rf ' . escapeshellarg(\dirname(__DIR__, 2) . '/docker/bulk/' . explode('/', $prefix)[0]));
		}
	}

	/**
	 * Remove everything under a prefix behind the plugin's back.
	 */
	public function removePrefix(string $prefix): void
	{
		if (trim($prefix, '/') === '')
		{
			throw new RuntimeException('Refusing to empty the whole bucket from a test; use the provisioner.');
		}

		(new ContainerCli($this->config))->s3(['s3', 'rm', '--recursive', 's3://' . $this->name . '/' . $prefix]);
	}

	private function encodeKey(string $key): string
	{
		return implode('/', array_map('rawurlencode', explode('/', $key)));
	}

	private function surfer(): Surfer
	{
		return new Surfer($this->publicUrl);
	}
}
