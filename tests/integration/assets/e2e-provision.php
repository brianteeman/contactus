<?php
/**
 * @package   contactus
 * @copyright Copyright (c)2013-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

/**
 * Nested-set fixture provisioner for the ContactUs end-to-end test suite.
 *
 * SiteProvisioner copies this file into the throwaway site's document root and runs it inside the
 * php container. It creates ONLY the fixtures that live in Joomla's nested sets or asset tree — user
 * groups and permission rules — and prints a JSON manifest of their ids on stdout. Everything flat
 * (users, group maps, contact categories, messages, component parameters) is seeded by the host-side
 * SiteProvisioner over PDO.
 *
 * WHY THIS PART RUNS INSIDE THE CONTAINER
 *
 * User groups and assets are nested sets. Building those with hand-written INSERTs means
 * reimplementing lft/rgt bookkeeping, getting it subtly wrong, and then debugging ACL results that
 * are wrong for reasons that have nothing to do with ContactUs. Joomla's own Table classes already do
 * it correctly.
 *
 * Idempotent: every item is found by name and reused, so re-running it (a fixture reset) never grows
 * the tree.
 *
 * THE GROUP MATRIX
 *
 *   CU Viewers   Registered + back-end login + core.manage on com_contactus, and NOTHING else on it:
 *                no core.create, core.edit, core.edit.state, core.delete or core.options. The
 *                "can see the list, may not touch anything" user of the back-end ACL tests.
 *   CU Denied    Administrator (so: back-end login, and core.manage inherited from the root asset),
 *                explicitly DENIED core.manage on com_contactus.
 */

use Joomla\CMS\Factory;
use Joomla\CMS\Table\Asset;
use Joomla\CMS\Table\Usergroup;
use Joomla\Console\Application;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\DatabaseInterface;

const _JEXEC = 1;

// ---------------------------------------------------------------------------
// Bootstrap, mirroring cli/joomla.php.
// ---------------------------------------------------------------------------
if (file_exists(__DIR__ . '/defines.php'))
{
	require_once __DIR__ . '/defines.php';
}

if (!defined('_JDEFINES'))
{
	define('JPATH_BASE', __DIR__);
	require_once JPATH_BASE . '/includes/defines.php';
}

require_once JPATH_BASE . '/includes/framework.php';

$container = Factory::getContainer();

$container->alias('session', 'session.cli')
	->alias('JSession', 'session.cli')
	->alias(\Joomla\CMS\Session\Session::class, 'session.cli')
	->alias(\Joomla\Session\Session::class, 'session.cli')
	->alias(\Joomla\Session\SessionInterface::class, 'session.cli');

$app                  = $container->get(Application::class);
Factory::$application = $app;

/**
 * Load the extension PSR-4 map by hand.
 *
 * Nothing in libraries/bootstrap.php or includes/framework.php registers the extension namespaces.
 * That is done by ExtensionNamespaceMapper::createExtensionNamespaceMap(), which the console
 * application only calls from doExecute(). This script never executes the application.
 */
$app->createExtensionNamespaceMap();

/** @var DatabaseDriver $db */
$db = $container->get(DatabaseInterface::class);

// ---------------------------------------------------------------------------
// Small helpers.
// ---------------------------------------------------------------------------

/**
 * Find a user group by title, or create it under the given parent.
 */
function ensureGroup(DatabaseDriver $db, string $title, int $parentId): int
{
	$query = $db->createQuery()
		->select($db->quoteName('id'))
		->from($db->quoteName('#__usergroups'))
		->where($db->quoteName('title') . ' = :title')
		->bind(':title', $title);

	$existing = $db->setQuery($query)->loadResult();
	$table    = new Usergroup($db);

	if ($existing)
	{
		// Reused from an earlier run; make sure it still hangs where the matrix says it must.
		$table->load((int) $existing);

		if ((int) $table->parent_id !== $parentId)
		{
			$table->parent_id = $parentId;

			if (!$table->store())
			{
				throw new RuntimeException(sprintf('Could not move user group "%s": %s', $title, $table->getError()));
			}

			$table->rebuild();
		}

		return (int) $existing;
	}

	if (!$table->save(['title' => $title, 'parent_id' => $parentId]))
	{
		throw new RuntimeException(sprintf('Could not create user group "%s": %s', $title, $table->getError()));
	}

	return (int) $table->id;
}

/**
 * Load a named asset, failing loudly when it does not exist.
 */
function loadAsset(DatabaseDriver $db, string $assetName): Asset
{
	$asset = new Asset($db);

	if (!$asset->loadByName($assetName))
	{
		throw new RuntimeException(sprintf('No such asset: "%s".', $assetName));
	}

	return $asset;
}

/**
 * Store the permission rules of an asset.
 */
function storeAssetRules(Asset $asset, array $rules): void
{
	// An action with no groups must be an object, not a JSON list, or Joomla's Rules class chokes on it.
	$asset->rules = json_encode(array_map(fn(array $groups) => (object) $groups, $rules));

	if (!$asset->store())
	{
		throw new RuntimeException(sprintf('Could not store the rules of asset "%s": %s', $asset->name, $asset->getError()));
	}
}

// ---------------------------------------------------------------------------
// 1. User groups.
// ---------------------------------------------------------------------------
$registered    = 2;
$administrator = 7;

$groups = [
	'viewers'       => ensureGroup($db, 'CU Viewers', $registered),
	'denied'        => ensureGroup($db, 'CU Denied', $administrator),
	'registered'    => $registered,
	'administrator' => $administrator,
	'superUsers'    => 8,
];

// ---------------------------------------------------------------------------
// 2. Back-end login for the viewers. core.login.admin is only ever evaluated on the root asset, so it
//    is merged into the root rules rather than replacing them.
// ---------------------------------------------------------------------------
$root  = loadAsset($db, 'root.1');
$rules = json_decode((string) $root->rules, true) ?: [];

$rules['core.login.admin']                             = $rules['core.login.admin'] ?? [];
$rules['core.login.admin'][(string) $groups['viewers']] = 1;

storeAssetRules($root, $rules);

// ---------------------------------------------------------------------------
// 3. The component's own permissions, replaced wholesale on every run.
// ---------------------------------------------------------------------------
storeAssetRules(
	loadAsset($db, 'com_contactus'),
	[
		'core.manage' => [(string) $groups['viewers'] => 1, (string) $groups['denied'] => 0],
	]
);

// ---------------------------------------------------------------------------
// 4. Manifest, for the host-side provisioner.
// ---------------------------------------------------------------------------
echo json_encode(
	[
		'groups' => $groups,
		'php'    => PHP_VERSION,
		'joomla' => JVERSION,
	],
	JSON_PRETTY_PRINT
);
