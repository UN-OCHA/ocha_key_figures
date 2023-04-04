<?php

namespace Drupal\ocha_key_figures\Event;

use Drupal\Component\EventDispatcher\Event;

/**
 * Event that is fired when a user logs in.
 */
class KeyFiguresUpdated extends Event {

  const EVENT_NAME = 'ocha_key_figures_updated';

  /**
   * Keyed array of entities.
   *
   * @var array
   */
  public $data;

  /**
   * Constructs the object.
   *
   * @param array $data
   *   Keyed array of entities grouped by type.
   */
  public function __construct(array $data) {
    $this->data = $data;
  }

}
