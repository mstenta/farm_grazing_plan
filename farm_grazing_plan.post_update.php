<?php

/**
 * @file
 * Post update hooks for mymodule.
 */

declare(strict_types=1);

/**
 * Add "My new field" to logs.
 */

 /**
  * Change name each time it is run. drush will not execute functions that have previously been executed through "drush updb".
  * https://drupal.stackexchange.com/questions/315086/hook-post-update-not-found
  */

/**
 * Removes field_MY_FIELD_NAME.
 */
function MYMODULE_update_8001() {
  /* @var $entityFieldManager Drupal\Core\Entity\EntityFieldManager */
  $entityFieldManager = Drupal::service('entity_field.manager');

  $fields = $entityFieldManager->getFieldDefinitions('logs', 'BUNDLE');

  if (isset($fields['field_planned'])) {
    $fields['field_planned']->delete();
  }
}
