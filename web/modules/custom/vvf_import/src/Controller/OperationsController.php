<?php

namespace Drupal\vvf_import\Controller;

use Dflydev\DotAccessData\Util;
use Drupal\Core\Controller\ControllerBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\utils\Controller\UtilsController;

/**
 * Provides a Controller for utilities in vvf import module.
 */
class OperationsController extends ControllerBase {
  /**
   * Associates locations with directors.
   *
   * This method links the taxonomy terms from
   * `UtilsController::TAXONOMY_TERM_LOCATIONS` (locations)
   * to the taxonomy terms from `UtilsController::TAXONOMY_TERM_DIRECTORS` (directors).
   *
   * As of 24/09/2025, it is not possible to automatically determine
   * the correct director when multiple people are associated with
   * the same location. For example, a location may list several directors,
   * and there is no way to uniquely identify the actual responsible person.
   * This service therefore remains incomplete and not usable due to the
   * lack of reliable information.
   *
   * @return void
   */
  /*
  public function associateLocationDirector(): void {
    $storedLocations = UtilsController::getAllFieldsFromVocabulary("office_list", "field_location_id");
    foreach ($storedLocations as $location) {
      $directorId = UtilsController::getTaxonomyIDByVocabularyAndField("director_list", [
        "machine_name" => "field_location_id",
        "value" => (string)$location,
      ]);
      if ($directorId && $directorId > 0) {
        $locationTermId = UtilsController::getTaxonomyIDByVocabularyAndField("office_list", $field);
        $locationTerm = Term::load($locationTermId);
        $directorTerm = Term::load($directorId);
        dd($directorTerm->name->value, $locationTerm->name->value);
      }
    }
  }
  */

  public function exportSedi() {
    $termIds = UtilsController::getAllFieldsFromVocabulary("office_list", "tid");
    $sedi = [];
    foreach ($termIds as $termId) {
      $term = Term::load($termId);
      if(!$term) {
        continue;
      }
      $sedi[] = [
        "name" => $term->name->value,
        "field_location_code" => $term->get("field_location_code")->value,
        "field_location_type" => $term->get("field_location_type")->value,
        "field_location_aoo_code" => $term->get("field_location_aoo_code")->value,
      ];

    }
    dd($sedi);
  }
}
