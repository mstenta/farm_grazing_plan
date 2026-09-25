<?php

declare(strict_types=1);

namespace Drupal\farm_grazing_plan\Form;

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
      // The pending table is draggable, so the user can reorder the pending
      // grazing events. The tabledrag group class is unique per asset.
      $group = 'grazing-event-order-' . $asset_id;
      $done_table = $this->buildGrazingEventTable('done', $done_events);
      $pending_table = $this->buildGrazingEventTable('pending', $pending_events, $group);

      // Wrap the pending table in a div, so it can be replaced via Ajax.
      $pending_table['#prefix'] = '<div id="pending-grazing-events-wrapper-' . $asset_id . '">';
      $pending_table['#suffix'] = '</div>';

      // Add new grazing event rows, if any were added via Ajax. New rows are
      // appended to the end of the pending table, after the existing rows.
      $num_new_rows = $new_rows_by_asset[$asset_id] ?? 0;
      for ($row_num = 1; $row_num <= $num_new_rows; $row_num++) {
        $row_key = 'new_' . $row_num;
        $defaults = $this->getNewGrazingEventRowDefaults($asset_id, $row_num, $grazing_events, $form_state);
        $defaults['weight'] = count($pending_events) + ($row_num - 1);
        $pending_table[$row_key] = $this->buildGrazingEventRowFields('pending', $defaults, $group);
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

      // Add a submit button to each asset's grazing events.
      $form['grazing_events'][$asset_id]['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Save events'),
        '#button_type' => 'primary',
      ];

      // Add a button to add a new grazing event row via Ajax.
      $form['grazing_events'][$asset_id]['add'] = [
        '#type' => 'submit',
        '#value' => $this->t('Add event'),
        '#name' => 'add_grazing_event_' . $asset_id,
        '#submit' => [[$this, 'addGrazingEventRow']],
        '#ajax' => [
          'callback' => [$this, 'addGrazingEventRowAjaxCallback'],
          'wrapper' => 'pending-grazing-events-wrapper-' . $asset_id,
        ],
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
   * @param string $status
   *   The status of the grazing events (done/pending).
   * @param \Drupal\farm_grazing_plan\Bundle\GrazingEvent[] $grazing_events
   *   The grazing events to include in the table.
   * @param string|null $group
   *   The tabledrag group class, for the draggable pending table.
   *
   * @return array
   *   Returns a render array of the table, with one row per grazing event.
   */
  protected function buildGrazingEventTable(string $status, array $grazing_events, ?string $group = NULL): array {

    // Set the caption based on the status.
    $caption = '';
    if ($status == 'done') {
      $caption = $this->t('Completed events');
    }
    elseif ($status == 'pending') {
      $caption = $this->t('Pending events');
    }

    // Initialize the table with a caption and column headers. The start
    // columns differ by status: completed events show only the actual start,
    // while pending events show the planned and actual starts.
    if ($status == 'done') {
      $headers = [
        $this->t('Location'),
        $this->t('Actual start'),
        $this->t('Planned duration (hours)'),
        $this->t('Planned recovery (hours)'),
      ];
    }
    else {
      $headers = [
        $this->t('Location'),
        $this->t('Planned start'),
        $this->t('Actual start'),
        $this->t('Planned duration (hours)'),
        $this->t('Planned recovery (hours)'),
      ];
    }
    $table = [
      '#type' => 'table',
      '#caption' => $caption,
      '#header' => $headers,
    ];

    // Make the pending table draggable, so the pending grazing events can be
    // reordered.
    if ($status == 'pending') {
      $table['#header'][] = $this->t('Weight');
      $table['#tabledrag'] = [
        [
          'action' => 'order',
          'relationship' => 'sibling',
          'group' => $group,
        ],
      ];
    }

    // Iterate through the grazing events for this asset.
    $weight = 0;
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
        'weight' => $weight,
      ];
      $table[$grazing_event_id] = $this->buildGrazingEventRowFields($status, $defaults, $group);
      $weight++;
    }

    return $table;
  }

  /**
   * Build form fields for a grazing event row.
   *
   * @param string $status
   *   The status of the grazing events (done/pending).
   * @param array $defaults
   *   The default row values, with keys: location, planned_start,
   *   actual_start, planned_duration, planned_recovery, weight.
   * @param string|null $group
   *   The tabledrag group class, for the draggable pending table.
   *
   * @return array
   *   Returns a render array of the row's form fields.
   */
  protected function buildGrazingEventRowFields(string $status, array $defaults = [], ?string $group = NULL) {
    $fields = [];

    // Mark the pending rows as draggable, and set the row weight.
    if ($status == 'pending') {
      $fields['#attributes']['class'][] = 'draggable';
      $fields['#weight'] = $defaults['weight'] ?? 0;
    }

    // Grazing events that are done cannot be edited.
    if ($status == 'done') {

      // Location.
      /** @var \Drupal\asset\Entity\AssetInterface $location */
      $location = $defaults['location'];
      $fields['location'] = [
        '#type' => 'markup',
        '#markup' => $location->toLink()->toString(),
      ];

      // Actual start.
      $fields['actual_start'] = [
        '#type' => 'markup',
        '#markup' => date('Y-m-d H:i:s', (int) $defaults['actual_start']),
      ];

      // Planned duration.
      $fields['planned_duration'] = [
        '#type' => 'markup',
        '#markup' => $this->t('@duration hours', ['@duration' => $defaults['planned_duration']]),
      ];

      // Planned recovery.
      $fields['planned_recovery'] = [
        '#type' => 'markup',
        '#markup' => !empty($defaults['planned_recovery']) ? $this->t('@recovery hours', ['@recovery' => $defaults['planned_recovery']]) : '',
      ];
    }

    // Grazing events that are pending can be edited.
    elseif ($status == 'pending') {

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

      // Weight is used to determine the order of the rows, and is updated by
      // the tabledrag JavaScript when a row is dragged, or via dropdown if
      // dragging is disabled.
      $fields['weight'] = [
        '#type' => 'weight',
        '#title' => $this->t('Weight'),
        '#title_display' => 'invisible',
        '#delta' => 100,
        '#default_value' => $defaults['weight'] ?? 0,
        '#attributes' => ['class' => [$group]],
      ];
    }

    return $fields;
  }

  /**
   * Submit handler for the "Add event" button.
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
   * Ajax callback for the "Add event" button.
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

    // Load the plan. Bail if null.
    /** @var \Drupal\plan\Entity\PlanInterface|null $plan */
    $plan = $this->entityTypeManager->getStorage('plan')->load($form_state->get('plan_id'));
    if (is_null($plan)) {
      return;
    }

    // Iterate through the submitted grazing events for each asset.
    $grazing_event_values_by_asset = $form_state->getValue('grazing_events');
    foreach ($grazing_event_values_by_asset as $asset_id => $grazing_events) {

      // Update the completed grazing events with the submitted values.
      foreach ($grazing_events['done'] ?? [] as $row_key => $values) {
        $this->updateGrazingEvent((int) $row_key, $values);
      }

      // Update and reorder the pending grazing events, recomputing their
      // start dates if the order has changed.
      $this->processPendingGrazingEvents($plan, (int) $asset_id, $grazing_events['pending'] ?? []);
    }

    // Tell the user that grazing events were updated.
    $this->messenger()->addMessage($this->t('Updated the grazing events.'));
  }

  /**
   * Process the submitted pending grazing events for an asset.
   *
   * Reorders the rows based on the submitted weight values, recomputes the
   * start dates if the order has changed, and updates or creates the grazing
   * events and logs.
   *
   * @param \Drupal\plan\Entity\PlanInterface $plan
   *   The grazing plan.
   * @param int $asset_id
   *   The asset ID.
   * @param array $rows
   *   The submitted pending grazing event values, keyed by row key.
   */
  protected function processPendingGrazingEvents(PlanInterface $plan, int $asset_id, array $rows) {

    // Bail if there are no pending rows.
    if (empty($rows)) {
      return;
    }

    // Sort the submitted rows by their submitted weight values, to get the
    // order of the rows as submitted.
    uasort($rows, function ($a, $b) {
      return ((int) ($a['weight'] ?? 0)) <=> ((int) ($b['weight'] ?? 0));
    });

    // If the submitted order differs from the saved order, recompute the
    // start dates of all pending rows.
    if (array_keys($rows) !== $this->getSavedPendingGrazingEventOrder($plan, $asset_id, $rows)) {
      $this->recomputePendingGrazingEventStarts($plan, $asset_id, $rows);
    }

    // Update the existing grazing events, and create the new ones.
    foreach ($rows as $row_key => $values) {
      if (is_numeric($row_key)) {
        $this->updateGrazingEvent((int) $row_key, $values);
      }
      else {
        $this->createGrazingEvent((int) $plan->id(), $asset_id, $values);
      }
    }
  }

  /**
   * Get the saved order of the pending grazing events for an asset.
   *
   * @param \Drupal\plan\Entity\PlanInterface $plan
   *   The grazing plan.
   * @param int $asset_id
   *   The asset ID.
   * @param array $rows
   *   The submitted pending grazing event values, keyed by row key.
   *
   * @return array
   *   Returns the row keys in saved order, with existing grazing event IDs
   *   first in chronological order, and new rows last in row number order.
   */
  protected function getSavedPendingGrazingEventOrder(PlanInterface $plan, int $asset_id, array $rows): array {

    // Get the saved grazing events for the asset, sorted chronologically.
    $grazing_events = $this->grazingPlan->getGrazingEventsByAsset($plan)[$asset_id] ?? [];

    // Get the pending grazing events, in chronological order.
    $saved_order = [];
    foreach ($grazing_events as $grazing_event_id => $grazing_event) {
      if ($grazing_event->getLog()->get('status')->value !== 'done') {
        $saved_order[] = $grazing_event_id;
      }
    }

    // Append the new rows, in row number order.
    $new_row_keys = array_filter(array_keys($rows), 'is_string');
    usort($new_row_keys, function ($a, $b) {
      return ((int) substr($a, 4)) <=> ((int) substr($b, 4));
    });

    return array_merge($saved_order, $new_row_keys);
  }

  /**
   * Recompute the start dates of the pending grazing events for an asset.
   *
   * The first event starts at the end of the last completed event for the
   * asset, or keeps its submitted start date if there are no completed
   * events. Each subsequent event starts at the end of the previous event,
   * based on its planned duration.
   *
   * @param \Drupal\plan\Entity\PlanInterface $plan
   *   The grazing plan.
   * @param int $asset_id
   *   The asset ID.
   * @param array $rows
   *   The pending grazing event values, keyed by row key, in the new order.
   *   The values are modified in place.
   */
  protected function recomputePendingGrazingEventStarts(PlanInterface $plan, int $asset_id, array &$rows) {

    // Get the end of the last completed grazing event for the asset, to use
    // as the anchor for the recomputed start dates. The events are sorted
    // chronologically, so the last completed event is the most recent.
    $grazing_events = $this->grazingPlan->getGrazingEventsByAsset($plan)[$asset_id] ?? [];
    $anchor_start = NULL;
    foreach ($grazing_events as $grazing_event) {
      if ($grazing_event->getLog()->get('status')->value === 'done') {
        $anchor_start = $grazing_event->getLog()->get('timestamp')->value + ($grazing_event->get('duration')->value * 60 * 60);
      }
    }

    // Cascade the start dates through the pending events.
    $previous_start = NULL;
    $previous_duration = 0;
    foreach ($rows as &$values) {

      // Convert duration from hours to seconds.
      $duration = (int) round((float) ($values['planned_duration'] ?? 0) * 60 * 60);

      // The first event starts at the end of the last completed event, or
      // keeps its submitted start date if there are no completed events.
      if ($previous_start === NULL) {
        if ($anchor_start !== NULL) {
          $values['planned_start'] = $values['actual_start'] = $anchor_start;
        }
      }

      // Subsequent events start at the end of the previous event.
      else {
        $start = $previous_start + $previous_duration;
        $values['planned_start'] = $values['actual_start'] = $start;
      }

      // Update previous start and duration tracking variables.
      $previous_start = (int) $values['actual_start'];
      $previous_duration = $duration;
    }
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
    $log = Log::create([
      'type' => 'activity',
      'name' => $this->t('Move @asset to @location', ['@asset' => $asset->label(), '@location' => $location->label()]),
      'timestamp' => $values['actual_start'],
      'asset' => [$asset],
      'location' => [$location],
      'status' => 'pending',
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
