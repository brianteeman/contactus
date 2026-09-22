<?php
/**
 * @package   contactus
 * @copyright Copyright (c)2013-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Joomla\CMS;

defined('_JEXEC') or die;

/**
 * Stand-in for Joomla's Factory, for the unit tests only.
 *
 * VersionLimits::mayDiscloseVersions() calls Factory::getApplication()->isClient('site') and treats
 * ANY failure to answer as "the public site". This stub reproduces exactly those two outcomes and
 * nothing more: set $application to an object with isClient(), or leave it null to make
 * getApplication() throw, as the real Factory does before an application exists.
 *
 * @since 4.3.0
 */
abstract class Factory
{
	/**
	 * The "running" application, or null for none.
	 *
	 * @var   object|null
	 * @since 4.3.0
	 */
	public static $application = null;

	/**
	 * Get the running application.
	 *
	 * @return  object
	 * @throws  \Exception  When there is none, like the real Factory.
	 * @since   4.3.0
	 */
	public static function getApplication()
	{
		if (!self::$application)
		{
			throw new \Exception('Failed to start application', 500);
		}

		return self::$application;
	}
}
