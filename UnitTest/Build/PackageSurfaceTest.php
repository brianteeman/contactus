<?php
/**
 * @package   contactus
 * @copyright Copyright (c)2013-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\ContactUs\UnitTest\Build;

defined('_JEXEC') or die;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * What the package ships, checked from the source tree.
 *
 * The manifests are generated at build time from build/templates/, so those templates are what is
 * checked: a folder or language file they name but the tree lacks makes the installer fail on every
 * site; a custom form field without its addfieldprefix silently degrades to a text box.
 *
 * @since 4.3.0
 */
class PackageSurfaceTest extends TestCase
{
	/**
	 * Every shipped PHP file.
	 *
	 * @return  array<string, array{0: string}>
	 * @since   4.3.0
	 */
	public static function shippedPhpFiles(): array
	{
		$cases = [];

		foreach (self::filesUnder('component', 'php') as $relative => $path)
		{
			$cases[$relative] = [$path];
		}

		return $cases;
	}

	/**
	 * Every shipped PHP file refuses direct web access.
	 *
	 * @param   string  $path  The file.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	#[DataProvider('shippedPhpFiles')]
	public function testJexecGuard(string $path): void
	{
		$this->assertMatchesRegularExpression(
			'/defined\s*\(\s*[\'"]_JEXEC[\'"]\s*\)\s*(or|\|\|)\s*die/i',
			(string) file_get_contents($path),
			'No `defined(\'_JEXEC\') or die` guard.'
		);
	}

	/**
	 * Every file and folder the component manifest template names exists, and every media folder is
	 * installed.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testComponentManifestMatchesTheTree(): void
	{
		$component = self::root() . '/component';
		$manifest  = self::manifest('build/templates/contactus.xml');

		foreach ([['files', 'frontend'], ['administration/files', 'backend'], ['media', 'media']] as [$path, $default])
		{
			$node   = $manifest->xpath($path)[0];
			$folder = $component . '/' . ((string) $node['folder'] ?: $default);

			foreach ($node->folder as $item)
			{
				$this->assertDirectoryExists($folder . '/' . $item, sprintf('<%s> names a folder that does not exist.', $path));
			}

			foreach ($node->filename as $item)
			{
				$this->assertFileExists($folder . '/' . $item, sprintf('<%s> names a file that does not exist.', $path));
			}
		}

		foreach (['languages', 'administration/languages'] as $path)
		{
			$node = $manifest->xpath($path)[0];

			foreach ($node->language as $language)
			{
				$this->assertFileExists($component . '/' . $node['folder'] . '/' . $language, sprintf('<%s> names a language file that does not exist.', $path));
			}
		}

		foreach ($manifest->xpath('//sql/file | //schemas/schemapath') as $sql)
		{
			$this->assertFileExists($component . '/backend/' . $sql, 'The manifest names SQL that does not exist.');
		}

		$media    = $manifest->xpath('media')[0];
		$listed   = array_map('strval', iterator_to_array($media->folder, false));
		$unlisted = array_values(array_diff(array_map('basename', glob($component . '/media/*', GLOB_ONLYDIR)), $listed));

		$this->assertSame([], $unlisted, 'Media folders the manifest does not install.');
	}

	/**
	 * Every language file the component has on disk is listed in the manifest template; an unlisted
	 * one is never installed.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testEveryLanguageFileIsListed(): void
	{
		$manifest = self::manifest('build/templates/contactus.xml');
		$listed   = [];

		foreach (['languages' => 'frontend/language', 'administration/languages' => 'backend/language'] as $path => $folder)
		{
			foreach ($manifest->xpath($path)[0]->language as $language)
			{
				$listed[] = $folder . '/' . $language;
			}
		}

		$onDisk = array_map(
			fn(string $relative): string => substr($relative, strlen('component/')),
			array_keys(array_filter(
				self::filesUnder('component', 'ini'),
				// Not a translation file: HTML Purifier's own config directive schema data, bundled under
				// src/Dependency/ (see scoper.inc.php).
				fn(string $relative): bool => !str_contains($relative, '/src/Dependency/'),
				ARRAY_FILTER_USE_KEY
			))
		);

		sort($listed);
		sort($onDisk);

		$this->assertSame($onDisk, $listed);
	}

	/**
	 * The package manifest template names only language files that exist.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testPackageManifestMatchesTheTree(): void
	{
		$manifest = self::manifest('build/templates/pkg_contactus.xml');

		foreach ($manifest->languages->language as $language)
		{
			$this->assertFileExists(self::root() . '/build/templates/language/' . $language);
		}

		$this->assertSame('script.contactus.php', (string) $manifest->scriptfile);
		$this->assertFileExists(self::root() . '/component/script.contactus.php');
	}

	/**
	 * A custom form field type only resolves when the nearest ancestor of its <field> carries an
	 * addfieldprefix naming the Field namespace of the side (site or administrator) that loads the
	 * form. Without it Joomla silently renders a plain text box instead.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testCustomFieldTypesHaveTheirPrefix(): void
	{
		$problems = [];
		$checked  = 0;

		foreach (['frontend' => 'Site', 'backend' => 'Administrator'] as $side => $namespace)
		{
			$customTypes = array_map(
				fn(string $file): string => strtolower(basename($file, 'Field.php')),
				glob(self::root() . '/component/' . $side . '/src/Field/*Field.php')
			);

			foreach (glob(self::root() . '/component/' . $side . '/{forms/*.xml,config.xml}', GLOB_BRACE) as $file)
			{
				$xml = self::manifest(substr($file, strlen(self::root()) + 1));

				foreach ($xml->xpath('//*[@type]') as $element)
				{
					if (!in_array(strtolower((string) $element['type']), $customTypes, true))
					{
						continue;
					}

					$checked++;
					$prefix = $element->xpath('ancestor::*[@addfieldprefix][1]/@addfieldprefix');
					$prefix = $prefix ? (string) $prefix[0] : '';

					if ($prefix !== 'Akeeba\\Component\\ContactUs\\' . $namespace . '\\Field')
					{
						$problems[] = sprintf('%s: type="%s" resolves against "%s"', basename($file), $element['type'], $prefix ?: '(no addfieldprefix)');
					}
				}
			}
		}

		$this->assertGreaterThan(0, $checked, 'Found no custom field types; the scan is broken.');
		$this->assertSame([], $problems);
	}

	/**
	 * Development-only files in the component tree are kept out of the package.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testBuildExcludesDevelopmentFiles(): void
	{
		$build   = self::manifest('build.xml');
		$fileset = $build->xpath('//fileset[@id="component"]')[0] ?? null;

		$this->assertNotNull($fileset, 'build.xml has no "component" fileset.');

		$excludes = array_map(fn($e) => (string) $e['name'], $fileset->xpath('exclude'));
		$expected = array_map(
			fn(string $relative): string => substr($relative, strlen('component/')),
			array_keys(self::filesUnder('component', 'md'))
		);

		foreach ($expected as $pattern)
		{
			$this->assertContains($pattern, $excludes, sprintf('build.xml does not exclude %s from the component package.', $pattern));
		}
	}

	/**
	 * Files with an extension under a directory of the repository.
	 *
	 * @param   string  $dir        Relative to the repository root.
	 * @param   string  $extension  Without the dot.
	 *
	 * @return  array<string, string>  Relative path => absolute path.
	 * @since   4.3.0
	 */
	private static function filesUnder(string $dir, string $extension): array
	{
		$root     = self::root();
		$files    = [];
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));

		foreach ($iterator as $file)
		{
			if ($file->getExtension() === $extension && !str_contains($file->getPathname(), '/vendor/'))
			{
				$files[substr($file->getPathname(), strlen($root) + 1)] = $file->getPathname();
			}
		}

		ksort($files);

		return $files;
	}

	/**
	 * Parse an XML file of the repository.
	 *
	 * @param   string  $relative  Relative to the repository root.
	 *
	 * @return  SimpleXMLElement
	 * @since   4.3.0
	 */
	private static function manifest(string $relative): SimpleXMLElement
	{
		return new SimpleXMLElement((string) file_get_contents(self::root() . '/' . $relative));
	}

	/**
	 * The repository root.
	 *
	 * @return  string
	 * @since   4.3.0
	 */
	private static function root(): string
	{
		return \dirname(__DIR__, 2);
	}
}
