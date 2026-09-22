<?php
/**
 * @package   contactus
 * @copyright Copyright (c)2013-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\ContactUs\IntegrationTest\Tests;

defined('_JEXEC') or die;

use Akeeba\ContactUs\IntegrationTest\AbstractE2ETestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The public form writes new messages; it must never read or rewrite the ones already stored.
 *
 * The site ItemModel extends the back-end AdminModel, which loads and saves "the record whose id is
 * in the request". Every stored message is personal data (name, email address, what they wrote), so
 * any request parameter that reaches that code is a disclosure or tampering primitive for anyone on
 * the internet.
 *
 * @since 4.3.0
 */
class StoredMessageProtectionTest extends AbstractE2ETestCase
{
	/**
	 * The ways a guest can point the form at an existing record by id.
	 *
	 * @return  array<string, array{0: array}>
	 * @since   4.3.0
	 */
	public static function displayRequests(): array
	{
		return [
			'task=item.display' => [['task' => 'item.display']],
			'view=item'         => [['view' => 'item']],
			'default task'      => [[]],
		];
	}

	/**
	 * A guest cannot read someone else's stored message through the form.
	 *
	 * @param   array  $query  How the form is requested.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	#[DataProvider('displayRequests')]
	public function testGuestCannotReadAStoredMessage(array $query): void
	{
		$secret = 'E2E-SECRET-' . bin2hex(random_bytes(6));
		$id     = static::$fixtures->createMessage('general', ['body' => $secret, 'fromemail' => 'victim-' . $secret . '@example.test']);

		$response = $this->guest()->get($this->siteUrl($query + ['contactus_item_id' => $id]));

		// The form rendered; otherwise "the secret is absent" would prove nothing.
		$this->assertStatus(200, $response);
		$this->assertBodyContains('name="jform[fromname]"', $response, 'The contact form did not render.');

		$this->assertOrKnownIssue(
			!str_contains($response->body, $secret),
			1,
			sprintf('a guest reads stored message #%d (sender, email, subject, body) by passing contactus_item_id to the public form.', $id)
		);
	}

	/**
	 * A guest's submission carrying an existing message id creates a new message and leaves the
	 * existing one alone.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testGuestCannotOverwriteAStoredMessageThroughTheRequest(): void
	{
		$id     = static::$fixtures->createMessage('general');
		$before = $this->messageRow($id);
		$jform  = $this->contactData('general');

		$response = $this->submitContact($this->guest(), $jform, null, ['contactus_item_id' => $id]);

		$this->assertAccepted($response, 'A valid submission must be accepted whatever else the request carries.');

		$after   = $this->messageRow($id);
		$created = $this->messagesMatching($this->markerOf($jform));

		$this->assertOrKnownIssue(
			$after === $before && count($created) === 1 && (int) $created[0]['contactus_item_id'] !== $id,
			2,
			sprintf(
				'a guest overwrote stored message #%d (its subject is now "%s") by adding contactus_item_id to the form POST.',
				$id,
				$after['subject'] ?? '(deleted)'
			)
		);
	}

	/**
	 * The same, with the id smuggled inside the form data. ItemModel::save() drops it explicitly.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	public function testGuestCannotOverwriteAStoredMessageThroughTheFormData(): void
	{
		$id     = static::$fixtures->createMessage('general');
		$before = $this->messageRow($id);
		$jform  = $this->contactData('general', ['contactus_item_id' => (string) $id]);

		$this->assertAccepted($this->submitContact($this->guest(), $jform));

		$this->assertSame($before, $this->messageRow($id), 'The stored message was modified.');
		$this->assertCount(1, $this->messagesMatching($this->markerOf($jform)), 'The submission must be a new message.');
	}
}
