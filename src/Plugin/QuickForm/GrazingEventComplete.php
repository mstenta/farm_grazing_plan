<?php

declare(strict_types=1);

namespace Drupal\farm_grazing_plan\Plugin\QuickForm;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\asset\Entity\AssetInterface;
use Drupal\farm_grazing_plan\Bundle\GrazingEventInterface;
use Drupal\farm_grazing_plan\GrazingPlanInterface;
use Drupal\farm_location\AssetLocationInterface;
use Drupal\farm_quick\Attribute\QuickForm;
use Drupal\farm_quick\Plugin\QuickForm\QuickFormBase;
use Drupal\farm_quick\Traits\QuickFormElementsTrait;
use Drupal\farm_quick\Traits\QuickLogTrait;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Complete grazing event quick form.
 *
 * Marks a pending grazing event as done and records the actual date, notes,
 * and geometry on its movement log. Graze height and photos are recorded on
 * an observation log for the paddock the animals entered, and residual
 * height on an observation log for the paddock they left.
 */
#[QuickForm(
  id: 'grazing_event_complete',
  label: new TranslatableMarkup('Complete grazing event'),
  description: new TranslatableMarkup('Complete a pending grazing event in a grazing plan.'),
  helpText: new TranslatableMarkup('Use this form when animals move into the paddock of a pending grazing event. Record the actual date, the graze height of the paddock they are entering, and the residual height of the paddock they are leaving.'),
  permissions: [
    'update any activity log',
    'create observation log',
  ],
)]
class GrazingEventComplete extends QuickFormBase {

  use QuickFormElementsTrait;
  use QuickLogTrait;

  /**
   * The units that forage heights are recorded in.
   */
  public const HEIGHT_UNITS = 'in';

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    AccountInterface $current_user,
    protected GrazingPlanInterface $grazingPlan,
    protected RequestStack $requestStack,
    protected ConfigFactoryInterface $configFactory,
    protected AssetLocationInterface $assetLocation,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $current_user);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    // Get the options for the grazing event select element.
    $options = $this->getGrazingEventOptions();

    // If a grazing event is specified in the query string, preselect it, as
    // long as it is one of the available options.
    $grazing_event_id = (int) $this->requestStack->getCurrentRequest()->query->get('grazing_event');
    $default = array_key_exists($grazing_event_id, $options) ? $grazing_event_id : NULL;

    // Select the grazing event to complete.
    $form['grazing_event'] = [
      '#type' => 'select',
      '#title' => $this->t('Grazing event'),
      '#description' => $this->t('Select a pending grazing event. Only events from "active" plans are included.'),
      '#options' => $options,
      '#default_value' => $default,
      '#required' => TRUE,
    ];

    // Get user.
    $uid = (string) \Drupal::currentUser()->id();

    // Store notification system.
    $storage = \Drupal::keyValue('farm_grazing_plan_notifications');
    $notifications = $storage->get($uid, []); 

    // Create expandable notification section.
    $form['notification_section'] = [
      '#type' => 'details',
      '#title' => $this->t('Notifications (@count)', [
        '@count' => count($notifications),
      ]),
      '#description' => $this->t('View your recent grazing events.'),
      '#open' => FALSE,
      '#weight' => -100,
    ];

    // If no notifications yet.
    if (empty($notifications)) {
      $form['notification_section']['empty'] = [
        '#markup' => $this->t("No notifications displayed"),
      ];
    }

    // Add notification to the list with label and date.
    else {
      $items = [];

      foreach ($notifications as $notification) {
        $date = \Drupal::service('date.formatter')->format(
          $notification['timestamp'],
          'short'
        );

        $items[] = $this->t('Grazing completed on @date', [
          '@date' => $date,
        ]);
      }

      $form['notification_section']['list'] = [
        '#theme' => 'item_list',
        '#items' => $items,
      ];
    }
    
    return $form;
  }

  /**
   * Get the options for the grazing event select element.
   *
   * The options are keyed by the grazing event ID.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   Returns the option labels, keyed by grazing event ID.
   */
  protected function getGrazingEventOptions(): array {
    $options = [];

    // Load all grazing plans with a status of "active".
    /** @var \Drupal\plan\Entity\PlanInterface[] $plans */
    $plans = $this->entityTypeManager->getStorage('plan')->loadByProperties([
      'type' => 'grazing',
      'status' => 'active',
    ]);

    // Iterate through the plans.
    foreach ($plans as $plan) {

      // If the user does not have access to update this plan, skip it.
      if (!$plan->access('update')) {
        continue;
      }

      // Iterate through each asset's grazing events.
      foreach ($this->grazingPlan->getGrazingEventsByAsset($plan) as $asset_id => $grazing_events) {

        // Load the asset.
        /** @var \Drupal\asset\Entity\AssetInterface $asset */
        $asset = $this->entityTypeManager->getStorage('asset')->load($asset_id);

        // If the user does not have access to this asset, continue to the
        // next.
        if (!$asset->access('view')) {
          continue;
        }

        // Iterate through grazing events.
        foreach ($grazing_events as $grazing_event) {

          // // If the grazing event is not pending, skip it.
          // if ($grazing_event->getStatus() !== 'pending') {
          //   continue;
          // }

          // Build the option label, keeping the start value for sorting.
          $options[$grazing_event->id()] = [
            'start' => (int) $grazing_event->get('start')->value,
            'label' => $grazing_event->label(),
          ];

          // Only include the first pending event.
          break;
        }
      }
    }

    // Sort the options by their planned start dates.
    uasort($options, function ($a, $b) {
      return $a['start'] <=> $b['start'];
    });

    // Return the list of options (just the labels, keyed by grazing event ID).
    return array_map(function ($option) {
      return $option['label'];
    }, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    // Load the selected grazing event.
    /** @var \Drupal\farm_grazing_plan\Bundle\GrazingEventInterface|null $grazing_event */
    $grazing_event = $this->entityTypeManager->getStorage('plan_record')->load($form_state->getValue('grazing_event'));

    // If the selected grazing event is missing or not pending, show an error.
    if (!$grazing_event || $grazing_event->bundle() !== 'grazing_event' || $grazing_event->getStatus() !== 'pending') {
      $this->messenger()->addError($this->t('The selected grazing event does not exist or is not pending.'));
      return;
    }

    // Get the plan and log.
    $plan = $grazing_event->getPlan();
    $log = $grazing_event->getLog();

    // If the user does not have access to update the plan or the log, show an
    // error.
    if (!$plan->access('update') || !$log->access('update')) {
      $this->messenger()->addError($this->t('You do not have access to the selected grazing event.'));
      return;
    }

    // Get the actual date as a timestamp.
    $date = $form_state->getValue('timestamp');
    $timestamp = $date instanceof DrupalDateTime ? $date->getTimestamp() : time();

    // Mark the movement log as done, on the actual date.
    $log->set('status', 'done');
    $log->set('timestamp', $timestamp);

    // Save the completed grazing event log.
    $log->save();

    // Get user info.
    $uid = (string) \Drupal::currentUser()->id();

    // Store notification system.
    $storage = \Drupal::keyValue('farm_grazing_plan_notifications');

    // Retrieve previous notifications.
    $notifications = $storage->get($uid, []);

    // Add new notification.
    array_unshift($notifications, ['timestamp' => \Drupal::time()->getRequestTime(),]); # storing timestamp

    // Display 10 most recent notifications.
    $notifications = array_slice($notifications, 0, 10);

    // Save updated notifications.
    $storage->set($uid, $notifications);

    // Append notes to any existing log notes, only if provided.
    $notes = trim((string) $form_state->getValue('notes'));
    if ($notes !== '') {
      $existing = (string) $log->get('notes')->value;
      $log->set('notes', [
        'value' => $existing !== '' ? $existing . "\n\n" . $notes : $notes,
        'format' => $log->get('notes')->format ?? 'default',
      ]);
    }

    // Only overwrite geometry if one was drawn.
    $geometry = $form_state->getValue('geometry');
    if (!empty($geometry)) {
      $log->set('geometry', $geometry);
    }

    // Save the movement log.
    $log->save();

    // Record graze height and photos on the paddock the animals entered.
    $photos = array_values(array_map('intval', array_filter((array) $form_state->getValue('images'))));
    $this->createPaddockObservation(
      $grazing_event->getLocation(),
      $grazing_event->getAsset(),
      $timestamp,
      $this->t('Graze height'),
      $form_state->getValue('graze_height'),
      $photos,
    );

    // Record residual height on the paddock the animals left, if any.
    $previous_paddock = $this->getPreviousPaddock($grazing_event);
    if ($previous_paddock) {
      $this->createPaddockObservation(
        $previous_paddock,
        $grazing_event->getAsset(),
        $timestamp,
        $this->t('Residual height'),
        $form_state->getValue('residual_height'),
      );
    }

    // Tell the user that the grazing event was completed.
    $this->messenger()->addMessage($this->t('Completed grazing event: %label', ['%label' => $grazing_event->label()]));

    // Redirect to the plan.
    $form_state->setRedirect('entity.plan.canonical', ['plan' => $plan->id()]);
  }

  /**
   * Create an observation log on a paddock, if there is anything to record.
   *
   * @param \Drupal\asset\Entity\AssetInterface $paddock
   *   The paddock.
   * @param \Drupal\asset\Entity\AssetInterface $animals
   *   The animal or group asset.
   * @param int $timestamp
   *   The observation timestamp.
   * @param \Stringable|string $height_label
   *   The label for the height quantity.
   * @param mixed $height
   *   The submitted height, or an empty value.
   * @param int[] $photos
   *   Uploaded photo file IDs.
   */
  protected function createPaddockObservation(AssetInterface $paddock, AssetInterface $animals, int $timestamp, $height_label, $height, array $photos = []): void {

    // Skip if there is no height and no photo.
    $has_height = $height !== '' && $height !== NULL;
    if (!$has_height && empty($photos)) {
      return;
    }

    // Build the log values.
    $values = [
      'type' => 'observation',
      'name' => $this->t('@label: @paddock', [
        '@label' => $height_label,
        '@paddock' => $paddock->label(),
      ]),
      'timestamp' => $timestamp,
      'asset' => [$paddock, $animals],
      'location' => [$paddock],
      'status' => 'done',
    ];
    if ($has_height) {
      $values['quantity'][] = [
        'type' => 'standard',
        'measure' => 'length',
        'value' => $height,
        'units' => static::HEIGHT_UNITS,
        'label' => (string) $height_label,
      ];
    }
    if (!empty($photos)) {
      $values['image'] = $photos;
    }

    // Create the log. This also shows a message with a link to it.
    $this->createLog($values);
  }

  /**
   * Get the paddock of the grazing event before this one, for the same asset.
   *
   * @param \Drupal\farm_grazing_plan\Bundle\GrazingEventInterface $grazing_event
   *   The grazing event.
   *
   * @return \Drupal\asset\Entity\AssetInterface|null
   *   The previous paddock, or NULL if this is the asset's first event.
   */
  protected function getPreviousPaddock(GrazingEventInterface $grazing_event): ?AssetInterface {
    $events = $this->grazingPlan->getGrazingEventsByAsset($grazing_event->getPlan());
    $previous = NULL;
    foreach ($events[$grazing_event->getAsset()->id()] ?? [] as $event) {
      if ((int) $event->id() === (int) $grazing_event->id()) {
        return $previous ? $previous->getLocation() : NULL;
      }
      $previous = $event;
    }
    return NULL;
  }

}
