<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_grazing_plan\Functional;

use Drupal\Tests\farm_grazing_plan\Traits\MockGrazingPlanEntitiesTrait;
use Drupal\Tests\farm_test\Functional\FarmBrowserTestBase;
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

    // Get plan_record storage.
    $plan_record_storage = \Drupal::entityTypeManager()->getStorage('plan_record');

    // Confirm the grazing event plan record was created with the expected
    // values.
    $plan_records = $plan_record_storage->loadMultiple();
    $this->assertCount(count($this->grazingEvents) + 1, $plan_records);
    $plan_record = end($plan_records);
    $this->assertEquals($this->plan->id(), $plan_record->get('plan')->target_id);
    $this->assertEquals($log->id(), $plan_record->get('log')->target_id);
    $this->assertEquals($timestamp, $plan_record->get('start')->value);
    $this->assertEquals(7 * 24, $plan_record->get('duration')->value);
    $this->assertEquals(15 * 24, $plan_record->get('recovery')->value);
    $this->grazingEvents[] = $plan_record;

    // Submit the same log a second time and confirm the duplicate check works.
    $this->drupalGet('/plan/' . $this->plan->id() . '/grazing/event');
    $this->submitForm($edit, 'Save');
    $this->assertSession()->pageTextContains('This log is already part of the plan.');
    $this->assertCount(1, $plan_record_storage->loadMultiple());
  }

}
