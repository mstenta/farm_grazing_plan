<<<<<<< HEAD
<?php

declare(strict_types=1);

namespace Drupal\farm_grazing_plan\Bundle;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\log\Entity\LogInterface;
use Drupal\plan\Entity\PlanRecord;

/**
 * Bundle logic for Grazing Event.
 */
class GrazingEvent extends PlanRecord implements GrazingEventInterface {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function label() {

    // Build label with the referenced plan and log.
    if ($plan = $this->getPlan()) {
      if ($log = $this->getLog()) {
        return $this->t('Grazing event: %log - %plan', ['%log' => $log->label(), '%plan' => $plan->label()]);
      }

      // Use the plan if no plant reference.
      return $this->t('Grazing event - %plan', ['%plan' => $plan->label()]);
    }

    // Fallback to default.
    return parent::label();
  }

  /**
   * {@inheritdoc}
   */
  public function getLog(): ?LogInterface {
    if ($this->get('log')->isEmpty()) {
      return NULL;
    }
    return $this->get('log')->referencedEntities()[0];
  }

}
=======
<?php

declare(strict_types=1);

namespace Drupal\farm_grazing_plan\Bundle;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\asset\Entity\AssetInterface;
use Drupal\log\Entity\LogInterface;
use Drupal\plan\Entity\PlanRecord;

/**
 * Bundle logic for Grazing Event.
 */
class GrazingEvent extends PlanRecord implements GrazingEventInterface {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function label() {
    return $this->t('@start: @asset -> @location (@plan)', [
      '@start' => date('Y-m-d', (int) $this->get('start')->value),
      '@asset' => $this->getAsset()->label(),
      '@location' => $this->getLocation()->label(),
      '@plan' => $this->getPlan()->label(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getLog(): LogInterface {
    return $this->get('log')->referencedEntities()[0];
  }

  /**
   * {@inheritdoc}
   */
  public function getAsset(): AssetInterface {
    return $this->getLog()->get('asset')->referencedEntities()[0];
  }

  /**
   * {@inheritdoc}
   */
  public function getLocation(): AssetInterface {
    return $this->getLog()->get('location')->referencedEntities()[0];
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus(): string {
    return $this->getLog()->get('status')->value;
  }

}
>>>>>>> 57fd3d48daf889bb7c23dc75f758b74a8b86f73e
