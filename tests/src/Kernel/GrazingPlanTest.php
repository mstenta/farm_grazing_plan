<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_grazing_plan\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\farm_grazing_plan\Traits\MockGrazingPlanEntitiesTrait;

/**
 * Tests for farmOS grazing plan.
 *
 * @group farm_grazing_plan
 */
class GrazingPlanTest extends KernelTestbase {

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

  /**
   * Test the grazing plan service.
   */
  public function testGrazingPlanService() {

    // Create mock plan entities.
    $this->createMockPlanEntities();

    // Test getting all grazing_event plan_record entities for a plan.
    $grazing_events = \Drupal::service('farm_grazing_plan')->getGrazingEvents($this->plan);
    $this->assertCount(10, $grazing_events);
    usort($grazing_events, function ($a, $b) {
      return ($a->getLog()->id() < $b->getLog()->id()) ? -1 : 1;
    });
    foreach ($grazing_events as $delta => $grazing_event) {
      $this->assertEquals($this->movementLogs[$delta]->id(), $grazing_event->getLog()->id());
      $this->assertEquals($this->movementLogs[$delta]->get('timestamp')->value, $grazing_event->get('start')->value);
      $this->assertEquals(168, $grazing_event->get('duration')->value);
      $this->assertEquals(360, $grazing_event->get('recovery')->value);
    }

    // Test getting grazing_event records by asset.
    $grazing_events_by_asset = \Drupal::service('farm_grazing_plan')->getGrazingEventsByAsset($this->plan);
    $this->assertEquals(2, count($grazing_events_by_asset));
    $log_ids = array_map(function ($log) {
      return $log->id();
    }, $this->movementLogs);
    $grazing_event_log_ids = [];
    foreach ($this->animalAssets as $animal_asset) {
      $this->assertNotEmpty($grazing_events_by_asset[$animal_asset->id()]);
      $this->assertCount(5, $grazing_events_by_asset[$animal_asset->id()]);
      foreach ($grazing_events_by_asset[$animal_asset->id()] as $grazing_event) {
        /** @var \Drupal\farm_grazing_plan\Bundle\GrazingEvent $grazing_event */
        $grazing_event_log_ids[] = $grazing_event->getLog()->id();
      }
    }
    $this->assertEquals($log_ids, $grazing_event_log_ids);

    // Test getting grazing_event records by location.
    $grazing_events_by_location = \Drupal::service('farm_grazing_plan')->getGrazingEventsByLocation($this->plan);
    $this->assertEquals(5, count($grazing_events_by_location));
    $grazing_event_log_ids = [];
    foreach ($this->landAssets as $land_asset) {
      $this->assertNotEmpty($grazing_events_by_location[$land_asset->id()]);
      $this->assertCount(2, $grazing_events_by_location[$land_asset->id()]);
      foreach ($grazing_events_by_location[$land_asset->id()] as $grazing_event) {
        /** @var \Drupal\farm_grazing_plan\Bundle\GrazingEvent $grazing_event */
        $grazing_event_log_ids[] = $grazing_event->getLog()->id();
      }
    }
    $this->assertEquals(sort($log_ids), sort($grazing_event_log_ids));

    // Reschedule the last grazing event so that happens before the first, and
    // confirm that grazing events are sorted chronologically by their planned
    // start dates.
    $first_event = reset($grazing_events);
    $last_event = end($grazing_events);
    $event_id = $last_event->id();
    $new_start = $first_event->get('start')->value - ($last_event->get('duration')->value * 60 * 60);
    $last_event->set('start', $new_start)->save();
    $grazing_events = \Drupal::service('farm_grazing_plan')->getGrazingEvents($this->plan);
    $first_event = reset($grazing_events);
    $this->assertEquals($first_event->id(), $event_id);
  }

}
