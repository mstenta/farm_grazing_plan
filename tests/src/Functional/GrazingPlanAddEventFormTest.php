<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_grazing_plan\Functional;

use Drupal\Tests\farm_grazing_plan\Traits\MockGrazingPlanEntitiesTrait;
use Drupal\Tests\farm_test\Functional\FarmBrowserTestBase;
use Drupal\log\Entity\Log;
use Drupal\plan\Entity\Plan;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the grazing plan add event form.
 */
#[Group('farm_grazing_plan')]
#[RunTestsInSeparateProcesses]
class GrazingPlanAddEventFormTest extends FarmBrowserTestBase {

  use MockGrazingPlanEntitiesTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'farm_grazing_plan',
    'farm_land',
  ];

  /**
   * Test adding a grazing event via the form.
   */
  public function testAddGrazingEvent() {

    // Create mock plan entities.
    $this->createMockPlanEntities();

    // Get plan_record entity storage.
    $plan_record_storage = \Drupal::entityTypeManager()->getStorage('plan_record');

    // Count plan_record entities.
    $expected_plan_record_count = count($plan_record_storage->loadMultiple());

    // Attempt to load the add grazing event form and confirm that access is
    // denied.
    $this->drupalGet('/plan/' . $this->plan->id() . '/grazing/event');
    $this->assertSession()->statusCodeEquals(403);

    // Create and log in a user with the necessary permissions.
    $user = $this->drupalCreateUser([
      'view any grazing plan',
      'update any grazing plan',
      'view any activity log',
    ]);
    $this->drupalLogin($user);

    // Load the add grazing event form and confirm the user has access and all
    // expected fields are visible.
    $this->drupalGet('/plan/' . $this->plan->id() . '/grazing/event');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('log');
    $this->assertSession()->fieldExists('start[date]');
    $this->assertSession()->fieldExists('start[time]');
    $this->assertSession()->fieldExists('duration');
    $this->assertSession()->fieldExists('recovery');

    // Get a timestamp for the next grazing event.
    $timestamp = $this->nextGrazingEventTimestamp();

    // Create a movement log that is not yet linked to the plan.
    $this->createMockGrazingEvent($timestamp, $this->animalAssets[0], $this->landAssets[0], FALSE);
    $log = end($this->movementLogs);

    // Submit the form.
    $edit = [
      'log' => $log->label() . ' (' . $log->id() . ')',
      'start[date]' => date('Y-m-d', $timestamp),
      'start[time]' => date('H:i:s', $timestamp),
      'duration' => 7 * 24,
      'recovery' => 15 * 24,
    ];
    $this->submitForm($edit, 'Save');

    // Confirm that the status message is shown.
    $this->assertSession()->pageTextContains('Added Grazing event: ' . $log->label() . ' - ' . $this->plan->label());

    // Confirm the grazing event plan record was created with the expected
    // values.
    $expected_plan_record_count++;
    $plan_records = $plan_record_storage->loadMultiple();
    $this->assertCount($expected_plan_record_count, $plan_records);
    $plan_record = end($plan_records);
    $this->assertEquals($this->plan->id(), $plan_record->get('plan')->target_id);
    $this->assertEquals($log->id(), $plan_record->get('log')->target_id);
    $this->assertEquals($timestamp, $plan_record->get('start')->value);
    $this->assertEquals(7 * 24, $plan_record->get('duration')->value);
    $this->assertEquals(15 * 24, $plan_record->get('recovery')->value);

    // Submit the same log a second time and confirm the duplicate check works.
    $this->drupalGet('/plan/' . $this->plan->id() . '/grazing/event');
    $this->submitForm($edit, 'Save');
    $this->assertSession()->pageTextContains('This log is already part of a grazing plan.');
    $this->assertCount($expected_plan_record_count, $plan_record_storage->loadMultiple());

    // Create a second plan and confirm that the log cannot be added to that
    // plan either.
    $plan2 = Plan::create([
      'name' => $this->randomMachineName(),
      'type' => 'grazing',
    ]);
    $plan2->save();
    $this->drupalGet('/plan/' . $plan2->id() . '/grazing/event');
    $this->submitForm($edit, 'Save');
    $this->assertSession()->pageTextContains('This log is already part of a grazing plan.');
    $this->assertCount($expected_plan_record_count, $plan_record_storage->loadMultiple());

    // Get a timestamp for the next grazing event.
    $timestamp = $this->nextGrazingEventTimestamp();

    // Create a non-movement log and confirm that it cannot be added.
    $non_movement_log = Log::create([
      'name' => $this->randomMachineName(),
      'type' => 'activity',
      'timestamp' => $timestamp,
      'asset' => [
        ['target_id' => $this->animalAssets[0]->id()],
      ],
      'location' => [
        ['target_id' => $this->landAssets[0]->id()],
      ],
      'status' => 'done',
      'is_movement' => FALSE,
    ]);
    $non_movement_log->save();
    $this->drupalGet('/plan/' . $this->plan->id() . '/grazing/event');
    $this->submitForm([
      'log' => $non_movement_log->label() . ' (' . $non_movement_log->id() . ')',
      'start[date]' => date('Y-m-d', $timestamp),
      'start[time]' => date('H:i:s', $timestamp),
      'duration' => 7 * 24,
      'recovery' => 15 * 24,
    ], 'Save');
    $this->assertSession()->pageTextContains('Only movement logs can be added to a grazing plan.');
    $this->assertCount($expected_plan_record_count, $plan_record_storage->loadMultiple());

    // Create a log without an asset and confirm that it cannot be added.
    $no_asset_log = Log::create([
      'name' => $this->randomMachineName(),
      'type' => 'activity',
      'timestamp' => \Drupal::time()->getRequestTime(),
      'location' => [
        ['target_id' => $this->landAssets[0]->id()],
      ],
      'status' => 'done',
      'is_movement' => TRUE,
    ]);
    $no_asset_log->save();
    $this->drupalGet('/plan/' . $this->plan->id() . '/grazing/event');
    $this->submitForm([
      'log' => $no_asset_log->label() . ' (' . $no_asset_log->id() . ')',
      'start[date]' => date('Y-m-d', $timestamp),
      'start[time]' => date('H:i:s', $timestamp),
      'duration' => 7 * 24,
      'recovery' => 15 * 24,
    ], 'Save');
    $this->assertSession()->pageTextContains('This log does not reference an asset. A grazing event must move one asset.');
    $this->assertCount($expected_plan_record_count, $plan_record_storage->loadMultiple());

    // Create a log without a location and confirm that it cannot be added.
    $no_location_log = Log::create([
      'name' => $this->randomMachineName(),
      'type' => 'activity',
      'timestamp' => \Drupal::time()->getRequestTime(),
      'asset' => [
        ['target_id' => $this->animalAssets[0]->id()],
      ],
      'status' => 'done',
      'is_movement' => TRUE,
    ]);
    $no_location_log->save();
    $this->drupalGet('/plan/' . $this->plan->id() . '/grazing/event');
    $this->submitForm([
      'log' => $no_location_log->label() . ' (' . $no_location_log->id() . ')',
      'start[date]' => date('Y-m-d', $timestamp),
      'start[time]' => date('H:i:s', $timestamp),
      'duration' => 7 * 24,
      'recovery' => 15 * 24,
    ], 'Save');
    $this->assertSession()->pageTextContains('This log does not reference a location. A grazing event must move an asset to a location.');
    $this->assertCount($expected_plan_record_count, $plan_record_storage->loadMultiple());

    // Create a multi-asset log and confirm that it cannot be added.
    $multi_asset_log = Log::create([
      'name' => $this->randomMachineName(),
      'type' => 'activity',
      'timestamp' => \Drupal::time()->getRequestTime(),
      'asset' => [
        ['target_id' => $this->animalAssets[0]->id()],
        ['target_id' => $this->animalAssets[1]->id()],
      ],
      'location' => [
        ['target_id' => $this->landAssets[0]->id()],
      ],
      'status' => 'done',
      'is_movement' => TRUE,
    ]);
    $multi_asset_log->save();
    $this->drupalGet('/plan/' . $this->plan->id() . '/grazing/event');
    $this->submitForm([
      'log' => $multi_asset_log->label() . ' (' . $multi_asset_log->id() . ')',
      'start[date]' => date('Y-m-d', $timestamp),
      'start[time]' => date('H:i:s', $timestamp),
      'duration' => 7 * 24,
      'recovery' => 15 * 24,
    ], 'Save');
    $this->assertSession()->pageTextContains('This log references multiple assets. A grazing event can only move one asset.');
    $this->assertSession()->pageTextContains('Tip: The Group asset type can be used');
    $this->assertCount($expected_plan_record_count, $plan_record_storage->loadMultiple());

    // Create a multi-location log and confirm that it cannot be added.
    $multi_location_log = Log::create([
      'name' => $this->randomMachineName(),
      'type' => 'activity',
      'timestamp' => \Drupal::time()->getRequestTime(),
      'asset' => [
        ['target_id' => $this->animalAssets[0]->id()],
      ],
      'location' => [
        ['target_id' => $this->landAssets[0]->id()],
        ['target_id' => $this->landAssets[1]->id()],
      ],
      'status' => 'done',
      'is_movement' => TRUE,
    ]);
    $multi_location_log->save();
    $this->drupalGet('/plan/' . $this->plan->id() . '/grazing/event');
    $this->submitForm([
      'log' => $multi_location_log->label() . ' (' . $multi_location_log->id() . ')',
      'start[date]' => date('Y-m-d', $timestamp),
      'start[time]' => date('H:i:s', $timestamp),
      'duration' => 7 * 24,
      'recovery' => 15 * 24,
    ], 'Save');
    $this->assertSession()->pageTextContains('This log references multiple locations. A grazing event can only move an asset to a single location.');
    $this->assertCount($expected_plan_record_count, $plan_record_storage->loadMultiple());
  }

}
