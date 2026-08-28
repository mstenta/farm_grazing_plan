<?php

declare(strict_types=1);

namespace Drupal\farm_grazing_plan\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Restrict editing grazing event logs.
 */
#[Constraint(
  id: 'GrazingEventLog',
  label: new TranslatableMarkup('Restrict editing grazing event logs.', ['context' => 'Validation']),
)]
class GrazingEventLog extends SymfonyConstraint {

  /**
   * The default violation message.
   *
   * @var string
   */
  public string $message = 'This log is part of the Grazing Plan: <a href=":plan_uri">%plan_name</a>. Some data can only be modified in the plan.';

}
