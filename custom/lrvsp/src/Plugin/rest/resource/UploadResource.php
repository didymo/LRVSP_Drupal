<?php

declare(strict_types=1);

namespace Drupal\lrvsp\Plugin\rest\resource;

use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\lrvsp\Entity\DocFile;
use Drupal\rest\ModifiedResourceResponse;
use Drupal\rest\Plugin\ResourceBase;
use finfo;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Route;

/**
 * Represents upload records as resources.
 *
 * @RestResource (
 *   id = "lrvsp_upload",
 *   label = @Translation("[LRVSP] Upload pdf for DocFile"),
 *   uri_paths = {
 *     "create" = "/upload"
 *   }
 * )
 *
 * @DCG
 * The plugin exposes key-value records as REST resources. In order to enable it
 * import the resource configuration into active configuration storage. An
 * example of such configuration can be located in the following file:
 * core/modules/rest/config/optional/rest.resource.entity.node.yml.
 * Alternatively, you can enable it through admin interface provider by REST UI
 * module.
 * @see https://www.drupal.org/project/restui
 *
 * @DCG
 * Notice that this plugin does not provide any validation for the data.
 * Consider creating custom normalizer to validate and normalize the incoming
 * data. It can be enabled in the plugin definition as follows.
 * @code
 *   serialization_class = "Drupal\foo\MyDataStructure",
 * @endcode
 *
 * @DCG
 * For entities, it is recommended to use REST resource plugin provided by
 * Drupal core.
 * @see \Drupal\rest\Plugin\rest\resource\EntityResource
 */
final class UploadResource extends ResourceBase {

  /**
   * The key-value storage.
   */
  private readonly KeyValueStoreInterface $storage;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    array $serializer_formats,
    LoggerInterface $logger,
    KeyValueFactoryInterface $keyValueFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
    $this->storage = $keyValueFactory->get('lrvsp_upload');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')->get('rest'),
      $container->get('keyvalue')
    );
  }

  /**
   * Responds to POST requests and saves the new record.
   */
  public function post(array $data): ModifiedResourceResponse {
    // get term to set
    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')
      ->loadByProperties([
        'vid' => 'lrvsp_status',
        'name' => 'Processing',
      ]);
    $term = reset($terms);

    // decode file data
    $fileData = base64_decode($data['pdf'], True);
    // check a pdf was actually provided
    $fInfo = new finfo(FILEINFO_MIME);
    $fType = $fInfo->buffer($fileData);
    $fGood = $fType === "application/pdf; charset=binary";
    if (!$fGood){
      throw new HttpException(415, "Non PDF provided");
    }
    // save the pdf
    $file = \Drupal::service('file.repository')->writeData($fileData, 'public://pdfs/'.$data['fileName']);
    // create the doc entity
    $entity = DocFile::create([
      'pdf' => ['target_id' => $file->id()],
      'docStatus' => ['target_id' => $term->id()],
      'linksStatus' => ['target_id' => $term->id()],
    ]);
    $entity->save();
    // Return the newly created record in the response body.
    return new ModifiedResourceResponse(array("fileId"=>$entity->id()), 201);
  }

  /**
   * {@inheritdoc}
   */
  protected function getBaseRoute($canonical_path, $method): Route {
    $route = parent::getBaseRoute($canonical_path, $method);
    // Set ID validation pattern.
    if ($method !== 'POST') {
      $route->setRequirement('id', '\d+');
    }
    return $route;
  }

  /**
   * Returns next available ID.
   */
  private function getNextId(): int {
    $ids = \array_keys($this->storage->getAll());
    return count($ids) > 0 ? max($ids) + 1 : 1;
  }

}
