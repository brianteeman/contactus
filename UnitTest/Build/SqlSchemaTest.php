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

/**
 * The database schema the component ships, for MySQL and PostgreSQL.
 *
 * Joomla never runs update SQL on a fresh install: it runs the install SQL and records the newest
 * update file as the schema version. So every column an update adds must also be in the install SQL,
 * or fresh installs silently lack it — forever, since the update that adds it will never run. And the
 * E2E suite only ever exercises MySQL, so the PostgreSQL schema is held to the MySQL one here.
 *
 * @since 4.3.0
 */
class SqlSchemaTest extends TestCase
{
	/**
	 * The database drivers the component ships SQL for.
	 *
	 * @return  array<string, array{0: string, 1: string}>
	 * @since   4.3.0
	 */
	public static function drivers(): array
	{
		return [
			'mysql'      => ['mysql', 'install.mysql.utf8.sql'],
			'postgresql' => ['postgresql', 'install.postgresql.utf8.sql'],
		];
	}

	/**
	 * Every column added by an update is created by the install SQL too.
	 *
	 * @param   string  $driver   The updates sub-folder.
	 * @param   string  $install  The install SQL file.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	#[DataProvider('drivers')]
	public function testUpdatesDoNotAddColumnsTheInstallLacks(string $driver, string $install): void
	{
		$tables  = $this->installedColumns(file_get_contents(self::sqlDir() . '/' . $install));
		$missing = [];

		foreach (glob(self::sqlDir() . '/updates/' . $driver . '/*.sql') as $update)
		{
			preg_match_all(
				'/ALTER\s+TABLE\s+[`"]?(#__[a-z_]+)[`"]?\s+ADD\s+(?:COLUMN\s+)?(?:IF\s+NOT\s+EXISTS\s+)?[`"]?([a-z_]+)[`"]?/i',
				file_get_contents($update),
				$matches,
				PREG_SET_ORDER
			);

			foreach ($matches as [, $table, $column])
			{
				// Only tables the install SQL still creates; dropped tables do not matter.
				if (isset($tables[$table]) && !in_array(strtolower($column), $tables[$table], true))
				{
					$missing[] = sprintf('%s.%s (added by %s)', $table, $column, basename($update));
				}
			}
		}

		$this->assertSame([], $missing, $install . ' lacks columns that the update SQL adds.');
	}

	/**
	 * Both drivers create the same tables with the same columns, in the same order.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testMysqlAndPostgresqlSchemasMatch(): void
	{
		$mysql      = $this->installedColumns(file_get_contents(self::sqlDir() . '/install.mysql.utf8.sql'));
		$postgresql = $this->installedColumns(file_get_contents(self::sqlDir() . '/install.postgresql.utf8.sql'));

		$this->assertSame($mysql, $postgresql);
	}

	/**
	 * Both drivers end on the same schema version. Joomla records the newest update file's name as the
	 * installed schema version; drivers disagreeing means one of them is missing an update.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testDriversEndOnTheSameSchemaVersion(): void
	{
		$newest = function (string $driver): string {
			$versions = array_map(fn(string $file): string => basename($file, '.sql'), glob(self::sqlDir() . '/updates/' . $driver . '/*.sql'));

			usort($versions, 'version_compare');

			return (string) end($versions);
		};

		$this->assertSame($newest('mysql'), $newest('postgresql'));
	}

	/**
	 * Both drivers uninstall every table they install.
	 *
	 * @param   string  $driver   The driver.
	 * @param   string  $install  The install SQL file.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	#[DataProvider('drivers')]
	public function testUninstallDropsEveryTable(string $driver, string $install): void
	{
		$tables    = array_keys($this->installedColumns(file_get_contents(self::sqlDir() . '/' . $install)));
		$uninstall = file_get_contents(self::sqlDir() . '/uninstall.' . $driver . '.utf8.sql');

		foreach ($tables as $table)
		{
			$this->assertMatchesRegularExpression(
				'/DROP\s+TABLE\s+(IF\s+EXISTS\s+)?[`"]?' . preg_quote($table, '/') . '[`"]?/i',
				$uninstall,
				sprintf('uninstall.%s.utf8.sql does not drop %s.', $driver, $table)
			);
		}
	}

	/**
	 * The columns of every table an install SQL file creates.
	 *
	 * @param   string  $sql  The install SQL.
	 *
	 * @return  array<string, string[]>  Table => lower-cased column names.
	 * @since   4.3.0
	 */
	private function installedColumns(string $sql): array
	{
		$tables = [];

		preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?(#__[a-z_]+)[`"]?\s*\((.*?)\)\s*[^,()]*;/is', $sql, $creates, PREG_SET_ORDER);

		foreach ($creates as [, $table, $body])
		{
			preg_match_all('/^\s*[`"]?([a-z_]+)[`"]?\s+[a-z]/im', $body, $columns);

			$tables[$table] = array_values(array_diff(
				array_map('strtolower', $columns[1]),
				['primary', 'key', 'unique', 'index', 'constraint']
			));
		}

		$this->assertNotEmpty($tables, 'Could not parse any CREATE TABLE statement.');

		return $tables;
	}

	/**
	 * The component's SQL folder.
	 *
	 * @return  string
	 * @since   4.3.0
	 */
	private static function sqlDir(): string
	{
		return \dirname(__DIR__, 2) . '/component/backend/sql';
	}
}
