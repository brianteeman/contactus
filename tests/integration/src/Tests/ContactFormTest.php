<?php
/**
 * @package   contactus
 * @copyright Copyright (c)2013-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\ContactUs\IntegrationTest\Tests;

defined('_JEXEC') or die;

use Akeeba\ContactUs\IntegrationTest\AbstractE2ETestCase;
use Akeeba\ContactUs\IntegrationTest\Engine\Mailpit;
use Akeeba\ContactUs\IntegrationTest\Engine\Response;

/**
 * The public contact form, when everything goes right.
 *
 * A submission is three effects, and each is asserted: a stored message, a notification to every
 * recipient of the chosen category (from the site, with Reply-To set to the visitor), and — only where
 * the category asks for one — an auto-reply to the visitor with its placeholders filled in.
 *
 * @since 4.3.0
 */
class ContactFormTest extends AbstractE2ETestCase
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
	 * A guest sees the form, with its token and consent checkbox, offering only the enabled, public
	 * categories.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testGuestSeesOnlyPublicEnabledCategories(): void
	{
		$response = $this->guest()->get($this->siteUrl());

		$this->assertStatus(200, $response);
		$this->assertNotNull($this->guest()->getFormToken($response->body), 'The form carries no anti-CSRF token.');
		$this->assertBodyContains('name="jform[consent]"', $response);
		$this->assertSame(['E2E General', 'E2E No Autoreply'], array_values($this->categoryOptions($response)));
	}

	/**
	 * A Registered user additionally sees the Registered-only category, and still not the Special one.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testRegisteredUserSeesRegisteredCategory(): void
	{
		$options = $this->categoryOptions($this->loggedIn('alice')->get($this->siteUrl()));

		$this->assertContains('E2E Registered Only', $options);
		$this->assertNotContains('E2E Special Only', $options);
		$this->assertNotContains('E2E Disabled', $options);
	}

	/**
	 * The privacy policy link in the consent help text is the configured URL.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testPrivacyPolicyLink(): void
	{
		$response = $this->guest()->get($this->siteUrl());

		$this->assertBodyContains('href="https://example.test/privacy.html"', $response);
	}

	/**
	 * A guest's submission is stored, the category's recipients are notified, and the visitor gets the
	 * auto-reply with every placeholder replaced.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testGuestSubmissionIsStoredAndMailed(): void
	{
		$jform    = $this->contactData('general');
		$marker   = $this->markerOf($jform);
		$response = $this->submitContact($this->guest(), $jform);

		$this->assertAccepted($response);

		// The thank-you page renders.
		$thanks = $this->follow($this->guest(), $response);
		$this->assertStatus(200, $thanks);

		// Stored, exactly once, as submitted.
		$rows = $this->messagesMatching($marker);
		$this->assertCount(1, $rows, 'The message must be stored exactly once.');
		$this->assertSame(static::$fixtures->categoryId('general'), (int) $rows[0]['contactus_category_id']);
		$this->assertSame($jform['fromname'], $rows[0]['fromname']);
		$this->assertSame($jform['fromemail'], $rows[0]['fromemail']);
		$this->assertSame($jform['subject'], $rows[0]['subject']);
		$this->assertSame($jform['body'], $rows[0]['body']);

		$this->mailpit()->waitForMessages(2);

		// The notification: to every recipient of the category, from the site, Reply-To the visitor.
		$notification = $this->mailWithSubjectContaining($jform['subject'], static::$fixtures->recipients('general')[0]);
		$recipients   = Mailpit::recipientsOf($notification);
		sort($recipients);
		$expected = static::$fixtures->recipients('general');
		sort($expected);

		$this->assertSame($expected, $recipients, 'The notification must go to every recipient of the category, and only to them.');
		$this->assertSame(static::$config->get('mail.from'), $notification['From']['Address'] ?? null);
		$this->assertSame($jform['fromemail'], $notification['ReplyTo'][0]['Address'] ?? null, 'Reply-To must be the visitor.');
		$this->assertStringContainsString('E2E General', $notification['Subject']);
		$this->assertStringContainsString(static::$config->get('site_name'), $notification['Subject']);
		$this->assertStringContainsString($jform['body'], $notification['Text'] . $notification['HTML']);

		// The auto-reply: to the visitor only, placeholders replaced.
		$autoReplies = $this->mailpit()->messagesTo($jform['fromemail']);
		$this->assertCount(1, $autoReplies, 'Exactly one auto-reply must reach the visitor.');

		$autoReply = $this->mailpit()->message($autoReplies[0]['ID']);
		$html      = $autoReply['HTML'];

		$this->assertSame([strtolower($jform['fromemail'])], Mailpit::recipientsOf($autoReply));
		$this->assertStringContainsString('Dear ' . $jform['fromname'], $html);
		$this->assertStringContainsString(static::$config->get('site_name'), $html);
		$this->assertStringContainsString('about E2E General', $html);
		$this->assertStringContainsString($jform['subject'], $html);
		$this->assertDoesNotMatchRegularExpression('/\[[A-Z_]+\]/', $html, 'A placeholder was left unreplaced.');

		// Nobody else was mailed.
		$this->assertCount(2, $this->mailpit()->list(), 'Only the notification and the auto-reply may be sent.');
	}

	/**
	 * A category without an auto-reply notifies its recipient and mails nothing to the visitor.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testCategoryWithoutAutoReplyMailsOnlyTheRecipients(): void
	{
		$jform    = $this->contactData('noreply');
		$response = $this->submitContact($this->guest(), $jform);

		$this->assertAccepted($response);
		$this->assertCount(1, $this->messagesMatching($this->markerOf($jform)));

		$this->mailpit()->waitForMessages(1);

		$messages = $this->mailpit()->list();
		$this->assertCount(1, $messages, 'Only the notification may be sent.');
		$this->assertSame(static::$fixtures->recipients('noreply'), Mailpit::recipientsOf($messages[0]));
		$this->assertSame([], $this->mailpit()->messagesTo($jform['fromemail']), 'No auto-reply for this category.');
	}

	/**
	 * A Registered user may submit to the Registered-only category. The control for the guest's
	 * refusal in SubmissionRefusalTest: the category itself works.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testRegisteredUserCanUseRegisteredCategory(): void
	{
		$jform    = $this->contactData('registered');
		$response = $this->submitContact($this->loggedIn('alice'), $jform);

		$this->assertAccepted($response);
		$this->assertCount(1, $this->messagesMatching($this->markerOf($jform)));

		$this->mailpit()->waitForMessages(1);
		$this->assertSame(static::$fixtures->recipients('registered'), Mailpit::recipientsOf($this->mailpit()->list()[0]));
	}

	/**
	 * Two submissions are two messages: the second never overwrites the first.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testEverySubmissionIsANewMessage(): void
	{
		$first  = $this->contactData('general');
		$second = $this->contactData('general');

		$this->assertAccepted($this->submitContact($this->guest(), $first));
		$this->assertAccepted($this->submitContact($this->guest(), $second));

		$this->assertCount(1, $this->messagesMatching($this->markerOf($first)));
		$this->assertCount(1, $this->messagesMatching($this->markerOf($second)));
	}

	/**
	 * The category titles offered by the form's category selector, value => title.
	 *
	 * @param   Response  $response  The rendered form.
	 *
	 * @return  array<int, string>
	 * @since   4.3.0
	 */
	private function categoryOptions(Response $response): array
	{
		$this->assertTrue(
			(bool) preg_match('/<select\b[^>]*name="jform\[contactus_category_id\]"[^>]*>(.*?)<\/select>/s', $response->body, $select),
			"The form has no category selector.\n" . $response->summary()
		);

		preg_match_all('/<option\b[^>]*value="(\d+)"[^>]*>([^<]*)<\/option>/', $select[1], $options);

		return array_combine(array_map('intval', $options[1]), array_map('trim', $options[2]));
	}

	/**
	 * The full message whose subject contains a string and which went to a given recipient.
	 *
	 * @param   string  $needle     Part of the subject.
	 * @param   string  $recipient  One of its recipients.
	 *
	 * @return  array
	 * @since   4.3.0
	 */
	private function mailWithSubjectContaining(string $needle, string $recipient): array
	{
		foreach ($this->mailpit()->messagesTo($recipient) as $summary)
		{
			if (str_contains($summary['Subject'] ?? '', $needle))
			{
				return $this->mailpit()->message($summary['ID']);
			}
		}

		$this->fail(sprintf('No mail to %s with "%s" in its subject.', $recipient, $needle));
	}
}
