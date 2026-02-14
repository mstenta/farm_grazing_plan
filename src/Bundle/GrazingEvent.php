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
    return $this->get('log')->first()?->entity;
  }

}
