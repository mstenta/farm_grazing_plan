<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_grazing_plan\Functional;

use Drupal\Tests\farm_grazing_plan\Traits\MockGrazingPlanEntitiesTrait;
use Drupal\Tests\farm_test\Functional\FarmBrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the grazing plan form.
 */
#[Group('farm_grazing_plan')]
#[RunTestsInSeparateProcesses]
class GrazingPlanEventsFormTest extends FarmBrowserTestBase {

  use MockGrazingPlanEntitiesTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'farm_grazing_plan',
    'farm_land',
  ];

  /**
   * Test the grazing plan form.
   */
  public function testGrazingPlanEventsForm() {

    // Create mock plan entities.
    $this->createMockPlanEntities();

    // Attempt to load the grazing plan form and confirm that access is denied.
    $this->drupalGet('/plan/' . $this->plan->id());
    $this->assertSession()->statusCodeEquals(403);

    // Create and log in a user with access to view grazing plans.
    $permissions = ['view any grazing plan'];
    $user = $this->drupalCreateUser($permissions);
    $this->drupalLogin($user);

    // Load the grazing plan and confirm the user has access.
    $this->drupalGet('/plan/' . $this->plan->id());
    $this->assertSession()->statusCodeEquals(200);

    // Confirm that the grazing plan form is inaccessible.
    $this->assertSession()->pageTextNotContains('Sheep 1 Grazing Events');

    // Create and log in a user with access to update grazing plans.
    $permissions[] = 'update any grazing plan';
    // The form checks access('view') on each asset, so the user must also be
    // able to view the animal assets.
    $permissions[] = 'view any animal asset';
    // Also grant permissions necessary for the location entity reference
    // fields.
    $permissions[] = 'access asset collection';
    $permissions[] = 'view any land asset';
    $user = $this->drupalCreateUser($permissions);
    $this->drupalLogin($user);

    // Load the grazing plan and confirm the user has access to the form.
    $this->drupalGet('/plan/' . $this->plan->id());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Sheep 1 Grazing Events');

    // Get the plan's grazing events grouped by asset.
    $grazing_events_by_asset = \Drupal::service('farm_grazing_plan')->getGrazingEventsByAsset($this->plan);

    // Confirm the expected fields are visible for each asset and each of its
    // grazing events.
    foreach ($grazing_events_by_asset as $asset_id => $grazing_events) {
      foreach (array_keys($grazing_events) as $grazing_event_id) {
        $prefix = 'grazing_events[' . $asset_id . '][values][' . $grazing_event_id . ']';
        $this->assertSession()->fieldExists($prefix . '[location]');
        $this->assertSession()->fieldExists($prefix . '[planned_start][date]');
        $this->assertSession()->fieldExists($prefix . '[planned_start][time]');
        $this->assertSession()->fieldExists($prefix . '[actual_start][date]');
        $this->assertSession()->fieldExists($prefix . '[actual_start][time]');
        $this->assertSession()->fieldExists($prefix . '[planned_duration]');
        $this->assertSession()->fieldExists($prefix . '[planned_recovery]');
      }
    }

    // Confirm at least one submit button exists.
    $this->assertSession()->buttonExists('Update grazing events');

    // Record the original values so that we can compare them later.
    $original = [];
    foreach ($grazing_events_by_asset as $asset_events) {
      $grazing_events = array_values($asset_events);
      foreach ($grazing_events as $index => $grazing_event) {
        $original[$grazing_event->id()] = [
          'start' => $grazing_event->get('start')->value,
          'duration' => $grazing_event->get('duration')->value,
          'recovery' => $grazing_event->get('recovery')->value,
          'timestamp' => $grazing_event->getLog()->get('timestamp')->value,
          'location' => $grazing_events[count($grazing_events) - 1 - $index]->getLog()->get('location')->target_id,
        ];
      }
    }

    // Build a set of form values to submit. Reverse the order of locations,
    // shift the planned/actual start date/times ahead 25 hours, and double the
    // planned duration and recovery times.
    $edit = [];
    foreach ($grazing_events_by_asset as $asset_id => $grazing_events) {
      $events = array_values($grazing_events);
      foreach ($events as $index => $grazing_event) {
        $prefix = 'grazing_events[' . $asset_id . '][values][' . $grazing_event->id() . ']';

        // Reverse the order of locations.
        $location = $events[count($events) - 1 - $index]->getLog()->get('location')->referencedEntities()[0];
        $edit[$prefix . '[location]'] = $location->label() . ' (' . $location->id() . ')';

        // Shift the planned/actual start date/times ahead 25 hours.
        $planned_start = $grazing_event->get('start')->value + 25 * 60 * 60;
        $actual_start = $grazing_event->getLog()->get('timestamp')->value + 25 * 60 * 60;
        $edit[$prefix . '[planned_start][date]'] = date('Y-m-d', $planned_start);
        $edit[$prefix . '[planned_start][time]'] = date('H:i:s', $planned_start);
        $edit[$prefix . '[actual_start][date]'] = date('Y-m-d', $actual_start);
        $edit[$prefix . '[actual_start][time]'] = date('H:i:s', $actual_start);

        // Double the planned duration and recovery times.
        $edit[$prefix . '[planned_duration]'] = $grazing_event->get('duration')->value * 2;
        $edit[$prefix . '[planned_recovery]'] = $grazing_event->get('recovery')->value * 2;
      }
    }

    // Submit the form.
    $this->submitForm($edit, 'Update grazing events');

    // Confirm that the status message is shown.
    $this->assertSession()->pageTextContains('Updated the grazing events.');

    // Confirm the grazing events and logs are updated with the expected
    // values.
    $plan_record_storage = \Drupal::entityTypeManager()->getStorage('plan_record');
    $log_storage = \Drupal::entityTypeManager()->getStorage('log');
    foreach ($grazing_events_by_asset as $grazing_events) {
      foreach ($grazing_events as $grazing_event) {

        // The grazing event should have the shifted start date/time and the
        // doubled duration and recovery.
        $updated_grazing_event = $plan_record_storage->load($grazing_event->id());
        $this->assertEquals($original[$grazing_event->id()]['start'] + 25 * 60 * 60, $updated_grazing_event->get('start')->value);
        $this->assertEquals($original[$grazing_event->id()]['duration'] * 2, $updated_grazing_event->get('duration')->value);
        $this->assertEquals($original[$grazing_event->id()]['recovery'] * 2, $updated_grazing_event->get('recovery')->value);

        // The log should have the shifted timestamp and the reversed location.
        $log = $log_storage->load($grazing_event->get('log')->target_id);
        $this->assertEquals($original[$grazing_event->id()]['timestamp'] + 25 * 60 * 60, $log->get('timestamp')->value);
        $this->assertEquals($original[$grazing_event->id()]['location'], $log->get('location')->target_id);
      }
    }
  }

}
