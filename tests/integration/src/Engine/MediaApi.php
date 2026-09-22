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
 * Joomla's internal Media Manager API — the very JSON endpoint the Media Manager's own JavaScript
 * calls — driven with a real back-end session.
 *
 *     administrator/index.php?option=com_media&task=api.files&format=json&path=<adapter>:<path>
 *
 * GET lists a folder or describes a file; POST creates; PUT updates, moves or copies; DELETE deletes.
 * Every non-GET request must carry the session's anti-CSRF token in an `X-CSRF-Token` header, exactly as
 * the Media Manager sends it (com_media ApiController::execute(), Session::checkToken('json')).
 *
 * The adapter id is `<provider>-<account>`: always `s3-<connection label>` for this plugin.
 */
class MediaApi
{
	/**
	 * `mediatypes=0,1,2,3` is what the full Media Manager sends: images, audio, video and documents.
	 * Without it the API defaults to images only, and silently drops every other file from listings and
	 * refuses to create it (ApiModel::isMediaFile()).
	 */
	private const ENDPOINT = 'administrator/index.php?option=com_media&task=api.files&format=json&mediatypes=0,1,2,3';

	/**
	 * Pass as $token to send the request with no token header at all.
	 */
	public const NO_TOKEN = '';

	private Surfer $surfer;

	private ?string $token = null;

	public function __construct(Surfer $surfer)
	{
		$this->surfer = $surfer;
	}

	/**
	 * The session's anti-CSRF token, as the Media Manager page hands it to its own JavaScript.
	 */
	public function token(): string
	{
		if ($this->token !== null)
		{
			return $this->token;
		}

		$response = $this->surfer->get('administrator/index.php?option=com_media');

		if (preg_match('/"csrf\.token"\s*:\s*"([0-9a-f]{32})"/i', $response->body, $match))
		{
			return $this->token = strtolower($match[1]);
		}

		$token = $this->surfer->getFormToken($response->body);

		if ($token === null)
		{
			throw new RuntimeException("No anti-CSRF token on the Media Manager page.\n" . $response->summary());
		}

		return $this->token = $token;
	}

	/**
	 * List a folder, or describe a single file.
	 *
	 * @param   array  $query  Extra query parameters, e.g. ['url' => 1] or ['search' => 'hello'].
	 */
	public function get(string $adapter, string $path, array $query = []): Response
	{
		return $this->surfer->get($this->url($adapter, $path, $query));
	}

	public function createFolder(string $adapter, string $parent, string $name, ?string $token = null): Response
	{
		return $this->send('POST', $adapter, $parent, ['name' => $name], $token);
	}

	public function createFile(
		string $adapter, string $parent, string $name, string $content, bool $override = false, ?string $token = null
	): Response
	{
		return $this->send(
			'POST',
			$adapter,
			$parent,
			['name' => $name, 'content' => base64_encode($content), 'override' => $override],
			$token
		);
	}

	public function updateFile(string $adapter, string $path, string $content, ?string $token = null): Response
	{
		return $this->send('PUT', $adapter, $path, ['content' => base64_encode($content)], $token);
	}

	public function move(string $adapter, string $path, string $newPath, ?string $token = null): Response
	{
		return $this->send('PUT', $adapter, $path, ['newPath' => $adapter . ':' . $newPath, 'move' => 1], $token);
	}

	public function copy(string $adapter, string $path, string $newPath, ?string $token = null): Response
	{
		return $this->send('PUT', $adapter, $path, ['newPath' => $adapter . ':' . $newPath, 'move' => 0], $token);
	}

	public function delete(string $adapter, string $path, ?string $token = null): Response
	{
		return $this->send('DELETE', $adapter, $path, null, $token);
	}

	/**
	 * The `data` member of a successful API response, or a failure with the response summary.
	 */
	public static function data(Response $response): mixed
	{
		$json = $response->json();

		if ($response->code !== 200 || !\is_array($json) || ($json['success'] ?? false) !== true)
		{
			throw new RuntimeException("The Media Manager API request did not succeed.\n" . $response->summary());
		}

		return $json['data'];
	}

	/**
	 * @param   string|null  $token  Null: the session's real token. NO_TOKEN: no header. Else: this value.
	 */
	private function send(string $verb, string $adapter, string $path, ?array $body, ?string $token): Response
	{
		$token ??= $this->token();
		$headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];

		if ($token !== self::NO_TOKEN)
		{
			$headers['X-CSRF-Token'] = $token;
		}

		return $this->surfer->request(
			$verb,
			$this->url($adapter, $path),
			$body === null ? '' : json_encode($body),
			$headers
		);
	}

	private function url(string $adapter, string $path, array $query = []): string
	{
		return self::ENDPOINT . '&' . http_build_query(array_merge(['path' => $adapter . ':' . $path], $query));
	}
}
