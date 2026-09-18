<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_grazing_plan\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\farm_grazing_plan\Traits\MockGrazingPlanEntitiesTrait;

/**
 * Base class for farm_grazing_plan kernel tests.
 */
abstract class GrazingPlanTestBase extends KernelTestBase {

  use MockGrazingPlanEntitiesTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'asset',
    'entity',
    'farm_activity',
    'farm_animal',
    'farm_animal_type',
    'farm_entity',
    'farm_grazing_plan',
    'farm_id_tag',
    'farm_field',
    'farm_land',
    'farm_location',
    'farm_log',
    'farm_log_asset',
    'farm_map',
    'geofield',
    'log',
    'options',
    'plan',
    'state_machine',
    'taxonomy',
    'text',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('asset');
    $this->installEntitySchema('log');
    $this->installEntitySchema('plan');
    $this->installEntitySchema('plan_record');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('user');
    $this->installConfig([
      'farm_grazing_plan',
      'farm_land',
      'farm_animal',
      'farm_animal_type',
      'farm_activity',
    ]);
  }

}
