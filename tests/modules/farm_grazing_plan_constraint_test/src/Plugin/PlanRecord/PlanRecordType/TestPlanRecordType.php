<?php

declare(strict_types=1);

namespace Drupal\farm_grazing_plan_constraint_test\Plugin\PlanRecord\PlanRecordType;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\farm_entity\Attribute\PlanRecordType;
use Drupal\farm_entity\Plugin\PlanRecord\PlanRecordType\FarmPlanRecordType;

/**
 * Provides a test plan record type.
 */
#[PlanRecordType(
  id: 'test',
  label: new TranslatableMarkup('Test'),
)]
class TestPlanRecordType extends FarmPlanRecordType {

}
