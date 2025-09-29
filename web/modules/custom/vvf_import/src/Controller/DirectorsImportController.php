<?php
namespace Drupal\vvf_import\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\utils\Controller\UtilsController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for importing and synchronizing VVF Directors and Prefects.
 *
 * Fetches data from external APIs and manages corresponding taxonomy terms
 * (create, update, delete) in Drupal.
 */
class DirectorsImportController extends ControllerBase {
  const string API_DIRIGENTI = "https://wauc.dipvvf.it/api/Personale?codiciTipiPersonale=1,8";
  const string LOGGER_CHANNEL = 'vvf_import_directors';
  const string VID = "director_list";

  /**
   * Imports directors from the external API and updates the taxonomy terms.
   *
   * This method fetches director data from the API defined by `self::API_DIRIGENTI`,
   * decodes the JSON response, and synchronizes the data with the Drupal taxonomy
   * identified by `self::VID`. It creates new terms, updates existing ones, and
   * removes any terms that no longer exist in the API data.
   *
   * Errors during the API request or JSON decoding will be caught and logged,
   * returning a JSON response with an error message and HTTP status code 500.
   *
   * @return JsonResponse
   *   A JSON response containing the synchronization report, including the
   *   number of terms created, updated, or deleted. In case of error, returns
   *   a JSON object with an 'error' key.
   *
   * @throws \RuntimeException
   *   If the API is unavailable or the JSON response is invalid.
   */
  public function importDirectors(): JsonResponse {
    try {
      $responseJSON = UtilsController::fetchAPI(self::API_DIRIGENTI, "GET", []);
      if ($responseJSON === false) {
        throw new \RuntimeException('API DIRIGENTI non disponibile.');
      }
      /** @var array $dataDirectors */
      $dataDirectors = json_decode($responseJSON, true);
      if (json_last_error() !== JSON_ERROR_NONE) {
        throw new \RuntimeException('JSON Dirigenti malformato: ' . json_last_error_msg());
      }
      $report = [];

      //Creo, aggiorno, rimuovo le tassonomie per ogni sede e alberatura
      if (!empty($dataDirectors)) {
        $report = $this->manageDirectors($dataDirectors);
      }
      return new JsonResponse(['report' => $report]);
    } catch (\Exception $exception) {
      \Drupal::logger(self::LOGGER_CHANNEL)->error($exception->getMessage());
      return new JsonResponse(['error' => $exception->getMessage()], 500);
    }
  }

  /**
   * Synchronizes director data with the Drupal taxonomy.
   *
   * Creates, updates, or deletes taxonomy terms for directors based on the
   * provided API data.
   *
   * @param array $dataDirectors
   *   An array of directors fetched from the external API.
   *
   * @return array
   *   A report containing the number of terms created, updated, and deleted.
   */
  private function manageDirectors(array $dataDirectors): array {
    $storedDirectors = UtilsController::getAllFieldsFromVocabulary(self::VID, "field_cf");
    $created = 0;
    $deleted = 0;
    $updated = 0;
    $directorCFs = [];
    foreach ($dataDirectors as $director) {
      $cf = $director['codiceFiscale'] ?? null;
      if (!$cf) {
        \Drupal::logger(self::LOGGER_CHANNEL)->warning(
          "Import Dirigente fallito: Codice fiscale vuoto.\nDati dirigente: @director", [
            '@director' => PHP_EOL . print_r($director, true),
          ]
        );
        continue;
      }
      $directorCFs[] = $cf;
      $termValues = [
        "name" => (!empty($director['nome']) && !empty($director['cognome']))
          ? ucwords(strtolower($director['nome'] . " " . $director['cognome']), " \t\r\n\f\v'")
          : null,
        "field_cf" => $cf,
        'field_email' => $director['emailVigilfuoco'] ?? null,
        "field_rank_id" => $director['qualifica']['nome'] ?? null,
        "field_rank_description" => $director['qualifica']['descrizione'] ?? null,
        'field_is_external' => isset($director['tipoPersonale']['codice']) && $director['tipoPersonale']['codice'] !== '1',
        "field_location_id" => $director['sede']['id'] ?? null,
        "vid" => self::VID
      ];

      try {
        $termId = UtilsController::getTaxonomyIDByVocabularyAndField(self::VID, [
          "machine_name" => "field_cf",
          "value" => $cf,
        ]);
        if (!$termId) {
          $term = Term::create($termValues);
          $term->save();
          $created++;
          \Drupal::service('pathauto.generator')->updateEntityAlias($term, 'update');
          \Drupal::logger(self::LOGGER_CHANNEL)->notice(
            "Creato Dirigente @cf.", [
              '@cf' => $cf
            ]
          );
        } else {
          $updates = false;
          $term = Term::load($termId);
          if ($term) {
            foreach ($termValues as $key => $value) {
              if (in_array($key, ["vid", "field_cf"]))
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
                "Aggiornato Dirigente @cf.", [
                  '@cf' => $cf
                ]
              );
            }
          }
        }
      } catch (\Exception $e) {
        \Drupal::logger(self::LOGGER_CHANNEL)->error(
          "Errore nell'importazione Dirigente @cf - " . $e->getMessage(), [
            '@cf' => $cf
          ]
        );
      }
    }

    //Elimino eventuali termini di tassonomia al quale non corrisponde nessun dirigente ricevuto dall'API
    $toDelete = array_diff($storedDirectors, $directorCFs);
    foreach ($toDelete as $cf) {
      try {
        $termId = UtilsController::getTaxonomyIDByVocabularyAndField(self::VID, [
          "machine_name" => "field_cf",
          "value" => (string) $cf,
        ]);
        if ($termId) {
          UtilsController::deleteTaxonomies(self::VID, $termId);
          $deleted++;
          \Drupal::logger(self::LOGGER_CHANNEL)->notice(
            "Eliminato Dirigente @cf.", [
              '@cf' => $cf
            ]
          );
        }
      } catch (\Exception $e) {
        \Drupal::logger(self::LOGGER_CHANNEL)->error(
          "Errore nella cancellazione Dirigente @cf - " . $e->getMessage(), [
            '@cf' => $cf
          ]
        );
      }
    }

    return [
      'created' => $created,
      'updated' => $updated,
      'deleted' => $deleted,
    ];
  }
}
