<?php
/**
 * @package   contactus
 * @copyright Copyright (c)2013-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\ContactUs\IntegrationTest;

defined('_JEXEC') or die;

use Akeeba\ContactUs\IntegrationTest\Engine\Configuration;
use Akeeba\ContactUs\IntegrationTest\Engine\ContainerCli;
use Akeeba\ContactUs\IntegrationTest\Engine\Database;
use RuntimeException;

/**
 * Creates, resets and reads back the ContactUs fixtures on the provisioned site.
 *
 * Two halves:
 *
 *   - The nested-set fixtures (user groups, permission rules) are built INSIDE the php container by
 *     assets/e2e-provision.php, with Joomla's own Table classes. See that file for why.
 *   - Everything flat — users, group maps, contact categories, messages, the component's parameters —
 *     is seeded from here over PDO.
 *
 * THE ROLES
 *
 *   alice      Registered. Sees the Registered-only category that a guest must not.
 *   viewer     CU Viewers: back-end login and core.manage on com_contactus, nothing else.
 *   nomanage   CU Denied: an Administrator explicitly denied core.manage on com_contactus.
 *   admin      The installer's Super User (not recreated; see config).
 *
 * THE CATEGORIES
 *
 *   general     Public, enabled, two recipients, sends an auto-reply using placeholders.
 *   noreply     Public, enabled, one recipient, no auto-reply.
 *   disabled    Public, DISABLED.
 *   registered  Registered access level, enabled.
 *   special     Special access level, enabled.
 *
 * Every category has recipients no other category shares, so a test can tell from Mailpit alone
 * which category a message was filed under.
 *
 * @since 4.3.0
 */
class SiteProvisioner
{
	/**
	 * Filename of the in-container provisioning script inside the site root.
	 *
	 * @since 4.3.0
	 */
	private const SCRIPT = 'e2e-provision.php';

	/**
	 * Filename of the manifest, inside the site root.
	 *
	 * @since 4.3.0
	 */
	private const MANIFEST = 'e2e-manifest.json';

	/**
	 * The shared accounts, role => [display name, group keys].
	 *
	 * @since 4.3.0
	 */
	private const ROLES = [
		'alice'    => ['Alice Example', ['registered']],
		'viewer'   => ['Vic Viewer', ['viewers']],
		'nomanage' => ['Nico Nomanage', ['denied']],
	];

	/**
	 * The contact categories, key => row. `access` is a viewing access level id: 1 Public,
	 * 2 Registered, 3 Special.
	 *
	 * @since 4.3.0
	 */
	private const CATEGORIES = [
		'general'    => [
			'title'         => 'E2E General',
			'email'         => 'support@example.test, sales@example.test',
			'sendautoreply' => 1,
			'autoreply'     => '<p>Dear [FROMNAME], thank you for contacting [SITENAME] about [CATEGORY]. Your subject was: [SUBJECT]</p>',
			'access'        => 1,
			'enabled'       => 1,
		],
		'noreply'    => [
			'title'         => 'E2E No Autoreply',
			'email'         => 'noreply-desk@example.test',
			'sendautoreply' => 0,
			'autoreply'     => '',
			'access'        => 1,
			'enabled'       => 1,
		],
		'disabled'   => [
			'title'         => 'E2E Disabled',
			'email'         => 'disabled-desk@example.test',
			'sendautoreply' => 0,
			'autoreply'     => '',
			'access'        => 1,
			'enabled'       => 0,
		],
		'registered' => [
			'title'         => 'E2E Registered Only',
			'email'         => 'registered-desk@example.test',
			'sendautoreply' => 0,
			'autoreply'     => '',
			'access'        => 2,
			'enabled'       => 1,
		],
		'special'    => [
			'title'         => 'E2E Special Only',
			'email'         => 'special-desk@example.test',
			'sendautoreply' => 0,
			'autoreply'     => '',
			'access'        => 3,
			'enabled'       => 1,
		],
	];

	/**
	 * The component's parameters after a reset: its shipped defaults, with a privacy policy URL.
	 *
	 * The Akismet key is empty on purpose. With a key, every submission makes an outbound HTTPS call
	 * to Akismet, which a hermetic test run can neither rely on nor control; the tests that need a
	 * key set their own.
	 *
	 * @since 4.3.0
	 */
	public const COMPONENT_PARAMS = [
		'akismet_api_key' => '',
		'privacypolicy'   => 'https://example.test/privacy.html',
		'offline'         => '0',
	];

	/**
	 * Where the path traversal fixture of LayoutTraversalTest lives, relative to the site root.
	 *
	 * @since 4.3.0
	 */
	public const TRAVERSAL_DIR = 'tmp/e2e-traversal';

	/**
	 * What the path traversal fixture prints when it is (wrongly) included.
	 *
	 * @since 4.3.0
	 */
	public const TRAVERSAL_MARKER = 'E2E-TRAVERSAL-FIXTURE-INCLUDED';

	/**
	 * The suite configuration.
	 *
	 * @var   Configuration
	 * @since 4.3.0
	 */
	private Configuration $config;

	/**
	 * The manifest of everything provisioned.
	 *
	 * @var   array|null
	 * @since 4.3.0
	 */
	private ?array $manifest = null;

	/**
	 * Shared instance.
	 *
	 * @var   self|null
	 * @since 4.3.0
	 */
	private static ?self $instance = null;

	/**
	 * Database connection, created on first use.
	 *
	 * @var   Database|null
	 * @since 4.3.0
	 */
	private ?Database $db = null;

	/**
	 * Constructor.
	 *
	 * @param   Configuration|null  $config  The suite configuration.
	 *
	 * @since   4.3.0
	 */
	public function __construct(?Configuration $config = null)
	{
		$this->config = $config ?? Configuration::getInstance();
	}

	/**
	 * The shared instance, so a whole PHPUnit run provisions once by default.
	 *
	 * @return  self
	 * @since   4.3.0
	 */
	public static function getInstance(): self
	{
		return self::$instance ??= new self();
	}

	/**
	 * Provision the fixtures from scratch. Re-running is a reset, not a duplication.
	 *
	 * @return  array  The manifest.
	 * @since   4.3.0
	 */
	public function provision(): array
	{
		$nested = $this->runNestedSetProvisioner();
		$db     = $this->db();

		$manifest = [
			'groups'     => $nested['groups'],
			'php'        => (string) $nested['php'],
			'joomla'     => (string) $nested['joomla'],
			'users'      => [],
			'usernames'  => [],
			'emails'     => [],
			'categories' => [],
		];

		// Everyone out. Sessions of accounts about to be deleted would otherwise linger.
		$db->query('DELETE FROM #__session');

		$this->deleteAllUsersButTheSuperUser();

		// The installer's Super User.
		[$adminUsername] = $this->config->getAdminCredentials();
		$adminId         = (int) $db->value('SELECT id FROM #__users WHERE username = ?', [$adminUsername]);

		if ($adminId <= 0)
		{
			throw new RuntimeException(sprintf('The Super User "%s" does not exist.', $adminUsername));
		}

		$db->query('UPDATE #__users SET block = 0, requireReset = 0 WHERE id = ?', [$adminId]);

		$manifest['users']['admin']     = $adminId;
		$manifest['usernames']['admin'] = $adminUsername;
		$manifest['emails']['admin']    = (string) $db->value('SELECT email FROM #__users WHERE id = ?', [$adminId]);

		// The shared accounts.
		foreach (self::ROLES as $role => [$name, $groupKeys])
		{
			$groupIds = array_map(fn(string $key): int => (int) $nested['groups'][$key], $groupKeys);
			$id       = $this->createUser([
				'username' => $role,
				'name'     => $name,
				'email'    => $role . '@example.test',
				'groups'   => $groupIds,
			]);

			$manifest['users'][$role]     = $id;
			$manifest['usernames'][$role] = $role;
			$manifest['emails'][$role]    = $role . '@example.test';
		}

		// The component's own data: no messages, the fixed set of categories.
		$db->query('DELETE FROM #__contactus_items');
		$db->query('DELETE FROM #__contactus_categories');

		$ordering = 0;

		foreach (self::CATEGORIES as $key => $row)
		{
			$manifest['categories'][$key] = $db->insert(
				'#__contactus_categories',
				$row + [
					'language'    => '*',
					'ordering'    => ++$ordering,
					'created_on'  => gmdate('Y-m-d H:i:s'),
					'created_by'  => $adminId,
					'modified_on' => gmdate('Y-m-d H:i:s'),
					'modified_by' => $adminId,
					'locked_by'   => 0,
				]
			);
		}

		$this->resetComponentParams();
		$this->deployTraversalFixture();

		SiteProbe::deploy($this->config);

		$this->writeManifest($manifest);

		return $this->manifest = $manifest;
	}

	/**
	 * Re-run the provisioner, discarding whatever the tests have done to the fixtures.
	 *
	 * @return  array  The manifest.
	 * @since   4.3.0
	 */
	public function reset(): array
	{
		return $this->provision();
	}

	/**
	 * The manifest, provisioning first if it has not been done yet on this site.
	 *
	 * @return  array
	 * @since   4.3.0
	 */
	public function getManifest(): array
	{
		if ($this->manifest !== null)
		{
			return $this->manifest;
		}

		$file = $this->siteRoot() . '/' . self::MANIFEST;

		if (is_file($file))
		{
			$data = json_decode((string) file_get_contents($file), true);

			if (is_array($data))
			{
				return $this->manifest = $data;
			}
		}

		return $this->provision();
	}

	/**
	 * The numeric id of a shared account, by role.
	 *
	 * @param   string  $role  A role, e.g. 'alice', 'viewer', 'admin'.
	 *
	 * @return  int
	 * @since   4.3.0
	 */
	public function userId(string $role): int
	{
		return (int) $this->lookup('users', $role);
	}

	/**
	 * The username of a shared account, by role.
	 *
	 * @param   string  $role  A role.
	 *
	 * @return  string
	 * @since   4.3.0
	 */
	public function username(string $role): string
	{
		return (string) $this->lookup('usernames', $role);
	}

	/**
	 * The email address of a shared account, by role.
	 *
	 * @param   string  $role  A role.
	 *
	 * @return  string
	 * @since   4.3.0
	 */
	public function email(string $role): string
	{
		return (string) $this->lookup('emails', $role);
	}

	/**
	 * The id of a provisioned user group.
	 *
	 * @param   string  $name  e.g. 'viewers', 'denied', 'registered'.
	 *
	 * @return  int
	 * @since   4.3.0
	 */
	public function groupId(string $name): int
	{
		return (int) $this->lookup('groups', $name);
	}

	/**
	 * The id of a provisioned contact category.
	 *
	 * @param   string  $key  e.g. 'general', 'disabled', 'registered'.
	 *
	 * @return  int
	 * @since   4.3.0
	 */
	public function categoryId(string $key): int
	{
		return (int) $this->lookup('categories', $key);
	}

	/**
	 * The provisioned row of a contact category, as seeded.
	 *
	 * @param   string  $key  e.g. 'general'.
	 *
	 * @return  array
	 * @since   4.3.0
	 */
	public function category(string $key): array
	{
		if (!isset(self::CATEGORIES[$key]))
		{
			throw new RuntimeException(sprintf('There is no category fixture named "%s".', $key));
		}

		return self::CATEGORIES[$key] + ['contactus_category_id' => $this->categoryId($key)];
	}

	/**
	 * The recipient addresses of a contact category.
	 *
	 * @param   string  $key  e.g. 'general'.
	 *
	 * @return  string[]
	 * @since   4.3.0
	 */
	public function recipients(string $key): array
	{
		return array_values(array_filter(array_map('trim', explode(',', $this->category($key)['email']))));
	}

	/**
	 * Create an account directly in the database: the user row and its group map.
	 *
	 * Recognised keys: username, name, email, password, groups (ids; default Registered), block.
	 *
	 * @param   array  $spec  What to create.
	 *
	 * @return  int  The new user's id.
	 * @since   4.3.0
	 */
	public function createUser(array $spec = []): int
	{
		$db       = $this->db();
		$suffix   = bin2hex(random_bytes(4));
		$username = $spec['username'] ?? ('user' . $suffix);

		$row = [
			'name'          => $spec['name'] ?? ('User ' . $suffix),
			'username'      => $username,
			'email'         => $spec['email'] ?? ($username . '@example.test'),
			'password'      => password_hash($spec['password'] ?? $this->config->getUserPassword(), PASSWORD_BCRYPT),
			'block'         => (int) ($spec['block'] ?? 0),
			'sendEmail'     => 0,
			'registerDate'  => gmdate('Y-m-d H:i:s', time() - 86400),
			'lastvisitDate' => gmdate('Y-m-d H:i:s'),
			'activation'    => '',
			'params'        => '{}',
			'requireReset'  => 0,
			'resetCount'    => 0,
		];

		$id = $db->insert('#__users', $row);

		foreach ($spec['groups'] ?? [2] as $groupId)
		{
			$db->insert('#__user_usergroup_map', ['user_id' => $id, 'group_id' => (int) $groupId]);
		}

		return $id;
	}

	/**
	 * Store a contact message directly, as if a visitor had submitted it earlier.
	 *
	 * @param   string  $categoryKey  The category fixture, e.g. 'general'.
	 * @param   array   $data         Column => value overrides.
	 *
	 * @return  int  The new message's id.
	 * @since   4.3.0
	 */
	public function createMessage(string $categoryKey = 'general', array $data = []): int
	{
		$suffix = bin2hex(random_bytes(4));

		return $this->db()->insert(
			'#__contactus_items',
			array_merge(
				[
					'contactus_category_id' => $this->categoryId($categoryKey),
					'fromname'              => 'Earlier Visitor ' . $suffix,
					'fromemail'             => 'earlier-' . $suffix . '@example.test',
					'subject'               => 'Earlier subject ' . $suffix,
					'body'                  => 'E2E-EARLIER-MESSAGE-BODY-' . $suffix,
					'enabled'               => 1,
					'created_on'            => gmdate('Y-m-d H:i:s', time() - 3600),
					'created_by'            => 0,
					'modified_by'           => 0,
					'locked_by'             => 0,
				],
				$data
			)
		);
	}

	/**
	 * Merge values into the component's parameters.
	 *
	 * @param   array  $params  Parameter => value.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function setComponentParams(array $params): void
	{
		$db  = $this->db();
		$raw = $db->value("SELECT params FROM #__extensions WHERE type = 'component' AND element = 'com_contactus'");

		if ($raw === null)
		{
			throw new RuntimeException('com_contactus is not installed.');
		}

		$current = json_decode((string) $raw, true) ?: [];

		$db->query(
			"UPDATE #__extensions SET params = ? WHERE type = 'component' AND element = 'com_contactus'",
			[json_encode(array_merge($current, $params))]
		);
	}

	/**
	 * Put the component's parameters back to the suite's baseline.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function resetComponentParams(): void
	{
		$this->db()->query(
			"UPDATE #__extensions SET params = ? WHERE type = 'component' AND element = 'com_contactus'",
			[json_encode(self::COMPONENT_PARAMS)]
		);
	}

	/**
	 * Delete every account except the installer's Super User, with everything hanging off them.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	private function deleteAllUsersButTheSuperUser(): void
	{
		$db              = $this->db();
		[$adminUsername] = $this->config->getAdminCredentials();

		$ids = array_map(
			'intval',
			$db->column('SELECT id FROM #__users WHERE username <> ?', [$adminUsername])
		);

		if ($ids === [])
		{
			return;
		}

		$in = implode(',', $ids);

		foreach (['#__user_usergroup_map', '#__user_profiles', '#__user_notes'] as $table)
		{
			$db->query(sprintf('DELETE FROM %s WHERE user_id IN (%s)', $table, $in));
		}

		$db->query(sprintf('DELETE FROM #__users WHERE id IN (%s)', $in));
	}

	/**
	 * Plant the file a path traversal through the `layout` parameter would include.
	 *
	 * A view template named by `layout=TEMPLATE:LAYOUT` is looked up under
	 * administrator/templates/TEMPLATE/html/com_contactus/VIEW/LAYOUT.php. Before b3a24d8 nothing
	 * cleaned TEMPLATE, so `layout=../../tmp/e2e-traversal:default` resolved to the file planted here —
	 * one that prints a marker nothing else on the site ever prints.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	private function deployTraversalFixture(): void
	{
		foreach (['items', 'categories', 'item', 'category'] as $view)
		{
			$dir = $this->siteRoot() . '/' . self::TRAVERSAL_DIR . '/html/com_contactus/' . $view;

			if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir))
			{
				throw new RuntimeException(sprintf('Could not create %s.', $dir));
			}

			file_put_contents($dir . '/default.php', "<?php echo '" . self::TRAVERSAL_MARKER . "';\n");
			file_put_contents($dir . '/edit.php', "<?php echo '" . self::TRAVERSAL_MARKER . "';\n");
		}
	}

	/**
	 * Run the in-container, nested-set part of the provisioning.
	 *
	 * @return  array  Its manifest.
	 * @since   4.3.0
	 */
	private function runNestedSetProvisioner(): array
	{
		$source = \dirname(__DIR__) . '/assets/' . self::SCRIPT;
		$target = $this->siteRoot() . '/' . self::SCRIPT;

		if (!copy($source, $target))
		{
			throw new RuntimeException(sprintf('Could not copy the provisioning script to %s.', $target));
		}

		[$exitCode, $output] = (new ContainerCli($this->config))->run(['php', self::SCRIPT]);

		// The JSON document is the last thing printed; anything before it is PHP noise worth showing.
		$start = strpos($output, "{\n");
		$data  = $start === false ? null : json_decode(substr($output, $start), true);

		if ($exitCode !== 0 || !is_array($data))
		{
			throw new RuntimeException(sprintf("Fixture provisioning failed (exit %d):\n%s", $exitCode, $output));
		}

		return $data;
	}

	/**
	 * Write the manifest into the site root, so later PHPUnit processes can read it without
	 * provisioning again.
	 *
	 * @param   array  $manifest  The manifest.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	private function writeManifest(array $manifest): void
	{
		file_put_contents(
			$this->siteRoot() . '/' . self::MANIFEST,
			json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
		);
	}

	/**
	 * Read a value out of a manifest section, failing loudly when it is absent.
	 *
	 * A missing key here means the fixture the test needs was never created. Returning null and
	 * letting the test carry on would produce a request for id 0, which the site quite correctly
	 * refuses — and the test would pass while proving nothing.
	 *
	 * @param   string  $section  The manifest section.
	 * @param   string  $key      The key within it.
	 *
	 * @return  mixed
	 * @since   4.3.0
	 */
	private function lookup(string $section, string $key)
	{
		$manifest = $this->getManifest();

		if (!isset($manifest[$section]) || !array_key_exists($key, $manifest[$section]))
		{
			throw new RuntimeException(
				sprintf(
					'The fixture manifest has no %s entry named "%s". Known: %s',
					$section,
					$key,
					implode(', ', array_keys($manifest[$section] ?? [])) ?: '(none)'
				)
			);
		}

		return $manifest[$section][$key];
	}

	/**
	 * The database connection.
	 *
	 * @return  Database
	 * @since   4.3.0
	 */
	private function db(): Database
	{
		return $this->db ??= new Database($this->config);
	}

	/**
	 * Absolute path to the provisioned site's document root on the host.
	 *
	 * @return  string
	 * @since   4.3.0
	 */
	private function siteRoot(): string
	{
		$root = rtrim($this->config->getSiteRoot(), '/');

		if (!is_dir($root))
		{
			throw new RuntimeException(
				sprintf('The site root %s does not exist. Run tests/integration/docker/run.sh first.', $root)
			);
		}

		return $root;
	}
}
