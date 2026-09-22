<?php
/**
 * @package   contactus
 * @copyright Copyright (c)2013-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\ContactUs\IntegrationTest\Tests;

defined('_JEXEC') or die;

use Akeeba\ContactUs\IntegrationTest\AbstractE2ETestCase;
use Akeeba\ContactUs\IntegrationTest\Engine\Response;
use Akeeba\ContactUs\IntegrationTest\Engine\Surfer;

/**
 * Who may do what in the back-end.
 *
 * The component relies on Joomla's stock AdminController / FormController ACL and token checks. These
 * tests hold it to that: core.manage opens the component, and each state change needs its own
 * privilege and the session's token. Every refusal has a control in which the Super User does the same
 * thing successfully.
 *
 * @since 4.3.0
 */
class BackendAccessTest extends AbstractE2ETestCase
{
	/**
	 * The Super User sees the stored messages and the categories.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testSuperUserSeesMessagesAndCategories(): void
	{
		$id      = static::$fixtures->createMessage('general');
		$subject = $this->messageRow($id)['subject'];

		$items = $this->superUser()->get($this->adminUrl(['view' => 'items']));

		$this->assertStatus(200, $items);
		$this->assertBodyContains($subject, $items);

		$categories = $this->superUser()->get($this->adminUrl(['view' => 'categories']));

		$this->assertStatus(200, $categories);
		$this->assertBodyContains('E2E Disabled', $categories, 'The back-end lists every category, disabled ones too.');
	}

	/**
	 * A guest reaching for the back-end gets the login form, not the list.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testGuestGetsTheLoginForm(): void
	{
		$id       = static::$fixtures->createMessage('general');
		$response = $this->guest()->get($this->adminUrl(['view' => 'items']));

		$this->assertBodyContains('name="passwd"', $response);
		$this->assertBodyNotContains($this->messageRow($id)['subject'], $response);
	}

	/**
	 * A back-end user explicitly denied core.manage on the component is refused everywhere in it.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testUserWithoutManageIsRefused(): void
	{
		$surfer = $this->loggedInBackend('nomanage');
		$id     = static::$fixtures->createMessage('general');

		foreach (['items', 'categories'] as $view)
		{
			$response = $surfer->get($this->adminUrl(['view' => $view]));

			$this->assertRefused($surfer, $response, 'view=' . $view);
			$this->assertBodyNotContains($this->messageRow($id)['subject'], $response);
		}

		$response = $surfer->post($this->adminUrl(), ['task' => 'items.delete', 'cid' => [$id], $surfer->fetchToken('administrator/index.php') => 1]);

		$this->assertRefused($surfer, $response);
		$this->assertNotNull($this->messageRow($id), 'The message was deleted.');
	}

	/**
	 * core.manage alone lists the messages, but does not delete them.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testViewerCanListButNotDelete(): void
	{
		$surfer = $this->loggedInBackend('viewer');
		$id     = static::$fixtures->createMessage('general');

		$list = $surfer->get($this->adminUrl(['view' => 'items']));

		$this->assertStatus(200, $list);
		$this->assertBodyContains($this->messageRow($id)['subject'], $list);

		$response = $this->deleteMessages($surfer, [$id]);

		$this->assertRefused($surfer, $response);
		$this->assertNotNull($this->messageRow($id), 'A user without core.delete deleted a message.');
	}

	/**
	 * The Super User deletes a message — the control for every refused deletion above.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testSuperUserCanDelete(): void
	{
		$id    = static::$fixtures->createMessage('general');
		$other = static::$fixtures->createMessage('general');

		$response = $this->deleteMessages($this->superUser(), [$id]);

		$this->assertTrue($response->isRedirect(), $response->summary());
		$this->assertNull($this->messageRow($id), 'The message was not deleted.');
		$this->assertNotNull($this->messageRow($other), 'A message that was not selected was deleted.');
	}

	/**
	 * Even the Super User cannot delete without the session's token.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testDeleteWithoutTokenIsRefused(): void
	{
		$surfer = $this->superUser();
		$id     = static::$fixtures->createMessage('general');

		$response = $this->deleteMessages($surfer, [$id], '');

		$this->assertRefused($surfer, $response);
		$this->assertNotNull($this->messageRow($id), 'The message was deleted without a token.');
	}

	/**
	 * The Super User creates a category through the real edit form; its recipients are stored as a
	 * comma-separated list, and the public form offers it at once.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testSuperUserCreatesCategory(): void
	{
		$title    = 'E2E New Category ' . bin2hex(random_bytes(4));
		$response = $this->saveCategory($this->superUser(), $title);

		$this->assertTrue($response->isRedirect(), $response->summary());

		$row = $this->db()->row('SELECT * FROM #__contactus_categories WHERE title = ?', [$title]);

		$this->assertNotNull($row, 'The category was not created.');
		$this->assertSame('first@example.test,second@example.test', $row['email']);
		$this->assertSame(1, (int) $row['enabled']);

		try
		{
			$this->assertBodyContains($title, $this->newGuest()->get($this->siteUrl()), 'The new public category is not offered.');
		}
		finally
		{
			$this->db()->query('DELETE FROM #__contactus_categories WHERE title = ?', [$title]);
		}
	}

	/**
	 * core.manage alone does not create categories.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testViewerCannotCreateCategory(): void
	{
		$surfer   = $this->loggedInBackend('viewer');
		$title    = 'E2E Forbidden Category ' . bin2hex(random_bytes(4));
		$response = $this->saveCategory($surfer, $title);

		$this->assertRefused($surfer, $response);
		$this->assertNull($this->db()->row('SELECT * FROM #__contactus_categories WHERE title = ?', [$title]), 'The category was created.');
	}

	/**
	 * Opening a message's edit form needs core.edit (and, as in core components, an edit id held by
	 * going through task=item.edit). With core.manage alone, the edit layout reached directly by URL
	 * must not show the message.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testEditLayoutNeedsEditPermission(): void
	{
		$secret = 'E2E-BACKEND-SECRET-' . bin2hex(random_bytes(5));
		$id     = static::$fixtures->createMessage('general', ['body' => $secret]);

		// Control: the page does show the message to someone allowed to edit it.
		$control = $this->superUser();
		$control->followRedirects = true;
		$this->assertBodyContains($secret, $control->get($this->adminUrl(['task' => 'item.edit', 'contactus_item_id' => $id])));

		$response = $this->loggedInBackend('viewer')->get($this->adminUrl(['view' => 'item', 'layout' => 'edit', 'contactus_item_id' => $id]));

		$this->assertBodyNotContains($secret, $response, 'a back-end user with core.manage only (no core.edit) reads a message through view=item&layout=edit&contactus_item_id=N.');
	}

	/**
	 * A malformed list filter must not break the list. The filter is kept in the user's session, so a
	 * failure here repeats on every later visit — and it is a plain GET, so a link is enough to do it
	 * to someone else.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testArrayFilterDoesNotBreakTheList(): void
	{
		// A session of its own: the point is what this session is left with.
		$surfer = new Surfer(static::$config->getSiteUrl());
		[$username, $password] = static::$config->getAdminCredentials();
		$this->session->loginBackend($surfer, $username, $password);

		$surfer->get($this->adminUrl(['view' => 'items', 'filter' => ['search' => ['x']]]));

		$after = $surfer->get($this->adminUrl(['view' => 'items']));

		$this->assertOrKnownIssue(
			$after->code === 200,
			7,
			sprintf('after one request with filter[search][]=x the messages list answers HTTP %d on every visit for the rest of the session.', $after->code)
		);
	}

	/**
	 * POST items.delete for the given messages.
	 *
	 * @param   Surfer       $surfer  A back-end surfer.
	 * @param   int[]        $ids     The messages.
	 * @param   string|null  $token   The token; null for the session's real one, '' for none.
	 *
	 * @return  Response
	 * @since   4.3.0
	 */
	private function deleteMessages(Surfer $surfer, array $ids, ?string $token = null): Response
	{
		$token ??= $surfer->fetchToken($this->adminUrl(['view' => 'items']));
		$data  = ['task' => 'items.delete', 'cid' => $ids, 'boxchecked' => count($ids)];

		if ($token !== '')
		{
			$data[$token] = 1;
		}

		return $surfer->post($this->adminUrl(), $data);
	}

	/**
	 * Create a category through the back-end edit form, as a browser would.
	 *
	 * @param   Surfer  $surfer  A back-end surfer.
	 * @param   string  $title   The new category's title.
	 *
	 * @return  Response
	 * @since   4.3.0
	 */
	private function saveCategory(Surfer $surfer, string $title): Response
	{
		// The token is the session's, wherever it is read; the list page renders it for everyone who may
		// open the component.
		$token = $surfer->fetchToken($this->adminUrl(['view' => 'categories']));

		return $surfer->post(
			$this->adminUrl(),
			[
				'task'  => 'category.save',
				'jform' => [
					'contactus_category_id' => '',
					'title'                 => $title,
					'email'                 => [
						'email0' => ['item' => 'first@example.test'],
						'email1' => ['item' => 'second@example.test'],
					],
					'sendautoreply'         => '0',
					'autoreply'             => '',
					'access'                => '1',
					'language'              => '*',
					'ordering'              => '',
					'enabled'               => '1',
				],
				$token  => 1,
			]
		);
	}
}
