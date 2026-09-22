<?php
/**
 * @package   contactus
 * @copyright Copyright (c)2013-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

// Required for classes which guard against direct web access.
define('_JEXEC', 1);

/**
 * The Joomla version the version-limit tests compare against. No Joomla is loaded: this only stands in
 * for the constant VersionLimits reads.
 */
define('JVERSION', '6.1.3');

/**
 * PSR-4 autoloading for the code under test and the tests themselves.
 *
 * Not Composer's: the project has no Composer dependencies, vendor/ is not committed, and `composer install`
 * must not be a prerequisite of running the unit tests.
 */
spl_autoload_register(
	static function (string $class): void {
		$prefixes = [
			'Akeeba\\Component\\ContactUs\\Administrator\\' => __DIR__ . '/../component/backend/src/',
			'Akeeba\\Component\\ContactUs\\Site\\'          => __DIR__ . '/../component/frontend/src/',
			'Akeeba\\ContactUs\\UnitTest\\'                   => __DIR__ . '/',
		];

		foreach ($prefixes as $prefix => $dir)
		{
			if (strncmp($class, $prefix, strlen($prefix)) !== 0)
			{
				continue;
			}

			$file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

			if (is_file($file))
			{
				require_once $file;
			}

			return;
		}
	}
);

/**
 * A stand-in for Joomla's Factory, reduced to the one thing VersionLimits asks of it: which application
 * is running. See the file for why a stand-in is enough.
 */
require_once __DIR__ . '/Stubs/Factory.php';

// Enable verbose error and notices
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Set the timezone to UTC to avoid surprises.
@date_default_timezone_set('UTC');
