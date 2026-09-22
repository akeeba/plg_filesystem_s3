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
 * Runs a command inside the site's php container.
 *
 * Needed for what a browser cannot observe from the host: whether a URL the plugin hands out is
 * reachable from where the site itself runs (the compose network), and the on-disk state of the local
 * thumbnail cache.
 */
class ContainerCli
{
	/**
	 * The suite configuration.
	 *
	 * @var   Configuration
	 */
	private Configuration $config;

	/**
	 * Constructor.
	 *
	 * @param   Configuration|null  $config  The suite configuration.
	 */
	public function __construct(?Configuration $config = null)
	{
		$this->config = $config ?? Configuration::getInstance();
	}

	/**
	 * Run Joomla's console application inside the container.
	 *
	 * @param   string[]  $arguments  Arguments after `cli/joomla.php`, e.g. ['extension:install', '--path=…'].
	 *
	 * @return  array{0: int, 1: string}  Exit code and combined output.
	 */
	public function joomla(array $arguments): array
	{
		return $this->run(array_merge(['php', 'cli/joomla.php'], $arguments));
	}

	/**
	 * Run Joomla's console application, failing loudly on a non-zero exit.
	 *
	 * @param   string[]  $arguments  Arguments after `cli/joomla.php`.
	 *
	 * @return  string  The combined output.
	 * @throws  RuntimeException  When the command fails.
	 */
	public function joomlaOrFail(array $arguments): string
	{
		[$exitCode, $output] = $this->joomla($arguments);

		if ($exitCode !== 0)
		{
			throw new RuntimeException(
				sprintf("`cli/joomla.php %s` failed (exit %d):\n%s", implode(' ', $arguments), $exitCode, $output)
			);
		}

		return $output;
	}

	/**
	 * Run an arbitrary command inside the php container.
	 *
	 * @param   string[]  $command  The command and its arguments.
	 *
	 * @return  array{0: int, 1: string}  Exit code and combined output.
	 */
	public function run(array $command): array
	{
		$docker = $this->config->getDocker();

		$parts = array_merge(
			// composeBin may be "docker compose" (two words) or "docker-compose".
			explode(' ', $docker['bin']),
			['-f', $docker['file'], 'exec', '-T', '-w', '/var/www/html', $docker['php']],
			$command
		);

		$escaped = implode(' ', array_map('escapeshellarg', $parts)) . ' 2>&1';

		exec($escaped, $output, $exitCode);

		return [$exitCode, implode("\n", $output)];
	}

	/**
	 * Run PHP code inside the php container, i.e. from where the site itself runs.
	 *
	 * @param   string  $code  PHP code, without the opening tag.
	 *
	 * @return  array{0: int, 1: string}  Exit code and combined output.
	 */
	public function php(string $code): array
	{
		return $this->run(['php', '-r', $code]);
	}

	/**
	 * Run the MinIO client (`mc`), with full credentials, as a one-shot container.
	 *
	 * The alias `e2e` points at the stack's MinIO. The host only ever gets anonymous, read-only access to
	 * the bucket; anything that writes behind the plugin's back goes through here.
	 *
	 * @param   string[]     $arguments  Arguments after `mc`, e.g. ['rm', '--recursive', '--force', 'e2e/bucket/x'].
	 * @param   string|null  $stdin      Data for the command's standard input (e.g. for `mc pipe`).
	 *
	 * @return  string  The combined output.
	 * @throws  RuntimeException  When the command fails.
	 */
	public function mc(array $arguments, ?string $stdin = null): string
	{
		$docker = $this->config->getDocker();
		$parts  = array_merge(
			explode(' ', $docker['bin']),
			['-f', $docker['file'], 'run', '--rm', '-T', 'mc'],
			$arguments
		);

		$process = proc_open(
			implode(' ', array_map('escapeshellarg', $parts)) . ' 2>&1',
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w']],
			$pipes
		);

		if (!\is_resource($process))
		{
			throw new RuntimeException('Could not start `docker compose run mc`.');
		}

		fwrite($pipes[0], $stdin ?? '');
		fclose($pipes[0]);
		$output = stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		$exitCode = proc_close($process);

		if ($exitCode !== 0)
		{
			throw new RuntimeException(
				sprintf("`mc %s` failed (exit %d):\n%s", implode(' ', $arguments), $exitCode, $output)
			);
		}

		return (string) $output;
	}
}
