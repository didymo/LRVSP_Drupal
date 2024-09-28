<?php

declare(strict_types=1);

namespace Drupal\lrvsp\Plugin\rest\resource;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\lrvsp\Entity\Doc;
use Drupal\lrvsp\Entity\Link;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Route;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Represents links records as resources.
 *
 * @RestResource (
 *   id = "lrvsp_links",
 *   label = @Translation("[LRVSP] Get all links from a Doc"),
 *   uri_paths = {
 *     "canonical" = "/links/{docId}"
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
final class LinksResource extends ResourceBase {

  /**
   * The key-value storage.
   */
  private readonly KeyValueStoreInterface $storage;
  private readonly AccountProxyInterface    $currentUser;

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
    $this->storage = $keyValueFactory->get('lrvsp_links');
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
   * Responds to GET requests.
   */
  public function get($docId): ResourceResponse {
    // check user permissions
    if (!$this->currentUser->hasPermission('access content')) {
      throw new AccessDeniedHttpException();
    }

    // check doc exists
    $doc = Doc::load($docId);
    if (!isset($doc)) {
      throw new NotFoundHttpException();
    }
    // get links for this doc
    $linkIds = \Drupal::entityQuery('lrvsp_link')
      ->condition('status', 1)
      ->condition('fromDoc', $doc->id())
      ->accessCheck(FALSE) // TODO decide if this is correct
      ->execute();
    $links = Link::loadMultiple($linkIds);
    $linkList = array();
    // extract required data
    foreach ($links as $link){
      if ($link instanceof Link){
        // extract link data
        $retLink['fromDoc'] = $link->getFromDocID();
        $retLink['toDoc'] = $link->getToDocID();
        $retLink['pages'] = $link->getPages();
        $linkList[] = $retLink;

        unset($retLink);
        unset($retDoc);
      }
    }
    // remove duplicate values (shouldn't be necessary, but here just in case)
    $linkList = array_unique($linkList, SORT_REGULAR);
    $linkList = array_values($linkList);
    // create response
    $response = new ResourceResponse($linkList);
    // set cache stuff
    $metadata = new CacheableMetadata();
    $metadata->setCacheMaxAge(0); // TODO replace with tags
    $response->addCacheableDependency($metadata);
    return $response;
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
