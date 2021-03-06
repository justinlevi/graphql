<?php

namespace Drupal\graphql_core\Plugin\GraphQL\Fields\Routing\InternalUrl;

use Drupal\Core\Url;
use Drupal\graphql\Plugin\GraphQL\Fields\FieldPluginBase;
use Youshido\GraphQL\Execution\ResolveInfo;

/**
 * Retrieve a routes internal path.
 *
 * @GraphQLField(
 *   id = "internal_url_path_internal",
 *   secure = true,
 *   name = "pathInternal",
 *   description = @Translation("The route's internal path."),
 *   type = "String",
 *   parents = {"InternalUrl"}
 * )
 */
class InternalPath extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function resolveValues($value, array $args, ResolveInfo $info) {
    if ($value instanceof Url) {
      yield "/{$value->getInternalPath()}";
    }
  }

}
