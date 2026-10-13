<?php

declare(strict_types=1);

namespace Drupal\farm_grazing_plan\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\farm_grazing_plan\GrazingPlanInterface;
use Drupal\log\Entity\LogInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the GrazingEventLogRestrictedFields constraint.
 */
class GrazingEventLogRestrictedFieldsValidator extends ConstraintValidator implements ContainerInjectionInterface {

  use AutowireTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected GrazingPlanInterface $grazingPlan,
  ) {}

  /**
   * Restricted log fields.
   *
   * @var string[]
   */
  protected array $restrictedFieldNames = [
    'timestamp',
    'asset',
    'location',
    'is_movement',
    'status',
  ];

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint) {
    /** @var \Drupal\log\Entity\LogInterface $value */
    /** @var \Drupal\farm_grazing_plan\Plugin\Validation\Constraint\GrazingEventLogRestrictedFields $constraint */

    // If the log is new, bail.
    $log = $value;
    if ($log->isNew()) {
      return;
    }

    // If the log is not associated with a grazing_event plan_record, bail.
    $grazing_events = $this->entityTypeManager->getStorage('plan_record')->loadByProperties([
      'type' => 'grazing_event',
      'log' => $log->id(),
    ]);
    if (empty($grazing_events)) {
      return;
    }

    // Load the original unchanged log from the database.
    $original_log = $this->entityTypeManager->getStorage('log')->load($log->id());

    // Determine whether the status change, if any, is allowed.
    $status_allowed = $this->isStatusChangeAllowed($log, $original_log, $grazing_events);

    // Do not allow restricted fields to be modified.
    $restricted_modification = FALSE;
    $status_modification = FALSE;
    foreach ($this->restrictedFieldNames as $field_name) {
      // Skip the status field, if the change is allowed.
      if ($field_name === 'status' && $status_allowed) {
        continue;
      }
      if ($log->get($field_name)->getValue() != $original_log->get($field_name)->getValue()) {
        $restricted_modification = TRUE;
        if ($field_name === 'status') {
          $status_modification = TRUE;
        }
      }
    }
    if ($restricted_modification) {
      $message = $status_modification ? $constraint->statusMessage : $constraint->message;
      $this->context->addViolation($message);
    }
  }

  /**
   * Determines whether a status change is allowed for the log.
   *
   * Status changes are allowed only when they cannot result in an out-of-order
   * sequence of grazing events. Completed events must always come before
   * pending events, per asset. Abandoned events do not participate in the
   * ordering.
   *
   * The allowed transitions, relative to the event's position in the
   * per-asset chronological ordering, are:
   * - pending to done: the first pending event only.
   * - pending to abandoned: any pending event.
   * - done to pending: the last completed event only.
   * - abandoned to pending: only if no completed events follow it.
   *
   * @param \Drupal\log\Entity\LogInterface $log
   *   The log entity being validated.
   * @param \Drupal\log\Entity\LogInterface $original_log
   *   The original, unchanged version of the log from the database.
   * @param \Drupal\plan\Entity\PlanRecordInterface[] $grazing_events
   *   The grazing event plan records referencing the log.
   *
   * @return bool
   *   Returns TRUE if the status change is allowed.
   */
  protected function isStatusChangeAllowed(LogInterface $log, LogInterface $original_log, array $grazing_events): bool {
    $original_status = $original_log->get('status')->value;
    $new_status = $log->get('status')->value;

    // If the status has not changed, the change is allowed.
    if ($original_status === $new_status) {
      return TRUE;
    }

    // Any other status change requires the log to be associated with exactly
    // one grazing event, and to reference exactly one asset.
    if (count($grazing_events) !== 1) {
      return FALSE;
    }
    $grazing_event = reset($grazing_events);

    // Get the grazing event's plan.
    $plan = $grazing_event->getPlan();
    if (!$plan) {
      return FALSE;
    }

    // Get the grazing event's asset, using the original log.
    $assets = $original_log->get('asset')->referencedEntities();
    if (count($assets) !== 1) {
      return FALSE;
    }
    $asset = $assets[0];

    // Abandoning a pending event is always allowed.
    if ($original_status === 'pending' && $new_status === 'abandoned') {
      return TRUE;
    }

    // Get the grazing event's planned start.
    $start = (int) $grazing_event->get('start')->value;

    // Find the earliest pending and latest completed start times for the
    // asset. For the event being validated, use the original status.
    $events_by_asset = $this->grazingPlan->getGrazingEventsByAsset($plan);
    $events = $events_by_asset[$asset->id()] ?? [];
    $first_pending_start = NULL;
    $last_completed_start = NULL;
    foreach ($events as $event) {
      if ($event->id() === $grazing_event->id()) {
        $status = $original_status;
      }
      else {
        $status = $event->getLog()->get('status')->value;
      }
      $event_start = (int) $event->get('start')->value;
      if ($status === 'pending') {
        if ($first_pending_start === NULL || $event_start < $first_pending_start) {
          $first_pending_start = $event_start;
        }
      }
      elseif ($status === 'done') {
        if ($last_completed_start === NULL || $event_start > $last_completed_start) {
          $last_completed_start = $event_start;
        }
      }
    }

    // Allow the transition only if it keeps the events in order.
    return match ("$original_status->$new_status") {
      'pending->done' => $first_pending_start === $start,
      'done->pending' => $last_completed_start === $start,
      'abandoned->pending' => $last_completed_start === NULL || $start >= $last_completed_start,
      default => FALSE,
    };
  }

}
