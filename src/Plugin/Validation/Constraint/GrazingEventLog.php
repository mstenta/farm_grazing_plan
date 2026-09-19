<?php

declare(strict_types=1);

namespace Drupal\farm_grazing_plan\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Validates the movement log associated with a grazing event.
 */
#[Constraint(
  id: 'GrazingEventLog',
  label: new TranslatableMarkup('Validate grazing event log.', ['context' => 'Validation']),
)]
class GrazingEventLog extends SymfonyConstraint {

  /**
   * The violation message for when the referenced log does not exist.
   *
   * @var string
   */
  public string $missingLogMessage = 'The referenced log does not exist.';

  /**
   * The violation message for when the log is not a movement.
   *
   * @var string
   */
  public string $nonMovementMessage = 'Only movement logs can be added to a grazing plan.';

  /**
   * The violation message for when the log is already part of a grazing plan.
   *
   * @var string
   */
  public string $existingGrazingEventMessage = 'This log is already part of a grazing plan.';

  /**
   * The violation message for when the log does not reference an asset.
   *
   * @var string
   */
  public string $noAssetMessage = 'This log does not reference an asset. A grazing event must move one asset.';

  /**
   * The violation message for when the log references multiple assets.
   *
   * @var string
   */
  public string $multipleAssetsMessage = 'This log references multiple assets. A grazing event can only move one asset.';

  /**
   * The violation message for when the log does not reference a location.
   *
   * @var string
   */
  public string $noLocationMessage = 'This log does not reference a location. A grazing event must move an asset to a location.';

  /**
   * The violation message for when the log references multiple locations.
   *
   * @var string
   */
  public string $multipleLocationsMessage = 'This log references multiple locations. A grazing event can only move an asset to a single location.';

}
