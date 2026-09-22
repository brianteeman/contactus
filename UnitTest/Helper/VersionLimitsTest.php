<?php
/**
 * @package   contactus
 * @copyright Copyright (c)2013-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\ContactUs\UnitTest\Helper;

defined('_JEXEC') or die;

use Akeeba\Component\ContactUs\Administrator\Helper\VersionLimits;
use Akeeba\ContactUs\UnitTest\KnownIssueTrait;
use Joomla\CMS\Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * VersionLimits, the runtime guard both dispatchers call, and its agreement with the installer and
 * composer.json.
 *
 * The supported range is declared once, in composer.json's extra.akcompat, and stamped into the
 * installer script and VersionLimits by the release tooling. If any of them drifts, the installer, the
 * runtime and the build disagree about what a supported site is.
 *
 * @since 4.3.0
 */
#[CoversClass(VersionLimits::class)]
class VersionLimitsTest extends TestCase
{
	use KnownIssueTrait;

	/**
	 * The original static state of VersionLimits, restored after each test.
	 *
	 * @var   array<string, mixed>
	 * @since 4.3.0
	 */
	private array $saved = [];

	/**
	 * Set up: remember VersionLimits' static state.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	protected function setUp(): void
	{
		foreach (['minPHPVersion', 'maxPHPVersion', 'minJoomlaVersion', 'maxJoomlaVersion', 'incompatibleReason'] as $property)
		{
			$this->saved[$property] = $this->getStatic($property);
		}
	}

	/**
	 * Tear down: restore VersionLimits' static state, and "stop" any application a test started.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	protected function tearDown(): void
	{
		foreach ($this->saved as $property => $value)
		{
			$this->setStatic($property, $value);
		}

		Factory::$application = null;
	}

	/**
	 * The declared bounds match composer.json and the installer script.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testBoundsMatchComposerAndInstaller(): void
	{
		$root     = \dirname(__DIR__, 2);
		$composer = json_decode(file_get_contents($root . '/composer.json'), true);
		$script   = file_get_contents($root . '/component/script.contactus.php');

		$this->assertTrue(
			(bool) preg_match('/^>=\s*([0-9.]+)\s+<\s*([0-9.]+)$/', $composer['require']['php'], $php),
			'composer.json require.php is not ">=MIN <MAX".'
		);
		$this->assertTrue(
			(bool) preg_match('/^>=\s*([0-9.]+)\s+<\s*([0-9.]+)$/', $composer['extra']['akcompat']['limit'], $joomla),
			'composer.json extra.akcompat.limit is not ">=MIN <MAX".'
		);

		$installer = fn(string $name): ?string => preg_match('/\$' . $name . '\s*=\s*\'([0-9.]+)\'/', $script, $m) ? $m[1] : null;

		$this->assertSame($php[1], $this->getStatic('minPHPVersion'), 'VersionLimits minimum PHP');
		$this->assertSame($php[2], $this->getStatic('maxPHPVersion'), 'VersionLimits maximum PHP');
		$this->assertSame($joomla[1], $this->getStatic('minJoomlaVersion'), 'VersionLimits minimum Joomla');
		$this->assertSame($joomla[2], $this->getStatic('maxJoomlaVersion'), 'VersionLimits maximum Joomla');

		$this->assertSame($php[1], $installer('minimumPhp'), 'installer minimum PHP');
		$this->assertSame($php[2], $installer('maximumPhp'), 'installer maximum PHP');
		$this->assertSame($joomla[1], $installer('minimumJoomla'), 'installer minimum Joomla');
		$this->assertSame($joomla[2], $installer('maximumJoomla'), 'installer maximum Joomla');

		// The build pins the platform inside the supported range.
		$this->assertTrue(
			version_compare($composer['config']['platform']['php'], $php[1], 'ge')
			&& version_compare($composer['config']['platform']['php'], $php[2], 'lt'),
			'composer.json config.platform.php is outside the supported range.'
		);
	}

	/**
	 * composer.lock was generated for the composer.json it sits next to. A stale lock makes `phing git`
	 * fail at `composer install`, i.e. no package can be built at all.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testComposerLockMatchesComposerJson(): void
	{
		$root     = \dirname(__DIR__, 2);
		$composer = json_decode(file_get_contents($root . '/composer.json'), true);
		$lock     = json_decode(file_get_contents($root . '/composer.lock'), true);

		$lockPlatform = $lock['platform-overrides']['php'] ?? null;
		$lockPhp      = $lock['platform']['php'] ?? null;

		$this->assertOrKnownIssue(
			$lockPlatform === ($composer['config']['platform']['php'] ?? null) && $lockPhp === $composer['require']['php'],
			5,
			sprintf(
				'composer.lock is stale (platform override %s, PHP requirement %s; composer.json says %s and %s), so `phing git` fails at `composer install`. Run `composer update --lock`.',
				var_export($lockPlatform, true),
				var_export($lockPhp, true),
				$composer['config']['platform']['php'] ?? 'none',
				$composer['require']['php']
			)
		);
	}

	/**
	 * Versions inside, at and outside the bounds. The maxima are exclusive.
	 *
	 * @return  array<string, array{0: string, 1: string, 2: string, 3: string, 4: bool, 5: string}>
	 * @since   4.3.0
	 */
	public static function rangeCases(): array
	{
		// minPHP, maxPHP, minJoomla, maxJoomla (all relative to PHP_VERSION and the bootstrap's JVERSION 6.1.3), compatible, why
		return [
			'inside'             => ['8.0.0', '99.0', '5.4.0', '6.3', true, ''],
			'PHP at the minimum' => [PHP_VERSION, '99.0', '5.4.0', '6.3', true, ''],
			'PHP too low'        => ['99.0.0', '99.9', '5.4.0', '6.3', false, 'requires PHP 99.0.0'],
			'PHP at the maximum' => ['8.0.0', PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, '5.4.0', '6.3', false, 'only compatible with PHP versions lower than'],
			'Joomla at minimum'  => ['8.0.0', '99.0', '6.1.3', '6.3', true, ''],
			'Joomla too low'     => ['8.0.0', '99.0', '6.2.0', '6.3', false, 'requires Joomla 6.2.0'],
			'Joomla at maximum'  => ['8.0.0', '99.0', '5.4.0', '6.1', false, 'only compatible with Joomla versions lower than 6.1'],
		];
	}

	/**
	 * isCompatible() and throwIfVersionsIncompatible() agree, and — to an administrator — the exception
	 * says what is wrong.
	 *
	 * @param   string  $minPhp      Minimum PHP.
	 * @param   string  $maxPhp      Exclusive maximum PHP.
	 * @param   string  $minJoomla   Minimum Joomla.
	 * @param   string  $maxJoomla   Exclusive maximum Joomla.
	 * @param   bool    $compatible  Expected result.
	 * @param   string  $message     Expected fragment of the exception message.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	#[DataProvider('rangeCases')]
	public function testRange(string $minPhp, string $maxPhp, string $minJoomla, string $maxJoomla, bool $compatible, string $message): void
	{
		$this->setLimits($minPhp, $maxPhp, $minJoomla, $maxJoomla);
		$this->runApplication('administrator');

		$this->assertSame($compatible, VersionLimits::isCompatible());

		if ($compatible)
		{
			VersionLimits::throwIfVersionsIncompatible();

			return;
		}

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage($message);

		VersionLimits::throwIfVersionsIncompatible();
	}

	/**
	 * Where the versions may and may not be disclosed: the public site and "no application at all" get
	 * the generic message; the administrator and the CLI get the detail (regression test for 8ef0aeb).
	 *
	 * @return  array<string, array{0: string|null, 1: bool}>
	 * @since   4.3.0
	 */
	public static function clients(): array
	{
		return [
			'public site'      => ['site', false],
			'no application'   => [null, false],
			'administrator'    => ['administrator', true],
			'command line'     => ['cli', true],
		];
	}

	/**
	 * The versions are named to administrators only.
	 *
	 * @param   string|null  $client    The running application, or null for none.
	 * @param   bool         $disclose  Should the message name the versions?
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	#[DataProvider('clients')]
	public function testVersionsAreDisclosedOnlyToAdministrators(?string $client, bool $disclose): void
	{
		// PHP too new: the message would name PHP_VERSION.
		$this->setLimits('8.0.0', '8.0', '5.4.0', '6.3');

		if ($client !== null)
		{
			$this->runApplication($client);
		}

		try
		{
			VersionLimits::throwIfVersionsIncompatible();

			$this->fail('An incompatible environment did not throw.');
		}
		catch (RuntimeException $e)
		{
			$message = $e->getMessage();
		}

		if ($disclose)
		{
			$this->assertStringContainsString(PHP_VERSION, $message);

			return;
		}

		$this->assertStringNotContainsString(PHP_VERSION, $message);
		$this->assertStringNotContainsString(JVERSION, $message);
		$this->assertStringContainsString('cannot run in this environment', $message);
	}

	/**
	 * Set the four bounds, and clear the cached verdict.
	 *
	 * @param   string  $minPhp     Minimum PHP.
	 * @param   string  $maxPhp     Exclusive maximum PHP.
	 * @param   string  $minJoomla  Minimum Joomla.
	 * @param   string  $maxJoomla  Exclusive maximum Joomla.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	private function setLimits(string $minPhp, string $maxPhp, string $minJoomla, string $maxJoomla): void
	{
		$this->setStatic('minPHPVersion', $minPhp);
		$this->setStatic('maxPHPVersion', $maxPhp);
		$this->setStatic('minJoomlaVersion', $minJoomla);
		$this->setStatic('maxJoomlaVersion', $maxJoomla);
		// The results are cached per request; start from a clean cache.
		$this->setStatic('incompatibleReason', []);
	}

	/**
	 * Make the stub Factory report a running application of the given client.
	 *
	 * @param   string  $client  'site', 'administrator', 'cli', …
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	private function runApplication(string $client): void
	{
		Factory::$application = new class ($client) {
			public function __construct(private string $client)
			{
			}

			public function isClient(string $identifier): bool
			{
				return $identifier === $this->client;
			}
		};
	}

	/**
	 * Read a private static property of VersionLimits.
	 *
	 * @param   string  $name  The property.
	 *
	 * @return  mixed
	 * @since   4.3.0
	 */
	private function getStatic(string $name)
	{
		return (new ReflectionClass(VersionLimits::class))->getProperty($name)->getValue();
	}

	/**
	 * Write a private static property of VersionLimits.
	 *
	 * @param   string  $name   The property.
	 * @param   mixed   $value  The value.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	private function setStatic(string $name, $value): void
	{
		(new ReflectionClass(VersionLimits::class))->getProperty($name)->setValue(null, $value);
	}
}
