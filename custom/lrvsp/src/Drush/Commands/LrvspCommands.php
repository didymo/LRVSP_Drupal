<?php

namespace Drupal\lrvsp\Drush\Commands;

use Drupal\Core\Database\Database;
use Drupal\Core\Utility\Token;
use Drupal\lrvsp\Entity\Doc;
use Drupal\lrvsp\Entity\DocFile;
use Drupal\lrvsp\Entity\Link;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

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

    // process new docs
    $query = $pyDbConn->query("SELECT * FROM DocObjs WHERE failed = 0 LIMIT ".$maxItems/2);
    $result = $query->fetchAll();
    foreach ($result as $res){
      $pyTransaction = $pyDbConn->startTransaction("pythonTransaction");
      try{
        // check if a doc exists with the same title
        $docId = \Drupal::entityQuery('lrvsp_doc')
          ->condition('status',1)
          ->condition('label',$res->title)
          ->accessCheck(False) // TODO decide if this is correct
          ->execute();
        if (empty($docId)){
          // doc doesn't exist, create new
          $doc = Doc::create([
            'label' => $res->title,
            'metadata' => "",
            'docFile' => ['target_id' => $res->entityId],
            'numLinks' => $res->numLinks
          ]);
        } else {
          // doc exists, update
          $docId = reset($docId);
          $doc = Doc::load($docId);
          $doc->setMetadata($res->metadata)
            ->setDocFile($res->entityId)
            ->setNumLinks($res->numLinks);
        }
        // delete from python database
        $pyDbConn->delete('DocObjs')
          ->condition('ID', $res->ID)
          ->execute();

        $this->logger()->success("Successfully created/updated doc.");
      } catch (\Exception $e){
        $this->logger()->error("Error creating/updating new doc ".$e->getMessage());

        // roll back changes
        $pyTransaction->rollBack();
        unset($doc);

        // update failed doc in python db
        $pyDbConn->update('DocObjs')
          ->fields([
            'failed' => 1
          ])
          ->condition('ID', $res->ID)
          ->execute();
      } finally {
        # commit changes
        unset($pyTransaction);
        if (isset($doc)){
          $doc->save();
        }
      }
    }

    // process new links
    $maxLinks = $maxItems - count($result);
    $query = $pyDbConn->query("SELECT * FROM LinkObjs WHERE failed = 0 LIMIT ".$maxLinks);
    $result = $query->fetchAll();
    foreach ($result as $res){
      $pyTransaction = $pyDbConn->startTransaction("pythonTransaction");
      try {
        // check if fromDoc exists:
        $docId = \Drupal::entityQuery('lrvsp_doc')
          ->condition('status', 1)
          ->condition('label', $res->fromTitle)
          ->accessCheck(False) // TODO decide if this is correct
          ->execute();
        if (empty($docId)) {
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
          ->condition('status', 1)
          ->condition('label', $res->toTitle)
          ->accessCheck(False) // TODO decide if this is correct
          ->execute();
        if (empty($docId)) {
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
        $link = Link::create([
          'fromDoc' => ['target_id' => $fromDocId],
          'toDoc' => ['target_id' => $toDocId]
        ]);
        // delete from database
        $pyDbConn->delete('LinkObjs')
          ->condition('ID', $res->ID)
          ->execute();
        $this->logger()->success("Successfully created link.");
      } catch (\Exception $e){
        $this->logger()->error("Error creating new link ".$e->getMessage());
        // undo changes
        $pyTransaction->rollBack();
        if (isset($link)){
          // undo creation of new link if we made it that far
          unset($link);
        }
        if (isset($fromDoc)){
          // if a fromDoc was created, attempt to delete
          try{
            // attempt deletion
            $fromDoc->delete();
            unset($fromDoc);
          } catch (\Exception $e1){
            // doc was not saved, unset
            unset($fromDoc);
          }
        }
        if (isset($toDoc)){
          // if a toDoc was created, attempt to delete
          try{
            // attempt deletion
            $toDoc->delete();
            unset($toDoc);
          } catch (\Exception $e1){
            // doc was not saved, unset
            unset($toDoc);
          }
        }
        // mark link as failed in db
        $pyDbConn->update('LinkObjs')
          ->fields([
            'failed' => 1
          ])
          ->condition('ID', $res->ID)
          ->execute();
      } finally {
        # commit changes
        unset($pyTransaction);
        if (isset($link)){
          $link->save();
        }
      }
    }

    // get failed paths
    $query = $pyDbConn->query("SELECT * FROM FilePaths WHERE failed = 1");
    $result = $query->fetchAll();
    foreach ($result as $res){
      // mark failed docFile as failed
      DocFile::load($res->entityId)->setDocFailed()->save();
      // delete db entry
      $pyDbConn->delete('FilePaths')
        ->condition('ID', $res->ID)
        ->execute();
    }

    // get failed docs
    $query = $pyDbConn->query("SELECT * FROM DocObjs WHERE failed = 1");
    $result = $query->fetchAll();
    foreach ($result as $res){
      // mark failed docFile as failed
      $docFile = DocFile::load($res->entityId);
      $docFile->setDocFailed()->save();
      // delete db entry
      $pyDbConn->delete('DocObjs')
        ->condition('ID', $res->ID)
        ->execute();
    }

    // get failed links
    $query = $pyDbConn->query("SELECT * FROM LinkObjs WHERE failed = 1");
    $result = $query->fetchAll();
    foreach ($result as $res){
      // mark failed docFile as failed
      // load doc
      $docId = \Drupal::entityQuery('lrvsp_doc')
        ->condition('status', 1)
        ->condition('label', $res->fromTitle)
        ->accessCheck(False) // TODO decide if this is correct
        ->execute();
      // check if doc exists
      if (!empty($docId)) {
        $fromDocId = reset($docId);
        $docFileID = Doc::load($fromDocId)->getDocFileId();
        $docfile = DocFile::load($docFileID);
        $docfile->setLinksFailed()->save();
        // delete db entry
        $pyDbConn->delete('LinkObjs')
          ->condition('ID', $res->ID)
          ->execute();
      }
      // if the doc hasn't been made yet, we'll keep trying until it exists
    }
  }
}
