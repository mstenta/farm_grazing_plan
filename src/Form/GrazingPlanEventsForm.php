<?php

declare(strict_types=1);

namespace Drupal\farm_grazing_plan\Form;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\farm_grazing_plan\GrazingPlanInterface;
use Drupal\log\Entity\Log;
use Drupal\plan\Entity\PlanInterface;
use Drupal\plan\Entity\PlanRecord;

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

    // Store the plan ID for use in the submit handlers.
    $form_state->set('plan_id', $plan->id());

    // Build vertical tabs.
    $form['tabs'] = [
      '#type' => 'vertical_tabs',
    ];

    // Load all grazing events, grouped by asset.
    $grazing_events_by_asset = $this->grazingPlan->getGrazingEventsByAsset($plan);

    // Get the number of new grazing event rows added to each asset's table.
    $new_rows_by_asset = $form_state->get('grazing_event_new_rows');
    if ($new_rows_by_asset === NULL) {
      $new_rows_by_asset = [];
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

      // Separate the grazing events by their log status.
      $done_events = [];
      $pending_events = [];
      foreach ($grazing_events as $grazing_event_id => $grazing_event) {
        if ($grazing_event->getLog()->get('status')->value === 'done') {
          $done_events[$grazing_event_id] = $grazing_event;
        }
        else {
          $pending_events[$grazing_event_id] = $grazing_event;
        }
      }

      // Create two tables for done and pending grazing events.
      $done_table = $this->buildGrazingEventTable($done_events, $this->t('Completed events'));
      $pending_table = $this->buildGrazingEventTable($pending_events, $this->t('Pending events'));

      // Wrap the pending table in a div, so it can be replaced via Ajax.
      $pending_table['#prefix'] = '<div id="pending-grazing-events-wrapper-' . $asset_id . '">';
      $pending_table['#suffix'] = '</div>';

      // Add new grazing event rows, if any were added via Ajax.
      $num_new_rows = $new_rows_by_asset[$asset_id] ?? 0;
      for ($row_num = 1; $row_num <= $num_new_rows; $row_num++) {
        $row_key = 'new_' . $row_num;
        $defaults = $this->getNewGrazingEventRowDefaults($asset_id, $row_num, $grazing_events, $form_state);
        $pending_table[$row_key] = $this->buildGrazingEventRowFields($defaults);
      }

      // Add the tables to a collapsed details box for this asset.
      // Only show done events if there are any.
      $form['grazing_events'][$asset_id] = [
        '#type' => 'details',
        '#title' => $this->t('@asset Grazing Events', ['@asset' => $asset->label()]),
        '#open' => FALSE,
        '#group' => 'tabs',
      ];
      if (!empty($done_events)) {
        $form['grazing_events'][$asset_id]['done'] = $done_table;
      }
      $form['grazing_events'][$asset_id]['pending'] = $pending_table;

      // Add a button to add a new grazing event row via Ajax.
      $form['grazing_events'][$asset_id]['add'] = [
        '#type' => 'submit',
        '#value' => $this->t('Add grazing event'),
        '#name' => 'add_grazing_event_' . $asset_id,
        '#submit' => [[$this, 'addGrazingEventRow']],
        '#ajax' => [
          'callback' => [$this, 'addGrazingEventRowAjaxCallback'],
          'wrapper' => 'pending-grazing-events-wrapper-' . $asset_id,
        ],
      ];

      // Add a submit button to each asset's grazing events.
      $form['grazing_events'][$asset_id]['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Update grazing events'),
      ];
    }

    // Show a message if there are no grazing events yet.
    if (empty($grazing_events_by_asset)) {
      $form['no_events'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('There are no grazing events in this plan.') . '</p>',
      ];
    }

    // Add a link to the "Add grazing event" form.
    $form['add_link'] = [
      '#type' => 'link',
      '#title' => $this->t('Add a grazing event'),
      '#url' => Url::fromRoute('farm_grazing_plan.add_event', ['plan' => $plan->id()]),
    ];

    return $form;
  }

  /**
   * Build a table of grazing events.
   *
   * @param \Drupal\farm_grazing_plan\Bundle\GrazingEvent[] $grazing_events
   *   The grazing events to include in the table.
   * @param string|\Drupal\Core\StringTranslation\TranslatableMarkup $caption
   *   The table caption.
   *
   * @return array
   *   Returns a render array of the table, with one row per grazing event.
   */
  protected function buildGrazingEventTable(array $grazing_events, $caption): array {

    // Initialize the table with a caption and column headers.
    $table = [
      '#type' => 'table',
      '#caption' => $caption,
      '#header' => [
        $this->t('Location'),
        $this->t('Planned start'),
        $this->t('Actual start'),
        $this->t('Planned duration (hours)'),
        $this->t('Planned recovery (hours)'),
      ],
    ];

    // Iterate through the grazing events for this asset.
    foreach ($grazing_events as $grazing_event_id => $grazing_event) {

      // Load the log.
      $log = $grazing_event->getLog();

      // Build the grazing event fields with default values from the grazing
      // event and log.
      $defaults = [
        'location' => $log->get('location')->referencedEntities()[0],
        'planned_start' => $grazing_event->get('start')->value,
        'actual_start' => $log->get('timestamp')->value,
        'planned_duration' => $grazing_event->get('duration')->value,
        'planned_recovery' => $grazing_event->get('recovery')->value,
      ];
      $table[$grazing_event_id] = $this->buildGrazingEventRowFields($defaults);
    }

    return $table;
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
      '#title_display' => 'hidden',
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

    // Planned start.
    $fields['planned_start'] = [
      '#type' => 'number',
      '#title' => $this->t('Planned start'),
      '#title_display' => 'hidden',
      '#scale' => 1,
      '#default_value' => $defaults['planned_start'] ?? NULL,
      '#required' => TRUE,
    ];

    // Actual start.
    $fields['actual_start'] = [
      '#type' => 'number',
      '#title' => $this->t('Actual start'),
      '#title_display' => 'hidden',
      '#scale' => 1,
      '#default_value' => $defaults['actual_start'] ?? NULL,
      '#required' => TRUE,
    ];

    // Planned duration.
    $fields['planned_duration'] = [
      '#type' => 'number',
      '#title' => $this->t('Planned duration (hours)'),
      '#title_display' => 'hidden',
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
      '#title_display' => 'hidden',
      '#min' => 1,
      '#max' => 8760,
      '#scale' => 1,
      '#default_value' => $defaults['planned_recovery'] ?? '',
    ];

    return $fields;
  }

  /**
   * Submit handler for the "Add grazing event" button.
   *
   * Increments the number of new grazing event rows for the triggering asset.
   */
  public function addGrazingEventRow(array &$form, FormStateInterface $form_state) {

    // Get the asset ID from the triggering element.
    $asset_id = $form_state->getTriggeringElement()['#parents'][1];

    // Increment the number of new grazing event rows for this asset.
    $new_rows_by_asset = $form_state->get('grazing_event_new_rows');
    if ($new_rows_by_asset === NULL) {
      $new_rows_by_asset = [];
    }
    $new_rows_by_asset[$asset_id] = ($new_rows_by_asset[$asset_id] ?? 0) + 1;
    $form_state->set('grazing_event_new_rows', $new_rows_by_asset);

    // Rebuild the form to render the new row.
    $form_state->setRebuild();
  }

  /**
   * Ajax callback for the "Add grazing event" button.
   *
   * Returns the table for the triggering asset so that the new row is
   * rendered.
   */
  public function addGrazingEventRowAjaxCallback(array $form, FormStateInterface $form_state) {
    $asset_id = $form_state->getTriggeringElement()['#parents'][1];
    return $form['grazing_events'][$asset_id]['pending'];
  }

  /**
   * Get default values for a new grazing event row.
   *
   * @param int|string $asset_id
   *   The asset ID.
   * @param int $row_num
   *   The new row number, starting at 1.
   * @param \Drupal\farm_grazing_plan\Bundle\GrazingEvent[] $grazing_events
   *   The asset's saved grazing events, sorted chronologically.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   Returns an array of default values with keys: start, duration, recovery.
   */
  protected function getNewGrazingEventRowDefaults($asset_id, int $row_num, array $grazing_events, FormStateInterface $form_state) {

    // Start with empty defaults.
    $values = [
      'location' => NULL,
      'planned_start' => NULL,
      'actual_start' => NULL,
      'planned_duration' => NULL,
      'planned_recovery' => NULL,
    ];

    // Build default values for the first new row based on the most recent
    // saved grazing event.
    if ($row_num == 1) {
      if (empty($grazing_events)) {
        return $values;
      }
      $grazing_event = end($grazing_events);
      $log = $grazing_event->getLog();
      $values['planned_start'] = $values['actual_start'] = $log->get('timestamp')->value;
      $values['planned_duration'] = $grazing_event->get('duration')->value;
      $values['planned_recovery'] = $grazing_event->get('recovery')->value;
    }

    // Build the default values for subsequent new rows from the previous new
    // row's values.
    else {
      $previous = $form_state->getValue(['grazing_events', $asset_id, 'pending', 'new_' . ($row_num - 1)]);
      $values['planned_start'] = $values['actual_start'] = $previous['actual_start'];
      $values['planned_duration'] = $previous['planned_duration'] ?? NULL;
      $values['planned_recovery'] = $previous['planned_recovery'] ?? NULL;
    }

    // The planned and actual starts default to the previous actual start plus
    // the previous duration.
    if (!empty($values['planned_duration'])) {
      $values['planned_start'] = $values['actual_start'] = $values['actual_start'] + ($values['planned_duration'] * 60 * 60);
    }

    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    // Iterate through the submitted grazing events for each asset.
    $grazing_event_values_by_asset = $form_state->getValue('grazing_events');
    foreach ($grazing_event_values_by_asset as $asset_id => $grazing_events) {
      foreach (['done', 'pending'] as $table) {
        foreach ($grazing_events[$table] as $row_key => $values) {

          // If the row key is numeric, update the grazing event and log with
          // submitted values.
          if (is_numeric($row_key)) {
            $this->updateGrazingEvent($row_key, $values);
          }

          // Otherwise, create a new grazing event and log.
          else {
            $this->createGrazingEvent((int) $form_state->get('plan_id'), $asset_id, $values);
          }
        }
      }
    }

    // Tell the user that grazing events were updated.
    $this->messenger()->addMessage($this->t('Updated the grazing events.'));
  }

  /**
   * Update an existing grazing event.
   *
   * @param int $grazing_event_id
   *   The grazing event ID.
   * @param array $values
   *   An array of values from $form_state.
   */
  protected function updateGrazingEvent(int $grazing_event_id, array $values) {
    /** @var \Drupal\farm_grazing_plan\Bundle\GrazingEventInterface $grazing_event */
    $grazing_event = $this->entityTypeManager->getStorage('plan_record')->load($grazing_event_id);

    // Update the grazing event values.
    $grazing_event->set('start', $values['planned_start']);
    $grazing_event->set('duration', $values['planned_duration']);
    $grazing_event->set('recovery', empty($values['planned_recovery']) ? NULL : $values['planned_recovery']);
    $grazing_event->save();

    // Update the grazing event's log values.
    $log = $grazing_event->getLog();
    $log->set('location', $values['location']);
    $log->set('timestamp', $values['actual_start']);
    $log->save();
  }

  /**
   * Create a new grazing event.
   *
   * @param int $plan_id
   *   The plan ID.
   * @param int $asset_id
   *   The asset ID.
   * @param array $values
   *   An array of values from $form_state.
   */
  protected function createGrazingEvent(int $plan_id, int $asset_id, array $values) {

    // Load the asset and location.
    $asset = $this->entityTypeManager->getStorage('asset')->load($asset_id);
    $location = $this->entityTypeManager->getStorage('asset')->load($values['location']);

    // Create the movement log.
    $now = new DrupalDateTime('now', $this->currentUser()->getTimeZone());
    $log = Log::create([
      'type' => 'activity',
      'name' => $this->t('Move @asset to @location', ['@asset' => $asset->label(), '@location' => $location->label()]),
      'timestamp' => $values['actual_start'],
      'asset' => [$asset],
      'location' => [$location],
      'status' => $values['actual_start'] <= $now->getTimestamp() ? 'done' : 'pending',
      'is_movement' => TRUE,
    ]);
    $log->save();

    // Create the grazing event.
    $grazing_event = PlanRecord::create([
      'type' => 'grazing_event',
      'plan' => $plan_id,
      'log' => $log->id(),
      'start' => $values['planned_start'],
      'duration' => $values['planned_duration'],
      'recovery' => $values['planned_recovery'],
    ]);
    $grazing_event->save();
  }

}
