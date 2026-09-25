<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_grazing_plan\FunctionalJavascript;

use Drupal\Tests\farm_grazing_plan\Traits\MockGrazingPlanEntitiesTrait;
use Drupal\Tests\farm_test\FunctionalJavascript\FarmWebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the grazing plan form.
 */
#[Group('farm_grazing_plan')]
#[RunTestsInSeparateProcesses]
class GrazingPlanEventsFormTest extends FarmWebDriverTestBase {

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
    $this->assertSession()->pageTextContains('You are not authorized to access this page.');
    $this->assertSession()->pageTextNotContains('Grazing events (by Asset)');

    // Create and log in a user with access to view grazing plans.
    $permissions = ['view any grazing plan'];
    $user = $this->drupalCreateUser($permissions);
    $this->drupalLogin($user);

    // Load the grazing plan and confirm the user has access.
    $this->drupalGet('/plan/' . $this->plan->id());
    $this->assertSession()->pageTextNotContains('You are not authorized to access this page.');
    $this->assertSession()->pageTextContains('Grazing events (by Asset)');

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
    $this->assertSession()->pageTextContains('Sheep 1 Grazing Events');

    // Get the plan's grazing events grouped by asset.
    $grazing_events_by_asset = \Drupal::service('farm_grazing_plan')->getGrazingEventsByAsset($this->plan);

    // Confirm the expected fields are visible for each asset and each of its
    // grazing events.
    foreach ($grazing_events_by_asset as $asset_id => $grazing_events) {
      foreach ($grazing_events as $grazing_event_id => $grazing_event) {
        $status = $grazing_event->getLog()->get('status')->value;
        $prefix = 'grazing_events[' . $asset_id . '][' . $status . '][' . $grazing_event_id . ']';
        $this->assertSession()->fieldExists($prefix . '[location]');
        $this->assertSession()->fieldExists($prefix . '[planned_start]');
        $this->assertSession()->fieldExists($prefix . '[actual_start]');
        $this->assertSession()->fieldExists($prefix . '[planned_duration]');
        $this->assertSession()->fieldExists($prefix . '[planned_recovery]');
      }
    }

    // Confirm at least one submit button exists.
    $this->assertSession()->buttonExists('Save events');

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

    // Reverse the order of locations, shift the planned/actual starts ahead
    // 25 hours, and double the planned duration and recovery times.
    foreach ($grazing_events_by_asset as $asset_id => $grazing_events) {

      // Click the vertical tab.
      $asset = \Drupal::entityTypeManager()->getStorage('asset')->load($asset_id);
      $this->getSession()->getPage()->clickLink($asset->label() . ' Grazing Events');

      // Iterate through the grazing event rows.
      $events = array_values($grazing_events);
      foreach ($events as $index => $grazing_event) {
        $status = $grazing_event->getLog()->get('status')->value;
        $prefix = 'grazing_events[' . $asset_id . '][' . $status . '][' . $grazing_event->id() . ']';

        // Reverse the order of locations.
        $location = $events[count($events) - 1 - $index]->getLog()->get('location')->referencedEntities()[0];
        $this->getSession()->getPage()->fillField($prefix . '[location]', $location->label() . ' (' . $location->id() . ')');

        // Shift the planned/actual starts ahead 25 hours.
        $this->getSession()->getPage()->fillField($prefix . '[planned_start]', (string) ($grazing_event->get('start')->value + 25 * 60 * 60));
        $this->getSession()->getPage()->fillField($prefix . '[actual_start]', (string) ($grazing_event->getLog()->get('timestamp')->value + 25 * 60 * 60));

        // Double the planned duration and recovery times.
        $this->getSession()->getPage()->fillField($prefix . '[planned_duration]', (string) ($grazing_event->get('duration')->value * 2));
        $this->getSession()->getPage()->fillField($prefix . '[planned_recovery]', (string) ($grazing_event->get('recovery')->value * 2));
      }
    }

    // Click on the first asset's vertical tab and press the submit button.
    $asset = \Drupal::entityTypeManager()->getStorage('asset')->load(array_key_first($grazing_events_by_asset));
    $this->getSession()->getPage()->clickLink($asset->label() . ' Grazing Events');
    $this->getSession()->getPage()->pressButton('Save events');

    // Confirm that the status message is shown.
    $this->assertTrue($this->assertSession()->waitForText('Updated the grazing events.', 30000));

    // Reload the page to ensure entities are refreshed.
    $this->drupalGet('/plan/' . $this->plan->id());

    // Confirm the grazing events and logs are updated with the expected
    // values.
    $plan_record_storage = \Drupal::entityTypeManager()->getStorage('plan_record');
    $log_storage = \Drupal::entityTypeManager()->getStorage('log');
    foreach ($grazing_events_by_asset as $grazing_events) {
      foreach ($grazing_events as $grazing_event) {

        // The grazing event should have the shifted starts and the doubled
        // duration and recovery.
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

    // Reload the page to test the "Add event" button.
    $this->drupalGet('/plan/' . $this->plan->id());

    // Reload the grazing events, now that they have been updated.
    $grazing_events_by_asset = \Drupal::service('farm_grazing_plan')->getGrazingEventsByAsset($this->plan);

    // Get the asset in the first vertical tab and its first and last grazing
    // events.
    $first_asset_id = array_key_first($grazing_events_by_asset);
    $asset = \Drupal::entityTypeManager()->getStorage('asset')->load($first_asset_id);
    $grazing_events = array_values($grazing_events_by_asset[$first_asset_id]);
    $first_grazing_event = reset($grazing_events);
    $last_grazing_event = end($grazing_events);

    // The new row should be pre-filled based on the most recent grazing event:
    // the planned and actual starts default to the most recent log timestamp
    // plus the duration, and the duration and recovery default to the most
    // recent grazing event's values.
    $expected_start = $last_grazing_event->getLog()->get('timestamp')->value + $last_grazing_event->get('duration')->value * 60 * 60;
    $expected_duration = $last_grazing_event->get('duration')->value;
    $expected_recovery = $last_grazing_event->get('recovery')->value;

    // Click the "Add event" button in the first tab (by name, since
    // there is one per asset).
    $this->getSession()->getPage()->clickLink($asset->label() . ' Grazing Events');
    $button = $this->getSession()->getPage()->find('xpath', "//input[@name='add_grazing_event_{$first_asset_id}']");
    $this->assertNotNull($button);
    $button->press();

    // Wait for Ajax.
    $prefix = 'grazing_events[' . $first_asset_id . '][pending][new_1]';
    $this->assertNotNull($this->assertSession()->waitForField($prefix . '[location]', 30000));

    // Confirm the new row is rendered with the expected pre-filled values.
    $this->assertSession()->fieldValueEquals($prefix . '[location]', '');
    $this->assertSession()->fieldValueEquals($prefix . '[planned_start]', (string) $expected_start);
    $this->assertSession()->fieldValueEquals($prefix . '[actual_start]', (string) $expected_start);
    $this->assertSession()->fieldValueEquals($prefix . '[planned_duration]', (string) $expected_duration);
    $this->assertSession()->fieldValueEquals($prefix . '[planned_recovery]', (string) $expected_recovery);

    // Confirm the existing rows are still present, and that only one new row
    // was added. The pending event should still be present in the re-rendered
    // pending table.
    $this->assertSession()->fieldExists('grazing_events[' . $first_asset_id . '][done][' . $first_grazing_event->id() . '][planned_duration]');
    $this->assertSession()->fieldExists('grazing_events[' . $first_asset_id . '][pending][' . $last_grazing_event->id() . '][planned_duration]');
    $this->assertSession()->fieldNotExists('grazing_events[' . $first_asset_id . '][pending][new_2][location]');

    // Set the location of the new grazing event.
    $location = $this->landAssets[0];
    $this->getSession()->getPage()->fillField($prefix . '[location]', $location->label() . ' (' . $location->id() . ')');

    // Count log and plan_record entities.
    $expected_log_count = count($log_storage->loadMultiple());
    $expected_plan_record_count = count($plan_record_storage->loadMultiple());

    // Submit the form.
    $this->getSession()->getPage()->pressButton('Save events');
    $this->assertTrue($this->assertSession()->waitForText('Updated the grazing events.', 30000));

    // Confirm that a new movement log was created with the expected values.
    $expected_log_count++;
    $logs = $log_storage->loadMultiple();
    $this->assertCount($expected_log_count, $logs);
    $log_ids = $log_storage->getQuery()
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();
    $log = $log_storage->load(reset($log_ids));
    $this->assertEquals('activity', $log->bundle());
    $this->assertEquals('Move ' . $asset->label() . ' to ' . $location->label(), $log->label());
    $this->assertEquals($expected_start, $log->get('timestamp')->value);
    $this->assertEquals($first_asset_id, $log->get('asset')->target_id);
    $this->assertEquals($location->id(), $log->get('location')->target_id);
    $this->assertTrue((bool) $log->get('is_movement')->value);
    $this->assertEquals('pending', $log->get('status')->value);

    // Confirm that a new grazing event plan record was created with the
    // expected values.
    $expected_plan_record_count++;
    $plan_records = $plan_record_storage->loadMultiple();
    $this->assertCount($expected_plan_record_count, $plan_records);
    $plan_record_ids = $plan_record_storage->getQuery()
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();
    $plan_record = $plan_record_storage->load(reset($plan_record_ids));
    $this->assertEquals('grazing_event', $plan_record->bundle());
    $this->assertEquals($this->plan->id(), $plan_record->get('plan')->target_id);
    $this->assertEquals($log->id(), $plan_record->get('log')->target_id);
    $this->assertEquals($expected_start, $plan_record->get('start')->value);
    $this->assertEquals($expected_duration, $plan_record->get('duration')->value);
    $this->assertEquals($expected_recovery, $plan_record->get('recovery')->value);
  }

}
