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
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Route;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Represents upload records as resources.
 *
 * @RestResource (
 *   id = "lrvsp_create",
 *   label = @Translation("[LRVSP] Create DocFile"),
 *   uri_paths = {
 *     "create" = "/create"
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
final class CreateResource extends ResourceBase {

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
    AccountProxyInterface    $currentUser,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
    $this->storage = $keyValueFactory->get('lrvsp_upload');
    $this->currentUser = $currentUser;
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
      $container->get('keyvalue'),
      $container->get('current_user')
    );
  }

  /**
   * Responds to POST requests and saves the new record.
   */
  public function post(array $data): ModifiedResourceResponse {
    // check user permissions
    if (!$this->currentUser->hasPermission('access content')) {
      throw new AccessDeniedHttpException();
    }

    // Validate required fields.
    if (empty($data['pdfId'])) {
      throw new BadRequestHttpException('Missing required fields');
    }

    // get term to set
    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')
      ->loadByProperties([
        'vid' => 'lrvsp_status',
        'name' => 'Processing',
      ]);
    $term = reset($terms);

    if (empty($data['processId']) || $data['processId'] == -1){
      \Drupal::logger("lrvsp")->notice("This");
      // create the doc entity
      $entity = DocFile::create([
        'pdf' => ['target_id' => $data['pdfId']],
        'docStatus' => ['target_id' => $term->id()],
        'linksStatus' => ['target_id' => $term->id()],
      ]);
    } else {
      \Drupal::logger("lrvsp")->notice("That");
      // create the doc entity
      $entity = DocFile::create([
        'pdf' => ['target_id' => $data['pdfId']],
        'processFile' => ['target_id' => $data['processId']],
        'docStatus' => ['target_id' => $term->id()],
        'linksStatus' => ['target_id' => $term->id()],
      ]);
    }
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
