<?php

declare(strict_types=1);

namespace Drupal\farm_grazing_plan\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the GrazingEventLog constraint.
 */
class GrazingEventLogValidator extends ConstraintValidator implements ContainerInjectionInterface {

  use AutowireTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint) {
    /** @var \Drupal\plan\Entity\PlanRecordInterface $value */
    /** @var \Drupal\farm_grazing_plan\Plugin\Validation\Constraint\GrazingEventLog $constraint */

    // Only validate grazing_event plan records.
    if ($value->bundle() !== 'grazing_event') {
      return;
    }

    // Attempt to load the referenced log.
    $logs = $value->get('log')->referencedEntities();
    $log = reset($logs);
    if (empty($log)) {
      $this->context->addViolation($constraint->missingLogMessage);
      return;
    }

    // The log must be a movement.
    if (!$log->get('is_movement')->value) {
      $this->context->addViolation($constraint->nonMovementMessage);
    }

    // The log must not already be part of another grazing event.
    $existing_query = $this->entityTypeManager->getStorage('plan_record')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'grazing_event')
      ->condition('log', $log->id());
    if (!$value->isNew()) {
      $existing_query->condition('id', $value->id(), '<>');
    }
    if ($existing_query->count()->execute() > 0) {
      $this->context->addViolation($constraint->existingGrazingEventMessage);
    }

    // The log must reference exactly one asset.
    $asset_count = count($log->get('asset'));
    if ($asset_count < 1) {
      $this->context->addViolation($constraint->noAssetMessage);
    }
    if ($asset_count > 1) {
      $this->context->addViolation($constraint->multipleAssetsMessage);
    }

    // The log must reference exactly one location.
    $location_count = count($log->get('location'));
    if ($location_count < 1) {
      $this->context->addViolation($constraint->noLocationMessage);
    }
    if ($location_count > 1) {
      $this->context->addViolation($constraint->multipleLocationsMessage);
    }
  }

}
