<?php
/**
 * @package   contactus
 * @copyright Copyright (c)2013-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\ContactUs\IntegrationTest\Tests;

defined('_JEXEC') or die;

use Akeeba\ContactUs\IntegrationTest\AbstractE2ETestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What the public pages must never show a visitor: secrets, internal error text, markup injected
 * through configuration.
 *
 * @since 4.3.0
 */
class SensitiveOutputTest extends AbstractE2ETestCase
{
	/**
	 * Start every test with an empty mailbox.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->mailpit()->clear();
	}

	/**
	 * When the Akismet check cannot reach Akismet, the visitor must not see the API key, and the
	 * message must still reach its recipients.
	 *
	 * The site has no route to the internet in this stack (see docker-compose.yml), exactly like a host
	 * that blocks outbound HTTP. The key is part of the Akismet hostname, so the transport error names
	 * it.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testAkismetFailureDoesNotDiscloseTheApiKey(): void
	{
		$key = 'e2ekey' . bin2hex(random_bytes(5));

		$this->setComponentParams(['akismet_api_key' => $key]);

		$surfer   = $this->guest();
		$jform    = $this->contactData('general');
		$response = $this->submitContact($surfer, $jform);
		$page     = $response->isRedirect() ? $this->follow($surfer, $response) : $response;

		$this->assertNotServerError($page);

		$leaked   = str_contains($page->body, $key);
		$stored   = count($this->messagesMatching($this->markerOf($jform)));
		$notified = $this->mailpit()->messagesTo(static::$fixtures->recipients('general')[0]) !== [];

		$this->assertFalse($leaked, 'The visitor sees the Akismet API key in the error message.');
		$this->assertSame(1, $stored, 'The message was not stored exactly once.');
		$this->assertTrue($notified, 'The recipients were not notified.');
	}

	/**
	 * The privacy policy URL is configuration, but it is printed into an attribute of the public form:
	 * it must not be able to break out of it.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testPrivacyPolicyUrlCannotInjectMarkup(): void
	{
		$this->setComponentParams(['privacypolicy' => 'https://example.test/" onmouseover="alert(\'E2E\')" data-x="']);

		$response = $this->guest()->get($this->siteUrl());

		$this->assertStatus(200, $response);
		$this->assertBodyContains('example.test/', $response, 'The privacy policy link did not render; the test would prove nothing.');

		$this->assertOrKnownIssue(
			!str_contains($response->body, 'onmouseover="alert('),
			9,
			'the privacy policy URL is printed into href="…" unescaped; a quote in it adds attributes (and script) to the public form.'
		);
	}

	/**
	 * Input the database cannot hold, or the mailer cannot use, is refused with a message meant for a
	 * visitor — not with raw database or PHPMailer error text — and leaves nothing half-done behind.
	 *
	 * @return  array<string, array{0: array, 1: string}>
	 * @since   4.3.0
	 */
	public static function badInput(): array
	{
		return [
			'subject longer than the column' => [['subject' => 'Subject E2E-MARKER ' . str_repeat('x', 300)], 'Data too long'],
			'name longer than the column'    => [['fromname' => 'Visitor ' . str_repeat('x', 300)], 'Data too long'],
			'invalid email address'          => [['fromemail' => 'not-an-email-address'], 'Invalid address'],
		];
	}

	/**
	 * Bad input is refused cleanly.
	 *
	 * @param   array   $overrides  What differs from a valid submission.
	 * @param   string  $rawError   The internal error text that must not reach the visitor.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	#[DataProvider('badInput')]
	public function testBadInputIsRefusedCleanly(array $overrides, string $rawError): void
	{
		$surfer   = $this->guest();
		$jform    = $this->contactData('general', $overrides);
		$response = $this->submitContact($surfer, $jform);

		$this->assertNotServerError($response);
		$this->assertStringNotContainsString('view=thanks', (string) $response->getLocation(), 'Bad input must not be accepted.');

		$page     = $response->isRedirect() ? $this->follow($surfer, $response) : $response;
		$messages = implode(' ', array_merge($this->queuedMessages($page, 'danger'), $this->queuedMessages($page, 'error'), $this->queuedMessages($page, 'warning')));
		$stored   = count($this->messagesMatching($this->markerOf($jform)));

		$this->assertNotSame('', $messages, "The visitor was not told why.\n" . $page->summary());

		$this->assertFalse(str_contains($messages, $rawError), sprintf('the visitor is shown "%s"', trim($messages)));
		$this->assertSame(0, $stored, sprintf('the message was stored %d time(s)', $stored));
		$this->assertSame(0, $this->mailpit()->count(), 'emails were sent despite bad input');
	}
}
