<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Workflow.Categories
 *
 * @copyright   Copyright (C) 2005 - 2020 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Event\View\DisplayEvent;
use Joomla\CMS\Event\Workflow\WorkflowFunctionalityUsedEvent;
use Joomla\CMS\Event\Workflow\WorkflowTransitionEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\DatabaseModelInterface;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Table\TableInterface;
use Joomla\CMS\Workflow\WorkflowPluginTrait;
use Joomla\CMS\Workflow\WorkflowServiceInterface;
use Joomla\Event\EventInterface;
use Joomla\Event\SubscriberInterface;
use Joomla\String\Inflector;
use Joomla\CMS\Categories\Categories;

/**
 * Workflow Categories Plugin
 *
 * @since  4.0.0
 */
class PlgWorkflowCategories extends CMSPlugin implements SubscriberInterface
{
	use WorkflowPluginTrait;

	/**
	 * Load the language file on instantiation.
	 *
	 * @var    boolean
	 * @since  4.0.0
	 */
	protected $autoloadLanguage = true;

	/**
	 * Loads the CMS Application for direct access
	 *
	 * @var   CMSApplicationInterface
	 * @since 4.0.0
	 */
	protected $app;

	/**
	 * The name of the supported name to check against
	 *
	 * @var   string
	 * @since 4.0.0
	 */
	protected $supportFunctionality = 'core.state';

	/**
	 * Returns an array of events this subscriber will listen to.
	 *
	 * @return  array
	 *
	 * @since   4.0.0
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'onContentPrepareForm'        => 'onContentPrepareForm',
			'onAfterDisplay'              => 'onAfterDisplay',
			'onWorkflowBeforeTransition'  => 'onWorkflowBeforeTransition',
			'onWorkflowAfterTransition'   => 'onWorkflowAfterTransition',
			'onContentBeforeSave'         => 'onContentBeforeSave',
			'onWorkflowFunctionalityUsed' => 'onWorkflowFunctionalityUsed',
		];
	}

	/**
	 * The form event.
	 *
	 * @param   EventInterface  $event  The event
	 *
	 * @since   4.0.0
	 */
	public function onContentPrepareForm(EventInterface $event)
	{
		$form = $event->getArgument('0');
		$data = $event->getArgument('1');

		$context = $form->getName();

		// Extend the transition form
		if ($context === 'com_workflow.transition')
		{
			$this->enhanceWorkflowTransitionForm($form, $data);

			return;
		}
		$field = $form->getField('catid');

		$this->enhanceItemForm($form, $data);

		$field = $form->getField('catid');

		return;
	}

	/**
	 * Add different parameter options to the transition view, we need when executing the transition
	 *
	 * @param   Form      $form The form
	 * @param   stdClass  $data The data
	 *
	 * @return  boolean
	 *
	 * @since   4.0.0
	 */
	protected function enhanceTransitionForm(Form $form, $data)
	{
		$workflow = $this->enhanceWorkflowTransitionForm($form, $data);

		if (!$workflow)
		{
			return true;
		}

		$form->setFieldAttribute('categories', 'extension', $workflow->extension, 'options');

		return true;
	}

	/**
	 * Disable certain fields in the item  form view, when we want to take over this function in the transition
	 * Check also for the workflow implementation and if the field exists
	 *
	 * @param   Form      $form  The form
	 * @param   stdClass  $data  The data
	 *
	 * @return  boolean
	 *
	 * @since   4.0.0
	 */
	protected function enhanceItemForm(Form $form, $data)
	{
		$context = $form->getName();

		if (!$this->isSupported($context))
		{
			return true;
		}

		$parts = explode('.', $context);

		$component = $this->app->bootComponent($parts[0]);

		$modelName = $component->getModelName($context);

		$table = $component->getMVCFactory()->createModel($modelName, $this->app->getName(), ['ignore_request' => true])
			->getTable();

		$fieldname = $table->getColumnAlias('catid');

		$options = $form->getField($fieldname)->options;
		$value = isset($data->$fieldname) ? $data->$fieldname : $form->getValue($fieldname);
		$test = $form->getValue($fieldname);

		// If we create a new article, we don't want to disable the drop-down
		if(!$form->getValue('id')){

			if(count((array)$data)>0){

				if(!((array) $data)['id']){

					return true;
				}
			}
		}


		$text = '-';

		$textclass = 'body';

		if (!empty($options))
		{
			foreach ($options as $option)
			{
				if ($option->value == $value)
				{
					$text = $option->text;

					break;
				}
			}
		}
		//Entferne Drop Down
		$form->setFieldAttribute($fieldname, 'type', 'spacer');
		$form->setValue($fieldname, $value);


		//Setze Label mit Cat-Namen
		$label = '<span class="text-' . $textclass . '">' . htmlentities($text, ENT_COMPAT, 'UTF-8') . '</span>';
		$form->setFieldAttribute($fieldname, 'label', Text::sprintf('Category: %s', $label));

		//Category Field wird nicht mehr benötigt
		$form->setFieldAttribute($fieldname,'required', false);

		return true;
	}

	/**
	 * Manipulate the generic list view
	 *
	 * @param   DisplayEvent    $event
	 *
	 * @since   4.0.0
	 */
	public function onAfterDisplay(DisplayEvent $event)
	{
		$app = Factory::getApplication();

		if (!$app->isClient('administrator'))
		{
			return;
		}

		$component = $event->getArgument('extensionName');
		$section   = $event->getArgument('section');

		// We need the single model context for checking for workflow
		$singularsection = Inflector::singularize($section);

		if (!$this->isSupported($component . '.' . $singularsection))
		{
			return true;
		}

		$model_categories = Categories::getInstance('com_content');
		$root = $model_categories->get('root');
		$categories = array();

		foreach ($root->getChildren() as $category){
			array_push($categories, $category->title);
		}

		// That's the hard coded list from the AdminController publish method => change, when it's make dynamic in the future
		$states = [
			'publish',
			'unpublish',
			'archive',
			'trash',
			'report'
		];

		$js = "
			document.addEventListener('DOMContentLoaded', function()
			{
				var dropdown = document.getElementById('toolbar-dropdown-status-group');

				if (!dropdown)
				{
					return;
				}

				" . \json_encode($categories) . ".forEach((action) => {
					var button = document.getElementById('status-group-children-' + action);

					if (button)
					{
						button.classList.add('d-none');
					}
				});

			});
		";

		$app->getDocument()->addScriptDeclaration($js);

		return true;
	}

	/**
	 * Check if we can execute the transition
	 *
	 * @param   WorkflowTransitionEvent  $event
	 *
	 * @return boolean
	 *
	 * @since   4.0.0
	 */
	public function onWorkflowBeforeTransition(WorkflowTransitionEvent $event)
	{
		$context    = $event->getArgument('extension');
		$extensionName = $event->getArgument('extensionName');
		$transition = $event->getArgument('transition');

		if (!$this->isSupported($context) || !is_numeric($transition->options->get('category')))
		{
			return true;
		}

		$value = $transition->options->get('category');

		if (!is_numeric($value))
		{
			return true;
		}

		$component = $this->app->bootComponent($extensionName);

		return true;

	}

	/**
	 * Change State of an item. Used to disable state change
	 *
	 * @param   WorkflowTransitionEvent  $event
	 *
	 * @return boolean
	 *
	 * @since   4.0.0
	 */
	public function onWorkflowAfterTransition(WorkflowTransitionEvent $event)
	{
		$context       = $event->getArgument('extension');
		$extensionName = $event->getArgument('extensionName');
		$transition    = $event->getArgument('transition');
		$pks           = $event->getArgument('pks');

		if (!$this->isSupported($context))
		{
			return true;
		}

		$component = $this->app->bootComponent($extensionName);

		$value = $transition->options->get('category');

		if (!is_numeric($value))
		{
			return;
		}

		$options = [
			'ignore_request'            => true,
			// We already have triggered onContentBeforeChangeState, so use our own
			'event_before_change_state' => 'onWorkflowBeforeChangeState'
		];

		$modelName = $component->getModelName($context);

		$model = $component->getMVCFactory()->createModel($modelName, $this->app->getName(), []);


		foreach ($pks as $pk){
			$article = $model->getItem($pk);
			$article->set('catid',$value);
			$data = (array) $article;
			unset($data['featured']);
			unset($data['state']);

			$model->save($data);
		}



		return true;

	}


	/**
	 * The save event.
	 *
	 * @param   EventInterface  $event
	 *
	 * @return  boolean
	 *
	 * @since   4.0.0
	 */
	public function onContentBeforeSave(EventInterface $event)
	{
		$context = $event->getArgument('0');

		/** @var TableInterface $table */
		$table = $event->getArgument('1');
		$isNew = $event->getArgument('2');
		$data  = $event->getArgument('3');

		if (!$this->isSupported($context))
		{
			return true;
		}

		$keyName = $table->getColumnAlias('catid');

		// Check for the old value
		$article = clone $table;

		$article->load($table->id);


		return true;
	}

	/**
	 * Check if the current plugin should execute workflow related activities
	 *
	 * @param   string  $context
	 *
	 * @return boolean
	 *
	 * @since   4.0.0
	 */
	protected function isSupported($context)
	{
		if (!$this->checkWhiteAndBlacklist($context) || !$this->checkExtensionSupport($context, $this->supportFunctionality))
		{
			return false;
		}

		$parts = explode('.', $context);

		// We need at least the extension + view for loading the table fields
		if (count($parts) < 2)
		{
			return false;
		}

		$component = $this->app->bootComponent($parts[0]);

		if (!$component instanceof WorkflowServiceInterface
			|| !$component->isWorkflowActive($context)
			|| !$component->supportFunctionality($this->supportFunctionality, $context))
		{
			return false;
		}

		$modelName = $component->getModelName($context);

		$model = $component->getMVCFactory()->createModel($modelName, $this->app->getName(), ['ignore_request' => true]);

		if (!$model instanceof DatabaseModelInterface || !method_exists($model, 'publish'))
		{
			return false;
		}

		$table = $model->getTable();

		if (!$table instanceof TableInterface || !$table->hasField('catid'))
		{
			return false;
		}

		return true;
	}

	/**
	 * If plugin supports the functionality we set the used variable
	 *
	 * @param   WorkflowFunctionalityUsedEvent  $event
	 *
	 * @since 4.0.0
	 */
	public function onWorkflowFunctionalityUsed(WorkflowFunctionalityUsedEvent $event)
	{
		$functionality = $event->getArgument('functionality');

		if ($functionality !== 'core.state')
		{
			return;
		}

		$event->setUsed();
	}
}
