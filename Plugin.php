<?php

namespace JanVince\SmallExtensions;

use \Illuminate\Support\Facades\Event;
use System\Classes\PluginBase;
use System\Classes\PluginManager;
use JanVince\SmallExtensions\Models\Settings;

class Plugin extends PluginBase {

	/**
	 * @var array Plugin dependencies
	 */
	public $require = ['RainLab.Blog'];

	/**
	 * Returns information about this plugin.
	 *
	 * @return array
	 */
	public function pluginDetails() {
		return [
			'name' => 'janvince.smallextensions::lang.plugin.name',
			'description' => 'janvince.smallextensions::lang.plugin.description',
			'author' => 'Jan Vince',
			'icon' => 'icon-universal-access'
		];
	}

	public function boot() {

		/*
		 * Replace default MD editor ?
		 */
		if (Settings::get('blog_wysiwyg')) {

			Event::listen('backend.form.extendFields', function($widget) {

				if (!$widget->getController() instanceof \RainLab\Blog\Controllers\Posts) {
					return;
				}

				if (!$widget->model instanceof \RainLab\Blog\Models\Post) {
					return;
				}


				$content = [
					'tab' => 'rainlab.blog::lang.post.tab_edit',
					'stretch' => 'true'
				];

				/*
				 * Custom toolbar?
				 */
				if (trim(Settings::get('blog_wysiwyg_toolbar'))) {
					$content['toolbarButtons'] = str_replace(' ', '', trim(Settings::get('blog_wysiwyg_toolbar')) );
				}

				/*
				 * Check the Rainlab.Translate plugin is installed
				 */
				$pluginManager = PluginManager::instance()->findByIdentifier('Rainlab.Translate');
				if ($pluginManager && !$pluginManager->disabled) {
					$content['type'] = 'mlricheditor';
				} else {
					$content['type'] = 'richeditor';
				}

				$widget->addSecondaryTabFields(['content' => $content]);
			});
		}

		/*
		 * Add Static.Menu fields
		 */
		if (Settings::get('static_pages_menu_notes')) {

			Event::listen('backend.form.extendFields', function ($widget) {

				if (
						!$widget->getController() instanceof \RainLab\Pages\Controllers\Index ||
						!$widget->model instanceof \RainLab\Pages\Classes\MenuItem
				) {
					return;
				}

				$widget->addTabFields([
					'viewBag[note]' => [
						'tab' => 'janvince.smallextensions::lang.static_menu.notes',
						'label' => 'janvince.smallextensions::lang.static_menu.add_note',
						'commentAbove' => 'janvince.smallextensions::lang.static_menu.add_note_comment',
						'type' => 'textarea'
					]
				]);
			});
		}
	}

	public function registerSettings() {

		return [
			'settings' => [
				'label' => 'janvince.smallextensions::lang.plugin.name',
				'description' => 'janvince.smallextensions::lang.plugin.description',
				'icon' => 'icon-universal-access',
				'class' => 'JanVince\SmallExtensions\Models\Settings',
				'keywords' => 'extension extensions blog static pages menu',
				'order' => 10,
				'permissions' => ['janvince.smallextensions.settings'],
			]
		];
	}

}
