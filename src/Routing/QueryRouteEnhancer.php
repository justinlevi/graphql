<?php

namespace Drupal\graphql\Routing;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Routing\Enhancer\RouteEnhancerInterface;
use Drupal\graphql\QueryProvider\QueryProviderInterface;
use Drupal\graphql\Utility\JsonHelper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

class QueryRouteEnhancer implements RouteEnhancerInterface {

  const SINGLE = 'single';
  const BATCH = 'batch';

  /**
   * The query provider service.
   *
   * @var \Drupal\graphql\QueryProvider\QueryProviderInterface
   */
  protected $queryProvider;

  /**
   * QueryRouteEnhancer constructor.
   *
   * @param \Drupal\graphql\QueryProvider\QueryProviderInterface $queryProvider
   *   The query provider service.
   */
  public function __construct(QueryProviderInterface $queryProvider) {
    $this->queryProvider = $queryProvider;
  }

  /**
   * {@inheritdoc}
   */
  public function applies(Route $route) {
    return $route->hasDefault('_graphql');
  }

  /**
   * {@inheritdoc}
   */
  public function enhance(array $defaults, Request $request) {
    if (!empty($defaults['_controller'])) {
      return $defaults;
    }

    $params = $this->extractParams($request);
    if ($enhanced = $this->enhanceSingle($defaults, $params, $request)) {
      return $enhanced;
    }

    if ($enhanced = $this->enhanceBatch($defaults, $params, $request)) {
      return $enhanced;
    }

    // By default we assume a 'single' request. This is going to fail in the
    // graphql processor due to a missing query string but at least provides
    // the right format for the client to act upon.
    return $defaults + [
      '_controller' => $defaults['_graphql']['single'],
    ];
  }

  /**
   * Attempts to enhance the request as a batch query.
   *
   * @param array $defaults
   *   The controller defaults.
   * @param array $params
   *   The query parameters.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return array|bool
   *   The enhanced controller defaults.
   */
  protected function enhanceBatch(array $defaults, array $params, Request $request) {
    // PHP 5.5.x does not yet support the ARRAY_FILTER_USE_KEYS constant.
    $keys = array_filter(array_keys($params), function($index) {
      return is_numeric($index);
    });

    $queries = array_intersect_key($params, array_flip($keys));
    if (!isset($queries[0])) {
      return FALSE;
    }

    if (array_keys($queries) !== range(0, count($queries) - 1)) {
      // If this is not a continuously numeric array, don't do anything.
      return FALSE;
    }

    return $defaults + [
      '_controller' => $defaults['_graphql']['multiple'],
      'queries' => $queries,
      'type' => static::BATCH,
    ];
  }

  /**
   * Attempts to enhance the request as a single query.
   *
   * @param array $defaults
   *   The controller defaults.
   * @param array $params
   *   The query parameters.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return array|bool
   *   The enhanced controller defaults.
   */
  protected function enhanceSingle(array $defaults, array $params, Request $request) {
    $values = $params + [
      'query' => empty($params['query']) ? $this->queryProvider->getQuery($params) : $params['query'],
      'variables' => [],
    ];

    if (empty($values['query'])) {
      return FALSE;
    }

    return $defaults + [
      '_controller' => $defaults['_graphql']['single'],
      'query' => !empty($values['query']) && is_string($values['query']) ? $values['query'] : '',
      'variables' => !empty($values['variables']) && is_array($values['variables']) ? $values['variables'] : [],
      'persisted' => empty($params['query']),
      'type' => static::SINGLE,
    ];
  }

  /**
   * Extract an associative array of query parameters from the request.
   *
   * If the given request does not have any POST body content it uses the GET
   * query parameters instead.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return array
   *   An associative array of query parameters.
   */
  protected function extractParams(Request $request) {
    $values = JsonHelper::decodeParams($request->query->all());

    // The request body parameters might contain file upload mutations. We treat
    // them according to the graphql multipart request specification.
    //
    // @see https://github.com/jaydenseric/graphql-multipart-request-spec#server
    if ($body = JsonHelper::decodeParams($request->request->all())) {
      // Flatten the operations array if it exists.
      $operations = isset($body['operations']) && is_array($body['operations']) ? $body['operations'] : [];
      $values = array_merge($values, $body, $operations);
    }

    // The request body content has precedence of query parameters.
    if ($content = $request->getContent()) {
      $values = array_merge($values, JsonHelper::decodeParams(json_decode($content, TRUE)));
    }

    // According to the graphql multipart request specification, uploaded files
    // are referenced to variable placeholders in a map. Here, we resolve this
    // map by assigning the uploaded files to the corresponding variables.
    if (!empty($values['map']) && is_array($values['map']) && $files = $request->files->all()) {
      foreach ($files as $key => $file) {
        if (!isset($values['map'][$key])) {
          continue;
        }

        $paths = (array) $values['map'][$key];
        foreach ($paths as $path) {
          $path = explode('.', $path);

          if (NestedArray::keyExists($values, $path)) {
            NestedArray::setValue($values, $path, $file);
          }
        }
      }
    }

    return $values;
  }


}
