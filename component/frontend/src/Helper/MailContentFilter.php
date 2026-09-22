<?php
/**
 * @package   contactus
 * @copyright Copyright (c)2013-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\ContactUs\Site\Helper;

defined('_JEXEC') || die;

use Akeeba\Component\ContactUs\Site\Dependency\HTMLPurifier;
use Akeeba\Component\ContactUs\Site\Dependency\HTMLPurifier_Config;

/**
 * Sanitises visitor-submitted content before it is embedded in an outgoing email.
 *
 * The message body is stored (and re-displayed in the backend) with whatever HTML the configured Joomla text
 * filter for the submitting user's group let through; on sites which loosen the Public/Guest filter this can be
 * attacker-controlled markup. Emails are sent from the site's own trusted address, so that markup must be purified
 * again before it goes out, independently of the text filter applied at submission time.
 *
 * The bundled ezyang/htmlpurifier copy under Dependency/ is namespaced with PHP-Scoper (see scoper.inc.php at
 * the repository root) so it cannot collide with a different version of the same library another extension on
 * the same site might load into the (unavoidably global) HTMLPurifier_* class names.
 */
class MailContentFilter
{
	private const DEPENDENCY_NAMESPACE = 'Akeeba\\Component\\ContactUs\\Site\\Dependency\\';

	/**
	 * Purifies visitor-submitted HTML for safe inclusion in the body of an outgoing email.
	 *
	 * @param   string  $html  The raw, stored message body.
	 *
	 * @return  string  Purified HTML safe to hand to msgHTML().
	 */
	public static function purifyBody(string $html): string
	{
		self::registerDependencyAutoloader();

		$config = HTMLPurifier_Config::createDefault();
		$config->set('Core.Encoding', 'UTF-8');
		$config->set('HTML.Doctype', 'HTML 4.01 Transitional');
		$config->set(
			'HTML.Allowed',
			'p,br,b,strong,i,em,u,a[href],ul,ol,li,blockquote,code,pre'
		);
		// Never touch the filesystem: this is a one-off sanitisation of a single message, not worth caching.
		$config->set('Cache.DefinitionImpl', null);

		return (new HTMLPurifier($config))->purify($html);
	}

	/**
	 * Registers an autoloader for the namespaced HTML Purifier copy.
	 *
	 * HTML Purifier's classes keep their original underscore-separated names (e.g. HTMLPurifier_Config, only
	 * wrapped in our namespace, not renamed) which PHP-Scoper left untouched, so Joomla's PSR-4 component
	 * autoloader cannot resolve them: PSR-4 only maps namespace *segments* (backslashes) to directories, and
	 * these class names have none below our own namespace. This mirrors the mapping HTML Purifier's own
	 * (unused here) HTMLPurifier.autoload.php / HTMLPurifier_Bootstrap::getPath() applies.
	 *
	 * @return  void
	 */
	private static function registerDependencyAutoloader(): void
	{
		static $registered = false;

		if ($registered)
		{
			return;
		}

		$registered = true;
		$baseDir    = __DIR__ . '/../Dependency/';

		// Defines the HTMLPURIFIER_PREFIX constant (also namespaced) that the library uses to find its own
		// data files (e.g. the config schema and HTML entity tables).
		require_once $baseDir . 'HTMLPurifier.composer.php';

		spl_autoload_register(
			static function (string $class) use ($baseDir): void {
				if (!str_starts_with($class, self::DEPENDENCY_NAMESPACE))
				{
					return;
				}

				$relative = substr($class, strlen(self::DEPENDENCY_NAMESPACE));
				$path     = $baseDir . str_replace('_', '/', $relative) . '.php';

				if (is_file($path))
				{
					require_once $path;
				}
			}
		);
	}
}
