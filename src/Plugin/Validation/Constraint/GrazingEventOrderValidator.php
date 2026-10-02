<?php

declare(strict_types=1);

namespace Drupal\farm_grazing_plan\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\farm_grazing_plan\GrazingPlanInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the GrazingEventOrder constraint.
 */
class GrazingEventOrderValidator extends ConstraintValidator implements ContainerInjectionInterface {

  use AutowireTrait;

  public function __construct(
    protected GrazingPlanInterface $grazingPlan,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint) {
    /** @var \Drupal\plan\Entity\PlanRecordInterface $value */
    /** @var \Drupal\farm_grazing_plan\Plugin\Validation\Constraint\GrazingEventOrder $constraint */

    // Only validate new grazing_event plan records.
    if ($value->bundle() !== 'grazing_event' || !$value->isNew()) {
      return;
    }

    // Bail if the referenced plan or log is not available.
    $plan = $value->getPlan();
    if (empty($plan)) {
      return;
    }
    $logs = $value->get('log')->referencedEntities();
    $log = reset($logs);
    if (empty($log)) {
      return;
    }

    // Bail if the log does not reference an asset.
    $assets = $log->get('asset')->referencedEntities();
    $asset = reset($assets);
    if (empty($asset)) {
      return;
    }

    // Get the last grazing event for this asset in the plan.
    $grazing_events = $this->grazingPlan->getGrazingEventsByAsset($plan);
    if (empty($grazing_events[$asset->id()])) {
      return;
    }
    $last_event = end($grazing_events[$asset->id()]);

    // The planned start must not be before the last event start.
    $last_start = (int) $last_event->get('start')->value;
    $start = (int) $value->get('start')->value;
    if (!empty($start) && $start < $last_start) {
      $this->context->buildViolation($constraint->earlyStartMessage)
        ->atPath('start')
        ->addViolation();
    }

    // The log timestamp must not be before the last event start.
    $timestamp = (int) $log->get('timestamp')->value;
    if (!empty($timestamp) && $timestamp < $last_start) {
      $this->context->buildViolation($constraint->earlyLogMessage)
        ->atPath('log')
        ->addViolation();
    }
  }

}
