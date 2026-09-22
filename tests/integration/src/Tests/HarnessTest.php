<?php
/**
 * @package   contactus
 * @copyright Copyright (c)2013-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\ContactUs\IntegrationTest\Tests;

defined('_JEXEC') or die;

use Akeeba\ContactUs\IntegrationTest\AbstractE2ETestCase;

/**
 * The harness checks itself before anything relies on it.
 *
 * A permissions test that passes because the fixture accidentally granted the wrong thing is worse
 * than no test at all, so the ACL matrix the other tests lean on is asserted here, through the real
 * site's own authorisation code (the identity probe), not assumed from what the provisioner meant to
 * do.
 *
 * @since 4.3.0
 */
class HarnessTest extends AbstractE2ETestCase
{
	/**
	 * The site is up, and the version it reports is the one run.sh says it installed.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testSiteIsTheOneWeProvisioned(): void
	{
		$identity = $this->session->probeIdentity($this->guest());

		$this->assertIsArray($identity, 'The identity probe did not answer.');
		$this->assertTrue($identity['guest'], 'A fresh surfer must be a guest.');

		$expected = static::$config->getJoomlaVersion();

		if ($expected !== '0.0.0')
		{
			$this->assertStringStartsWith($expected, (string) $identity['joomla']);
		}
	}

	/**
	 * ContactUs is installed, enabled, and running the baseline parameters.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testComponentIsInstalled(): void
	{
		$row = $this->db()->row("SELECT enabled, params FROM #__extensions WHERE type = 'component' AND element = 'com_contactus'");

		$this->assertNotNull($row, 'com_contactus is not installed.');
		$this->assertSame(1, (int) $row['enabled']);
		$this->assertSame('', json_decode($row['params'], true)['akismet_api_key'] ?? null, 'The baseline must not call Akismet.');
		$this->assertSame(5, (int) $this->db()->value('SELECT COUNT(*) FROM #__contactus_categories'));
	}

	/**
	 * Every role logs in and is who it claims to be, and the ACL matrix is what the fixture claims.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testAclMatrixIsWhatItClaims(): void
	{
		$actions = ['core.manage', 'core.create', 'core.edit', 'core.edit.state', 'core.delete', 'core.options', 'core.admin'];

		// role => [manage, create, edit, edit.state, delete, options, admin] on com_contactus
		$expected = [
			'alice'    => [false, false, false, false, false, false, false],
			'viewer'   => [true, false, false, false, false, false, false],
			'nomanage' => [false, true, true, true, true, false, false],
		];

		foreach ($expected as $role => $grants)
		{
			$identity = $this->session->probeIdentity($this->loggedIn($role), ['com_contactus'], $actions);

			$this->assertIsArray($identity, sprintf('The identity probe did not answer for %s.', $role));
			$this->assertSame(static::$fixtures->username($role), $identity['username']);

			foreach ($actions as $i => $action)
			{
				$this->assertSame(
					$grants[$i],
					$identity['authorise']['com_contactus'][$action],
					sprintf('Role %s: %s on com_contactus should be %s.', $role, $action, $grants[$i] ? 'granted' : 'denied')
				);
			}
		}

		// The viewer can log into the back-end; alice cannot.
		$viewer = $this->session->probeIdentity($this->loggedIn('viewer'), ['root.1'], ['core.login.admin']);
		$alice  = $this->session->probeIdentity($this->loggedIn('alice'), ['root.1'], ['core.login.admin']);

		$this->assertTrue($viewer['authorise']['root.1']['core.login.admin'], 'viewer must be able to log into the back-end.');
		$this->assertFalse($alice['authorise']['root.1']['core.login.admin'], 'alice must not be able to log into the back-end.');

		// nomanage is refused by the explicit Deny, not by never having had the privilege: on any other
		// component it has core.manage, inherited like every Administrator's.
		$identity = $this->session->probeIdentity($this->loggedIn('nomanage'), ['com_users'], ['core.manage']);
		$this->assertTrue($identity['authorise']['com_users']['core.manage'], 'nomanage must inherit core.manage elsewhere.');
	}

	/**
	 * The view levels behind the category fixtures: a guest has Public only; alice adds Registered but
	 * not Special.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testViewLevels(): void
	{
		$guest = $this->session->probeIdentity($this->guest());
		$alice = $this->session->probeIdentity($this->loggedIn('alice'));

		$this->assertNotContains(2, $guest['viewLevels']);
		$this->assertContains(2, $alice['viewLevels']);
		$this->assertNotContains(3, $alice['viewLevels']);
	}

	/**
	 * Mailpit answers.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testMailpitIsUp(): void
	{
		$this->assertTrue($this->mailpit()->isAvailable(), 'Mailpit is not reachable.');
	}
}
