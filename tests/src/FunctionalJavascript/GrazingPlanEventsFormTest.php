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

    // Confirm the expected fields are present for each asset and each of its
    // grazing events.
    foreach ($grazing_events_by_asset as $asset_id => $grazing_events) {
      foreach ($grazing_events as $grazing_event_id => $grazing_event) {
        $status = $grazing_event->getLog()->get('status')->value;
        if ($status != 'pending') {
          continue;
        }
        $prefix = 'grazing_events[' . $asset_id . '][' . $status . '][' . $grazing_event_id . ']';
        $this->assertSession()->fieldExists($prefix . '[planned_duration]');
        $this->assertSession()->fieldExists($prefix . '[planned_recovery]');
      }
    }

    // Confirm at least one submit button exists.
    $this->assertSession()->buttonExists('Save events');

    // Record the original values so that we can compare them later.
    $original = [];
    foreach ($grazing_events_by_asset as $asset_events) {
      foreach ($asset_events as $grazing_event) {
        $original[$grazing_event->id()] = [
          'start' => $grazing_event->get('start')->value,
          'duration' => $grazing_event->get('duration')->value,
          'recovery' => $grazing_event->get('recovery')->value,
          'timestamp' => $grazing_event->getLog()->get('timestamp')->value,
          'location' => $grazing_event->getLog()->get('location')->target_id,
        ];
      }
    }

    // Double the planned duration and recovery times. The pending starts are
    // not editable, so they are left as is and recomputed on submit. The
    // locations are also not editable, and are not updated on submit.
    foreach ($grazing_events_by_asset as $asset_id => $grazing_events) {

      // Click the vertical tab.
      $asset = \Drupal::entityTypeManager()->getStorage('asset')->load($asset_id);
      $this->getSession()->getPage()->clickLink($asset->label() . ' Grazing Events');

      // Iterate through the grazing event rows.
      foreach ($grazing_events as $grazing_event) {
        $status = $grazing_event->getLog()->get('status')->value;
        if ($status != 'pending') {
          continue;
        }
        $prefix = 'grazing_events[' . $asset_id . '][' . $status . '][' . $grazing_event->id() . ']';

        // Double the planned duration and recovery times. The form fields
        // use days, so convert the entity values (in hours) to days.
        $this->getSession()->getPage()->fillField($prefix . '[planned_duration]', (string) ($grazing_event->get('duration')->value / 24 * 2));
        $this->getSession()->getPage()->fillField($prefix . '[planned_recovery]', (string) ($grazing_event->get('recovery')->value / 24 * 2));
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
        $status = $grazing_event->getLog()->get('status')->value;
        if ($status != 'pending') {
          continue;
        }

        // The pending start dates are not editable, so they are recomputed on
        // submit. Each asset's first pending event is anchored to the end of
        // its last completed event, which for this test data matches the
        // original start date. The duration and recovery are doubled.
        $updated_grazing_event = $plan_record_storage->load($grazing_event->id());
        $this->assertEquals($original[$grazing_event->id()]['start'], $updated_grazing_event->get('start')->value);
        $this->assertEquals($original[$grazing_event->id()]['duration'] * 2, $updated_grazing_event->get('duration')->value);
        $this->assertEquals($original[$grazing_event->id()]['recovery'] * 2, $updated_grazing_event->get('recovery')->value);

        // The log should have the original timestamp and the original location,
        // which is not updated since the location is not editable.
        $log = $log_storage->load($grazing_event->get('log')->target_id);
        $this->assertEquals($original[$grazing_event->id()]['timestamp'], $log->get('timestamp')->value);
        $this->assertEquals($original[$grazing_event->id()]['location'], $log->get('location')->target_id);
      }
    }

    // Reload the page to test the "Add event" button.
    $this->drupalGet('/plan/' . $this->plan->id());

    // Reload the grazing events, now that they have been updated.
    $grazing_events_by_asset = \Drupal::service('farm_grazing_plan')->getGrazingEventsByAsset($this->plan);

    // Get the asset in the first vertical tab and its most recent grazing
    // event.
    $first_asset_id = array_key_first($grazing_events_by_asset);
    $asset = \Drupal::entityTypeManager()->getStorage('asset')->load($first_asset_id);
    $grazing_events = array_values($grazing_events_by_asset[$first_asset_id]);
    $last_grazing_event = end($grazing_events);

    // The new row should be pre-filled based on the most recent grazing event:
    // the pending start defaults to the most recent log timestamp plus the
    // duration, and the duration and recovery default to the most recent
    // grazing event's values, converted from hours to days.
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
    // The duration and recovery fields are in days, so convert the expected
    // values (in hours) to days.
    $this->assertSession()->fieldValueEquals($prefix . '[location]', '');
    $this->assertSession()->fieldValueEquals($prefix . '[planned_duration]', (string) ($expected_duration / 24));
    $this->assertSession()->fieldValueEquals($prefix . '[planned_recovery]', (string) ($expected_recovery / 24));

    // Confirm the pending event is still present in the re-rendered pending
    // table, and that only one new row was added.
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

    // Reload the page to test reordering the pending grazing events.
    $this->drupalGet('/plan/' . $this->plan->id());

    // Get the pending and done grazing events for the first asset. The first
    // asset should now have two pending grazing events: the original one and
    // the one added above.
    $grazing_events_by_asset = \Drupal::service('farm_grazing_plan')->getGrazingEventsByAsset($this->plan);
    $grazing_events = $grazing_events_by_asset[$first_asset_id];
    $pending_events = array_filter($grazing_events, fn ($event) => $event->getLog()->get('status')->value !== 'done');
    $this->assertCount(2, $pending_events);
    $first_pending = reset($pending_events);
    $last_pending = end($pending_events);
    $done_events = array_filter($grazing_events, fn ($event) => $event->getLog()->get('status')->value === 'done');
    $last_done = end($done_events);

    // The recomputed start dates will be anchored to the end of the last done
    // grazing event.
    $anchor_start = $last_done->getLog()->get('timestamp')->value + $last_done->get('duration')->value * 60 * 60;

    // Open the first asset's vertical tab.
    $asset = \Drupal::entityTypeManager()->getStorage('asset')->load($first_asset_id);
    $this->getSession()->getPage()->clickLink($asset->label() . ' Grazing Events');

    // Drag the last pending row onto the first pending row, so the pending
    // grazing events are reordered.
    $rows = $this->getSession()->getPage()->findAll('css', '#edit-grazing-events-' . $first_asset_id . '-pending tr.draggable');
    $this->assertCount(2, $rows);
    $source_handle = $rows[1]->find('css', 'a.tabledrag-handle');
    $target_handle = $rows[0]->find('css', 'a.tabledrag-handle');
    $this->assertNotNull($source_handle);
    $this->assertNotNull($target_handle);
    $source_handle->dragTo($target_handle);

    // Confirm that the weights were updated by the drag, so that the dragged
    // row now comes first. For weight select fields, the tabledrag JavaScript
    // assigns the select's option values in order, so only the relative
    // order of the weights is asserted.
    $weight_last_pending = $this->getSession()->getPage()->findField('grazing_events[' . $first_asset_id . '][pending][' . $last_pending->id() . '][weight]')->getValue();
    $weight_first_pending = $this->getSession()->getPage()->findField('grazing_events[' . $first_asset_id . '][pending][' . $first_pending->id() . '][weight]')->getValue();
    $this->assertLessThan($weight_first_pending, $weight_last_pending);

    // Save the events.
    $this->getSession()->getPage()->pressButton('Save events');
    $this->assertTrue($this->assertSession()->waitForText('Updated the grazing events.', 30000));

    // Confirm that the start dates of the pending grazing events and their
    // logs were recomputed based on the new order. The first pending event
    // starts at the anchor, and the second pending event starts at the end of
    // the first pending event.
    $this->drupalGet('/plan/' . $this->plan->id());
    $updated_last_pending = $plan_record_storage->load($last_pending->id());
    $this->assertEquals($anchor_start, $updated_last_pending->get('start')->value);
    $log = $log_storage->load($updated_last_pending->get('log')->target_id);
    $this->assertEquals($anchor_start, $log->get('timestamp')->value);
    $updated_first_pending = $plan_record_storage->load($first_pending->id());
    $this->assertEquals($anchor_start + $last_pending->get('duration')->value * 60 * 60, $updated_first_pending->get('start')->value);
  }

}
