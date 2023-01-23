<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.cookiemanager
 *
 * @copyright   (C) 2021 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;

/**
 * System plugin to manage cookies.
 *
 * @since  __DEPLOY_VERSION__
 */
class PlgSystemCookiemanager extends CMSPlugin
{
    /**
     * Load the language file on instantiation.
     *
     * @var    boolean
     * @since  __DEPLOY_VERSION__
     */
    protected $autoloadLanguage = true;

	/**
	 * Application object.
	 *
	 * @var    \Joomla\CMS\Application\CMSApplication
	 * @since  __DEPLOY_VERSION__
	 */
	protected $app;

	/**
	 * Template contents for cookie banners
	 *
	 * @var    string
	 * @since  __DEPLOY_VERSION__
	 */
	protected $bannerContent;

	/**
	 * Database object
	 *
	 * @var    \Joomla\Database\DatabaseDriver
	 * @since  __DEPLOY_VERSION__
	 */
	protected $db;

	/**
	 * Cookies
	 *
	 * @var    object
	 * @since  __DEPLOY_VERSION__
	 */
	protected $cookies;

	/**
	 * Cookie settings scripts
	 *
	 * @var    object
	 * @since  __DEPLOY_VERSION__
	 */
	protected $cookieScripts;

	/**
	 * Cookie categories
	 *
	 * @var    object
	 * @since  __DEPLOY_VERSION__
	 */
	protected $cookieCategories;

	/**
	 * Add assets for the cookie banners.
	 *
	 * @return  void
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function onBeforeCompileHead()
	{
		if (!$this->app->isClient('site'))
		{
			return;
		}

		$wa = $this->app->getDocument()->getWebAssetManager();
		$wa->registerAndUseScript(
				'plg_system_cookiemanager.script',
				'plg_system_cookiemanager/cookiemanager.min.js',
				['dependencies' => ['cookieconsent']],
			)
			->registerAndUseStyle(
				'plg_system_cookiemanager.style',
				'plg_system_cookiemanager/cookiemanager.min.css',
				['dependencies' => ['cookieconsent']],
			);

		$this->app->getLanguage()->load('com_cookiemanager', JPATH_ADMINISTRATOR);

		Text::script('PLG_SYSTEM_COOKIEMANAGER_BANNER_TITLE');
		Text::script('PLG_SYSTEM_COOKIEMANAGER_BANNER_DESC');
		Text::script('PLG_SYSTEM_COOKIEMANAGER_BANNER_BTN_ACCEPT_ALL');
		Text::script('PLG_SYSTEM_COOKIEMANAGER_BANNER_BTN_REJECT_ALL');
		Text::script('PLG_SYSTEM_COOKIEMANAGER_SETTINGS_TITLE');
		Text::script('PLG_SYSTEM_COOKIEMANAGER_SETTINGS_BTN_SAVE');
		Text::script('PLG_SYSTEM_COOKIEMANAGER_SETTINGS_BTN_ACCEPT_ALL');
		Text::script('PLG_SYSTEM_COOKIEMANAGER_SETTINGS_BTN_REJECT_ALL');
		Text::script('PLG_SYSTEM_COOKIEMANAGER_SETTINGS_BTN_CLOSE');
		Text::script('PLG_SYSTEM_COOKIEMANAGER_TABLE_HEADERS_COL1');
		Text::script('PLG_SYSTEM_COOKIEMANAGER_TABLE_HEADERS_COL2');
		Text::script('PLG_SYSTEM_COOKIEMANAGER_TABLE_HEADERS_COL3');
		Text::script('PLG_SYSTEM_COOKIEMANAGER_TABLE_HEADERS_COL4');

		$params = ComponentHelper::getParams('com_cookiemanager');

		$cookieManagerConfig = [];
		$cookieManagerConfig['expiration'] = $params->get('consent_expiration', 30);
		$cookieManagerConfig['position'] = $params->get('modal_position', null);
		$this->app->getDocument()->addScriptOptions('plg_system_cookiemanager.config', $cookieManagerConfig);

		$db    = $this->db;
		$query = $db->getQuery(true)
			->select($db->quoteName(['c.id', 'c.alias', 'a.cookie_name', 'a.cookie_desc', 'a.exp_period', 'a.exp_value']))
			->from($db->quoteName('#__categories', 'c'))
			->join(
				'RIGHT',
				$db->quoteName('#__cookiemanager_cookies', 'a') . ' ON ' . $db->quoteName('c.id') . ' = ' . $db->quoteName('a.catid') . 'WHERE' . $db->quoteName('a.published') . ' =  1'
			)
			->order($db->quoteName('lft'));

		$this->cookies = $db->setQuery($query)->loadObjectList();
		$this->app->getDocument()->addScriptOptions('plg_system_cookiemanager.cookies', $this->cookies);

		$query = $db->getQuery(true)
			->select($db->quoteName(['id', 'title', 'alias', 'description']))
			->from($db->quoteName('#__categories'))
			->where(
				[
					$db->quoteName('extension') . ' = ' . $db->quote('com_cookiemanager'),
					$db->quoteName('published') . ' =  1',
				]
			)
			->order($db->quoteName('lft'));

		$this->cookieCategories = $db->setQuery($query)->loadObjectList();
		$this->app->getDocument()->addScriptOptions('plg_system_cookiemanager.categories', $this->cookieCategories);

		$query = $db->getQuery(true)
			->select($db->quoteName(['a.type', 'a.position', 'a.code', 'a.catid']))
			->from($db->quoteName('#__cookiemanager_scripts', 'a'))
			->where($db->quoteName('a.published') . ' =  1')
			->join(
				'LEFT',
				$db->quoteName('#__categories', 'c') . ' ON ' . $db->quoteName('c.id') . ' = ' . $db->quoteName('a.catid')
			);

		$this->cookieScripts = $db->setQuery($query)->loadObjectList();
		$this->app->getDocument()->addScriptOptions('plg_system_cookiemanager.scripts', $this->cookieScripts);

		$cookieCodes = [];

		foreach ($this->cookieCategories as $category)
		{
			$cookie = $this->app->input->cookie->get('cookie_category_' . $category->alias);

			if (!isset($cookie) || $cookie === 'false')
			{
				$cookieCodes[$category->alias] = [];

				foreach ($this->cookieScripts as $script)
				{
					if ($category->id == $script->catid)
					{
						array_push($cookieCodes[$category->alias], $script);
					}
				}
			}
		}

		$this->app->getDocument()->addScriptOptions('plg_system_cookiemanager.codes', $cookieCodes);

		include PluginHelper::getLayoutPath('system', 'cookiemanager');
	}

	/**
	 * Echo the cookie banners, button and scripts.
	 *
	 * @return  void
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function onAfterRespond()
	{
		if (!$this->app->isClient('site'))
		{
			return;
		}

		// Return early in case of AJAX request
		if ($this->app->input->get('format') === 'json')
		{
			return;
		}

		// No need to load scripts when there are none
		if (empty($this->cookieScripts))
		{
			return;
		}

		echo '<button class="preview btn btn-info" data-bs-toggle="modal" data-bs-target="#consentBanner">'
			. Text::_('COM_COOKIEMANAGER_PREVIEW_BUTTON_TEXT')
			. '</button>';

		foreach ($this->cookieCategories as $category)
		{
			$cookie = $this->app->input->cookie->get('cookie_category_' . $category->alias);

			if (isset($cookie) && $cookie === 'true')
			{
				foreach ($this->cookieScripts as $script)
				{
					if ($category->id == $script->catid)
					{
						if ($script->type == 1 || $script->type == 2)
						{
							if ($script->position == 1)
							{
								$html = ob_get_contents();

								if ($html)
								{
									ob_end_clean();
								}

								echo str_replace('<head>', '<head>' . $script->code, $html);
							}
							elseif ($script->position == 2)
							{
								$html = ob_get_contents();

								if ($html)
								{
									ob_end_clean();
								}

								echo str_replace('</head>', $script->code . '</head>', $html);
							}
							elseif ($script->position == 3)
							{
								$html = ob_get_contents();

								if ($html)
								{
									ob_end_clean();
								}

								echo preg_replace('/<body[^>]+>\K/i', $script->code, $html);
							}
							else
							{
								$html = ob_get_contents();

								if ($html)
								{
									ob_end_clean();
								}

								echo str_replace('</body>', $script->code . '</body>', $html);
							}
						}
					}
				}
			}
		}
	}

	/**
	 * AJAX Handler
	 *
	 * @return  string
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function onAjaxCookiemanager()
	{
		$cookieConsentsData = $this->app->input->get('data', '', 'STRING');

		$cookieConsentsData = json_decode($cookieConsentsData);
		$ccuuid = bin2hex(random_bytes(32));
		$cookieConsentsData->ccuuid = $ccuuid;
		$cookieConsentsData->consent_date = Factory::getDate()->toSql();
		$cookieConsentsData->user_agent = $_SERVER['HTTP_USER_AGENT'];

		$this->db->insertObject('#__cookiemanager_consents', $cookieConsentsData);

		return $ccuuid;
	}
}
