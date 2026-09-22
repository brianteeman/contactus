<?php
/**
 * @package   contactus
 * @copyright Copyright (c)2013-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

use Isolated\Symfony\Component\Finder\Finder;

/*
 * PHP-Scoper configuration for the ezyang/htmlpurifier dependency.
 *
 * HTML Purifier ships plain, non-namespaced (underscore-prefixed) classes. Wrapping its files in our own
 * namespace, without renaming the classes themselves, is enough to stop it colliding with a different
 * version of the same library another extension on the same Joomla site might bundle globally: the two
 * become distinct fully-qualified class names even though their short names stay identical.
 *
 * Run with:
 *   php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/php-scoper add-prefix \
 *       --config=scoper.inc.php --output-dir=component/frontend/src/Dependency --force
 *
 * The generated files are committed to the repository; there is no build-time step that regenerates them.
 */
return [
	'prefix' => 'Akeeba\\Component\\ContactUs\\Site\\Dependency',

	'finders' => [
		Finder::create()
			->files()
			->in(__DIR__ . '/vendor/ezyang/htmlpurifier/library'),
	],

	// HTML Purifier resolves a handful of its internal classes (filters, injectors, definition cache
	// decorators, language packs, URI schemes, and its list of HTML module class prefixes) by building the
	// unqualified class name as a *string* at runtime and instantiating it dynamically (`new $class`).
	// PHP-Scoper rewrites literal `new ClassName()` / `ClassName::method()` references via the AST, but it
	// cannot see through string concatenation, so these dynamic lookups would otherwise keep looking for the
	// unprefixed, un-namespaced class and fail. Each patch below prepends the current (now-prefixed)
	// namespace, via the `__NAMESPACE__` magic constant so it never needs to hardcode the prefix, to the
	// fixed part of the class name string that's being built.
	'patchers' => [
		static function (string $filePath, string $prefix, string $contents): string {
			// Note: PHP-Scoper reprints interpolated strings via its AST printer, which normalises the
			// short `"$var"` interpolation form to the explicit `"{$var}"` form. The patches below match
			// that reprinted form, not the original source's.
			$patches = [
				'HTMLPurifier.php' => [
					'$class = "HTMLPurifier_Filter_{$filter}";'
					=> '$class = __NAMESPACE__ . "\\\\HTMLPurifier_Filter_{$filter}";',
				],
				'HTMLPurifier/DefinitionCacheFactory.php' => [
					'$class = "HTMLPurifier_DefinitionCache_Decorator_{$decorator}";'
					=> '$class = __NAMESPACE__ . "\\\\HTMLPurifier_DefinitionCache_Decorator_{$decorator}";',
				],
				'HTMLPurifier/HTMLModuleManager.php' => [
					"public \$prefixes = array('HTMLPurifier_HTMLModule_');"
					=> "public \$prefixes = array();",
					'$class = "HTMLPurifier_Injector_{$injector}";'
					=> '$class = __NAMESPACE__ . "\\\\HTMLPurifier_Injector_{$injector}";',
				],
				'HTMLPurifier/LanguageFactory.php' => [
					"\$class = 'HTMLPurifier_Language_' . \$pcode;"
					=> "\$class = __NAMESPACE__ . '\\\\HTMLPurifier_Language_' . \$pcode;",
				],
				'HTMLPurifier/Strategy/MakeWellFormed.php' => [
					'$injector = "HTMLPurifier_Injector_{$injector}";'
					=> '$injector = __NAMESPACE__ . "\\\\HTMLPurifier_Injector_{$injector}";',
				],
				'HTMLPurifier/URISchemeRegistry.php' => [
					"\$class = 'HTMLPurifier_URIScheme_' . \$scheme;"
					=> "\$class = __NAMESPACE__ . '\\\\HTMLPurifier_URIScheme_' . \$scheme;",
				],
				// These two build a ['ClassName', 'method'] callable using a hardcoded string literal
				// naming their own (unprefixed) class; __CLASS__ resolves to the correct, already-scoped
				// FQCN at compile time.
				'HTMLPurifier/Encoder.php' => [
					"array('HTMLPurifier_Encoder', 'muteErrorHandler')"
					=> "array(__CLASS__, 'muteErrorHandler')",
				],
				'HTMLPurifier/Lexer.php' => [
					"array('HTMLPurifier_Lexer', 'CDATACallback')"
					=> "array(__CLASS__, 'CDATACallback')",
				],
			];

			foreach ($patches as $suffix => $replacements) {
				if (!str_ends_with($filePath, '/library/' . $suffix)) {
					continue;
				}

				$contents = strtr($contents, $replacements);
			}

			// HTMLModuleManager::__construct() sets the class prefix array back up, now via __NAMESPACE__,
			// since the property default above was emptied out (property defaults must be constant
			// expressions in older supported PHP versions, so building the string in the constructor is
			// the more portable spot for it).
			if (str_ends_with($filePath, '/library/HTMLPurifier/HTMLModuleManager.php')) {
				$contents = preg_replace(
					'/(public function __construct\(\)\s*\{)/',
					"$1\n        \$this->prefixes[] = __NAMESPACE__ . '\\\\HTMLPurifier_HTMLModule_';",
					$contents,
					1
				);
			}

			// Every shipped PHP file in this Joomla component refuses direct web access, including bundled
			// third-party code — see the project's audit-jexec convention. Insert the guard right after the
			// namespace declaration PHP-Scoper adds to every processed file.
			$contents = preg_replace(
				'/^(namespace ' . preg_quote($prefix, '/') . '[^;]*;\n)/m',
				"$1\ndefined('_JEXEC') or die;\n",
				$contents,
				1
			);

			return $contents;
		},
	],

	'tag-declarations-as-internal' => false,

	'exclude-namespaces' => [],
	'exclude-classes' => [],
	'exclude-functions' => [],
	'exclude-constants' => [],

	// Nothing needs to stay unprefixed: this dependency is used exclusively by our own code, under our own
	// namespace, so every symbol HTML Purifier declares must be wrapped in our namespace.
	'expose-global-constants' => false,
	'expose-global-classes' => false,
	'expose-global-functions' => false,
	'expose-namespaces' => [],
	'expose-classes' => [],
	'expose-functions' => [],
	'expose-constants' => [],
];
