<?php
/**
 * @package   contactus
 * @copyright Copyright (c)2013-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

/**
 * Provision the ContactUs fixtures against an already-running stack.
 *
 * docker/run.sh calls this after installing the packages. You can also run it by hand against a
 * stack left up with --keep-containers, to put the fixtures back the way they started:
 *
 *     php tests/integration/provision.php
 */

require_once __DIR__ . '/autoload.php';

use Akeeba\ContactUs\IntegrationTest\Engine\Configuration;
use Akeeba\ContactUs\IntegrationTest\SiteProvisioner;

$config = Configuration::getInstance();

fwrite(STDOUT, sprintf("Provisioning ContactUs fixtures (config: %s)\n", $config->getSourceFile()));

try
{
	$manifest = (new SiteProvisioner($config))->provision();
}
catch (Throwable $e)
{
	fwrite(STDERR, $e->getMessage() . "\n");

	exit(1);
}

fwrite(
	STDOUT,
	sprintf(
		"  %d user groups, %d users, %d contact categories\n",
		count($manifest['groups'] ?? []),
		count($manifest['users'] ?? []),
		count($manifest['categories'] ?? [])
	)
);

exit(0);
