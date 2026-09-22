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
 * Every way the contact form must say no.
 *
 * Each refusal is asserted twice over: the visitor is told no, AND nothing happened — no message
 * stored, no mail sent. The category checks are the regression tests for ce83818: the category
 * selector only DISPLAYS enabled categories the visitor may access, so without the server-side
 * re-validation a crafted POST could file a message under a disabled or access-restricted category and
 * mail its recipients.
 *
 * Each crafted request has a legitimate twin in ContactFormTest that succeeds, so these cannot pass
 * merely because the request was malformed.
 *
 * @since 4.3.0
 */
class SubmissionRefusalTest extends AbstractE2ETestCase
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
	 * Submissions the model must refuse: who submits, to which category, with what changed.
	 *
	 * @return  array<string, array{0: string|null, 1: string, 2: array}>
	 * @since   4.3.0
	 */
	public static function refusedSubmissions(): array
	{
		return [
			'no consent'                        => [null, 'general', ['consent' => null]],
			'disabled category'                 => [null, 'disabled', []],
			'Registered-only category, guest'   => [null, 'registered', []],
			'Special-only category, Registered' => ['alice', 'special', []],
			'no category'                       => [null, 'general', ['contactus_category_id' => null]],
			'empty name'                        => [null, 'general', ['fromname' => '']],
			'empty email'                       => [null, 'general', ['fromemail' => '']],
			'empty subject'                     => [null, 'general', ['subject' => '']],
			'empty body'                        => [null, 'general', ['body' => '']],
		];
	}

	/**
	 * The submission is refused, and neither stored nor mailed.
	 *
	 * @param   string|null  $role         Who submits; null for a guest.
	 * @param   string       $categoryKey  The category fixture.
	 * @param   array        $overrides    What differs from a valid submission.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	#[DataProvider('refusedSubmissions')]
	public function testRefusedAndNothingHappens(?string $role, string $categoryKey, array $overrides): void
	{
		$surfer   = $role === null ? $this->guest() : $this->loggedIn($role);
		$jform    = $this->contactData($categoryKey, $overrides);
		$before   = $this->messageCount();
		$response = $this->submitContact($surfer, $jform);

		$this->assertRefused($surfer, $response);
		$this->assertNothingHappened($before, $jform);
	}

	/**
	 * A category id that does not exist at all.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testNonexistentCategoryIsRefused(): void
	{
		$jform    = $this->contactData('general', ['contactus_category_id' => '999999']);
		$before   = $this->messageCount();
		$response = $this->submitContact($this->guest(), $jform);

		$this->assertRefused($this->guest(), $response);
		$this->assertNothingHappened($before, $jform);
	}

	/**
	 * No anti-CSRF token, and a well-formed but wrong one.
	 *
	 * @return  array<string, array{0: bool}>
	 * @since   4.3.0
	 */
	public static function badTokens(): array
	{
		return [
			'missing token'   => [false],
			'corrupted token' => [true],
		];
	}

	/**
	 * A submission without the session's anti-CSRF token is refused.
	 *
	 * @param   bool  $corrupt  Send a wrong token instead of none.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	#[DataProvider('badTokens')]
	public function testSubmissionWithoutValidTokenIsRefused(bool $corrupt): void
	{
		$surfer   = $this->guest();
		$token    = $corrupt ? $surfer->corruptToken($this->formToken($surfer)) : '';
		$jform    = $this->contactData('general');
		$before   = $this->messageCount();
		$response = $this->submitContact($surfer, $jform, $token);

		$this->assertRefused($surfer, $response);
		$this->assertNothingHappened($before, $jform);
	}

	/**
	 * While the component is offline, the form shows no fields and a submission is refused.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testOfflineFormAcceptsNothing(): void
	{
		$this->setComponentParams(['offline' => '1']);

		$surfer = $this->guest();
		$form   = $surfer->get($this->siteUrl());

		$this->assertStatus(200, $form);
		$this->assertBodyNotContains('name="jform[fromname]"', $form, 'The offline form must not offer the fields.');

		$jform    = $this->contactData('general');
		$before   = $this->messageCount();
		$response = $this->submitContact($surfer, $jform, $surfer->getFormToken($form->body));

		$this->assertRefused($surfer, $response);
		$this->assertNothingHappened($before, $jform);
	}

	/**
	 * A refused submission must send the visitor back to the form, with what they typed, and tell
	 * them why. Instead every refusal of ItemController::save() redirects to
	 * `index.php?option=com_contactus&view=Item.add` — a view that does not exist — so the visitor
	 * lands on an HTTP 404 error page carrying the message, and what they typed is gone from sight.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testRefusalReturnsToTheForm(): void
	{
		$surfer   = $this->guest();
		$jform    = $this->contactData('general', ['consent' => null]);
		$response = $this->submitContact($surfer, $jform);

		$this->assertRefused($surfer, $response);

		$landing = $this->follow($surfer, $response);

		$this->assertOrKnownIssue(
			$landing->code === 200 && str_contains($landing->body, 'name="jform[fromname]"'),
			4,
			sprintf('a refused submission lands on an HTTP %d error page instead of the form (redirect to view=Item.add).', $landing->code)
		);

		$this->assertBodyContains($jform['fromname'], $landing, 'The form must keep what the visitor typed.');
	}

	/**
	 * A submission without the consent checkbox must be refused without PHP warnings. Browsers do not
	 * send an unchecked checkbox at all, so this is what every "forgot to tick the box" looks like.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testMissingConsentRaisesNoPhpWarning(): void
	{
		$surfer   = $this->guest();
		$response = $this->submitContact($surfer, $this->contactData('general', ['consent' => null]));

		$this->assertRefused($surfer, $response);

		$warnings = array_values(array_filter(
			$this->newPhpErrors(),
			fn(string $line): bool => str_contains($line, 'com_contactus')
		));

		$this->assertOrKnownIssue(
			$warnings === [],
			10,
			"the site logged PHP warnings for an ordinary refusal:\n" . implode("\n", $warnings)
		);
	}

	/**
	 * Nothing was stored, and nothing was mailed.
	 *
	 * @param   int    $before  The message count before the request.
	 * @param   array  $jform   The submission.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	private function assertNothingHappened(int $before, array $jform): void
	{
		$this->assertSame($before, $this->messageCount(), 'A refused submission must not store a message.');
		$this->assertSame([], $this->messagesMatching($this->markerOf($jform)));
		$this->assertSame(0, $this->mailpit()->count(), 'A refused submission must not send any mail.');
	}
}
