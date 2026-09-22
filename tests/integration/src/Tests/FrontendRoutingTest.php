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
 * What the public side of the component answers to, beyond the form itself.
 *
 * Regression tests for b69505f (the form factory accessors were callable as controller tasks), the
 * thank-you pages, and what an unknown view does.
 *
 * @since 4.3.0
 */
class FrontendRoutingTest extends AbstractE2ETestCase
{
	/**
	 * Controller methods that are not tasks.
	 *
	 * @return  array<string, array{0: string}>
	 * @since   4.3.0
	 */
	public static function nonTasks(): array
	{
		return [
			'getFormFactory' => ['item.getFormFactory'],
			'setFormFactory' => ['item.setFormFactory'],
			'getModel'       => ['item.getModel'],
			'nonexistent'    => ['item.doesNotExist'],
		];
	}

	/**
	 * A public method of the controller that is not a task is not executed: the request falls through
	 * to the controller's default task and gets the ordinary form.
	 *
	 * Before b69505f, `task=item.setFormFactory` called the accessor with no argument — a PHP fatal
	 * error on a public URL.
	 *
	 * @param   string  $task  The task.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	#[DataProvider('nonTasks')]
	public function testNonTaskMethodsAreNotExecuted(string $task): void
	{
		$response = $this->guest()->get($this->siteUrl(['task' => $task]));

		$this->assertNotServerError($response);
		$this->assertStatus(200, $response);
		$this->assertBodyContains('name="jform[fromname]"', $response, 'Expected the default task: the contact form.');
	}

	/**
	 * The thank-you page, and its variant for messages Akismet flagged as spam.
	 *
	 * @return  array<string, array{0: array, 1: string}>
	 * @since   4.3.0
	 */
	public static function thankYouPages(): array
	{
		return [
			'thanks'  => [['view' => 'thanks'], 'card border-success'],
			'spammer' => [['view' => 'thanks', 'layout' => 'spammer'], 'card border-danger'],
		];
	}

	/**
	 * The thank-you pages render for anyone.
	 *
	 * @param   array   $query   The page.
	 * @param   string  $marker  Markup only that page has.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	#[DataProvider('thankYouPages')]
	public function testThankYouPagesRender(array $query, string $marker): void
	{
		$response = $this->guest()->get($this->siteUrl($query));

		$this->assertStatus(200, $response);
		$this->assertBodyContains($marker, $response);
	}

	/**
	 * A request for a view that does not exist is an ordinary 404, and it does not make the site log
	 * warnings about files the component does not ship.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testUnknownViewIsA404WithoutWarnings(): void
	{
		$response = $this->guest()->get($this->siteUrl(['view' => 'nosuchview']));

		$this->assertStatus(404, $response);

		$warnings = array_values(array_filter($this->newPhpErrors(), fn(string $line): bool => str_contains($line, 'errorhandler.php')));

		$this->assertOrKnownIssue(
			$warnings === [],
			10,
			'every front-end error makes the dispatcher include the removed commontemplates/errorhandler.php, logging two PHP warnings.'
		);
	}
}
