<?php

/**
 * @contains TextEmbedPromoFormatter.php
 * User: goce
 * Date: 1/22/18
 * Time: 10:49 AM
 */

namespace Drupal\qtx_embed_promo\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Provides a text formatter.
 *
 * @FieldFormatter(
 *   id = "text_embed_promo",
 *   label = @Translation("Embed Promo"),
 *   field_types={
 *      "text_long",
 *      "text_with_summary",
 *   }
 * )
 */
class TextEmbedPromoFormatter extends FormatterBase {

  /**
   * Builds a renderable array for a field value.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The field values to be rendered.
   * @param string $langcode
   *   The language that should be used to render the field.
   *
   * @return array
   *   A renderable array for $items, as an array of child elements keyed by
   *   consecutive numeric indexes starting from 0.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Exception
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];
    /** @var \Drupal\Core\Entity\Plugin\DataType\EntityAdapter $parent */
    $node = $items->getEntity();
    /** @var \Drupal\qtx_embed_promo\EmbedPromoManager $epManager */
    $epManager = \Drupal::service('qtx_embed_promo.manager');

    $contentSection = $epManager->getTargetPrimaryTaxonomy($node);
    $promo = NULL;
    if ($contentSection instanceof TermInterface) {
      $promo = $epManager->getPromoNode($contentSection);
    }

    // The ProcessedText element already handles cache context & tag bubbling.
    // @see \Drupal\filter\Element\ProcessedText::preRenderText()
    foreach ($items as $delta => $item) {
      $value = $item->value;

      $elements[$delta] = [
        '#type' => 'processed_text',
        '#text' => $value,
        '#format' => $item->format,
        '#langcode' => $item->getLangcode(),
      ];

      // Override the text value and inject cache tags to clear content on promo targeting changes.
      if ($promo instanceof NodeInterface) {
        $elements[$delta]['#text'] = $epManager->injectPromo($promo, $value);
        $elements[$delta]['#cache']['tags'] = $epManager::getTargetTermCacheTags($contentSection->id());
      }
    }

    return $elements;
  }
}