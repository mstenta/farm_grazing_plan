<?php

declare(strict_types=1);

namespace Drupal\farm_grazing_plan\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Restrict editing grazing event log fields.
 */
#[Constraint(
  id: 'GrazingEventLogRestrictedFields',
  label: new TranslatableMarkup('Restrict editing grazing event log fields.', ['context' => 'Validation']),
)]
class GrazingEventLogRestrictedFields extends SymfonyConstraint {

  /**
   * The default violation message.
   *
   * @var string
   */
  public string $message = 'This log is part of the Grazing Plan: <a href=":plan_uri">%plan_name</a>. Some data can only be modified in the plan.';

}
