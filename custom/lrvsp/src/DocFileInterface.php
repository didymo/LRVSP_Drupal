<?php

declare(strict_types=1);

namespace Drupal\lrvsp;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides an interface defining a docfile entity type.
 */
interface DocFileInterface extends ContentEntityInterface, EntityOwnerInterface, EntityChangedInterface {

}
