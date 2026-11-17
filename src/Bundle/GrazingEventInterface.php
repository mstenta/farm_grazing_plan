<<<<<<< HEAD
<?php

declare(strict_types=1);

namespace Drupal\farm_grazing_plan\Bundle;

use Drupal\log\Entity\LogInterface;
use Drupal\plan\Entity\PlanRecordInterface;

/**
 * Bundle logic for Grazing Event.
 */
interface GrazingEventInterface extends PlanRecordInterface {

  /**
   * Returns the grazing event's movement log.
   *
   * @return \Drupal\log\Entity\LogInterface|null
   *   The log entity or NULL if not assigned.
   */
  public function getLog(): ?LogInterface;

}
=======
<?php

declare(strict_types=1);

namespace Drupal\farm_grazing_plan\Bundle;

use Drupal\asset\Entity\AssetInterface;
use Drupal\log\Entity\LogInterface;
use Drupal\plan\Entity\PlanRecordInterface;

/**
 * Bundle logic for Grazing Event.
 */
interface GrazingEventInterface extends PlanRecordInterface {

  /**
   * Returns the grazing event's movement log.
   *
   * @return \Drupal\log\Entity\LogInterface
   *   The log entity.
   */
  public function getLog(): LogInterface;

  /**
   * Returns the grazing event movement log's asset.
   *
   * @return \Drupal\asset\Entity\AssetInterface
   *   The asset entity.
   */
  public function getAsset(): AssetInterface;

  /**
   * Returns the grazing event movement log's location asset.
   *
   * @return \Drupal\asset\Entity\AssetInterface
   *   The location asset entity.
   */
  public function getLocation(): AssetInterface;

  /**
   * Returns the grazing event movement log's status.
   *
   * @return string
   *   The log status.
   */
  public function getStatus(): string;

}
>>>>>>> 57fd3d48daf889bb7c23dc75f758b74a8b86f73e
