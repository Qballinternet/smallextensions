<?php

namespace JanVince\SmallExtensions;

use \Illuminate\Support\Facades\Event;
use System\Classes\PluginBase;
use System\Classes\PluginManager;
use JanVince\SmallExtensions\Models\Settings;
use JanVince\SmallExtensions\Models\BlogFields;
use JanVince\SmallExtensions\Models\AdminFields;
use Config;
use Auth;
use Log;
use BackendAuth;
use Redirect;
use Schema;
use Backend\Models\User as UserModel;


class Plugin extends PluginBase {

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

  public function listUsers($fieldName, $value, $formData)
  {
      return ['published' => 'Published'];
  }

  public function boot() {

    /**
     * Add relation
     */

    // Check for Rainlab.Blog plugin
    $pluginManager = PluginManager::instance()->findByIdentifier('Winter.Blog');

    if ($pluginManager && !$pluginManager->disabled) {

      \Winter\Blog\Models\Post::extend(function($model) {
        $model->hasOne['custom_fields'] = ['JanVince\SmallExtensions\Models\BlogFields', 'delete' => 'true', 'key' => 'post_id', 'otherKey' => 'id'];
        $model->attachOne = ['featured_image' => ['System\Models\File']];

        /**
         * If Blog plugin exists but there is no custom_repeater column, create it
         * Mostly because Rainlab Blog plugin was installed after Small Extensions
         */
        if (Schema::hasTable('winter_blog_posts') and !Schema::hasColumn('winter_blog_posts', 'custom_repeater')) 
        {
          Schema::table('winter_blog_posts', function($table)
          {
              $table->text('custom_repeater')->nullable();
          });
        }
        
        
        $model->addJsonable('custom_repeater');

        /*
        * Deferred bind doesn't work with extended models?
        * I haven't found a better way yet :(
        */
        $model->bindEvent('model.afterSave', function() use ($model) {

          /*
          * Custom fields model deferred bind
          */
          if (!$model->custom_fields) {
            $sessionKey = uniqid('session_key', true);

            $custom_fields = new BlogFields;
            $model->custom_fields = $custom_fields;
          }

          $model->custom_fields->post_id = $model->id;
          $model->custom_fields->save();
        });

        if( BackendAuth::getUser() && Settings::get('blog_author') ) {

          /**
          *  Other users only for user with correct permission
          */
          if( BackendAuth::getUser()->hasAccess('winter.blog.access_other_posts') ) {
            $users = UserModel::get();

            $usersFormated = [];

            foreach($users as $user){
                $usersFormated[$user->id] = ($user->last_name . ' ' . $user->first_name);
            }

          } else {
            $user = BackendAuth::getUser();
            $usersFormated[ $user->id ] = ($user->last_name . ' ' . $user->first_name);
          }

          $model->addDynamicMethod('listUsers', function() use($usersFormated) {
                  return $usersFormated;
              });

        }

      });

        \Winter\Blog\Controllers\Posts::extendListColumns(function($list, $model)
        {
            if (!$model instanceof \Winter\Blog\Models\Post) {
                return;
            }

            /**
             * Author column
             */
            $column = [
              'author' => [
                'label' => 'janvince.smallextensions::lang.labels.author',
                'relation' => 'user',
                'select' => 'concat(first_name, " ",last_name)',
                'searchable' => true,
                'invisible' => true,
              ],
            ];

            $list->addColumns($column);

            if(Settings::get('custom_repeater_allow', null) and Settings::get('custom_repeater_fields', null))
            {
              
              $fields = Settings::get('custom_repeater_fields', []);
              
              $columnTypesMap = [
                'number' => 'number',
                'datepicker' => 'date',
              ];

              foreach($fields as $field) 
              {
                $columns = [];
                $fieldType = 'sme_json_field';


                if(isset($field['custom_repeater_field_type']) 
                  and $field['custom_repeater_field_type']
                  and $field['custom_repeater_field_type'] != 'section')
                {

                  if(Settings::get('custom_repeater_simple', null))
                  {
                    $fieldType = 'text';

                    if(isset($columnTypesMap[$field['custom_repeater_field_type']]))
                    {
                      $fieldType = $columnTypesMap[$field['custom_repeater_field_type']];
                    }

                  }

                  $columns = [
                      'custom_repeater['.$field['custom_repeater_field_name'].']' => [
                          'label' => $field['custom_repeater_field_label'],
                          'type' => $fieldType,
                          'repeaterValue' => $field['custom_repeater_field_name'],
                          'repeaterType' => $field['custom_repeater_field_type'],
                          'invisible' => true,
                          'searchable' => false,
                      ]
                  ];
                }

                $list->addColumns($columns);
              }
            }
        });
    }

    // Check for Rainlab.User plugin
    $pluginManagerUser = PluginManager::instance()->findByIdentifier('Winter.User');

    if ( ($pluginManager && !$pluginManager->disabled) and  
        ($pluginManagerUser && !$pluginManagerUser->disabled) ){

      \Winter\Blog\Models\Post::extend(function($model) {
          
        $usersFormated = [];

        if( Settings::get('blog_winter_user') ) {

            $users = \Winter\User\Models\User::get();

            foreach($users as $user){
                $usersFormated[$user->id] = ($user->surname . ' ' . $user->name);
            }

        } 
            
        $model->addDynamicMethod('listWinterUsers', function() use($usersFormated) {
            return $usersFormated;
        });
            

      });

      \JanVince\SmallExtensions\Models\BlogFields::extend(function($model) {

        $model->belongsTo['winter_user'] = ['Winter\User\Models\User', 'key' => 'winter_user_id', 'otherKey' => 'id'];

      });

    }

    Event::listen('backend.form.extendFields', function($widget) {

      if (!$widget->getController() instanceof \Winter\Blog\Controllers\Posts) {
        return;
      }

      if (!$widget->model instanceof \Winter\Blog\Models\Post) {
        return;
      }

      if( $widget->isNested ) {
          return;
      }

      /*
      * Replace default MD editor ?
      */
      if (Settings::get('blog_wysiwyg')) {

        /*
        * WYSIWYG editor
        */
        $wysiwyg_editor = [
          'tab' => 'winter.blog::lang.post.tab_edit',
          'stretch' => 'true'
        ];

        /*
         * Custom toolbar?
         */
        if (trim(Settings::get('blog_wysiwyg_toolbar'))) {
          $wysiwyg_editor['toolbarButtons'] = str_replace(' ', '', trim(Settings::get('blog_wysiwyg_toolbar')) );
        }

        /*
         * Check the Rainlab.Translate plugin is installed
         */
        $pluginManager = PluginManager::instance()->findByIdentifier('Winter.Translate');
        if ($pluginManager && !$pluginManager->disabled) {
          $wysiwyg_editor['type'] = 'mlricheditor';
        } else {
          $wysiwyg_editor['type'] = 'richeditor';
        }

        $widget->addTabFields(['content' => $wysiwyg_editor]);

      }

      /*
      * Custom fields model deferred bind
      */
      if (!$widget->model->custom_fields) {
        $sessionKey = uniqid('session_key', true);

        $custom_fields = new BlogFields;
        $widget->model->custom_fields = $custom_fields;
      }

      /*
      * Author field
      */
      if( Settings::get('blog_author') ) {

        $user = BackendAuth::getUser();

        if( !empty($user->id) ) {
          $defaultValue = $user->id;
        } else {
          $defaultValue = NULL;
        }

        $field = [
          'user_id' => [
            'label' => 'janvince.smallextensions::lang.labels.author',
            'comment' => 'janvince.smallextensions::lang.labels.author_comment',
            'span' => 'left',
            'type' => 'dropdown',
            'options' => 'listUsers',
            'default' => $defaultValue,
            'tab' => 'janvince.smallextensions::lang.tabs.custom_fields'
          ],
        ];

        /**
        *  Empty option only for user with correct permission
        */
        if( BackendAuth::getUser()->hasAccess('winter.blog.access_other_posts') ) {
          $field['user_id']['emptyOption'] = 'janvince.smallextensions::lang.labels.author_empty';
        }

        $widget->addTabFields( $field );

        $widget->removeField('user');
      }

      /*
      * Rainlab User field
      */
    // Check for Rainlab.User plugin
    $pluginManagerUser = PluginManager::instance()->findByIdentifier('Winter.User');

      if( ($pluginManagerUser && !$pluginManagerUser->disabled) and Settings::get('blog_winter_user') ) {

        $field = [
          'custom_fields[winter_user_id]' => [
            'label' => 'janvince.smallextensions::lang.labels.winter_user',
            'comment' => 'janvince.smallextensions::lang.labels.winter_user_comment',
            'span' => 'left',
            'type' => 'dropdown',
            'options' => 'listWinterUsers',
            'tab' => 'janvince.smallextensions::lang.tabs.custom_fields'
          ],
        ];

        $field['custom_fields[winter_user_id]']['emptyOption'] = 'janvince.smallextensions::lang.labels.winter_user_empty';

        $widget->addTabFields( $field );

      }

      /*
      * API code field
      */
      if(Settings::get('blog_custom_fields_api_code')) {

        $fields = [
          'custom_fields[api_code]' => [
            'label' => ( Settings::get('blog_custom_fields_api_code_label') ? Settings::get('blog_custom_fields_api_code_label') : 'janvince.smallextensions::lang.labels.custom_fields_api_code'),
            'comment' => 'janvince.smallextensions::lang.labels.custom_fields_api_code_description',
            'span' => 'full',
            'type' => 'text',
            'deferredBinding' => 'true',
            'tab' => 'janvince.smallextensions::lang.tabs.custom_fields'
          ]
        ];

        /*
         * Check the Rainlab.Translate plugin is installed
         */
        $pluginManager = PluginManager::instance()->findByIdentifier('Winter.Translate');
        
        if ($pluginManager && !$pluginManager->disabled) 
        {
          $fields['custom_fields[api_code]']['type'] = 'text';
        } else {
          $fields['custom_fields[api_code]']['type'] = 'text';
        }

        $widget->addTabFields($fields);


        // dump($widget->model);
        // dd($widget);
      }

      /*
      * String field
      */
      if(Settings::get('blog_custom_fields_string')) {

        $string = [
          'label' => ( Settings::get('blog_custom_fields_string_label') ? Settings::get('blog_custom_fields_string_label') : 'janvince.smallextensions::lang.labels.custom_fields_string'),
          'comment' => 'janvince.smallextensions::lang.labels.custom_fields_string_description',
          'span' => 'full',
          'deferredBinding' => 'true',
          'tab' => 'janvince.smallextensions::lang.tabs.custom_fields'
        ];

        /*
         * Check the Rainlab.Translate plugin is installed
         */
         // TODO: Translation not work with relation - find out more about this!

        $pluginManager = PluginManager::instance()->findByIdentifier('Winter.Translate');
        if ($pluginManager && !$pluginManager->disabled) {
          $string['type'] = 'text';  // TODO: Find out why 'mltext' not work.
        } else {
          $string['type'] = 'text';
        }

        $widget->addTabFields([
          'custom_fields[string]' => $string
        ]);

      }

      /*
      * Text field
      */
      if(Settings::get('blog_custom_fields_text')) {

        $string = [
          'label' => ( Settings::get('blog_custom_fields_text_label') ? Settings::get('blog_custom_fields_text_label') : 'janvince.smallextensions::lang.labels.custom_fields_text'),
          'comment' => 'janvince.smallextensions::lang.labels.custom_fields_text_description',
          'span' => 'full',
          'deferredBinding' => 'true',
          'tab' => 'janvince.smallextensions::lang.tabs.custom_fields'
        ];

        /*
         * Check the Rainlab.Translate plugin is installed
         */
         // TODO: Translation not work with relation - find out more about this!

        $pluginManager = PluginManager::instance()->findByIdentifier('Winter.Translate');
        if ($pluginManager && !$pluginManager->disabled) {
          $string['type'] = 'richeditor';  // TODO: Find out why 'mlricheditor' not work.
        } else {
          $string['type'] = 'richeditor';
        }

        $widget->addTabFields([
          'custom_fields[text]' => $string
        ]);

      }


      /*
      * Datetime field
      */
      if(Settings::get('blog_custom_fields_datetime')) {

        $datetime = [
          'label' => ( Settings::get('blog_custom_fields_datetime_label') ? Settings::get('blog_custom_fields_datetime_label') : 'janvince.smallextensions::lang.labels.custom_fields_datetime'),
          'comment' => 'janvince.smallextensions::lang.labels.custom_fields_datetime_description',
          'type' => 'datepicker',
          'span' => 'left',
          'deferredBinding' => 'true',
          'tab' => 'janvince.smallextensions::lang.tabs.custom_fields'
        ];

        if(Config::get('app.locale') == 'cs'){
          $datetime['format'] = 'd.m.Y';
        }

        $widget->addTabFields([
          'custom_fields[datetime]' => $datetime
        ]);

      }

      /*
      * Switch field
      */
      if(Settings::get('blog_custom_fields_switch')) {

        $widget->addTabFields([
          'custom_fields[switch]' => [
            'label' => ( Settings::get('blog_custom_fields_switch_label') ? Settings::get('blog_custom_fields_switch_label') : 'janvince.smallextensions::lang.labels.custom_fields_switch'),
            'comment' => 'janvince.smallextensions::lang.labels.custom_fields_switch_description',
            'type' => 'switch',
            'span' => 'left',
            'deferredBinding' => 'true',
            'tab' => 'janvince.smallextensions::lang.tabs.custom_fields'
          ]
        ]);

      }

      /*
      * Image field
      */
      if(Settings::get('blog_custom_fields_image')) {

        $image = [
          'label' => ( Settings::get('blog_custom_fields_image_label') ? Settings::get('blog_custom_fields_image_label') : 'janvince.smallextensions::lang.labels.custom_fields_image'),
          'comment' => 'janvince.smallextensions::lang.labels.custom_fields_image_description',
          'type' => 'mediafinder',
          'span' => 'left',
          'deferredBinding' => 'true',
          'mode' => 'image',
          'tab' => 'janvince.smallextensions::lang.tabs.custom_fields'
        ];

        $widget->addTabFields([
          'custom_fields[image]' => $image
        ]);

      }

      /*
      * Featured image field
      */
      if(Settings::get('blog_featured_image')) {

        $featuredImage = [
          'label' => ( Settings::get('blog_featured_image_label') ? Settings::get('blog_featured_image_label') : 'janvince.smallextensions::lang.labels.custom_fields_featured_image' ),
          'comment' => 'janvince.smallextensions::lang.labels.custom_fields_featured_image_description',
          'type' => 'mediafinder',
          'span' => 'left',
          'deferredBinding' => 'true',
          'mode' => 'image',
          'tab' => 'winter.blog::lang.post.tab_manage'
        ];

        $featuredImageTitle = [
          'label' => 'janvince.smallextensions::lang.labels.custom_fields_featured_image_title',
          'comment' => 'janvince.smallextensions::lang.labels.custom_fields_featured_image_title_description',
          'type' => 'text',
          'span' => 'right',
          'tab' => 'winter.blog::lang.post.tab_manage'
        ];

        $featuredImageAlt = [
          'label' => 'janvince.smallextensions::lang.labels.custom_fields_featured_image_alt',
          'comment' => 'janvince.smallextensions::lang.labels.custom_fields_featured_image_alt_description',
          'type' => 'textarea',
          'span' => 'right',
          'size' => 'tiny',
          'tab' => 'winter.blog::lang.post.tab_manage'
        ];

        $featuredImageSection = [
          'type' => 'section',
          'label' => 'janvince.smallextensions::lang.labels.custom_fields_featured_image_description',
          'tab' => 'winter.blog::lang.post.tab_manage'
        ];

        if(empty(Settings::get('blog_featured_image_both', null))) 
        {
          $widget->removeField('featured_images');
        }

        $widget->addTabFields([
          'section_featured_image' => $featuredImageSection,
          'custom_fields[featured_image]' => $featuredImage,
        ]);

        if(Settings::get('blog_featured_image_meta')) 
        {
          $widget->addTabFields([
            'custom_fields[featured_image_title]' => $featuredImageTitle,
            'custom_fields[featured_image_alt]' => $featuredImageAlt,
          ]);
        }
      }

      /*
      * Featured image field (from upload)
      */
      if(Settings::get('blog_featured_image_upload')) {

        $featuredImage = [
          'label' => ( Settings::get('blog_featured_image_upload_label') ? Settings::get('blog_featured_image_upload_label') : 'janvince.smallextensions::lang.labels.custom_fields_featured_image_upload' ),
          'comment' => 'janvince.smallextensions::lang.labels.custom_fields_featured_image_upload_description',
          'type' => 'fileupload',
          'span' => 'left',
          'deferredBinding' => 'true',
          'mode' => 'image',
          'tab' => 'winter.blog::lang.post.tab_manage'
        ];

        $featuredImageSection = [
          'type' => 'section',
          'label' => 'janvince.smallextensions::lang.labels.custom_fields_featured_image_upload_description',
          'tab' => 'winter.blog::lang.post.tab_manage'
        ];


        $widget->addTabFields([
          'section_featured_image_upload' => $featuredImageSection,
          'featured_image' => $featuredImage,
        ]);
      }

    });
    // Check for Rainlab.Blog plugin
    $pluginManager = PluginManager::instance()->findByIdentifier('Winter.Blog');

    if ($pluginManager && !$pluginManager->disabled) {

        \Winter\Blog\Models\Post::extend(function($model) {
          $model->hasOne['custom_fields_repeater'] = ['JanVince\SmallExtensions\Models\BlogFields', 'delete' => 'true', 'key' => 'post_id', 'otherKey' => 'id'];

          /*
          * Deferred bind doesn't work with extended models?
          * I haven't found a better way yet :(
          */
          $model->bindEvent('model.afterSave', function() use ($model) {
            $model->custom_fields_repeater->post_id = $model->id;
            $model->custom_fields_repeater->save();
          });

        });

        Event::listen('backend.form.extendFields', function($widget) {

          if (!$widget->getController() instanceof \Winter\Blog\Controllers\Posts) {
            return;
          }

          if (!$widget->model instanceof \Winter\Blog\Models\Post) {
            return;
          }

          if( $widget->isNested ) {
              return;
          }

          /*
          * Custom fields model deferred bind
          */
          if (!$widget->model->custom_fields) {
            $sessionKey = uniqid('session_key', true);

            $custom_fields = new BlogFields;
            $widget->model->custom_fields = $custom_fields;
          }

            
            /**
             * Custom repeater builder (new repeater)
             */
            if(Settings::get('custom_repeater_allow', null) and Settings::get('custom_repeater_fields', null)) {

              $fields = [];
              $counter = 0;

              foreach(Settings::get('custom_repeater_fields', null) as $field) {
                  
                  if(empty($field['custom_repeater_field_name'])) {
                      $fieldName = 'field' . $counter;
                  } else {
                      $fieldName = $field['custom_repeater_field_name'];
                  }

                  if (Settings::get('custom_repeater_simple', null))
                  {
                    if(!empty($field['custom_repeater_field_name']))
                    {
                      $fieldName = 'custom_repeater['.$field['custom_repeater_field_name'].']';
                    }
                    else 
                    {
                      $fieldName = 'custom_repeater['.$counter.']';
                    }
                  }


                  $fields[$fieldName] = [
                      'type' => $field['custom_repeater_field_type'],
                      'label' => $field['custom_repeater_field_label'],
                      'span' => $field['custom_repeater_field_span'],
                      'tab' => Settings::get('custom_repeater_tab_title', 'Data'),
                  ];


                  if(!empty($field['custom_repeater_field_attributes'])) 
                  {
                    foreach($field['custom_repeater_field_attributes'] as $value) 
                    {
                      $fields[$fieldName][$value['attribute_name']] = $value['attribute_value'];
                    }
                  }

                  if(!empty($field['custom_repeater_field_mode'])) {
                      $fields[$fieldName]['mode'] = $field['custom_repeater_field_mode'];
                  }

                  if(!empty($field['custom_repeater_field_size'])) {
                      $fields[$fieldName]['size'] = $field['custom_repeater_field_size'];
                  }

                  $options = [];

                  if(!empty($field['custom_repeater_field_options'])) 
                  {
                    foreach($field['custom_repeater_field_options'] as $value) 
                    {
                      $options[$value['option_key']] = $value['option_value'];
                    }
                  }
                  
                  $fields[$fieldName]['options'] = $options;

                }

              /*
              * Check the Rainlab.Translate plugin is installed
              */
              $repeaterType = 'repeater';

              $pluginManager = PluginManager::instance()->findByIdentifier('Winter.Translate');

              if ($pluginManager && !$pluginManager->disabled) {
                $repeaterType = 'mlrepeater';
              }

              /*
              * Check that schema and column exists!
              */


              if(Settings::get('custom_repeater_simple', null) )
              {
                $widget->addTabFields($fields);
              }
              else
              {
                $widget->addTabFields([
                    'custom_repeater' => [
                        'type' => $repeaterType,
                        'prompt' => Settings::get('custom_repeater_prompt', '+'),
                        'minItems' => Settings::get('custom_repeater_min_items', 0),
                        'maxItems' => Settings::get('custom_repeater_max_items', 0),
                        'tab' => Settings::get('custom_repeater_tab_title', 'Data'),
                        'form' => [
                            'fields' => $fields,
                        ]
                    ],
                ]);
              }
            }





            /*
            * Repeater fields (old repeater)
            */
            if(Settings::get('blog_custom_fields_repeater')) {

                $repeaterFields = [];

                if(Settings::get('blog_custom_fields_repeater_title_allow')) {

                    $repeaterFields['repeater_title'] = [
                        'label' => ( Settings::get('blog_custom_fields_repeater_title_label') ? Settings::get('blog_custom_fields_repeater_title_label') : 'janvince.smallextensions::lang.labels.custom_fields_repeater_items.title' ),
                        'type' => 'text',
                        'span' => 'left',
                    ];

                }

                /**
                 * Mimic translate plugin with locales field
                 */
                $pluginManager = PluginManager::instance()->findByIdentifier('Winter.Translate');

                if ($pluginManager && !$pluginManager->disabled && Settings::get('blog_custom_fields_repeater_allow_locale')) {

                  $localeModel = new \Winter\Translate\Models\Locale;
                  $localeArray = $localeModel->listEnabled();

                  $repeaterFields['repeater_locale'] = [
                        'label' => 'janvince.smallextensions::lang.labels.custom_fields_repeater_items.locale',
                        'type' => 'dropdown',
                        'emptyOption' => 'janvince.smallextensions::lang.labels.custom_fields_repeater_items.locale_empty_option',
                        'options' => $localeArray,
                        'span' => 'right',
                    ];
                } else {
                  $repeater['type'] = 'repeater';
                }

                if(Settings::get('blog_custom_fields_repeater_description_allow')) {

                    $repeaterFields['repeater_description'] = [
                        'label' => ( Settings::get('blog_custom_fields_repeater_description_label') ? Settings::get('blog_custom_fields_repeater_description_label') : 'janvince.smallextensions::lang.labels.custom_fields_repeater_items.description' ),
                        'type' => 'textarea',
                        'size' => 'tiny',
                        'span' => 'left',
                    ];

                }

                if(Settings::get('blog_custom_fields_repeater_image_allow')) {

                    $repeaterFields['repeater_image'] = [
                        'label' => ( Settings::get('blog_custom_fields_repeater_image_label') ? Settings::get('blog_custom_fields_repeater_image_label') : 'janvince.smallextensions::lang.labels.custom_fields_repeater_items.image' ),
                        'type' => 'mediafinder',
                        'mode' => 'image',
                        'span' => 'right',
                    ];

                }

                if(Settings::get('blog_custom_fields_repeater_file_allow')) {

                    $repeaterFields['repeater_file'] = [
                        'label' => ( Settings::get('blog_custom_fields_repeater_file_label') ? Settings::get('blog_custom_fields_repeater_file_label') : 'janvince.smallextensions::lang.labels.custom_fields_repeater_items.file' ),
                        'type' => 'mediafinder',
                        'mode' => 'file',
                        'span' => 'right',
                    ];

                }

                if(Settings::get('blog_custom_fields_repeater_url_allow')) {

                    $repeaterFields['repeater_url'] = [
                        'label' => ( Settings::get('blog_custom_fields_repeater_url_label') ? Settings::get('blog_custom_fields_repeater_url_label') : 'janvince.smallextensions::lang.labels.custom_fields_repeater_items.url' ),
                        'type' => 'text',
                        'span' => 'left',
                    ];

                }

                if(Settings::get('blog_custom_fields_repeater_text_allow')) {

                    $repeaterFields['repeater_text'] = [
                        'label' => ( Settings::get('blog_custom_fields_repeater_text_label') ? Settings::get('blog_custom_fields_repeater_text_label') : 'janvince.smallextensions::lang.labels.custom_fields_repeater_items.text' ),
                        'type' => 'richeditor',
                        'span' => 'full',
                    ];

                }

              $repeater = [
                'label' => ( Settings::get('blog_custom_fields_repeater_label') ? Settings::get('blog_custom_fields_repeater_label') : 'janvince.smallextensions::lang.labels.custom_fields_repeater'),
                'comment' => 'janvince.smallextensions::lang.labels.custom_fields_repeater_description',
                'span' => 'full',
                'deferredBinding' => 'true',
                'minItems' => Settings::get('blog_custom_fields_repeater_min_items', 0),
                'maxItems' => Settings::get('blog_custom_fields_repeater_max_items', 0),
                'tab' => 'janvince.smallextensions::lang.tabs.custom_fields_repeater',
                'prompt' => 'janvince.smallextensions::lang.labels.custom_fields_repeater_prompt',
                'form' => [
                    'fields' => $repeaterFields,
                ],
              ];

              /*
               * Check the Rainlab.Translate plugin is installed
               */
               // TODO: Translation not work with relation - find out more about this!

              $pluginManager = PluginManager::instance()->findByIdentifier('Winter.Translate');
              if ($pluginManager && !$pluginManager->disabled) {
                $repeater['type'] = 'repeater';  // TODO: Find out why 'mlrepeater' not work.
              } else {
                $repeater['type'] = 'repeater';
              }

              $widget->addTabFields([
                'custom_fields[repeater]' => $repeater
              ]);

            }

        });

    }

    /*
     * Add Static.Menu fields
     */
    if (Settings::get('static_pages_menu_notes')) {

      Event::listen('backend.form.extendFields', function ($widget) {

        if (
          !$widget->getController() instanceof \Winter\Pages\Controllers\Index ||
          !$widget->model instanceof \Winter\Pages\Classes\MenuItem
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

    if (Settings::get('static_pages_menu_image')) {

          Event::listen('backend.form.extendFields', function ($widget) {

            if (!$widget->getController() instanceof \Winter\Pages\Controllers\Index ||
                !$widget->model instanceof \Winter\Pages\Classes\MenuItem) {
              return;
            }

            $widget->addTabFields([
              'viewBag[image]' => [
                'tab' => 'janvince.smallextensions::lang.static_menu.image',
                'label' => 'janvince.smallextensions::lang.static_menu.add_image',
                'commentAbove' => 'janvince.smallextensions::lang.static_menu.add_image_comment',
                'type' => 'mediafinder',
                'mode' => 'image',
                'span' => 'full'
              ]
            ]);

          });

    }

    if (Settings::get('static_pages_menu_color')) {

          Event::listen('backend.form.extendFields', function ($widget) {

            if (!$widget->getController() instanceof \Winter\Pages\Controllers\Index || !$widget->model instanceof \Winter\Pages\Classes\MenuItem) {
              return;
            }

            $widget->addTabFields([
              'viewBag[color]' => [
                'tab' => 'janvince.smallextensions::lang.static_menu.color',
                'label' => 'janvince.smallextensions::lang.static_menu.add_color',
                'commentAbove' => 'janvince.smallextensions::lang.static_menu.add_color_comment',
                'type' => 'text',
              ],
            ]);
          });
        }

    /*
     * Hide CONTENT field tab
     */
    if (Settings::get('static_pages_hide_content')) {

      Event::listen('backend.form.extendFields', function($widget) {

        if (!$widget->getController() instanceof \Winter\Pages\Controllers\Index) {
          return;
        }

        if (!$widget->model instanceof \Winter\Pages\Classes\Page) {
          return;
        }

        $tabs = $widget->getTabs();

        foreach( $tabs->secondary->fields as $name => $field ) {

          if($name <> 'winter.pages::lang.editor.content'){
            $tabs->primary->fields[$name] = $field;
            unset($tabs->secondary->fields[$name]);
          }


        }

        $tabs->primary->stretch = true;
        $tabs->secondary->stretch = NULL;
        $tabs->secondary->cssClass = 'hidden';

        // Make sure, primary tabs are not collapsed
        $widget->addJs('/plugins/janvince/smallextensions/assets/js/primary-tabs.js');

      });

    }

    /*
     * Add extra admin form fields
     */
    if (Settings::get('add_backend_admin_fields')) {

        \Backend\Models\User::extend(function($model) {

          $model->hasOne['custom_fields'] = [
              'JanVince\SmallExtensions\Models\AdminFields',
              'key' => 'backend_user_id',
              'otherKey' => 'id'
          ];

        });

        Event::listen('backend.form.extendFields', function ($widget) {

          if (
            !$widget->getController() instanceof \Backend\Controllers\Users ||
            !$widget->model instanceof \Backend\Models\User
          ) {
            return;
          }

          $widget->addTabFields([
            'custom_fields[description]' => [
              'tab' => 'janvince.smallextensions::lang.backend_admin_fields.tab_info',
              'label' => 'janvince.smallextensions::lang.backend_admin_fields.description',
              'type' => 'richeditor',
              'size' => 'huge'
            ],

          ]);

          /*
          * Custom fields model deferred bind
          */
          if (!$widget->model->custom_fields) {
            $sessionKey = uniqid('session_key', true);

            $custom_fields = new AdminFields;
            $widget->model->custom_fields = $custom_fields;
          }


        });

    }

  }

  public function registerSettings() {

    return [
      'settings' => [
        'label' => 'janvince.smallextensions::lang.plugin.name',
        'description' => 'janvince.smallextensions::lang.plugin.description',
        'category' => 'Small plugins',
        'icon' => 'icon-universal-access',
        'class' => 'JanVince\SmallExtensions\Models\Settings',
        'keywords' => 'extension extensions blog static pages menu small',
        'order' => 990,
        'permissions' => ['janvince.smallextensions.settings'],
      ]
    ];
  }

  /**
   * Twig extensions
   */
  public function registerMarkupTags()
  {

    $twigExtensions = [];

    /**
     * New Twig functions
     */
    if (Settings::get('twig_functions_allow', 0) == 1) {

        $twigExtensions['functions'] = [

              /**
              *   Get image dimensions for use in <img> tag
              *   <img src="{{ image.getPath }}" {{ getImageSizeAttributes(image) }}>
              *   will output <img ... width="123" height="123">
              */
              'getImageSizeAttributes' => function($value) {

                  if( !empty($value->getDiskPath()) ){

                      $filePath = storage_path('app/' . $value->getDiskPath());

                      if( is_file($filePath) ) {

                          list($width, $height, $type, $attributes) = getimagesize($filePath);

                          return $attributes;

                      }

                  }

              }

        ];


        // If Rainlab.Translate is not present, bypass translate filters
        $pluginManager = PluginManager::instance()->findByIdentifier('Winter.Translate');

        if (!$pluginManager or ($pluginManager and $pluginManager->disabled)) {
  
            $twigExtensions['filters'] = [

                '_' => ['Lang', 'get'],
                '__' => ['Lang', 'choice'],

            ];

        }

    }

    return $twigExtensions;

  }

  public function registerReportWidgets(){

      return [
          'JanVince\SmallExtensions\ReportWidgets\CacheCleaner' => [
              'label'   => 'janvince.smallextensions::lang.reportwidgets.cachecleaner.label',
              'context' => 'dashboard'
          ],
          'JanVince\SmallExtensions\ReportWidgets\OptimizeDb' => [
              'label'   => 'janvince.smallextensions::lang.reportwidgets.optimizedb.label',
              'context' => 'dashboard'
          ],
      ];

  }

  public function registerComponents()
  {
      return [
          'JanVince\SmallExtensions\Components\ForceLogin' => 'forceLogin',
      ];
  }

    /**
    *	Custom list types
    */
    public function registerListColumnTypes()
    {
        return [
          'sme_json_field' => function($value, $column, $record) 
          { 
              $values = [];

              if(is_array($record->custom_repeater) and isset($column->config['repeaterValue']))
              {
                foreach($record->custom_repeater as $field)
                
                  if(isset($field[$column->config['repeaterValue']]))
                  {
                    $values[] = $field[$column->config['repeaterValue']];
                  }
              }

              return implode(',', $values);
            }
        ];
    }
}
