<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_grazing_plan\Traits;

use Drupal\asset\Entity\Asset;
use Drupal\asset\Entity\AssetInterface;
use Drupal\log\Entity\Log;
use Drupal\plan\Entity\Plan;
use Drupal\plan\Entity\PlanInterface;
use Drupal\plan\Entity\PlanRecord;
use Drupal\taxonomy\Entity\Term;

/**
 * Provides test methods for creating mock grazing plan entities.
 */
trait MockGrazingPlanEntitiesTrait {

  /**
   * Season term.
   *
   * @var \Drupal\taxonomy\Entity\Term|null
   */
  protected ?Term $seasonTerm = NULL;

  /**
   * Animal type term.
   *
   * @var \Drupal\taxonomy\Entity\Term|null
   */
  protected ?Term $animalType = NULL;

  /**
   * Animal assets.
   *
   * @var \Drupal\asset\Entity\AssetInterface[]
   */
  protected array $animalAssets = [];

  /**
   * Land assets.
   *
   * @var \Drupal\asset\Entity\AssetInterface[]
   */
  protected array $landAssets = [];

  /**
   * Movement logs.
   *
   * @var \Drupal\log\Entity\LogInterface[]
   */
  protected array $movementLogs = [];

  /**
   * Grazing plan.
   *
   * @var \Drupal\plan\Entity\PlanInterface|null
   */
  protected ?PlanInterface $plan = NULL;

  /**
   * Grazing events.
   *
   * @var \Drupal\plan\Entity\PlanRecordInterface[]
   */
  protected array $grazingEvents = [];

  /**
   * Create mock grazing plan entities.
   */
  public function createMockPlanEntities(): void {

    // Create a season term.
    $year = date('Y');
    $this->seasonTerm = Term::create([
      'name' => $year,
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

    // Create a grazing plan for the season.
    $this->plan = Plan::create([
      'name' => $this->seasonTerm->label() . ' Grazing Plan',
      'type' => 'grazing',
      'season' => [
        ['target_id' => $this->seasonTerm->id()],
      ],
    ]);
    $this->plan->save();

    // Create activity logs that move each animal through all paddocks.
    $timestamp = NULL;
    foreach ($this->animalAssets as $animal_asset) {
      foreach ($this->landAssets as $land_asset) {

        // If this is the first log, start on May 1.
        if (is_null($timestamp)) {
          $timestamp = strtotime('May 1, ' . $year);
        }

        // Otherwise, add a week to the previous timestamp.
        else {
          $timestamp = strtotime('+7 days', $timestamp);
        }

        // Create the log and plan_record entities.
        $this->createMockGrazingEvent($timestamp, $animal_asset, $land_asset, TRUE, $this->plan);
      }
    }
  }

  /**
   * Create mock grazing event log (and optional plan_record) entity.
   */
  public function createMockGrazingEvent(int $timestamp, AssetInterface $asset, AssetInterface $location, bool $plan_record = TRUE, ?PlanInterface $plan = NULL) {

    // Create the log entity.
    $log = Log::create([
      'name' => 'Move ' . $asset->label() . ' to ' . $location->label(),
      'type' => 'activity',
      'timestamp' => $timestamp,
      'asset' => [
        ['target_id' => $asset->id()],
      ],
      'location' => [
        ['target_id' => $location->id()],
      ],
      'is_movement' => TRUE,
      'status' => 'done',
    ]);
    $log->save();
    $this->movementLogs[] = $log;

    // Create a plan_record, if desired.
    if ($plan_record) {
      $grazing_event = PlanRecord::create([
        'type' => 'grazing_event',
        'plan' => ['target_id' => $plan->id()],
        'log' => ['target_id' => $log->id()],
        'start' => $log->get('timestamp')->value,
        'duration' => 7 * 24,
        'recovery' => 15 * 24,
      ]);
      $grazing_event->save();
      $this->grazingEvents[] = $grazing_event;
    }
  }

}
