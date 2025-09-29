<?php

namespace Drupal\vvf_import\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\utils\Controller\UtilsController;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller for importing and synchronizing VVF locations and details.
 *
 * Fetches data from external APIs and manages corresponding taxonomy terms
 * (create, update, delete) in Drupal.
 */
class LocationsImportController extends ControllerBase {
  const string API_DIPARTIMENTO = "https://wauc.dipvvf.it/api/Dipartimento";
  const string API_INFO_SEDE = "https://wauc.dipvvf.it/api/Sedi/GetInfoSede?codSede=";
  const string LOGGER_CHANNEL = 'vvf_import_locations';
  const string VID = "office_list";

  /**
   * Imports VVF locations from the external API and updates the taxonomy terms.
   *
   * This method fetches location data from the API defined by `UtilsController::API_DIPARTIMENTO`,
   * decodes the JSON response, and flattens the hierarchical structure. It then
   * synchronizes the data with the Drupal taxonomy identified by `self::VID`.
   * For each location, it creates new terms, updates existing ones, and removes
   * any terms that no longer exist in the API data.
   *
   * Errors during the API request or JSON decoding are caught and logged, returning
   * a JSON response with an error message and HTTP status code 500.
   *
   * @return JsonResponse
   *   A JSON response containing the synchronization report, including the number
   *   of terms created, updated, or deleted. In case of error, returns a JSON
   *   object with an 'error' key.
   *
   * @throws \RuntimeException
   *   If the API is unavailable or the JSON response is invalid.
   */
  public function importLocations(): JsonResponse {
    try {
      $responseJSON = UtilsController::fetchAPI(self::API_DIPARTIMENTO, "GET", []);
      if ($responseJSON === false) {
        throw new \RuntimeException('API DIPARTIMENTO non disponibile.');
      }
      /** @var array $locations */
      $locations = json_decode($responseJSON, true);
      if (json_last_error() !== JSON_ERROR_NONE) {
        throw new \RuntimeException('JSON Sedi Dipartimento malformato: ' . json_last_error_msg());
      }
      $report = [];
      $mainLocations = [];

      //Restituisce un JSON con tutte le sedi alberate sullo stesso livello
      foreach ($locations as $location) {
        $mainLocations = array_merge($mainLocations, $this->getDepartmentLocations($location));
      }

      //Creo, aggiorno, rimuovo le tassonomie per ogni sede
      if (!empty($mainLocations)) {
        $report = $this->manageLocations($mainLocations);
        $storedTerms = UtilsController::getAllFieldsFromVocabulary(self::VID, "field_location_id");
        $report["updatedLocationsDetails"] = $this->updateLocationsInfo($storedTerms);
      }
      return new JsonResponse(['report' => $report]);
    } catch (\Exception $exception) {
      \Drupal::logger(self::LOGGER_CHANNEL)->error($exception->getMessage());
      return new JsonResponse(['error' => $exception->getMessage()], 500);
    }
  }

  /**
   * Updates taxonomy terms with the latest location details from the API.
   *
   * For each provided location ID, this method calls the `GetInfoSede` API
   * to retrieve updated details (email, phone, address) and synchronizes them
   * with the corresponding taxonomy term.
   *
   * @param array<string> $locationIds
   *   A list of location IDs to process. Non-string IDs are skipped.
   *
   * @return int
   *   The number of taxonomy terms successfully updated.
   *
   * @throws \JsonException
   *   If the API response cannot be decoded into valid JSON
   *   (only when using `JSON_THROW_ON_ERROR` with `json_decode`).
   */
  private function updateLocationsInfo(array $locationIds): int {
    $updated = 0;
    foreach ($locationIds as $locationId) {
      if (!is_string($locationId)) {
        continue;
      }
      $responseJSON = UtilsController::fetchAPI(self::API_INFO_SEDE . $locationId, "GET", []);
      $locationDetails = json_decode($responseJSON, true);
      $termValues = [
        "field_email" => $locationDetails['email'] ?? null,
        "field_phone" => $locationDetails['telefono'] ?? null,
        "field_location_address" => isset($locationDetails['indirizzo']) &&
        isset($locationDetails['cap']) &&
        isset($locationDetails['comune']) &&
        isset($locationDetails['provincia'])
          ? $locationDetails['indirizzo'] . "\n" . $locationDetails['cap'] . "\n" . $locationDetails['comune'] . " " . $locationDetails['provincia']
          : null,
      ];

      //Controllo che esista un termine di tassonomia che abbia come field_id  l'id dell'item iterato
      $termId = UtilsController::getTaxonomyIDByVocabularyAndField(self::VID, [
        "machine_name" => "field_location_id",
        "value" => $locationId,
      ]);

      if ($termId) {
        $updates = false;
        $term = Term::load($termId);
        if ($term) {
          foreach ($termValues as $key => $value) {
            if ($term->get($key)->value != $value) {
              if ($key === "field_location_address") {
                $value = [
                  "value" => $value,
                  "format" => "plain_text",
                ];
              }
              $term->set($key, $value);
              $updates = true;
            }
          }
          if ($updates) {
            $term->save();
            $updated++;
          }
        }
      }
    }
    return $updated;
  }

  /**
   * Flattens a hierarchical location structure into a single-level array of main locations.
   *
   * This method recursively processes a location array and its child locations
   * Each valid location is returned as an associative array.
   *
   * @param array $location
   *   The location data array, potentially containing child locations in 'sediChild'.
   *
   * @return array
   *   A flat array of filtered locations including all nested child locations.
   */
  private function getDepartmentLocations(array $location): array {
    $result = [];
    if (
      !in_array($location['tipo'] ?? '', ['COM', 'DST']) &&
      !str_contains($location['codAOO'], 'DIR-') &&
      !str_contains($location['descrizione'], 'COMANDO')
    ) {
      $result[] = [
        'id' => $location['id'] ?? null,
        'codice' => $location['codice'] ?? null,
        'descrizione' => $location['descrizione'] ?? null,
        'tipo' => $location['tipo'] ?? null,
        'idSedePadre' => $location['idSedePadre'] ?? null,
        'codAOO' => $location['codAOO'] ?? null,
      ];
    }
    if (!empty($location['sediChild'])) {
      foreach ($location['sediChild'] as $child) {
        $result = array_merge($result, $this->getDepartmentLocations($child));
      }
    }
    return $result;
  }

  /**
   * Manages the import and update of locations as taxonomy terms.
   *
   * This method compares the provided list of main locations with the existing
   * terms in the specified taxonomy. For each location:
   *   - If the term does not exist, it is created.
   *   - If the term exists but has different values, it is updated.
   *   - If a parent location is provided, it is set.
   *
   * At the end, terms that do not correspond to any location received from the API
   * are deleted.
   *
   * @param array $mainLocations
   *   Array of locations to import.
   *
   * @return array
   *   Associative array with the count of performed operations:
   *
   * @throws \Exception
   *   Any exceptions during creation, update, or deletion of terms are caught
   *   and logged; they do not interrupt the import process.
   */
  private function manageLocations(array $mainLocations): array {
    $storedTerms = UtilsController::getAllFieldsFromVocabulary(self::VID, "field_location_id");
    $created = 0;
    $deleted = 0;
    $updated = 0;
    $locationIDs = [];
    foreach ($mainLocations as $location) {
      $locationId = $location['id'] ?? null;
      $locationName = $location['descrizione'] ?? null;
      if (!$locationId || !$locationName) {
        \Drupal::logger(self::LOGGER_CHANNEL)->warning(
          "Import Sede fallito: ID o Nome sede non valorizzati.\nDati sede: @location", [
            '@location' => PHP_EOL . print_r($location, true),
          ]
        );
        continue;
      }
      $locationIDs[] = $locationId;
      $termValues = [
        "name" => $locationName,
        "field_location_code" => $location['codice'] ?? null,
        "field_location_aoo_code" => $location['codAOO'] ?? null,
        "field_location_id" => $location['id'] ?? null,
        "field_location_parent_id" => $location['idSedePadre'] ?? null,
        "field_location_type" => $location['tipo'] ?? null,
        "vid" => self::VID,
      ];

      $termId = 0;
      try {
        $termId = UtilsController::getTaxonomyIDByVocabularyAndField(self::VID, [
          "machine_name" => "field_location_id",
          "value" => $locationId,
        ]);
        if (!$termId) {
          $term = Term::create($termValues);
          $term->save();
          $created++;
          \Drupal::service('pathauto.generator')->updateEntityAlias($term, 'update');
          \Drupal::logger(self::LOGGER_CHANNEL)->notice(
            "Creata Sede @locationId.", [
              '@locationId' => $locationId
            ]
          );
        } else {
          $updates = false;
          $term = Term::load($termId);
          if ($term) {
            foreach ($termValues as $key => $value) {
              if (in_array($key, ["vid", "field_location_id"]))
                continue;
              $currentValue = $term->get($key)->value ?? null;
              if ($currentValue != $value) {
                $term->set($key, $value);
                $updates = true;
              }
            }
            if ($updates) {
              $term->save();
              $updated++;
              \Drupal::service('pathauto.generator')->updateEntityAlias($term, 'update');
              \Drupal::logger(self::LOGGER_CHANNEL)->notice(
                "Aggiornata Sede @locationId.", [
                  '@locationId' => $locationId
                ]
              );
            }
          }
        }
        $termId = $term->id();
      } catch (\Exception $e) {
        \Drupal::logger(self::LOGGER_CHANNEL)->error(
          "Errore nell'importazione Sede @locationId - " . $e->getMessage(), [
            '@locationId' => $locationId
          ]
        );
      }

      try {
        $parent = null;
        $parentLocationTmp = (bool)$location['idSedePadre'];
        if ($termId > 0 && $parentLocationTmp) {
          $parentLocationTmpId = $location['idSedePadre'];
          $parent = UtilsController::getTaxonomyIDByVocabularyAndField(self::VID, [
            "machine_name" => "field_location_id",
            "value" => $location['idSedePadre'],
          ]);
          if ($parent) {
            $term = Term::load($termId);
            if ($term) {
              $termParents = $term->get("parent")->getValue();

//              dd(
//                isset($termParents[0]['target_id']),
//                $termParents[0]['target_id'],
//                $parent,
//                isset($termParents[0]['target_id']),
//                $termParents[0]['target_id'] !== "0",
//                $termParents[0]['target_id'],
//                $termId,
//                $parent,
//              );


              if (
                isset($termParents[0]['target_id']) &&
                $termParents[0]['target_id'] != $parent
              ) {
                $term->set('parent', [$parent]);;
                $term->save();
                \Drupal::service('pathauto.generator')->updateEntityAlias($term, 'update');
                \Drupal::logger(self::LOGGER_CHANNEL)->notice(
                  "Aggiornato termine parent della Sede @locationId con nuovo valore parent @parent.", [
                    '@locationId' => $termId,
                    '@parent' => $parent
                  ]
                );
              }
            }
          }
        }
      } catch (\Exception $e) {
        \Drupal::logger(self::LOGGER_CHANNEL)->error(
          "Errore nel settaggio Sede parent per la sede @locationId - ID Tassonomia @termId - ID Parent @parent.", [
            '@locationId' => $locationId,
            '@termId' => $term->id(),
            '@parent' => $parent,
          ]
        );
      }
    }

    $toDelete = array_diff($storedTerms, $locationIDs);
    foreach ($toDelete as $locationId) {
      try {
        $termId = UtilsController::getTaxonomyIDByVocabularyAndField(self::VID, [
          "machine_name" => "field_location_id",
          "value" => (string)$locationId,
        ]);
        if ($termId) {
          UtilsController::deleteTaxonomies(self::VID, $termId);
          $deleted++;
          \Drupal::logger(self::LOGGER_CHANNEL)->notice('Eliminata Sede @id.', ['@id' => $locationId]);
        }
      } catch (\Exception $e) {
        \Drupal::logger(self::LOGGER_CHANNEL)->error(
          "Errore nella cancellazione Sede @id - " . $e->getMessage(), [
            '@id' => $locationId
          ]
        );
      }
    }

    return [
      "created" => $created,
      "deleted" => $deleted,
      "updated" => $updated,
    ];
  }
}
