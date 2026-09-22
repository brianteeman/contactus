<?php
/**
 * @package   contactus
 * @copyright Copyright (c)2013-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\ContactUs\IntegrationTest\Tests;

defined('_JEXEC') or die;

use Akeeba\ContactUs\IntegrationTest\AbstractE2ETestCase;
use Akeeba\ContactUs\IntegrationTest\Engine\Response;
use Akeeba\ContactUs\IntegrationTest\Engine\Surfer;

/**
 * In an unsupported environment the component refuses to run — and says why only to an administrator
 * (regression test for 8ef0aeb).
 *
 * VersionLimits throws from both dispatchers, and Joomla's error page prints the exception message
 * whatever the debug setting. The detailed message names the exact PHP and Joomla versions: the right
 * thing to tell an administrator, and a free CVE-matching fingerprint if told to an anonymous visitor.
 *
 * An unsupported environment is simulated the only way it can be without rebuilding the stack: by
 * lowering the maximum PHP version in the INSTALLED copy of VersionLimits, below the PHP the site is
 * actually running. The original file is always put back.
 *
 * @since 4.3.0
 */
class VersionLimitsDisclosureTest extends AbstractE2ETestCase
{
	/**
	 * The installed VersionLimits.php.
	 *
	 * @var   string
	 * @since 4.3.0
	 */
	private string $installedFile;

	/**
	 * Its original contents.
	 *
	 * @var   string|null
	 * @since 4.3.0
	 */
	private ?string $original = null;

	/**
	 * Remember the installed file.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->installedFile = static::$config->getSiteRoot()
			. '/administrator/components/com_contactus/src/Helper/VersionLimits.php';
		$this->original      = (string) file_get_contents($this->installedFile);
	}

	/**
	 * Put the installed file back, and wait until the site runs it again.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	protected function tearDown(): void
	{
		if ($this->original !== null)
		{
			$this->writeInstalledFile($this->original);

			$this->waitUntil(fn(): bool => !$this->componentRefusesToRun(), 'the site runs the restored VersionLimits');
		}

		parent::tearDown();
	}

	/**
	 * A visitor is told the component cannot run, and nothing about the versions.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testThePublicSiteGivesOnlyTheGenericMessage(): void
	{
		$this->simulateUnsupportedPhp();

		$surfer                  = $this->guest();
		$surfer->followRedirects = true;
		$response                = $surfer->get($this->siteUrl());

		$this->assertGreaterThanOrEqual(400, $response->code, "The component ran in an unsupported environment.\n" . $response->summary());
		$this->assertBodyNotContains('name="jform[fromname]"', $response, 'The contact form rendered anyway.');
		$this->assertBodyContains('cannot run in this environment', $response);
		$this->assertNoVersionsIn($response);
	}

	/**
	 * The administrator is told exactly what is wrong.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testTheAdministratorIsToldExactlyWhy(): void
	{
		$surfer = $this->superUser();

		$this->simulateUnsupportedPhp();

		$response = $surfer->get($this->adminUrl(['view' => 'items']));

		$this->assertGreaterThanOrEqual(400, $response->code, "The component ran in an unsupported environment.\n" . $response->summary());
		$this->assertBodyContains('lower than 8.0', $response);
		$this->assertBodyContains($this->runningPhpVersion(), $response);
	}

	/**
	 * Make the running PHP "too new". Called after any login, so that only the request under test can
	 * trip over it.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	private function simulateUnsupportedPhp(): void
	{
		$lowered = preg_replace(
			'/(private static string \$maxPHPVersion = )\'[0-9.]+\';/',
			"\$1'8.0';",
			$this->original,
			1,
			$count
		);

		$this->assertSame(1, $count, 'Could not find $maxPHPVersion in the installed VersionLimits.php.');

		$this->writeInstalledFile($lowered);

		$this->waitUntil(fn(): bool => $this->componentRefusesToRun(), 'the site runs the lowered VersionLimits');
	}

	/**
	 * Write the installed file with a modification time opcache cannot mistake for the previous one.
	 *
	 * @param   string  $contents  The new contents.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	private function writeInstalledFile(string $contents): void
	{
		clearstatcache(true, $this->installedFile);

		$mtime = max(time(), (int) filemtime($this->installedFile)) + 2;

		file_put_contents($this->installedFile, $contents);
		touch($this->installedFile, $mtime);
	}

	/**
	 * Does the public site currently refuse to run the component?
	 *
	 * @return  bool
	 * @since   4.3.0
	 */
	private function componentRefusesToRun(): bool
	{
		return str_contains($this->newGuest()->get($this->siteUrl())->body, 'cannot run in this environment');
	}

	/**
	 * Poll until a condition holds, or fail.
	 *
	 * @param   callable  $condition  The condition.
	 * @param   string    $what       For the failure message.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	private function waitUntil(callable $condition, string $what): void
	{
		$deadline = microtime(true) + 15;

		do
		{
			if ($condition())
			{
				return;
			}

			usleep(250000);
		}
		while (microtime(true) < $deadline);

		$this->fail('Timed out waiting until ' . $what . '.');
	}

	/**
	 * Neither the PHP nor the Joomla version is in the response.
	 *
	 * @param   Response  $response  The response.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	private function assertNoVersionsIn(Response $response): void
	{
		$this->assertBodyNotContains($this->runningPhpVersion(), $response, 'The public site was told the PHP version.');
		$this->assertBodyNotContains((string) static::$fixtures->getManifest()['joomla'], $response, 'The public site was told the Joomla version.');
	}

	/**
	 * The full PHP version the site runs, as VersionLimits would print it — read from the site's own
	 * PHP_VERSION by the in-container provisioner.
	 *
	 * @return  string
	 * @since   4.3.0
	 */
	private function runningPhpVersion(): string
	{
		return (string) static::$fixtures->getManifest()['php'];
	}
}
