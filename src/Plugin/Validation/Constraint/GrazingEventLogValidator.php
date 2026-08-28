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
   * Restricted log fields.
   *
   * @var string[]
   */
  protected array $restrictedFieldNames = [
    'timestamp',
    'asset',
    'location',
    'is_movement',
  ];

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint) {
    /** @var \Drupal\log\Entity\LogInterface $value */
    /** @var \Drupal\farm_grazing_plan\Plugin\Validation\Constraint\GrazingEventLog $constraint */

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
    $grazing_event = reset($grazing_events);

    // Load the plan from the plan_record.
    /** @var \Drupal\plan\Entity\PlanInterface $plan */
    $plan = $grazing_event->get('plan')->referencedEntities()[0];

    // Load the original unchanged log from the database.
    $original_log = $this->entityTypeManager->getStorage('log')->load($log->id());

    // Do not allow restricted fields to be modified.
    $restricted_modification = FALSE;
    foreach ($this->restrictedFieldNames as $field_name) {
      if ($log->get($field_name)->getValue() != $original_log->get($field_name)->getValue()) {
        $restricted_modification = TRUE;
      }
    }
    if ($restricted_modification) {
      $this->context->addViolation($constraint->message, [':plan_uri' => $plan->toUrl()->toString(), '%plan_name' => $plan->label()]);
    }
  }

}
