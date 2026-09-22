<?php
/*
 * @package   PlgFilesystemS3
 * @copyright Copyright (c)2026 Akeeba Ltd / Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

/**
 * Minimal Joomla symbol stubs for the unit test suite.
 *
 * The plugin's classes are Joomla classes: they extend `FormRule`, implement `AdapterInterface`, and
 * call `File::getExt()` and `Uri`. PHP resolves class hierarchies when a class is defined, so a class
 * cannot even be *loaded* without those symbols existing. These stubs make loading possible; they do
 * not make the CMS available, and they are not meant to.
 *
 * Three rules keep this file honest.
 *
 * 1. **Every declaration is guarded with `class_exists(..., false)`** (or the interface equivalent). If
 *    a real Joomla is on the autoloader, the real class always wins.
 * 2. **A stub carries no behaviour a test could mistake for the real thing.** Where a method has to
 *    return something, it returns the most inert value that lets the caller proceed.
 * 3. **Where fidelity genuinely matters, the real implementation is ported** and the provenance is
 *    stated: `File::getExt()` (makeSafeName and Preview are built on it), `MediaHelper::isImage()`,
 *    `Registry::get()`'s treatment of null and empty strings (Preview's defaults depend on it), and
 *    the parts of `Uri` the plugin uses to build and strip URLs.
 *
 * `HttpFactory` is the one deliberate exception to rule 2: it is a programmable fake, so the tests can
 * put Ec2Metadata in front of every answer the EC2 metadata service can give without a network. Its
 * behaviour is whatever the test queues — it cannot be mistaken for a real HTTP client.
 *
 * Keep this file short. A growing stub set is a signal that the class under test wants a seam, or that
 * it belongs in the end-to-end suite instead. See tests/README.md.
 */

namespace Joomla\Registry {
	defined('_JEXEC') or die;

	/**
	 * Stand-in for Joomla\Registry\Registry: a flat key/value store.
	 *
	 * get() is ported from joomla/registry 3.x for top-level keys: a null OR EMPTY-STRING value returns
	 * the default. Preview relies on that to fall back to its default extension list.
	 */
	if (!class_exists(Registry::class, false))
	{
		class Registry
		{
			/** @var array<string,mixed> */
			private $data = [];

			public function __construct($data = null)
			{
				if (\is_array($data))
				{
					$this->data = $data;
				}
				elseif (\is_object($data))
				{
					$this->data = get_object_vars($data);
				}
			}

			public function get($path, $default = null)
			{
				if (empty($path))
				{
					return $default;
				}

				return (isset($this->data[$path]) && $this->data[$path] !== '') ? $this->data[$path] : $default;
			}

			public function set($path, $value = null)
			{
				$this->data[$path] = $value;

				return $value;
			}
		}
	}
}

namespace Joomla\Filesystem {
	defined('_JEXEC') or die;

	if (!class_exists(File::class, false))
	{
		class File
		{
			/**
			 * Ported verbatim from joomla/filesystem 3.x (Joomla 6.1).
			 */
			public static function getExt($file)
			{
				$dot = strrpos($file, '.');

				if ($dot === false)
				{
					return '';
				}

				$ext = substr($file, $dot + 1);

				if (strpos($ext, '/') !== false || (DIRECTORY_SEPARATOR === '\\' && strpos($ext, '\\') !== false))
				{
					return '';
				}

				return $ext;
			}
		}
	}
}

namespace Joomla\CMS\Helper {
	defined('_JEXEC') or die;

	if (!class_exists(MediaHelper::class, false))
	{
		class MediaHelper
		{
			/**
			 * Ported verbatim from Joomla 6.1's libraries/src/Helper/MediaHelper.php.
			 */
			public static function isImage($fileName)
			{
				static $imageTypes = 'xcf|odg|gif|jpg|jpeg|png|bmp|webp|avif';

				return preg_match("/\.(?:$imageTypes)$/i", $fileName);
			}
		}
	}
}

namespace Joomla\CMS\Uri {
	defined('_JEXEC') or die;

	/**
	 * The subset of Joomla\Uri\AbstractUri / Joomla\CMS\Uri\Uri the plugin uses, ported from joomla/uri
	 * 3.x: parsing, getPath(), setVar(), and toString() with a parts list — the last is how getUrl()
	 * strips the signature off an authenticated S3 URL, so its rendering rules are copied exactly.
	 */
	if (!class_exists(Uri::class, false))
	{
		class Uri
		{
			private const PARTS = [
				'scheme' => 1, 'user' => 2, 'pass' => 4, 'host' => 8,
				'port'   => 16, 'path' => 32, 'query' => 64, 'fragment' => 128,
			];

			private $scheme;
			private $host;
			private $port;
			private $user;
			private $pass;
			private $path;
			private $query;
			private $fragment;
			private $vars = [];

			public function __construct($uri = null)
			{
				if ($uri === null)
				{
					return;
				}

				$parts = parse_url($uri) ?: [];

				if (isset($parts['query']) && strpos($parts['query'], '&amp;') !== false)
				{
					$parts['query'] = str_replace('&amp;', '&', $parts['query']);
				}

				foreach ($parts as $key => $value)
				{
					$this->$key = $value;
				}

				if (isset($parts['query']))
				{
					parse_str($parts['query'], $this->vars);
				}
			}

			public static function getInstance($uri = 'SERVER')
			{
				return new static($uri);
			}

			public function getPath()
			{
				return $this->path;
			}

			public function getVar($name, $default = null)
			{
				return $this->vars[$name] ?? $default;
			}

			public function setVar($name, $value)
			{
				$tmp               = $this->vars[$name] ?? null;
				$this->vars[$name] = $value;
				$this->query       = null;

				return $tmp;
			}

			public function toString(array $parts = ['scheme', 'user', 'pass', 'host', 'port', 'path', 'query', 'fragment'])
			{
				$mask = 0;

				foreach ($parts as $part)
				{
					$mask |= self::PARTS[$part] ?? 0;
				}

				if ($this->query === null)
				{
					$this->query = urldecode(http_build_query($this->vars, '', '&'));
				}

				$uri = '';
				$uri .= $mask & 1 ? (!empty($this->scheme) ? $this->scheme . '://' : '') : '';
				$uri .= $mask & 2 ? $this->user : '';
				$uri .= $mask & 4 ? (!empty($this->pass) ? ':' : '') . $this->pass . (!empty($this->user) ? '@' : '') : '';
				$uri .= $mask & 8 ? $this->host : '';
				$uri .= $mask & 16 ? (!empty($this->port) ? ':' : '') . $this->port : '';
				$uri .= $mask & 32 ? $this->path : '';
				$uri .= $mask & 64 ? (!empty($this->query) ? '?' . $this->query : '') : '';
				$uri .= $mask & 128 ? (!empty($this->fragment) ? '#' . $this->fragment : '') : '';

				return $uri;
			}

			public function __toString()
			{
				return $this->toString();
			}
		}
	}
}

namespace Joomla\CMS\Application {
	defined('_JEXEC') or die;

	/**
	 * Only get() is declared: it is the one method the adapter and Preview call on the application.
	 */
	if (!interface_exists(CMSApplicationInterface::class, false))
	{
		interface CMSApplicationInterface
		{
			public function get($name, $default = null);
		}
	}
}

namespace Joomla\CMS\Form {
	defined('_JEXEC') or die;

	if (!class_exists(Form::class, false))
	{
		class Form
		{
		}
	}

	if (!class_exists(FormRule::class, false))
	{
		class FormRule
		{
			public function test(\SimpleXMLElement $element, $value, $group = null, ?\Joomla\Registry\Registry $input = null, ?Form $form = null)
			{
				return true;
			}
		}
	}
}

namespace Joomla\Component\Media\Administrator\Adapter {
	defined('_JEXEC') or die;

	if (!interface_exists(AdapterInterface::class, false))
	{
		interface AdapterInterface
		{
		}
	}
}

namespace Joomla\Component\Media\Administrator\Exception {
	defined('_JEXEC') or die;

	if (!class_exists(FileNotFoundException::class, false))
	{
		class FileNotFoundException extends \RuntimeException
		{
		}
	}
}

namespace Joomla\Http {
	defined('_JEXEC') or die;

	/**
	 * Programmable fake. See the file docblock for why this one stub has behaviour.
	 *
	 * A test queues one entry per expected request with {@see HttpFactory::queue()}: either a
	 * [statusCode, body] pair, or a Throwable to throw. Every request is recorded in
	 * {@see HttpFactory::$requests}. An unexpected request (empty queue) throws, so a test can never
	 * pass by accident because Ec2Metadata made fewer — or more — calls than it thinks.
	 */
	if (!class_exists(HttpFactory::class, false))
	{
		class HttpFactory
		{
			/** @var array<int, array{0:int,1:string}|\Throwable> */
			public static array $queue = [];

			/** @var array<int, array{method:string, url:string, headers:array}> */
			public static array $requests = [];

			public static function reset(): void
			{
				self::$queue    = [];
				self::$requests = [];
			}

			/**
			 * @param   array{0:int,1:string}|\Throwable  $response
			 */
			public static function queue($response): void
			{
				self::$queue[] = $response;
			}

			public function getHttp()
			{
				return new class {
					public function get($url, array $headers = [], $timeout = null)
					{
						return $this->respond('GET', $url, $headers);
					}

					public function put($url, $data, array $headers = [], $timeout = null)
					{
						return $this->respond('PUT', $url, $headers);
					}

					private function respond(string $method, string $url, array $headers)
					{
						HttpFactory::$requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers];

						if (HttpFactory::$queue === [])
						{
							throw new \LogicException(sprintf('Unexpected HTTP %s %s: nothing queued.', $method, $url));
						}

						$next = array_shift(HttpFactory::$queue);

						if ($next instanceof \Throwable)
						{
							throw $next;
						}

						return new class ($next[0], $next[1]) {
							public function __construct(private int $code, private string $body)
							{
							}

							public function getStatusCode()
							{
								return $this->code;
							}

							public function getBody()
							{
								return $this->body;
							}
						};
					}
				};
			}
		}
	}
}
