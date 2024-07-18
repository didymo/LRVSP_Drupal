<?php

declare(strict_types=1);

namespace Drupal\lrvsp\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\lrvsp\LinkInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the link entity class.
 *
 * @ContentEntityType(
 *   id = "lrvsp_link",
 *   label = @Translation("Link"),
 *   label_collection = @Translation("Links"),
 *   label_singular = @Translation("link"),
 *   label_plural = @Translation("links"),
 *   label_count = @PluralTranslation(
 *     singular = "@count links",
 *     plural = "@count links",
 *   ),
 *   handlers = {
 *     "list_builder" = "Drupal\lrvsp\LinkListBuilder",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "access" = "Drupal\lrvsp\LinkAccessControlHandler",
 *     "form" = {
 *       "add" = "Drupal\lrvsp\Form\LinkForm",
 *       "edit" = "Drupal\lrvsp\Form\LinkForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm",
 *       "delete-multiple-confirm" = "Drupal\Core\Entity\Form\DeleteMultipleForm",
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   base_table = "lrvsp_link",
 *   admin_permission = "administer lrvsp_link",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *     "owner" = "uid",
 *   },
 *   links = {
 *     "collection" = "/admin/content/link",
 *     "add-form" = "/link/add",
 *     "canonical" = "/link/{lrvsp_link}",
 *     "edit-form" = "/link/{lrvsp_link}/edit",
 *     "delete-form" = "/link/{lrvsp_link}/delete",
 *     "delete-multiple-form" = "/admin/content/link/delete-multiple",
 *   },
 *   field_ui_base_route = "entity.lrvsp_link.settings",
 * )
 */
final class Link extends ContentEntityBase implements LinkInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    // set label
    $fromDocId = $this->get('fromDoc')->getValue()[0]['target_id'];
    $fromDocTitle = Doc::load($fromDocId)->getTitle();
    $toDocId = $this->get('toDoc')->getValue()[0]['target_id'];
    $toDocTitle = Doc::load($toDocId)->getTitle();
    $this->set('label','LINK '.$fromDocTitle.' TO '.$toDocTitle);

    if (!$this->getOwnerId()) {
      // If no owner has been set explicitly, make the anonymous user the owner.
      $this->setOwnerId(0);
    }
  }

  /**
   *  {@inheritdoc}
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE) {
    parent::postSave($storage, $update);

    $fromDocId = $this->get('fromDoc')->getValue()[0]['target_id'];
    $nodeStorage = \Drupal::entityTypeManager()->getStorage('lrvsp_link');
    $linkIds = $nodeStorage->getQuery()
      ->condition('status', 1)
      ->condition('fromDoc', $fromDocId)
      ->accessCheck(FALSE)
      ->execute();
    $fromDocDoc = Doc::load($fromDocId);
    if (sizeof($linkIds) == $fromDocDoc->getNumLinks()){
      $fromDocDoc->setLinksProcessed();
    } elseif (sizeof($linkIds) > $fromDocDoc->getNumLinks()){
      $fromDocDoc->setLinksProcessed(); // set processed anyway
      \Drupal::logger('lrvsp')->error("To many links processed for document.\nExpected number: ".$fromDocDoc->getNumLinks()."\nActual number: ".sizeof($linkIds));
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {

    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Label'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Status'))
      ->setDefaultValue(TRUE)
      ->setSetting('on_label', 'Enabled')
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'settings' => [
          'display_label' => FALSE,
        ],
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'type' => 'boolean',
        'label' => 'above',
        'weight' => 0,
        'settings' => [
          'format' => 'enabled-disabled',
        ],
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['fromDoc'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('fromDoc'))
      ->setSetting('target_type', 'lrvsp_doc')
      ->setRequired(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => 60,
          'placeholder' => '',
        ],
        'weight' => 11,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'weight' => 11,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['toDoc'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('toDoc'))
      ->setSetting('target_type', 'lrvsp_doc')
      ->setRequired(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => 60,
          'placeholder' => '',
        ],
        'weight' => 11,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'weight' => 11,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Author'))
      ->setSetting('target_type', 'user')
      ->setDefaultValueCallback(self::class . '::getDefaultEntityOwner')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => 60,
          'placeholder' => '',
        ],
        'weight' => 15,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'author',
        'weight' => 15,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Authored on'))
      ->setDescription(t('The time that the link was created.'))
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'timestamp',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'datetime_timestamp',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time that the link was last edited.'));

    return $fields;
  }

}
