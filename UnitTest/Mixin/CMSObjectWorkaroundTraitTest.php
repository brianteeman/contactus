<?php
/**
 * @package   contactus
 * @copyright Copyright (c)2013-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\ContactUs\UnitTest\Mixin;

defined('_JEXEC') or die;

use Akeeba\Component\ContactUs\Administrator\Mixin\CMSObjectWorkaroundTrait;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * CMSObjectWorkaroundTrait bridges the two ways Joomla models report failure: legacy getError() on
 * older versions, exceptions on newer ones. The front-end save relies on it to turn either into a
 * message for the visitor, so both shapes must come out the same way.
 *
 * @since 4.3.0
 */
#[CoversTrait(CMSObjectWorkaroundTrait::class)]
class CMSObjectWorkaroundTraitTest extends TestCase
{
	/**
	 * A successful call returns its result and no errors.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testSuccess(): void
	{
		$callee = new class {
			public function save(array $data)
			{
				return $data['id'];
			}
		};

		$this->assertSame([7, '', []], $this->caller()->call($callee, 'save', ['id' => 7]));
	}

	/**
	 * A thrown exception becomes a false result carrying its message.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testExceptionBecomesAnError(): void
	{
		$callee = new class {
			public function save()
			{
				throw new RuntimeException('It broke');
			}
		};

		$this->assertSame([false, 'It broke', ['It broke']], $this->caller()->call($callee, 'save'));
	}

	/**
	 * A falsy result reports the legacy errors of the object, when it has any.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testLegacyErrorsAreReported(): void
	{
		$callee = new class {
			public function save()
			{
				return false;
			}

			public function getError()
			{
				return 'Legacy error';
			}

			public function getErrors()
			{
				return ['Legacy error', 'Another'];
			}
		};

		$this->assertSame([false, 'Legacy error', ['Legacy error', 'Another']], $this->caller()->call($callee, 'save'));
	}

	/**
	 * setErrorOrThrow() stores errors on a legacy object and throws on a modern one; empty errors are
	 * ignored.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testSetErrorOrThrow(): void
	{
		$legacy = new class {
			use CMSObjectWorkaroundTrait;

			public array $errors = [];

			public function setErrors(array $errors): void
			{
				$this->errors = $errors;
			}

			public function fail($errors): void
			{
				$this->setErrorOrThrow($errors);
			}
		};

		$legacy->fail('First');
		$this->assertSame(['First'], $legacy->errors);

		$legacy->fail(['One', 'Two']);
		$this->assertSame(['One', 'Two'], $legacy->errors);

		$legacy->errors = [];
		$legacy->fail('');
		$legacy->fail(['   ']);
		$this->assertSame([], $legacy->errors, 'Empty errors must be ignored.');

		$modern = $this->caller();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Modern error');

		$modern->fail('Modern error');
	}

	/**
	 * Anything other than a string or an array is a programming error.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testSetErrorOrThrowRejectsOtherTypes(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		$this->caller()->fail(42);
	}

	/**
	 * An object using the trait, exposing its protected methods.
	 *
	 * @return  object
	 * @since   4.3.0
	 */
	private function caller(): object
	{
		return new class {
			use CMSObjectWorkaroundTrait;

			public function call(object $object, string $method, ...$arguments): array
			{
				return $this->cmsObjectSafeCall($object, $method, ...$arguments);
			}

			public function fail($errors): void
			{
				$this->setErrorOrThrow($errors);
			}
		};
	}
}
