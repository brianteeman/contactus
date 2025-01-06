<?php
/**
 * @package   contactus
 * @copyright Copyright (c)2013-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

defined('_JEXEC') || die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \Akeeba\Component\ContactUs\Site\View\Item\HtmlView $this */

?>
<form action="<?= Route::_('index.php?option=com_contactus&task=Item.save'); ?>"
	  aria-label="<?= Text::_('COM_CONTACTUS_TITLE_ITEMS_EDIT', true); ?>"
	  class="form-validate" id="profile-form" method="post" name="adminForm">

	<?= \Akeeba\Component\ContactUs\Site\Helper\ModuleRenderHelper::loadPosition('contactus_top') ?>

	<?= \Akeeba\Component\ContactUs\Site\Helper\ModuleRenderHelper::loadPosition('contactus_middle') ?>

	<?= \Akeeba\Component\ContactUs\Site\Helper\ModuleRenderHelper::loadPosition('contactus_bottom') ?>

	<?= HTMLHelper::_('form.token'); ?>
</form>
