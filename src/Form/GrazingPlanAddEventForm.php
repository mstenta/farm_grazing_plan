<?php

declare(strict_types=1);

namespace Drupal\farm_grazing_plan\Form;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\log\Entity\Log;
use Drupal\log\Entity\LogInterface;
use Drupal\plan\Entity\PlanInterface;
use Drupal\plan\Entity\PlanRecord;
use Drupal\plan\Entity\PlanRecordInterface;

/**
 * Grazing plan add event form.
 */
class GrazingPlanAddEventForm extends FormBase {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'farm_grazing_plan_add_event_form';
  }

  /**
   * Title callback.
   *
   * @param \Drupal\plan\Entity\PlanInterface|null $plan
   *   The plan entity.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   Returns the title.
   */
  public function title(?PlanInterface $plan = NULL) {
    if (empty($plan)) {
      return $this->t('Add grazing event');
    }
    return $this->t('Add grazing event to @plan', ['@plan' => $plan->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?PlanInterface $plan = NULL) {
    if (empty($plan)) {
      return $form;
    }
    $form_state->set('plan_id', $plan->id());

    // Either select an existing movement log or create a new one.
    $form['existing_log'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Link an existing movement log'),
      '#description' => $this->t('Add an existing movement log to this plan. Leave this unchecked to create a new log.'),
    ];

    // Select an existing log.
    $form['log'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Movement log'),
      '#target_type' => 'log',
      '#selection_settings' => [
        'target_bundles' => ['activity'],
      ],
      '#ajax' => [
        'wrapper' => 'grazing-event-details',
        'callback' => [$this, 'grazingEventDetailsCallback'],
        'event' => 'autocompleteclose change',
      ],
      '#states' => [
        'required' => [
          ':input[name="existing_log"]' => ['checked' => TRUE],
        ],
        'visible' => [
          ':input[name="existing_log"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // If the Movement quick form module is installed, add a link to it.
    if ($this->moduleHandler->moduleExists('farm_quick_movement')) {
      $quick_movement_url = Url::fromRoute('farm.quick.movement', ['plan' => $this->getRouteMatch()->getParameter('plan')->id()])->toString();
      $form['log']['#description'] = '<p>' . $this->t('Tip: Use the <a href=":url">Movement quick form</a> to create a movement log. You will be redirected back here to fill in more details for the plan.', [':url' => $quick_movement_url]) . '</p>';
    }

    // If a log ID was provided via query parameter, load it and set the
    // appropriate form values.
    $log_id = $this->getRequest()->query->get('log');
    if ($log_id) {
      $log = $this->entityTypeManager->getStorage('log')->load($log_id);
      if (!empty($log) && $log->bundle() == 'activity') {
        $form['existing_log']['#default_value'] = TRUE;
        $form_state->setValue('existing_log', TRUE);
        $form['log']['#default_value'] = $log;
        $form_state->setValue('log', $log_id);
      }
    }

    // Select an asset and location, if an existing log is not being linked.
    $form['asset'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Animal(s)'),
      '#description' => $this->t('Select the animal(s) that will be moving. Only a single asset can be selected. If you are moving a herd/flock, select a Group asset that represents the herd.'),
      '#target_type' => 'asset',
      '#selection_settings' => [
        'target_bundles' => ['animal', 'group'],
        'sort' => [
          'field' => 'archived',
          'direction' => 'DESC',
        ],
      ],
      '#maxlength' => 1024,
      '#states' => [
        'required' => [
          ':input[name="existing_log"]' => ['checked' => FALSE],
        ],
        'visible' => [
          ':input[name="existing_log"]' => ['checked' => FALSE],
        ],
      ],
    ];
    $form['location'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Location'),
      '#description' => $this->t('Select the location that the animal(s) will be moving to. Only a single location can be selected.'),
      '#target_type' => 'asset',
      '#selection_handler' => 'views',
      '#selection_settings' => [
        'view' => [
          'view_name' => 'farm_location_reference',
          'display_name' => 'entity_reference',
          'arguments' => [],
        ],
        'match_operator' => 'CONTAINS',
      ],
      '#maxlength' => 1024,
      '#states' => [
        'required' => [
          ':input[name="existing_log"]' => ['checked' => FALSE],
        ],
        'visible' => [
          ':input[name="existing_log"]' => ['checked' => FALSE],
        ],
      ],
    ];

    $form['details'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'grazing-event-details',
      ],
    ];

    // If the form is being built with a log selected, reset grazing event
    // details and populate their default values.
    $log = NULL;
    if ($form_state->getValue('log')) {
      $this->resetGrazingEventDetails($form_state);
      $log = $this->entityTypeManager->getStorage('log')->load($form_state->getValue('log'));
    }
    $default_values = $this->grazingEventDefaultValues($log);

    $form['details']['start'] = [
      '#type' => 'datetime',
      '#title' => $this->t('Planned start date/time'),
      '#default_value' => $default_values['start'],
      '#required' => TRUE,
    ];

    $form['details']['duration'] = [
      '#type' => 'number',
      '#title' => $this->t('Duration (hours)'),
      '#step' => 1,
      '#min' => 1,
      '#max' => 8760,
      '#default_value' => $default_values['duration'],
      '#required' => TRUE,
    ];

    $form['details']['recovery'] = [
      '#type' => 'number',
      '#title' => $this->t('Recovery (hours)'),
      '#step' => 1,
      '#min' => 1,
      '#max' => 8760,
      '#default_value' => $default_values['recovery'],
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
    ];

    return $form;
  }

  /**
   * Ajax callback for grazing event details.
   */
  public function grazingEventDetailsCallback(array $form, FormStateInterface $form_state) {
    return $form['details'];
  }

  /**
   * Reset grazing event details.
   */
  public function resetGrazingEventDetails(FormStateInterface $form_state) {
    $details_fields = [
      'start',
      'duration',
      'recovery',
    ];
    $user_input = $form_state->getUserInput();
    foreach ($details_fields as $field_name) {
      unset($user_input[$field_name]);
    }
    $form_state->setUserInput($user_input);
    $form_state->setRebuild();
  }

  /**
   * Get default values for grazing event details.
   *
   * @param \Drupal\log\Entity\LogInterface|null $log
   *   A movement log (optional).
   *
   * @return array
   *   Returns a keyed array of grazing event default values, including:
   *   - start
   *   - duration
   *   - recovery
   */
  public function grazingEventDefaultValues($log = NULL) {

    // Start with defaults.
    $values = [
      'start' => new DrupalDateTime('midnight', $this->currentUser()->getTimeZone()),
      'duration' => NULL,
      'recovery' => NULL,
    ];

    // If a log was provided, load the start date from it.
    if ($log instanceof LogInterface) {
      $values['start'] = DrupalDateTime::createFromTimestamp($log->get('timestamp')->value);
    }

    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {

    // If we are linking an existing movement log, validate it.
    if ($form_state->getValue('existing_log')) {

      // Load the log.
      /** @var \Drupal\log\Entity\LogInterface|null $log */
      $log = $this->entityTypeManager->getStorage('log')->load($form_state->getValue('log'));

      // Draft a grazing event from the form values.
      $grazing_event = $this->buildGrazingEvent($log, $form_state);

      // Validate the grazing event and add any violations as form errors.
      $violations = $grazing_event->validate();
      foreach ($violations as $violation) {
        $property = $violation->getPropertyPath();
        $field_name = $property ? explode('.', $property)[0] : 'log';
        $element = match ($field_name) {
          'start' => 'details][start',
          'duration' => 'details][duration',
          'recovery' => 'details][recovery',
          default => 'log',
        };
        $form_state->setErrorByName($element, $violation->getMessage());
      }

      // Suggest the Group asset type for logs that reference multiple assets.
      if ($log instanceof LogInterface && count($log->get('asset')) > 1) {
        $this->messenger()->addStatus($this->t('Tip: The Group asset type can be used to group multiple animal assets together into a single entity, and track their membership in/out of the group. This is useful for representing herds/flocks of individual animals.'));
      }
    }
  }

  /**
   * Build a grazing event from the form values.
   *
   * @param \Drupal\log\Entity\LogInterface $log
   *   The log entity.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\plan\Entity\PlanRecordInterface
   *   Returns an unsaved plan_record entity.
   */
  protected function buildGrazingEvent(LogInterface $log, FormStateInterface $form_state): PlanRecordInterface {
    return PlanRecord::create([
      'type' => 'grazing_event',
      'plan' => $form_state->get('plan_id'),
      'log' => $log->id(),
      'start' => $form_state->getValue('start')->getTimestamp(),
      'duration' => $form_state->getValue('duration'),
      'recovery' => $form_state->getValue('recovery'),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    // If we are not linking an existing log, create one.
    if (!$form_state->getValue('existing_log')) {
      $now = new DrupalDateTime('now', $this->currentUser()->getTimeZone());
      /** @var \Drupal\Core\Datetime\DrupalDateTime $start */
      $start = $form_state->getValue('start');
      $asset = $this->entityTypeManager->getStorage('asset')->load($form_state->getValue('asset'));
      $location = $this->entityTypeManager->getStorage('asset')->load($form_state->getValue('location'));
      $log = Log::create([
        'type' => 'activity',
        'name' => $this->t('Move @asset to @location', ['@asset' => $asset->label(), '@location' => $location->label()]),
        'timestamp' => $start->getTimestamp(),
        'asset' => [$asset],
        'location' => [$location],
        'status' => $start->getTimestamp() <= $now->getTimestamp() ? 'done' : 'pending',
        'is_movement' => TRUE,
      ]);
      $log->save();
      $this->messenger()->addMessage($this->t('Created log: @log', ['@log' => $log->label()]));
    }

    // Otherwise, load the existing log.
    else {
      /** @var \Drupal\log\Entity\LogInterface|null $log */
      $log = $this->entityTypeManager->getStorage('log')->load($form_state->getValue('log'));
    }

    // Create the grazing event.
    /** @var \Drupal\plan\Entity\PlanRecordInterface $grazing_event */
    $grazing_event = $this->buildGrazingEvent($log, $form_state);
    $grazing_event->save();
    $this->messenger()->addMessage($this->t('Added @grazing_event', ['@grazing_event' => $grazing_event->label()]));

    // Redirect to the plan.
    $form_state->setRedirect('entity.plan.canonical', ['plan' => $form_state->get('plan_id')]);
  }

}
