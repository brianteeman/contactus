<?php
/**
 * @package   contactus
 * @copyright Copyright (c)2013-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\ContactUs\Site\Helper;

defined('_JEXEC') || die;

use Joomla\CMS\Environment\Browser;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Uri\Uri;
use Joomla\Http\HttpFactory;
use Joomla\Utilities\IpHelper;
use Throwable;

class Akismet
{
	public static function isSpamContent(string $apiKey, string $name, string $email, string $content): bool
	{
		if (empty($apiKey) || !preg_match('/^[a-z0-9]{1,64}$/i', $apiKey))
		{
			return false;
		}

		try
		{
			$app    = Factory::getApplication();
			$struct = [
				'api_key'              => $apiKey,
				'blog'                 => Uri::base(),
				'user_ip'              => IpHelper::getIp(),
				'user_agent'           => Browser::getInstance()->getAgentString(),
				'referrer'             => $app->getInput()->server->get('HTTP_REFERER', '', 'raw'),
				'comment_type'         => 'contact-form',
				'comment_author'       => $name,
				'comment_author_email' => $email,
				'comment_content'      => $content,
			];

			$http = (new HttpFactory())->getHttp([
				'timeout'         => 5,
				'follow_location' => false,
			]);

			$response = $http->post('https://rest.akismet.com/1.1/comment-check', $struct);

			return trim((string) $response->getBody()) === 'true';
		}
		catch (Throwable $e)
		{
			Log::add('Akismet spam check failed: ' . $e->getMessage(), Log::WARNING, 'com_contactus');

			return false;
		}
	}
}