<?php

declare(strict_types=1);

namespace Drupal\farm_grazing_plan\Form;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
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
      '#required' => TRUE,
    ];

    // If a log ID was provided via query parameter, load it and set the
    // form value.
    $log_id = $this->getRequest()->query->get('log');
    if ($log_id) {
      $log = $this->entityTypeManager->getStorage('log')->load($log_id);
      if (!empty($log) && $log->bundle() == 'activity') {
        $form['log']['#default_value'] = $log;
        $form_state->setValue('log', $log_id);
      }
    }

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

    // Build the grazing event from the form values.
    $grazing_event = $this->buildGrazingEvent($form_state);

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
    /** @var \Drupal\log\Entity\LogInterface|null $log */
    $log = $this->entityTypeManager->getStorage('log')->load($form_state->getValue('log'));
    if ($log instanceof LogInterface && count($log->get('asset')) > 1) {
      $this->messenger()->addStatus($this->t('Tip: The Group asset type can be used to group multiple animal assets together into a single entity, and track their membership in/out of the group. This is useful for representing herds/flocks of individual animals.'));
    }

    // Store the grazing event, so it can be saved on submit.
    $form_state->set('grazing_event', $grazing_event);
  }

  /**
   * Build a grazing event from the form values.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\plan\Entity\PlanRecordInterface
   *   Returns an unsaved plan_record entity.
   */
  protected function buildGrazingEvent(FormStateInterface $form_state): PlanRecordInterface {
    return PlanRecord::create([
      'type' => 'grazing_event',
      'plan' => $form_state->get('plan_id'),
      'log' => $form_state->getValue('log'),
      'start' => $form_state->getValue('start')->getTimestamp(),
      'duration' => $form_state->getValue('duration'),
      'recovery' => $form_state->getValue('recovery'),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\plan\Entity\PlanRecordInterface $grazing_event */
    $grazing_event = $form_state->get('grazing_event');
    $grazing_event->save();
    $this->messenger()->addMessage($this->t('Added @grazing_event', ['@grazing_event' => $grazing_event->label()]));
    $form_state->setRedirect('entity.plan.canonical', ['plan' => $form_state->get('plan_id')]);
  }

}
