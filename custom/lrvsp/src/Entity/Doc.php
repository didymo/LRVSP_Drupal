<?php

declare(strict_types=1);

namespace Drupal\lrvsp\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\lrvsp\DocInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the doc entity class.
 *
 * @ContentEntityType(
 *   id = "lrvsp_doc",
 *   label = @Translation("Doc"),
 *   label_collection = @Translation("Docs"),
 *   label_singular = @Translation("doc"),
 *   label_plural = @Translation("docs"),
 *   label_count = @PluralTranslation(
 *     singular = "@count docs",
 *     plural = "@count docs",
 *   ),
 *   handlers = {
 *     "list_builder" = "Drupal\lrvsp\DocListBuilder",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "access" = "Drupal\lrvsp\DocAccessControlHandler",
 *     "form" = {
 *       "add" = "Drupal\lrvsp\Form\DocForm",
 *       "edit" = "Drupal\lrvsp\Form\DocForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm",
 *       "delete-multiple-confirm" = "Drupal\Core\Entity\Form\DeleteMultipleForm",
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   base_table = "lrvsp_doc",
 *   admin_permission = "administer lrvsp_doc",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *     "owner" = "uid",
 *   },
 *   links = {
 *     "collection" = "/admin/content/doc",
 *     "add-form" = "/doc/add",
 *     "canonical" = "/doc/{lrvsp_doc}",
 *     "edit-form" = "/doc/{lrvsp_doc}/edit",
 *     "delete-form" = "/doc/{lrvsp_doc}/delete",
 *     "delete-multiple-form" = "/admin/content/doc/delete-multiple",
 *   },
 *   field_ui_base_route = "entity.lrvsp_doc.settings",
 * )
 */
final class Doc extends ContentEntityBase implements DocInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
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

    // set this document as processed
    $docFile = $this->get('docFile')->getValue();
    if ($this->hasField('docFile') && !$this->get('docFile')->isEmpty()){
      DocFile::load($docFile[0]['target_id'])->setDocProcessed()->save();
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {

    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Title'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -5,
      ])
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

    $fields['metadata'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Metadata'))
      ->setDescription(t('The metadata of the document (currently unused)'))
      ->setDefaultValue('')
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'type' => 'text_default',
        'label' => 'above',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['docFile'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('DocFile'))
      ->setSetting('target_type', 'lrvsp_docfile')
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

    $fields['numLinks'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Number of Links'))
      ->setDescription(t('The number of links this document contains'))
      ->setRequired(TRUE)
      ->setDisplayOptions('form', [
        'weight' => 12,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'weight' => 12,
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
      ->setDescription(t('The time that the doc was created.'))
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
      ->setDescription(t('The time that the doc was last edited.'));

    return $fields;
  }

  public function getTitle(): string{
    return $this->get('label')->value;
  }

  public function getNumLinks(): int{
    return intval($this->get('numLinks')->value);
  }

  public function setLinksProcessed(): void{
    // set this documents links as processed
    $docFileId = $this->get('docFile')->getValue()[0]['target_id'];
    DocFile::load($docFileId)->setLinksProcessed()->save();
  }

  public function getIsTracked(): bool {
    // does this Doc have a docFile attached to it?
    if ($this->hasField('docFile') && !$this->get('docFile')->isEmpty()){
      $fileId = $this->get('docFile')->getValue()[0]['target_id'];
      $docFile = DocFile::load($fileId);
      // get current processing status and one to compare it to
      $status = $docFile->getProcessingStatus();
      $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')
        ->loadByProperties([
          'vid' => 'lrvsp_status',
          'name' => 'Processed',
        ]);
      $term = reset($terms);
      // processed = both doc and links are marked as processed
      if ($status['doc'] == $status['links'] && $status['links'] == $term->id()){
        return true;
      }
    }
    return false;
  }

  public function getDocFileId(): string{
    return $this->get('docFile')->getValue()[0]['target_id'];
  }

  public function getMetadata(): string{
    if ($this->hasField('metadata')){
      $val = $this->get('metadata');
      if (!$val->isEmpty()){
        return $val->value;
      }
    }
    return '';
  }

  public function setMetadata(string $metadata): Doc{
    $this->set('metadata',$metadata);
    return $this;
  }

  public function setDocFile(int $docFileId): Doc{
    $this->set('docFile',['target_id'=>$docFileId]);
    return $this;
  }

  public function setNumLinks(int $numLinks): Doc{
    $this->set('numLinks', $numLinks);
    return $this;
  }

}
