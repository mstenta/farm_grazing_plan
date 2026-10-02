<?php

declare(strict_types=1);

namespace Drupal\farm_grazing_plan\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Validates the chronological order of a new grazing event.
 */
#[Constraint(
  id: 'GrazingEventOrder',
  label: new TranslatableMarkup('Validate grazing event order.', ['context' => 'Validation']),
)]
class GrazingEventOrder extends SymfonyConstraint {

  /**
   * The violation message for when the planned start is before the last event.
   *
   * @var string
   */
  public string $earlyStartMessage = 'The planned start date/time is before the last existing grazing event for this asset in the plan. Grazing events can only be added to the end of the plan.';

  /**
   * The violation message for when the log timestamp is before the last event.
   *
   * @var string
   */
  public string $earlyLogMessage = 'The movement log timestamp is before the last existing grazing event for this asset in the plan. Grazing events can only be added to the end of the plan.';

}
