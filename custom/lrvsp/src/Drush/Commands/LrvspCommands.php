<?php

namespace Drupal\lrvsp\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\Database\Database;
use Drupal\Core\Utility\Token;
use Drupal\lrvsp\Entity\Doc;
use Drupal\lrvsp\Entity\Link;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;
use function PHPUnit\Framework\isEmpty;

/**
 * A Drush commandfile.
 */
final class LrvspCommands extends DrushCommands {

  /**
   * Constructs a LrvspCommands object.
   */
  public function __construct(
    private readonly Token $token,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('token'),
    );
  }

  /**
   * Command description here.
   */
  #[CLI\Command(name: 'lrvsp:check-db', aliases: ['lrvsCheck-db'])]
  #[CLI\Argument(name: 'maxItems', description: 'How many rows to grab from the database')]
  #[CLI\Usage(name: 'lrvsp:check-db maxItems', description: 'Check the database for updates')]
  public function commandname($maxItems = 50) {
    $pyDbConn = Database::getConnection('default','python');
    $transaction = $pyDbConn->startTransaction();
    // process new docs
    $query = $pyDbConn->query("SELECT * FROM DocObjs LIMIT ".$maxItems/2);
    $result = $query->fetchAll();
    foreach ($result as $res){
      // check if a doc exists with the same title
      $docId = \Drupal::entityQuery('lrvsp_doc')
        ->condition('status',1)
        ->condition('label',$res->title)
        ->accessCheck(False) // TODO decide if this is correct
        ->execute();
      if (empty($docId)){
        // doc doesn't exist, create new
        Doc::create([
          'label' => $res->title,
          'metadata' => $res->metadata,
          'docFile' => ['target_id' => $res->entityId],
          'numLinks' => $res->numLinks
        ])->save();
      } else {
        // doc exists, update
        $docId = reset($docId);
        $doc = Doc::load($docId);
        $doc->setMetadata($res->metadata)
          ->setDocFile($res->entityId)
          ->setNumLinks($res->numLinks)
          ->save();
      }
      // delete from python database
      $pyDbConn->delete('DocObjs')
        ->condition('ID', $res->ID)
        ->execute();
    }
    // process new links
    $maxLinks = $maxItems - count($result);
    $query = $pyDbConn->query("SELECT * FROM LinkObjs LIMIT ".$maxLinks);
    $result = $query->fetchAll();
    foreach ($result as $res){
      // check if fromDoc exists:
      $docId = \Drupal::entityQuery('lrvsp_doc')
        ->condition('status',1)
        ->condition('label',$res->fromTitle)
        ->accessCheck(False) // TODO decide if this is correct
        ->execute();
      if (empty($docId)){
        // doesn't exist, make new
        $fromDoc = Doc::create([
          'label' => $res->fromTitle,
          'numLinks' => -1 // so bad things don't happen
        ]);
        $fromDoc->save();
        $fromDocId = $fromDoc->id();
      } else {
        // exists, select
        $fromDocId = reset($docId);
      }
      // check if toDoc exists:
      $docId = \Drupal::entityQuery('lrvsp_doc')
        ->condition('status',1)
        ->condition('label',$res->toTitle)
        ->accessCheck(False) // TODO decide if this is correct
        ->execute();
      if (empty($docId)){
        // doesn't exist, make new
        $toDoc = Doc::create([
          'label' => $res->toTitle,
          'numLinks' => -1 // same as before, stops bad things from happening
        ]);
        $toDoc->save();
        $toDocId = $toDoc->id();
      } else {
        // already exists, get id
        $toDocId = reset($docId);
      }
      // create link object
      Link::create([
        'fromDoc' => ['target_id' => $fromDocId],
        'toDoc' => ['target_id' => $toDocId]
      ])->save();
      // delete from database
      $pyDbConn->delete('LinkObjs')
        ->condition('ID', $res->ID)
        ->execute();
    }
    // commit transaction
    unset($transaction);
  }

}
