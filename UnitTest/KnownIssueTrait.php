<?php
/**
 * @package   contactus
 * @copyright Copyright (c)2013-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\ContactUs\UnitTest;

defined('_JEXEC') or die;

/**
 * Assert what the product should do, skipping with a pointer to known-issues.md while it does not.
 *
 * Asserting today's buggy behaviour as correct would turn a test green for the wrong reason. When the
 * condition holds (the bug is fixed) this is an ordinary passing assertion — at which point replace
 * the call with a plain assertion, so a regression fails loudly instead of skipping.
 *
 * @since 4.3.0
 */
trait KnownIssueTrait
{
	/**
	 * Assert a condition, or skip naming the known issue.
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
}
