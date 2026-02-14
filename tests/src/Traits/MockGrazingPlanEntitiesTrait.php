<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_grazing_plan\Traits;

use Drupal\asset\Entity\Asset;
use Drupal\log\Entity\Log;
use Drupal\plan\Entity\Plan;
use Drupal\plan\Entity\PlanRecord;
use Drupal\taxonomy\Entity\Term;

/**
 * Provides test methods for creating mock grazing plan entities.
 */
trait MockGrazingPlanEntitiesTrait {

  /**
   * Season term.
   *
   * @var \Drupal\taxonomy\Entity\Term
   */
  protected $seasonTerm = NULL;

  /**
   * Animal type term.
   *
   * @var \Drupal\taxonomy\Entity\Term
   */
  protected $animalType = NULL;

  /**
   * Animal assets.
   *
   * @var \Drupal\asset\Entity\AssetInterface[]
   */
  protected $animalAssets = [];

  /**
   * Land assets.
   *
   * @var \Drupal\asset\Entity\AssetInterface[]
   */
  protected $landAssets = [];

  /**
   * Movement logs.
   *
   * @var \Drupal\log\Entity\LogInterface[]
   */
  protected $movementLogs = [];

  /**
   * Grazing plan.
   *
   * @var \Drupal\plan\Entity\PlanInterface
   */
  protected $plan = NULL;

  /**
   * Create mock grazing plan entities.
   */
  public function createMockPlanEntities() {

    // Create a season term.
    $this->seasonTerm = Term::create([
      'name' => '2024',
      'vid' => 'season',
    ]);
    $this->seasonTerm->save();

    // Create animal_type term.
    $this->animalType = Term::create([
      'name' => 'sheep',
      'vid' => 'animal_type',
    ]);
    $this->animalType->save();

    // Create two animal assets.
    for ($i = 1; $i <= 2; $i++) {
      $asset = Asset::create([
        'name' => 'Sheep ' . $i,
        'type' => 'animal',
        'animal_type' => [['target_id' => $this->animalType->id()]],
      ]);
      $asset->save();
      $this->animalAssets[] = $asset;
    }

    // Create 5 land assets.
    for ($i = 1; $i <= 5; $i++) {
      $asset = Asset::create([
        'name' => 'Paddock ' . $i,
        'type' => 'land',
        'land_type' => 'paddock',
        'is_fixed' => TRUE,
        'is_location' => TRUE,
      ]);
      $asset->save();
      $this->landAssets[] = $asset;
    }

    // Start the plan on May 1, 2024.
    $timestamp = strtotime('May 1, 2024');

    // Create activity logs that move each animal through all paddocks.
    foreach ($this->animalAssets as $animal_asset) {
      foreach ($this->landAssets as $land_asset) {
        $log = Log::create([
          'name' => 'Move ' . $animal_asset->label() . ' to ' . $land_asset->label(),
          'type' => 'activity',
          'timestamp' => strtotime('+7 days', $timestamp),
          'asset' => [
            ['target_id' => $animal_asset->id()],
          ],
          'location' => [
            ['target_id' => $land_asset->id()],
          ],
          'is_movement' => TRUE,
          'status' => 'done',
        ]);
        $log->save();
        $this->movementLogs[] = $log;
      }
    }

    // Create a grazing plan for the season.
    $this->plan = Plan::create([
      'name' => $this->seasonTerm->label() . ' Grazing Plan',
      'type' => 'grazing',
      'season' => [
        ['target_id' => $this->seasonTerm->id()],
      ],
    ]);
    $this->plan->save();

    // Create grazing_event plan_record entities to link movement logs to the
    // plan.
    foreach ($this->movementLogs as $log) {
      $grazing_event = PlanRecord::create([
        'type' => 'grazing_event',
        'plan' => ['target_id' => $this->plan->id()],
        'log' => ['target_id' => $log->id()],
        'start' => $log->get('timestamp')->value,
        'duration' => 7 * 24,
        'recovery' => 15 * 24,
      ]);
      $grazing_event->save();
    }
  }

}
