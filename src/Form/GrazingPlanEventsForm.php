<?php

declare(strict_types=1);

namespace Drupal\farm_grazing_plan\Form;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\farm_grazing_plan\GrazingPlanInterface;
use Drupal\plan\Entity\PlanInterface;

/**
 * Grazing plan form.
 */
class GrazingPlanEventsForm extends FormBase {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected GrazingPlanInterface $grazingPlan,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'farm_grazing_plan_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $plan = NULL) {

    // If a plan is not available, bail.
    if (empty($plan) || !($plan instanceof PlanInterface) || $plan->bundle() != 'grazing') {
      return [
        '#type' => 'markup',
        '#markup' => 'No grazing plan was provided.',
      ];
    }

    // Build vertical tabs.
    $form['tabs'] = [
      '#type' => 'vertical_tabs',
    ];

    // Load all grazing events, grouped by asset.
    $grazing_events_by_asset = $this->grazingPlan->getGrazingEventsByAsset($plan);

    // If there are no grazing events, stop here.
    if (empty($grazing_events_by_asset)) {
      return $form;
    }

    // Build the grazing events as a form tree so form state values are built
    // as a nested array.
    $form['grazing_events']['#tree'] = TRUE;

    // For each asset, generate fields for editing each grazing event.
    foreach ($grazing_events_by_asset as $asset_id => $grazing_events) {

      // Load the asset.
      $asset = $this->entityTypeManager->getStorage('asset')->load($asset_id);

      // If the user does not have access to this asset, continue to the next.
      if (!$asset->access('view')) {
        continue;
      }

      // Create a table for this asset in a collapsed details box.
      $form['grazing_events'][$asset_id] = [
        '#type' => 'details',
        '#title' => $this->t('@asset Grazing Events', ['@asset' => $asset->label()]),
        '#open' => FALSE,
        '#group' => 'tabs',
      ];
      $form['grazing_events'][$asset_id]['values'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Location'),
          $this->t('Planned start date/time'),
          $this->t('Actual start date/time'),
          $this->t('Planned duration (hours)'),
          $this->t('Planned recovery (hours)'),
        ],

        // Set #input to FALSE otherwise datetime fields don't validate.
        // @see https://www.drupal.org/project/drupal/issues/3554225
        '#input' => FALSE,
      ];

      // Iterate through the grazing events for this asset.
      foreach ($grazing_events as $grazing_event_id => $grazing_event) {

        // Load the log.
        $log = $grazing_event->get('log')->referencedEntities()[0];

        // Build the grazing event fields with default values from the grazing
        // event and log.
        $defaults = [
          'location' => $log->get('location')->referencedEntities()[0],
          'planned_start' => DrupalDateTime::createFromTimestamp($grazing_event->get('start')->value, $this->currentUser()->getTimeZone()),
          'actual_start' => DrupalDateTime::createFromTimestamp($log->get('timestamp')->value, $this->currentUser()->getTimeZone()),
          'planned_duration' => $grazing_event->get('duration')->value,
          'planned_recovery' => $grazing_event->get('recovery')->value,
        ];
        $form['grazing_events'][$asset_id]['values'][$grazing_event_id] = $this->buildGrazingEventRowFields($defaults);
      }

      // Add a submit button to each asset's grazing events.
      $form['grazing_events'][$asset_id]['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Update grazing events'),
      ];
    }

    return $form;
  }

  /**
   * Build form fields for a grazing event row.
   *
   * @param array $defaults
   *   The default row values, with keys: location, planned_start,
   *   actual_start, planned_duration, planned_recovery.
   *
   * @return array
   *   Returns a render array of the row's form fields.
   */
  protected function buildGrazingEventRowFields(array $defaults = []) {

    // Location.
    $fields['location'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Location'),
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
      '#default_value' => $defaults['location'] ?? NULL,
      '#required' => TRUE,
    ];

    // Planned start date/time.
    $fields['planned_start'] = [
      '#type' => 'datetime',
      '#title' => $this->t('Planned start date/time'),
      '#default_value' => $defaults['planned_start'] ?? NULL,
      '#required' => TRUE,
    ];

    // Actual start date/time.
    $fields['actual_start'] = [
      '#type' => 'datetime',
      '#title' => $this->t('Actual start date/time'),
      '#default_value' => $defaults['actual_start'] ?? NULL,
      '#required' => TRUE,
    ];

    // Planned duration.
    $fields['planned_duration'] = [
      '#type' => 'number',
      '#title' => $this->t('Planned duration (hours)'),
      '#min' => 1,
      '#max' => 8760,
      '#scale' => 1,
      '#default_value' => $defaults['planned_duration'] ?? '',
      '#required' => TRUE,
    ];

    // Planned recovery.
    $fields['planned_recovery'] = [
      '#type' => 'number',
      '#title' => $this->t('Planned recovery (hours)'),
      '#min' => 1,
      '#max' => 8760,
      '#scale' => 1,
      '#default_value' => $defaults['planned_recovery'] ?? '',
    ];

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    // Iterate through the submitted grazing events for each asset.
    $grazing_event_values_by_asset = $form_state->getValue('grazing_events');
    foreach ($grazing_event_values_by_asset as $grazing_events) {
      foreach ($grazing_events['values'] as $grazing_event_id => $values) {

        // Load the grazing event.
        /** @var \Drupal\farm_grazing_plan\Bundle\GrazingEventInterface $grazing_event */
        $grazing_event = $this->entityTypeManager->getStorage('plan_record')->load($grazing_event_id);

        // Update the grazing event values.
        $grazing_event->set('start', $values['planned_start']->getTimestamp());
        $grazing_event->set('duration', $values['planned_duration']);
        $grazing_event->set('recovery', empty($values['planned_recovery']) ? NULL : $values['planned_recovery']);
        $grazing_event->save();

        // Update the grazing event's log values.
        $log = $grazing_event->getLog();
        $log->set('location', $values['location']);
        $log->set('timestamp', $values['actual_start']->getTimestamp());
        $log->save();
      }
    }

    // Tell the user that grazing events were updated.
    $this->messenger()->addMessage($this->t('Updated the grazing events.'));
  }

}
