<?php
/**
 * @package   contactus
 * @copyright Copyright (c)2013-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\ContactUs\IntegrationTest;

defined('_JEXEC') or die;

use Akeeba\ContactUs\IntegrationTest\Engine\Configuration;
use Akeeba\ContactUs\IntegrationTest\Engine\ContainerCli;
use Akeeba\ContactUs\IntegrationTest\Engine\Database;
use Akeeba\ContactUs\IntegrationTest\Engine\JoomlaSession;
use Akeeba\ContactUs\IntegrationTest\Engine\Mailpit;
use Akeeba\ContactUs\IntegrationTest\Engine\Response;
use Akeeba\ContactUs\IntegrationTest\Engine\Surfer;
use PHPUnit\Framework\TestCase;

/**
 * Base class for the end-to-end tests.
 *
 * Every test here drives a real HTTP request against a real Joomla site with a real session. The
 * assertions that matter most are the negative ones — the message that must not be stored, the
 * stored message that must not be disclosed or overwritten — and for those the rule is: assert the
 * refusal AND the absence of its effect.
 *
 * @since 4.3.0
 */
abstract class AbstractE2ETestCase extends TestCase
{
	/**
	 * The suite configuration.
	 *
	 * @var   Configuration
	 * @since 4.3.0
	 */
	protected static Configuration $config;

	/**
	 * The fixtures.
	 *
	 * @var   SiteProvisioner
	 * @since 4.3.0
	 */
	protected static SiteProvisioner $fixtures;

	/**
	 * Surfers created during a test, keyed by role, so each test starts from a clean session.
	 *
	 * @var   array<string, Surfer>
	 * @since 4.3.0
	 */
	private array $surfers = [];

	/**
	 * The login helper.
	 *
	 * @var   JoomlaSession
	 * @since 4.3.0
	 */
	protected JoomlaSession $session;

	/**
	 * Byte offset into php-errors.log when the test started.
	 *
	 * @var   int
	 * @since 4.3.0
	 */
	private int $phpErrorLogOffset = 0;

	/**
	 * Did this test change the component's parameters? They are put back in tearDown().
	 *
	 * @var   bool
	 * @since 4.3.0
	 */
	private bool $paramsChanged = false;

	/**
	 * Set up the shared configuration and fixtures.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		static::$config   = Configuration::getInstance();
		static::$fixtures = SiteProvisioner::getInstance();
	}

	/**
	 * Set up a test.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->session           = new JoomlaSession();
		$this->phpErrorLogOffset = $this->phpErrorLogSize();
	}

	/**
	 * Tear down a test.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	protected function tearDown(): void
	{
		$this->surfers = [];

		if ($this->paramsChanged)
		{
			static::$fixtures->resetComponentParams();

			$this->paramsChanged = false;
		}

		parent::tearDown();
	}

	/**
	 * Change the component's parameters for the rest of this test. They are put back afterwards.
	 *
	 * @param   array  $params  Parameter => value, merged over the current ones.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	protected function setComponentParams(array $params): void
	{
		$this->paramsChanged = true;

		static::$fixtures->setComponentParams($params);
	}

	// -----------------------------------------------------------------------
	// Actors.
	// -----------------------------------------------------------------------

	/**
	 * A logged-out surfer.
	 *
	 * @return  Surfer
	 * @since   4.3.0
	 */
	protected function guest(): Surfer
	{
		return $this->surfers['__guest'] ??= new Surfer(static::$config->getSiteUrl());
	}

	/**
	 * A brand new logged-out surfer, with a session of its own.
	 *
	 * @return  Surfer
	 * @since   4.3.0
	 */
	protected function newGuest(): Surfer
	{
		return new Surfer(static::$config->getSiteUrl());
	}

	/**
	 * A surfer logged into the front-end as one of the shared accounts.
	 *
	 * @param   string  $role  A role, e.g. 'alice'.
	 *
	 * @return  Surfer
	 * @since   4.3.0
	 */
	protected function loggedIn(string $role): Surfer
	{
		if (isset($this->surfers[$role]))
		{
			return $this->surfers[$role];
		}

		$surfer = new Surfer(static::$config->getSiteUrl());

		$this->session->loginFrontend($surfer, static::$fixtures->username($role), static::$config->getUserPassword());

		return $this->surfers[$role] = $surfer;
	}

	/**
	 * A surfer logged into the back-end as the Super User.
	 *
	 * @return  Surfer
	 * @since   4.3.0
	 */
	protected function superUser(): Surfer
	{
		if (isset($this->surfers['__super']))
		{
			return $this->surfers['__super'];
		}

		[$username, $password] = static::$config->getAdminCredentials();

		$surfer = new Surfer(static::$config->getSiteUrl());
		$this->session->loginBackend($surfer, $username, $password);

		return $this->surfers['__super'] = $surfer;
	}

	/**
	 * A surfer logged into the back-end as one of the shared accounts: viewer or nomanage.
	 *
	 * @param   string  $role  A role.
	 *
	 * @return  Surfer
	 * @since   4.3.0
	 */
	protected function loggedInBackend(string $role): Surfer
	{
		$key = '__admin_' . $role;

		if (isset($this->surfers[$key]))
		{
			return $this->surfers[$key];
		}

		$surfer = new Surfer(static::$config->getSiteUrl());

		$this->session->loginBackend($surfer, static::$fixtures->username($role), static::$config->getUserPassword());

		return $this->surfers[$key] = $surfer;
	}

	// -----------------------------------------------------------------------
	// Access to the stack.
	// -----------------------------------------------------------------------

	/**
	 * The site's database.
	 *
	 * @return  Database
	 * @since   4.3.0
	 */
	protected function db(): Database
	{
		static $db = null;

		return $db ??= new Database(static::$config);
	}

	/**
	 * The outbound mail sink.
	 *
	 * @return  Mailpit
	 * @since   4.3.0
	 */
	protected function mailpit(): Mailpit
	{
		static $mailpit = null;

		return $mailpit ??= new Mailpit(static::$config->getMailpitUrl());
	}

	/**
	 * Joomla's console application inside the stack.
	 *
	 * @return  ContainerCli
	 * @since   4.3.0
	 */
	protected function cli(): ContainerCli
	{
		static $cli = null;

		return $cli ??= new ContainerCli(static::$config);
	}

	// -----------------------------------------------------------------------
	// URLs and requests.
	// -----------------------------------------------------------------------

	/**
	 * Build a front-end ContactUs URL.
	 *
	 * @param   array  $query  Query parameters, merged over option=com_contactus.
	 *
	 * @return  string
	 * @since   4.3.0
	 */
	protected function siteUrl(array $query = []): string
	{
		return 'index.php?' . http_build_query(array_merge(['option' => 'com_contactus'], $query));
	}

	/**
	 * Build a back-end ContactUs URL.
	 *
	 * @param   array  $query  Query parameters, merged over option=com_contactus.
	 *
	 * @return  string
	 * @since   4.3.0
	 */
	protected function adminUrl(array $query = []): string
	{
		return 'administrator/index.php?' . http_build_query(array_merge(['option' => 'com_contactus'], $query));
	}

	/**
	 * The anti-CSRF token of a surfer's front-end session, read from the contact form itself.
	 *
	 * @param   Surfer  $surfer  The surfer.
	 *
	 * @return  string
	 * @since   4.3.0
	 */
	protected function formToken(Surfer $surfer): string
	{
		return $surfer->fetchToken($this->siteUrl());
	}

	/**
	 * A complete, valid contact form submission, marked so that its row and its mail can be found.
	 *
	 * @param   string  $categoryKey  The category fixture, e.g. 'general'.
	 * @param   array   $overrides    jform field => value; null removes the field.
	 *
	 * @return  array<string, string>  jform field => value.
	 * @since   4.3.0
	 */
	protected function contactData(string $categoryKey = 'general', array $overrides = []): array
	{
		$marker = 'E2E-' . strtoupper(bin2hex(random_bytes(5)));

		$data = array_merge(
			[
				'contactus_category_id' => (string) static::$fixtures->categoryId($categoryKey),
				'fromname'              => 'Visitor ' . $marker,
				'fromemail'             => strtolower($marker) . '@visitor.example.test',
				'subject'               => 'Subject ' . $marker,
				'body'                  => 'Body ' . $marker,
				'consent'               => 'on',
			],
			$overrides
		);

		return array_filter($data, fn($value) => $value !== null);
	}

	/**
	 * POST the contact form, exactly as the rendered form does.
	 *
	 * @param   Surfer       $surfer  The surfer.
	 * @param   array        $jform   jform field => value, e.g. from contactData().
	 * @param   string|null  $token   The token to send; null for the session's real one, '' for none.
	 * @param   array        $extra   Extra top-level POST fields.
	 *
	 * @return  Response
	 * @since   4.3.0
	 */
	protected function submitContact(Surfer $surfer, array $jform, ?string $token = null, array $extra = []): Response
	{
		$token ??= $this->formToken($surfer);

		$data = ['jform' => $jform] + $extra;

		if ($token !== '')
		{
			$data[$token] = 1;
		}

		return $surfer->post($this->siteUrl(['task' => 'item.save']), $data);
	}

	/**
	 * Follow a redirect with the surfer that received it, so any queued message is rendered.
	 *
	 * @param   Surfer    $surfer    The surfer that made the request.
	 * @param   Response  $response  A redirect response.
	 *
	 * @return  Response  The landing page.
	 * @since   4.3.0
	 */
	protected function follow(Surfer $surfer, Response $response): Response
	{
		$this->assertTrue($response->isRedirect(), "Expected a redirect.\n" . $response->summary());

		$wasFollowing            = $surfer->followRedirects;
		$surfer->followRedirects = true;

		try
		{
			return $surfer->get((string) $response->getLocation());
		}
		finally
		{
			$surfer->followRedirects = $wasFollowing;
		}
	}

	// -----------------------------------------------------------------------
	// Observations.
	// -----------------------------------------------------------------------

	/**
	 * The stored contact messages whose subject contains a marker.
	 *
	 * @param   string  $marker  e.g. the subject of a contactData() submission.
	 *
	 * @return  array[]
	 * @since   4.3.0
	 */
	protected function messagesMatching(string $marker): array
	{
		return $this->db()->all(
			'SELECT * FROM #__contactus_items WHERE subject LIKE ? OR fromname LIKE ? OR body LIKE ?',
			['%' . $marker . '%', '%' . $marker . '%', '%' . $marker . '%']
		);
	}

	/**
	 * A stored contact message, or null when there is none.
	 *
	 * @param   int  $id  The message id.
	 *
	 * @return  array|null
	 * @since   4.3.0
	 */
	protected function messageRow(int $id): ?array
	{
		return $this->db()->row('SELECT * FROM #__contactus_items WHERE contactus_item_id = ?', [$id]);
	}

	/**
	 * How many contact messages are stored.
	 *
	 * @return  int
	 * @since   4.3.0
	 */
	protected function messageCount(): int
	{
		return (int) $this->db()->value('SELECT COUNT(*) FROM #__contactus_items');
	}

	/**
	 * The marker a contactData() submission carries in its name, subject and body.
	 *
	 * Looked for in all three, because a refusal test empties one of them.
	 *
	 * @param   array  $jform  The submission.
	 *
	 * @return  string
	 * @since   4.3.0
	 */
	protected function markerOf(array $jform): string
	{
		$fields = array_intersect_key($jform, array_flip(['fromname', 'subject', 'body']));

		$this->assertTrue(
			(bool) preg_match('/E2E-[0-9A-F]{10}/', implode(' ', $fields), $match),
			'The submission carries no marker; it did not come from contactData().'
		);

		return $match[0];
	}

	/**
	 * PHP errors, warnings and notices the site logged since this test started.
	 *
	 * @return  string[]
	 * @since   4.3.0
	 */
	protected function newPhpErrors(): array
	{
		$file = static::$config->getSiteRoot() . '/php-errors.log';

		clearstatcache(true, $file);

		if (!is_file($file) || filesize($file) <= $this->phpErrorLogOffset)
		{
			return [];
		}

		$handle = fopen($file, 'r');
		fseek($handle, $this->phpErrorLogOffset);
		$contents = stream_get_contents($handle);
		fclose($handle);

		return array_values(array_filter(array_map('trim', explode("\n", (string) $contents))));
	}

	/**
	 * The size of php-errors.log right now.
	 *
	 * @return  int
	 * @since   4.3.0
	 */
	private function phpErrorLogSize(): int
	{
		$file = static::$config->getSiteRoot() . '/php-errors.log';

		clearstatcache(true, $file);

		return is_file($file) ? (int) filesize($file) : 0;
	}

	// -----------------------------------------------------------------------
	// Assertions.
	//
	// Each carries the response summary into the failure message. A bare
	// "failed asserting that 200 matches 403" tells you nothing about which
	// of the moving parts (session, ACL fixture, route, controller) broke.
	// -----------------------------------------------------------------------

	/**
	 * Assert that the response has a given status code.
	 *
	 * @param   int       $expected  The expected status code.
	 * @param   Response  $response  The response.
	 * @param   string    $message   Extra context.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	protected function assertStatus(int $expected, Response $response, string $message = ''): void
	{
		$this->assertSame($expected, $response->code, trim($message . "\n" . $response->summary()));
	}

	/**
	 * Assert that a contact form submission was accepted: it redirects to the thank-you page.
	 *
	 * @param   Response  $response  The response to the POST.
	 * @param   string    $message   Extra context.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	protected function assertAccepted(Response $response, string $message = ''): void
	{
		$this->assertTrue(
			$response->isRedirect() && str_contains((string) $response->getLocation(), 'view=thanks'),
			trim("Expected the submission to be accepted (a redirect to the thank-you page).\n" . $message . "\n" . $response->summary())
		);
	}

	/**
	 * Assert that the request was refused.
	 *
	 * Joomla refuses in more than one shape and all of them count:
	 *
	 *   - an error page — 401, 403 or 404 from an uncaught exception carrying that code;
	 *   - a redirect to the login page;
	 *   - a redirect carrying a warning or error message. BaseController::checkToken() does NOT throw
	 *     on a bad token: it enqueues JINVALID_TOKEN_NOTICE and redirects. The message lives in the
	 *     session of the surfer that made the request, so the redirect is followed WITH THAT SURFER —
	 *     a fresh one would find an empty queue and read the refusal as success.
	 *
	 * For a request that would change state, this assertion is necessary but not sufficient: also
	 * assert that the change did not happen.
	 *
	 * @param   Surfer    $surfer    The surfer that made the request, whose session holds any message.
	 * @param   Response  $response  The response.
	 * @param   string    $message   Extra context.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	protected function assertRefused(Surfer $surfer, Response $response, string $message = ''): void
	{
		$context = trim($message . "\n" . $response->summary());

		if (in_array($response->code, [401, 403, 404], true))
		{
			$this->assertTrue(true);

			return;
		}

		if ($response->isRedirect())
		{
			$location = (string) $response->getLocation();

			if (stripos($location, 'com_users') !== false && stripos($location, 'login') !== false)
			{
				$this->assertTrue(true);

				return;
			}

			$this->assertStringNotContainsString(
				'view=thanks',
				$location,
				"The request redirected to the thank-you page, i.e. it succeeded.\n" . $context
			);

			$landing = $this->follow($surfer, $response);

			$this->assertTrue(
				$this->bodyLooksLikeRefusal($landing->body),
				"The request redirected, but the page it redirected to carries no refusal message,\n"
				. "so this looks like the action succeeded.\n" . $context . "\nLanding page: " . $landing->summary()
			);

			return;
		}

		if ($response->code === 200 && $this->bodyLooksLikeRefusal($response->body))
		{
			$this->assertTrue(true);

			return;
		}

		$this->fail("Expected the request to be refused, but it was not.\n" . $context);
	}

	/**
	 * Assert that the response is not a server error, and that the site logged no PHP fatal error.
	 *
	 * A refusal delivered as an HTTP 500 — an uncaught TypeError, say — is not a refusal: it is a
	 * crash that happens to stop the action.
	 *
	 * @param   Response  $response  The response.
	 * @param   string    $message   Extra context.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	protected function assertNotServerError(Response $response, string $message = ''): void
	{
		$fatals = array_filter($this->newPhpErrors(), fn(string $line): bool => stripos($line, 'Fatal error') !== false);

		$this->assertLessThan(
			500,
			$response->code,
			trim($message . "\n" . $response->summary() . "\n" . implode("\n", $fatals))
		);
		$this->assertSame([], array_values($fatals), trim("The site logged a PHP fatal error.\n" . $message));
	}

	/**
	 * Assert that the response body contains a string.
	 *
	 * @param   string    $needle    The string to look for.
	 * @param   Response  $response  The response.
	 * @param   string    $message   Extra context.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	protected function assertBodyContains(string $needle, Response $response, string $message = ''): void
	{
		// assertStringContainsString() would print the entire rendered page as the haystack, which
		// buries the message.
		$this->assertTrue(
			str_contains($response->body, $needle),
			trim(sprintf('Expected the response body to contain "%s".', $needle) . "\n" . $message . "\n" . $response->summary())
		);
	}

	/**
	 * Assert that the response body does NOT contain a string.
	 *
	 * @param   string    $needle    The string that must be absent.
	 * @param   Response  $response  The response.
	 * @param   string    $message   Extra context.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	protected function assertBodyNotContains(string $needle, Response $response, string $message = ''): void
	{
		$this->assertFalse(
			str_contains($response->body, $needle),
			trim(sprintf('Expected the response body NOT to contain "%s".', $needle) . "\n" . $message . "\n" . $response->summary())
		);
	}

	/**
	 * Assert something the product currently gets wrong, skipping with a pointer to known-issues.md
	 * instead of failing.
	 *
	 * The suite surfaces suspected bugs rather than encoding them: asserting today's buggy behaviour
	 * as correct would turn the test green for the wrong reason. When the condition holds (the bug is
	 * fixed), this is an ordinary passing assertion — at which point replace the call with a plain
	 * assertion so a regression fails loudly instead of skipping.
	 *
	 * @param   bool    $condition  The correct behaviour holds.
	 * @param   int     $issue      The item number in known-issues.md.
	 * @param   string  $diagnosis  What is wrong, in one or two sentences.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	protected function assertOrKnownIssue(bool $condition, int $issue, string $diagnosis): void
	{
		if ($condition)
		{
			$this->assertTrue(true);

			return;
		}

		$this->markTestSkipped(sprintf('Known issue #%d (see known-issues.md): %s', $issue, $diagnosis));
	}

	/**
	 * Does this body look like Joomla saying no?
	 *
	 * Primarily this looks at the SEVERITY of the rendered system message, not at its wording.
	 * Joomla enqueues a refusal as 'warning' or 'error' and a success as 'info' or 'success', and the
	 * template renders that severity into the markup — `<joomla-alert type="warning">`, or the message
	 * queue JSON in the script options. The phrases below are a backstop for refusals rendered as a
	 * full error page.
	 *
	 * @param   string  $body  The response body.
	 *
	 * @return  bool
	 * @since   4.3.0
	 */
	protected function bodyLooksLikeRefusal(string $body): bool
	{
		if (preg_match('/<joomla-alert\b[^>]*\btype\s*=\s*["\'](warning|danger|error)["\']/i', $body))
		{
			return true;
		}

		// Joomla renders the message queue into the script options as {"joomla.messages":[{"error":[…]}]}
		if (preg_match('/"joomla\.messages"\s*:\s*\[\s*\{\s*"(warning|error|danger)"/i', $body))
		{
			return true;
		}

		$needles = [
			// JINVALID_TOKEN_NOTICE
			'security token did not match',
			// JERROR_ALERTNOAUTHOR
			'not authorised to view this resource',
			'not authorized to view this resource',
			'not permitted to use that link',
		];

		foreach ($needles as $needle)
		{
			if (stripos($body, $needle) !== false)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * The messages of a given severity in the Joomla message queue rendered on a page.
	 *
	 * @param   Response  $response  The page.
	 * @param   string    $type      'error', 'warning', 'message', 'notice'.
	 *
	 * @return  string[]
	 * @since   4.3.0
	 */
	protected function queuedMessages(Response $response, string $type): array
	{
		if (!preg_match('/<script[^>]+class="joomla-script-options[^"]*"[^>]*>(.*?)<\/script>/s', $response->body, $match))
		{
			return [];
		}

		$options = json_decode($match[1], true);
		$found   = [];

		foreach ($options['joomla.messages'] ?? [] as $group)
		{
			foreach ($group[$type] ?? [] as $text)
			{
				$found[] = (string) $text;
			}
		}

		return $found;
	}
}
