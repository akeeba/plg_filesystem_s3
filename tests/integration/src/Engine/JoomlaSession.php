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
 * Establishes real Joomla back-end sessions for a {@see Surfer}.
 *
 * "Real" is the operative word: this logs in by POSTing the actual login form with a token obtained
 * from the actual rendered page, so the resulting session is indistinguishable from a browser's.
 * Anything short of that — forging a session row — would not exercise the path a Media Manager
 * request really takes.
 *
 * Only the back-end is needed: the Media Manager, and with it every adapter this plugin provides, is a
 * back-end feature.
 */
class JoomlaSession
{
	public function loginBackend(Surfer $surfer, string $username, string $password): void
	{
		$loginUrl = 'administrator/index.php';
		$token    = $surfer->fetchToken($loginUrl);

		$response = $surfer->post(
			$loginUrl,
			[
				'option'   => 'com_login',
				'task'     => 'login',
				'username' => $username,
				// The back-end login form calls this field `passwd`, not `password`.
				'passwd'   => $password,
				'lang'     => '',
				'return'   => base64_encode('index.php'),
				$token     => 1,
			]
		);

		if (!$this->isLoggedInBackend($surfer))
		{
			throw new RuntimeException(
				sprintf("Could not log in as '%s' on the back-end.\n%s", $username, $response->summary())
			);
		}
	}

	/**
	 * Is this surfer logged into the back-end?
	 *
	 * A failed login redirects back to the login form with a message rather than returning an error
	 * status, so the HTTP code proves nothing. Instead we ask the back-end itself: when not
	 * authenticated it serves the login form, and that form's `passwd` field is the one unambiguous
	 * marker of it.
	 */
	public function isLoggedInBackend(Surfer $surfer): bool
	{
		$wasFollowing            = $surfer->followRedirects;
		$surfer->followRedirects = true;

		try
		{
			$response = $surfer->get('administrator/index.php');

			return $response->code === 200 && stripos($response->body, 'name="passwd"') === false;
		}
		finally
		{
			$surfer->followRedirects = $wasFollowing;
		}
	}
}
