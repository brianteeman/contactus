<?php
/**
 * @package   contactus
 * @copyright Copyright (c)2013-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\ContactUs\IntegrationTest\Tests;

defined('_JEXEC') or die;

use Akeeba\ContactUs\IntegrationTest\AbstractE2ETestCase;
use Akeeba\ContactUs\IntegrationTest\SiteProvisioner;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Regression test for b3a24d8: the `layout` URL parameter could make a back-end page include a PHP
 * file from outside the templates folder.
 *
 * `layout=TEMPLATE:LAYOUT` asks for a template override; ViewLoadAnyTemplateTrait::loadTemplate()
 * used TEMPLATE as a path segment without cleaning it. The provisioner plants a file where
 * `layout=../../tmp/e2e-traversal:default` used to resolve to (see
 * SiteProvisioner::deployTraversalFixture()), printing a marker nothing else prints.
 *
 * Verified to fail against the code before b3a24d8, by reverting the fix in the installed copy.
 *
 * @since 4.3.0
 */
class LayoutTraversalTest extends AbstractE2ETestCase
{
	/**
	 * Every back-end view using ViewLoadAnyTemplateTrait, and traversal spellings.
	 *
	 * @return  array<string, array{0: array, 1: string}>
	 * @since   4.3.0
	 */
	public static function traversals(): array
	{
		$cases = [];

		foreach (['items' => [], 'categories' => []] as $view => $extra)
		{
			foreach (['../../', '/../../', '..%2F..%2F'] as $prefix)
			{
				$cases[$view . ' via ' . $prefix] = [['view' => $view] + $extra, rawurldecode($prefix) . SiteProvisioner::TRAVERSAL_DIR . ':default'];
			}
		}

		return $cases;
	}

	/**
	 * The planted file is never included, and the page still renders.
	 *
	 * @param   array   $query   The page.
	 * @param   string  $layout  The layout parameter.
	 *
	 * @return  void
	 * @since   4.3.0
	 */
	#[DataProvider('traversals')]
	public function testLayoutTemplateCannotLeaveTheTemplatesFolder(array $query, string $layout): void
	{
		$fixture = static::$config->getSiteRoot() . '/' . SiteProvisioner::TRAVERSAL_DIR . '/html/com_contactus/' . $query['view'] . '/default.php';

		$this->assertFileExists($fixture, 'The traversal fixture is missing; the test would prove nothing.');

		$response = $this->superUser()->get($this->adminUrl($query + ['layout' => $layout]));

		$this->assertNotServerError($response);
		$this->assertBodyNotContains(SiteProvisioner::TRAVERSAL_MARKER, $response, 'A file outside the templates folder was included.');
	}
}
