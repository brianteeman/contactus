<?php
/**
 * @package   contactus
 * @copyright Copyright (c)2013-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\ContactUs\Administrator\Model;

defined('_JEXEC') || die;

use Joomla\CMS\Installer\Adapter\PackageAdapter;
use Joomla\CMS\MVC\Model\BaseModel;
use Joomla\CMS\User\UserHelper;
use Joomla\Filesystem\File;
use Joomla\Filesystem\Folder;
use Throwable;

/**
 * Post-installation upgrade handling.
 *
 * Joomla's installer only overwrites what the current manifest lists; it never deletes files which were dropped from
 * the manifest in an earlier version. Anything removed from this component over the years therefore stays on disk
 * forever on a site which has been upgraded rather than freshly installed — including the legacy error handler
 * template, which dumped `$_GET`/`$_POST`/`$_COOKIE`, the session, `php_uname()`, and DB/PHP details, and is one
 * `include` away from being reachable again if it is left behind.
 *
 * This Model is instantiated and called by the package installation script.
 *
 * @see  Pkg_ContactusInstallerScript::postflight()
 */
class UpgradeModel extends BaseModel
{
	/**
	 * Obsolete files and folders removed on every install and update.
	 *
	 * READ THIS BEFORE ADDING AN ENTRY.
	 *
	 * On a case-insensitive filesystem — which is to say macOS and Windows, i.e. a large share of real sites — two
	 * paths differing only in case are ONE entry on disk. Deleting the obsolete spelling therefore deletes the live
	 * file. Before adding anything here, check it against the current contents of the installed tree,
	 * case-insensitively.
	 */
	private const REMOVE_FROM_ALL_VERSIONS = [
		'files'   => [
			// Removed in 30ff9fc: dumped request/session/server details, dormant but reachable if re-included.
			JPATH_ADMINISTRATOR . '/components/com_contactus/tmpl/commontemplates/errorhandler.php',
		],
		'folders' => [
			// Only ever contained errorhandler.php above.
			JPATH_ADMINISTRATOR . '/components/com_contactus/tmpl/commontemplates',
		],
	];

	/**
	 * Runs after the package has been installed, updated, or uninstalled.
	 *
	 * @param   string               $type    Installation type: install, discover_install, update, or uninstall.
	 * @param   PackageAdapter|null  $parent  The parent installer adapter.
	 *
	 * @return  bool  Always true; a failure here must never fail the installation.
	 */
	public function postflight(string $type, ?PackageAdapter $parent = null): bool
	{
		switch ($type)
		{
			case 'install':
			case 'discover_install':
			case 'update':
			default:
				$this->runIsolated(['removeObsoleteFiles']);
				break;

			case 'uninstall':
				// Joomla removes everything listed in the manifest; there is nothing for us to do.
				break;
		}

		return true;
	}

	/**
	 * Remove the files and folders which are no longer part of this component.
	 *
	 * @return  void
	 * @noinspection PhpUnused
	 */
	protected function removeObsoleteFiles(): void
	{
		foreach (self::REMOVE_FROM_ALL_VERSIONS['files'] as $file)
		{
			if (!is_file($file))
			{
				continue;
			}

			try
			{
				File::delete($file);
			}
			catch (Throwable $e)
			{
				// Swallow. A file we cannot remove must not fail the installation.
			}
		}

		foreach (self::REMOVE_FROM_ALL_VERSIONS['folders'] as $folder)
		{
			$this->deleteFolder($folder);
		}
	}

	/**
	 * Delete a folder, taking case-insensitive filesystems into account.
	 *
	 * On macOS and Windows a mixed case folder and its all-lowercase spelling are the same directory. Deleting the
	 * mixed case spelling would therefore delete the lowercase one. Before removing a mixed case folder we prove
	 * whether the two spellings are in fact one directory, by writing a probe file through one and reading it back
	 * through the other, and refuse to delete when they are.
	 *
	 * @param   string  $path  Absolute path of the folder to remove.
	 *
	 * @return  bool  True if the folder was removed.
	 */
	private function deleteFolder(string $path): bool
	{
		if (!is_dir($path))
		{
			return false;
		}

		$baseName          = basename($path);
		$lowercaseBaseName = strtolower($baseName);

		// An all-lowercase folder cannot be shadowing a differently cased live one.
		if ($baseName === $lowercaseBaseName)
		{
			return $this->reallyDeleteFolder($path);
		}

		$altPath = dirname($path) . '/' . $lowercaseBaseName;

		// The lowercase spelling does not exist: case-sensitive filesystem, or simply no twin. Safe to delete.
		if (!is_dir($altPath))
		{
			return $this->reallyDeleteFolder($path);
		}

		// Both spellings resolve. Are they the same directory on disk?
		$testBasename      = UserHelper::genRandomPassword(8) . '.dat';
		$data              = UserHelper::genRandomPassword(32);
		$lowercaseTestFile = $altPath . '/' . $testBasename;
		$uppercaseTestFile = $path . '/' . $testBasename;
		$readData          = null;

		try
		{
			File::write($lowercaseTestFile, $data);

			$readData = @file_get_contents($uppercaseTestFile);
		}
		catch (Throwable $e)
		{
			// Swallow; $readData stays null, which we treat as "cannot prove they differ".
		}

		try
		{
			File::delete($lowercaseTestFile);
		}
		catch (Throwable $e)
		{
			// Swallow.
		}

		/**
		 * The probe written through the lowercase spelling was read back through the mixed case one: the two are one
		 * directory, and the lowercase spelling is the live one. Deleting it would remove the running component.
		 *
		 * We also land here when the probe could not be completed at all, in which case we cannot prove the two are
		 * distinct — and an obsolete folder left on disk is vastly preferable to deleting a live one.
		 */
		if ($readData === null || $readData === $data)
		{
			return false;
		}

		// The two folders are genuinely distinct. Case-sensitive filesystem; proceed.
		return $this->reallyDeleteFolder($path);
	}

	/**
	 * Delete a folder, swallowing any failure.
	 *
	 * @param   string  $path  Absolute path of the folder to remove.
	 *
	 * @return  bool  True if the folder was removed.
	 */
	private function reallyDeleteFolder(string $path): bool
	{
		try
		{
			return Folder::delete($path);
		}
		catch (Throwable $e)
		{
			return false;
		}
	}

	/**
	 * Run each named method, ignoring any failure.
	 *
	 * A problem while tidying up obsolete files must never abort an installation which has otherwise succeeded.
	 *
	 * @param   string[]  $methodNames  The methods to run.
	 *
	 * @return  void
	 */
	private function runIsolated(array $methodNames): void
	{
		foreach ($methodNames as $methodName)
		{
			try
			{
				$this->{$methodName}();
			}
			catch (Throwable $e)
			{
				// No problem, let's move on.
			}
		}
	}
}
