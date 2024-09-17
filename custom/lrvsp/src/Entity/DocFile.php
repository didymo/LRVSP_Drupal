<?php

declare(strict_types=1);

namespace Drupal\lrvsp\Entity;

use Drupal\Core\Database\Database;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\lrvsp\DocFileInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the docfile entity class.
 *
 * @ContentEntityType(
 *   id = "lrvsp_docfile",
 *   label = @Translation("DocFile"),
 *   label_collection = @Translation("DocFiles"),
 *   label_singular = @Translation("docfile"),
 *   label_plural = @Translation("docfiles"),
 *   label_count = @PluralTranslation(
 *     singular = "@count docfiles",
 *     plural = "@count docfiles",
 *   ),
 *   handlers = {
 *     "list_builder" = "Drupal\lrvsp\DocFileListBuilder",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "access" = "Drupal\lrvsp\DocFileAccessControlHandler",
 *     "form" = {
 *       "add" = "Drupal\lrvsp\Form\DocFileForm",
 *       "edit" = "Drupal\lrvsp\Form\DocFileForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm",
 *       "delete-multiple-confirm" = "Drupal\Core\Entity\Form\DeleteMultipleForm",
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   base_table = "lrvsp_docfile",
 *   admin_permission = "administer lrvsp_docfile",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *     "owner" = "uid",
 *   },
 *   links = {
 *     "collection" = "/admin/content/docfile",
 *     "add-form" = "/docfile/add",
 *     "canonical" = "/docfile/{lrvsp_docfile}",
 *     "edit-form" = "/docfile/{lrvsp_docfile}/edit",
 *     "delete-form" = "/docfile/{lrvsp_docfile}/delete",
 *     "delete-multiple-form" = "/admin/content/docfile/delete-multiple",
 *   },
 *   field_ui_base_route = "entity.lrvsp_docfile.settings",
 * )
 */
final class DocFile extends ContentEntityBase implements DocFileInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    // set label to file name
    $fid = $this->get('pdf')->get(0)->getValue()['target_id'];
    $fileName = File::Load($fid)->getFilename();
    $this->set('label',$fileName);

    if (!$this->getOwnerId()) {
      // If no owner has been set explicitly, make the anonymous user the owner.
      $this->setOwnerId(0);
    }
  }

  public function postSave(EntityStorageInterface $storage, $update = TRUE){
    parent::postSave($storage, $update);

    // get saved path of pdf
    $fid = $this->get('pdf')->get(0)->getValue()['target_id'];
    $uri = File::Load($fid)->getFileUri();
    $stream_wrapper_manager = \Drupal::service('stream_wrapper_manager')->getViaUri($uri);
    $pdf_path = $stream_wrapper_manager->realpath();

    // get saved path of processing document
    $process = $this->get('processFile')->get(0);
    if (isset($process) && !$process){
      $fid = $process->getValue()['target_id'];
      $uri = File::Load($fid)->getFileUri();
      $stream_wrapper_manager = \Drupal::service('stream_wrapper_manager')->getViaUri($uri);
      $process_path = $stream_wrapper_manager->realpath();
    } else {
      $process_path = "";
    }

    // add file path to pythonconn database (only do this once)
    if (!$this->get('python')->value){
      $pyDbConn = Database::getConnection('default','python');
      $transaction = $pyDbConn->startTransaction();
      $pyDbConn->insert('FilePaths')
        ->fields(['pdfPath', 'processPath','entityId'])
        ->values([
          'pdfPath' => $pdf_path,
          'processPath' => $process_path,
          'entityId' => $this->id()
        ])
        ->execute();
      unset($transaction); // commit
      $this->set('python', TRUE)->save();
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    // get term
    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')
      ->loadByProperties([
        'vid' => 'lrvsp_status',
        'name' => 'Processing',
      ]);
    $term = reset($terms);

    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Document'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
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

    $fields['pdf'] = BaseFieldDefinition::create('file')
      ->setLabel(t('The pdf for this document'))
      ->setRequired(TRUE)
      ->setSettings([
        'file_extensions' =>'pdf',
        'file_directory' => 'lrvsp_files',
        'handler' => 'default:file'
      ])
      ->setDisplayOptions('form', [
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'type' => 'text_default',
        'label' => 'above',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['processFile'] = BaseFieldDefinition::create('file')
      ->setLabel(t('The file to be used for processing'))
      ->setSettings([
        'file_extensions' =>'xml',
        'file_directory' => 'lrvsp_files',
        'handler' => 'default:file'
      ])
      ->setDisplayOptions('form', [
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'type' => 'text_default',
        'label' => 'above',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['docStatus'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Doc Processing Status'))
      ->setRequired(TRUE)
      ->setDescription(t('The processing status of this document.'))
      ->setSettings([
        'target_type' => 'taxonomy_term',
        'handler' => 'default',
        'handler_settings' => array(
          'target_bundles' => array(
            'lrvsp_status' => 'lrvsp_status'
          )
        )
      ])
      ->setDefaultValue(['target_id' => $term->id()])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'text_default',
        'weight' => 11,
      ])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['linksStatus'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Links Processing Status'))
      ->setRequired(TRUE)
      ->setDescription(t('The processing status of the links in this document.'))
      ->setSettings([
        'target_type' => 'taxonomy_term',
        'handler' => 'default',
        'handler_settings' => array(
          'target_bundles' => array(
            'lrvsp_status' => 'lrvsp_status'
          )
        )
      ])
      ->setDefaultValue(['target_id' => $term->id()])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'text_default',
        'weight' => 12,
      ])
      ->setDisplayConfigurable('form', FALSE)
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
      ->setDescription(t('The time that the docfile was created.'))
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
      ->setDescription(t('The time that the docfile was last edited.'));

    $fields['python'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('python'))
      ->setDescription(t('has this entity been sent to python?'))
      ->setDefaultValue(FALSE)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    return $fields;
  }

  public function setDocProcessed(): DocFileInterface{
    // mark this document as being processed
    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')
    ->loadByProperties([
      'vid' => 'lrvsp_status',
      'name' => 'Processed',
    ]);
    $term = reset($terms);
    $this->set('docStatus',['target_id' => $term->id()]);
    return $this;
  }

  public function setLinksProcessed(): DocFileInterface{
    // mark this document as being processed
    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')
      ->loadByProperties([
        'vid' => 'lrvsp_status',
        'name' => 'Processed',
      ]);
    $term = reset($terms);
    $this->set('linksStatus',['target_id' => $term->id()]);
    return $this;
  }

  public function setDocFailed(): DocFileInterface{
    // mark this document as being failed
    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')
      ->loadByProperties([
        'vid' => 'lrvsp_status',
        'name' => 'Failed',
      ]);
    $term = reset($terms);
    $this->set('docStatus',['target_id' => $term->id()]);
    return $this;
  }

  public function setLinksFailed(): DocFileInterface{
    // mark this documents links as being failed
    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')
      ->loadByProperties([
        'vid' => 'lrvsp_status',
        'name' => 'Failed',
      ]);
    $term = reset($terms);
    $this->set('linksStatus',['target_id' => $term->id()]);
    return $this;
  }

  public function getProcessingStatus(): array{
    return array(
      'doc' => $this->get('docStatus')->getValue()[0]['target_id'],
      'links' => $this->get('linksStatus')->getValue()[0]['target_id']
    );
  }

  public function getFileUrl(): string{
    $fid = $this->get('pdf')->get(0)->getValue()['target_id'];
    return File::Load($fid)->createFileUrl();
  }

}
