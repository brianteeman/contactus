<?php
/**
 * @package   contactus
 * @copyright Copyright (c)2013-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\ContactUs\UnitTest\Build;

defined('_JEXEC') or die;

use Akeeba\ContactUs\UnitTest\LanguageFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shipped language files.
 *
 * Joomla keeps the LAST definition of a key defined twice, silently; shows the raw key for a string
 * that is not defined; and hands Text::sprintf() whatever the translation says, so a translation with
 * fewer or different placeholders than the original loses or garbles the values it should show.
 *
 * @since 4.3.0
 */
class LanguageFilesTest extends TestCase
{
	/**
	 * Every shipped .ini file, including the package's.
	 *
	 * @return  array<string, array{0: string}>
	 * @since   4.3.0
	 */
	public static function languageFiles(): array
	{
		$cases = [];

		foreach (self::iniFiles() as $relative => $path)
		{
			$cases[$relative] = [$path];
		}

		return $cases;
	}

	/**
	 * Every translation, paired with its en-GB original.
	 *
	 * @return  array<string, array{0: string, 1: string}>
	 * @since   4.3.0
	 */
	public static function translations(): array
	{
		$cases = [];

		foreach (self::iniFiles() as $relative => $path)
		{
			if (str_contains($path, '/en-GB/'))
			{
				continue;
			}

			$original = preg_replace('#/[a-z]{2}-[A-Z]{2}/#', '/en-GB/', $path);

			if (is_file($original))
			{
				$cases[$relative] = [$path, $original];
			}
		}

		return $cases;
	}

	/**
	 * No key is defined twice.
	 *
	 * @param   string  $path  The .ini file.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	#[DataProvider('languageFiles')]
	public function testNoDuplicateKeys(string $path): void
	{
		$this->assertSame([], LanguageFile::duplicates($path), 'Keys defined more than once; Joomla silently keeps the last one.');
	}

	/**
	 * Every line is a comment, blank, a section header or a KEY="value" pair in the format Joomla's own
	 * language debugger accepts — and PHP's raw INI scanner, which Joomla parses the files with, reads
	 * every one of those pairs.
	 *
	 * Unescaped double quotes inside a value are fine: the raw scanner takes everything between the
	 * first and the last quote of the line, which is how the consent help's <a href="%s"> works.
	 *
	 * @param   string  $path  The .ini file.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	#[DataProvider('languageFiles')]
	public function testEveryLineParses(string $path): void
	{
		$bad = [];

		foreach (file($path, FILE_IGNORE_NEW_LINES) as $number => $line)
		{
			if (trim($line) === '' || preg_match('/^\s*;/', $line) || preg_match('/^\s*\[[^\]]+\]\s*$/', $line))
			{
				continue;
			}

			if (!preg_match('#^[A-Z][A-Z0-9_:*\-.]*\s*=\s*".*"(\s*;.*)?$#', $line))
			{
				$bad[] = sprintf('line %d: %s', $number + 1, mb_substr($line, 0, 100));
			}
		}

		$this->assertSame([], $bad, 'Lines not in the KEY="value" format.');

		$parsed = @parse_ini_string((string) file_get_contents($path), false, INI_SCANNER_RAW);

		$this->assertIsArray($parsed, 'PHP\'s raw INI scanner cannot parse the file.');
		$this->assertSame(array_keys(LanguageFile::load($path)), array_keys($parsed), 'The raw INI scanner does not read every key.');
	}

	/**
	 * A translation defines no key its en-GB original does not (a stale or misspelled key), and keeps
	 * the original's printf placeholders.
	 *
	 * @param   string  $path      The translation.
	 * @param   string  $original  The en-GB file.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	#[DataProvider('translations')]
	public function testTranslationMatchesOriginal(string $path, string $original): void
	{
		$english     = LanguageFile::load($original);
		$translation = LanguageFile::load($path);

		$this->assertSame([], array_values(array_diff(array_keys($translation), array_keys($english))), 'Keys the en-GB file does not have.');

		$mismatched = [];

		foreach ($translation as $key => $text)
		{
			if (isset($english[$key]) && self::placeholders($english[$key]) !== self::placeholders($text))
			{
				$mismatched[] = sprintf('%s: en-GB has %s, translation has %s', $key, json_encode(self::placeholders($english[$key])), json_encode(self::placeholders($text)));
			}
		}

		$this->assertSame([], $mismatched, 'Translations whose printf placeholders differ from the original.');
	}

	/**
	 * Every COM_CONTACTUS_* key the code and XML files use literally is defined in en-GB. Joomla shows
	 * the raw key for one that is not.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testEveryUsedKeyIsDefined(): void
	{
		$root    = \dirname(__DIR__, 2);
		$defined = [];

		foreach (self::iniFiles() as $path)
		{
			if (str_contains($path, '/en-GB/'))
			{
				$defined += LanguageFile::load($path);
			}
		}

		$used     = [];
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/component', \FilesystemIterator::SKIP_DOTS));

		foreach ($iterator as $file)
		{
			if (!in_array($file->getExtension(), ['php', 'xml'], true))
			{
				continue;
			}

			// A key between quotes or tags. Keys built by concatenation ('..._' . $x) end in "_" and are skipped.
			// $text_prefix names a prefix for keys Joomla builds itself, not a key.
			$code = preg_replace('/^.*\$text_prefix\s*=.*$/m', '', (string) file_get_contents($file->getPathname()));

			preg_match_all('/[\'">](COM_CONTACTUS_[A-Z0-9_]*[A-Z0-9])[\'"<]/', $code, $matches);

			foreach ($matches[1] as $key)
			{
				$used[$key][] = substr($file->getPathname(), strlen($root) + 1);
			}
		}

		$this->assertNotEmpty($used, 'Found no language keys in the code; the scan is broken.');

		$missing = [];

		foreach (array_diff_key($used, $defined) as $key => $files)
		{
			$missing[] = $key . ' (' . implode(', ', array_unique($files)) . ')';
		}

		$this->assertSame([], $missing, "language keys used in the code but defined in no en-GB file (Joomla shows the raw key):\n" . implode("\n", $missing));
	}

	/**
	 * The printf placeholders of a string, in order.
	 *
	 * @param   string  $text  The string.
	 *
	 * @return  string[]
	 * @since   4.3.0
	 */
	private static function placeholders(string $text): array
	{
		preg_match_all('/%(?:\d+\$)?[-+ 0]*\d*(?:\.\d+)?[sdfuxXco]/', str_replace('%%', '', $text), $matches);

		$placeholders = $matches[0];

		// Numbered placeholders may be reordered by a translation; compare them as a set.
		if (array_filter($placeholders, fn(string $p): bool => str_contains($p, '$')) !== [])
		{
			sort($placeholders);
		}

		return $placeholders;
	}

	/**
	 * Every shipped .ini file, relative path => absolute path.
	 *
	 * @return  array<string, string>
	 * @since   4.3.0
	 */
	private static function iniFiles(): array
	{
		$root  = \dirname(__DIR__, 2);
		$files = [];

		foreach (['component', 'build/templates/language'] as $dir)
		{
			$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));

			foreach ($iterator as $file)
			{
				if ($file->getExtension() === 'ini' && !str_contains($file->getPathname(), '/Dependency/'))
				{
					$files[substr($file->getPathname(), strlen($root) + 1)] = $file->getPathname();
				}
			}
		}

		ksort($files);

		return $files;
	}
}
