<?php
/**
 * @contains EmbedPromoManager.php
 * User: goce
 * Date: 1/22/18
 * Time: 9:43 AM
 */

namespace Drupal\qtx_embed_promo;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Tektite\Utilities\MarkupHelper;


/**
 * File: EmbedPromoManager.php
 * Author: goce
 * Created:  2018.01.22
 *
 * Description:
 */
class EmbedPromoManager {

  /** CID prefix for a promo item active in a specific Taxonomy. One item per taxonomy. */
  const CID_PREFIX_PROMO_NID = "promo:term:";

  /** CID prefix for the rendered markup of a promo entity. */
  const CID_PREFIX_RENDERED_PROMO = "promo:rendered:";

  /** @var Connection */
  protected $database;

  /** @var EntityTypeManagerInterface */
  protected $entityTypeManager;

  /** @var RendererInterface */
  protected $renderer;

  /** @var CacheBackendInterface */
  protected $dataCache;

  /** @var CacheBackendInterface */
  protected $renderCache;

  /**
   * EmbedPromoManager constructor.
   *
   * @param \Drupal\Core\Database\Connection               $database
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   * @param \Drupal\Core\Render\RendererInterface          $renderer
   * @param \Drupal\Core\Cache\CacheBackendInterface       $dataCache
   * @param \Drupal\Core\Cache\CacheBackendInterface       $renderCache
   */
  public function __construct(Connection $database, EntityTypeManagerInterface $entityTypeManager, RendererInterface $renderer, CacheBackendInterface $dataCache, CacheBackendInterface $renderCache) {
    $this->database = $database;
    $this->entityTypeManager = $entityTypeManager;
    $this->renderer = $renderer;
    $this->dataCache = $dataCache;
    $this->renderCache = $renderCache;
  }

  public static function getTargetTermCacheTags($termId) {
    return ['promo.target:' . $termId];
  }

  /**
   * Returns the most recently modified node of type embed_promo whose
   *  primary_taxonomy field targets the $node primary taxonomy field.
   *
   * @param \Drupal\taxonomy\TermInterface $contentSection
   *
   * @return \Drupal\node\NodeInterface|null
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   */
  public function getPromoNode(TermInterface $contentSection) {

    if (!$nodeId = $this->promoNodeByTaxonomy($contentSection)) {
      return NULL;
    }

    /** @var NodeInterface $promo */
    $promo = $this->entityTypeManager->getStorage('node')
      ->load($nodeId);

    if (!$promo instanceof NodeInterface || $promo->bundle() !== 'embed_promo') {
      return NULL;
    }

    return $promo;
  }

  /**
   * Splits the string $text into paragraphs and injects a promo item.
   *
   * The paragraphs are split based on the rules in the MarkupHelper::splitIntoParagraphs function.
   *
   * @param \Drupal\node\NodeInterface $promo
   * @param                            $text
   *
   * @return string
   * @throws \Exception
   */
  public function injectPromo(NodeInterface $promo, $text) {
    $offset = isset($promo->field_paragraph_offset) ? $promo->field_paragraph_offset->value : 3;

    $paragraphs = MarkupHelper::splitIntoParagraphs($text);

    if (count($paragraphs) < $offset) {
      return $text;
    }
    array_splice($paragraphs, $offset, 0, $this->renderPromoNode($promo));

    // Before returning the text value, combine the paragraphs and trim any newlines and spaces from the end of the string.
    return rtrim(implode('', $paragraphs));
  }

  /**
   * Returns a render array for a node object.
   *
   * @param \Drupal\node\NodeInterface|NULL $promo
   * @param string                          $viewMode
   *
   * @return \Drupal\Component\Render\MarkupInterface|NULL
   * @throws \Exception
   */
  public function renderPromoNode(NodeInterface $promo = NULL, $viewMode = 'body_embed_promo') {
    if (!$promo instanceof NodeInterface || $promo->bundle() !== 'embed_promo') {
      return NULL;
    }

    if ($cacheItem = $this->renderCache->get($this->getRenderCacheCid($promo))) {
      return $cacheItem->data;
    }

    // @todo: do we need to insert two newlines after inserting the promo markup?
    $viewBuilder = $this->entityTypeManager->getViewBuilder($promo->getEntityTypeId());
    $renderArray = $viewBuilder->view($promo, $viewMode);
    $markup = $this->renderer->render($renderArray);
    $cacheTags = [
      'node:' . $promo->id(),
    ];
    $this->renderCache->set($this->getRenderCacheCid($promo), $markup, Cache::PERMANENT, $cacheTags);
    return $markup;
  }

  /**
   * @param \Drupal\node\NodeInterface|NULL $node
   *
   * @return \Drupal\taxonomy\TermInterface|null
   */
  public function getTargetPrimaryTaxonomy(NodeInterface $node = NULL) {
    if (!isset($node->field_primary_taxonomy)) {
      return NULL;
    }

    $referenced = $node->field_primary_taxonomy->referencedEntities();
    // There should be only 1 term in the $referencedTerms array since the Primary Taxonomy is a single cardinality field.
    return reset($referenced) ?: NULL;
  }

  protected function promoNodeByTaxonomy(TermInterface $term = NULL) {
    if (!$term) {
      return NULL;
    }

    // Pull the item from cache so we don't have to query the database.
    if ($cacheItem = $this->dataCache->get($this->getDataCacheCid($term))) {
      return $cacheItem->data;
    }

    $query = $this->buildQuery($term);
    $nid = $query->execute()
      ->fetchField();

    // We're going to cache this result. The cache store for this cid should be cleared when there is an available promo for this taxonomy term.
    $cacheTags = array_merge(['node:' . $nid], self::getTargetTermCacheTags($term->id()));

    $this->dataCache->set($this->getDataCacheCid($term), $nid, Cache::PERMANENT, $cacheTags);

    return $nid ?: NULL;
  }

  /**
   * @param \Drupal\taxonomy\TermInterface $term
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   */
  private function buildQuery(TermInterface $term) {
    $query = $this->database->select('node', 'n');
    $query->condition('nfd.status', 1);
    $query->condition('n.type', 'embed_promo');
    $query->innerJoin('node_field_data', 'nfd', 'n.nid=nfd.nid');
    $query->innerJoin('node__field_primary_taxonomy_target', 'npt', 'npt.entity_id=n.nid');
    $query->condition('npt.field_primary_taxonomy_target_target_id', $term->id());
    $query->orderBy('nfd.changed', 'DESC');
    $query->range(0, 1);
    $query->addField('n', 'nid');

    return $query;
  }

  private function getDataCacheCid(TermInterface $term) {
    return self::CID_PREFIX_PROMO_NID . $term->id();
  }

  private function getRenderCacheCid(NodeInterface $promo) {
    return self::CID_PREFIX_RENDERED_PROMO . $promo->id();
  }

}