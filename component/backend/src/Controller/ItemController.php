<?php
/**
 * @package   contactus
 * @copyright Copyright (c)2013-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\ContactUs\Administrator\Controller;

defined('_JEXEC') || die;

use Akeeba\Component\ContactUs\Administrator\Mixin\ControllerEventsTrait;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\FormController;
use Joomla\CMS\Router\Route;

class ItemController extends FormController
{
	use ControllerEventsTrait;

	protected $text_prefix = 'COM_CONTACTUS_ITEM';

	public function display($cachable = false, $urlparams = [])
	{
		$layout = $this->input->get('layout', 'default');
		$id     = $this->input->getInt('contactus_item_id', $this->input->getInt('id', 0));

		// Check for edit form.
		if ($layout === 'edit' && !$this->checkEditId('com_contactus.edit.item', $id))
		{
			// Somehow the person just went to the form and didn't use the edit action
			$this->setMessage(Text::sprintf('JLIB_APPLICATION_ERROR_UNHELD_ID', $id), 'error');
			$this->setRedirect(Route::_('index.php?option=com_contactus&view=items', false));

			return false;
		}

		return parent::display($cachable, $urlparams);
	}
}