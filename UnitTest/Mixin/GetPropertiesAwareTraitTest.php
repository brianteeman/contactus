<?php
/**
 * @package   contactus
 * @copyright Copyright (c)2013-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\ContactUs\UnitTest\Mixin;

defined('_JEXEC') or die;

use Akeeba\Component\ContactUs\Administrator\Mixin\GetPropertiesAwareTrait;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

/**
 * GetPropertiesAwareTrait replaces CMSObject::getProperties() for the component's tables.
 *
 * Joomla binds, checks and stores a table through what getProperties() returns, so it must return the
 * table's columns and nothing private: the table's database driver, dispatcher and the like must never
 * be treated as columns.
 *
 * @since 4.3.0
 */
#[CoversTrait(GetPropertiesAwareTrait::class)]
class GetPropertiesAwareTraitTest extends TestCase
{
	/**
	 * Only public properties, by default — including null and empty ones; everything, when asked.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testPublicPropertiesOnly(): void
	{
		$object = new class {
			use GetPropertiesAwareTrait;

			public $contactus_item_id = 42;

			public $subject = 'Hello';

			public $body = '';

			public $locked_on = null;

			protected $_db = 'secret-driver';

			private $_secret = 'hidden';
		};

		$public = $object->getProperties();

		$this->assertSame(['contactus_item_id' => 42, 'subject' => 'Hello', 'body' => '', 'locked_on' => null], $public);

		$all = $object->getProperties(false);

		$this->assertCount(6, $all, 'getProperties(false) must include protected and private members.');
	}

	/**
	 * Dynamic properties (the tables allow them) are public properties too.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testDynamicPropertiesAreIncluded(): void
	{
		$object = new #[\AllowDynamicProperties] class {
			use GetPropertiesAwareTrait;

			public $title = 'Category';
		};

		$object->email = ['a@example.test'];

		$this->assertSame(['title' => 'Category', 'email' => ['a@example.test']], $object->getProperties());
	}
}
