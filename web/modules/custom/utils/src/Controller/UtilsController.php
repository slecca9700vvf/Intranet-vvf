<?php
namespace Drupal\utils\Controller;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\user\Entity\User;
use Drupal\views\Views;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

/**
* Provides a Controller for the Utils module.
*/
class UtilsController extends ControllerBase {

  /**
  * Labels used in the modules.
  */
  const REQUEST_GET = "GET";
  const REQUEST_POST = "POST";

  /**
  * This method allows you to delete all nodes of a specified Content Type
  *
  * @param String $content_type The node Content Type.
  * @param String $field A specific field to filter.
  * @param String $value The value of the specified filter.
  *
  * @return Boolean Returns true if at least one node is deleted else return false
  *
  */
  public static function deleteNodes(string $content_type, string $field = NULL, string $value = NULL) {
    $check = FALSE;
    $query = \Drupal::entityQuery('node')
      ->accessCheck(false)
      ->condition('type', $content_type);

    if ($field !== NULL && $value !== NULL) {
      $query->condition($field, $value);
    }

    $nodes = $query->execute();
    foreach ($nodes as $nid) {
      $check = TRUE;
      $node = Node::load($nid);
      $node ? $node->delete() : "";
    }
    return $check;
  }

  /**
  * Delete taxonomy terms of a specified Vocabulary, optionally filtering by ID.
  *
  * @param string $vid
  *   The machine name of the vocabulary.
  * @param int|null $tid
  *   (Optional) The term ID to delete. If null, all terms in the vocabulary will be deleted.
  *
  * @return bool
  *   TRUE if at least one term was deleted, FALSE otherwise.
  */
  public static function deleteTaxonomies(string $vid, ?int $tid = null): bool {
    $query = \Drupal::entityQuery('taxonomy_term')
      ->accessCheck(false)
      ->condition('vid', $vid);
    if ($tid !== NULL) {
      $query->condition('tid', $tid);
    }

    $terms = $query->execute();
    if (!empty($terms)) {
      \Drupal::entityTypeManager()->getStorage('taxonomy_term')->delete(Term::loadMultiple($terms));
    }
    return !empty($terms);
  }


  /**
  * This method allows you to get the term ID of a specified Taxonomy Vocabulary
  *
  * @param String $vid Taxonomy vocabulary.
  * @param array $field Array with field and value to match.
  *
  * @return Integer|Null Taxonomy ID
  *
  */
  public static function getTaxonomyIDByVocabularyAndField(string $vid, array $field): ?int {
    $termID = NULL;
    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['vid' => $vid, $field["machine_name"] => $field["value"]]);
    foreach ($terms as $term) {
      $termID = $term->id();
    }
    return $termID;
  }

  /**
   * Gets all values of a specific field in a vocabulary.
   *
   * @param string $vid
   *   The vocabulary ID (machine_name).
   * @param string $field_name
   *   The field machine name whose values will be retrieved.
   *
   * @return array
   *   A list of found values for the specific field.
   */
  public static function getAllFieldsFromVocabulary(string $vid, string $field_name): array {
    $list = [];
    $query = \Drupal::entityQuery('taxonomy_term')
      ->accessCheck(false)
      ->condition('vid', $vid);
    $tids = $query->execute();
    if(!empty($tids)) {
      $terms = Term::loadMultiple($tids);
      foreach ($terms as $term) {
        if ($term->hasField($field_name) && !$term->get($field_name)->isEmpty()) {
          $value = $term->get($field_name)->value;
          $list[] = $value;
        }
      }
    }
    return array_values($list);
  }

  /**
  * This method allows you to get a specific Field by TaxonomyID
  *
  * @param String $tid Taxonomy vocabulary.
  * @param String $field Array with field and value to match.
  *
  * @return Integer|Null Taxonomy ID
  *
  */
  public static function getFieldByTaxonomyID(string $tid, string $field): ?int {
    $field_value = NULL;
    $term = Term::load($tid);
    if($term) {
      $field_value = $term->get($field)->value;
    }
    return $field_value;
  }

  /**
  * This method allows you to send requests to an API
  *
  * @param String $url API Url.
  * @param String $method The API type. It can be GET or POST.
  * @param array $data API params for POST calls.
  *
  * @return String|null The API Response.
  *
  */
  public static function fetchAPI(string $url, string $method, array $data = []): ?string {
    $response = [];
    $curl = curl_init($url);
    curl_setopt_array($curl, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'Content-Type: application/json',
      ],
      CURLOPT_SSL_VERIFYHOST => 0, // SOLO PER TEST
      CURLOPT_SSL_VERIFYPEER => 0, // SOLO PER TEST
    ]);
    if ($method === 'GET') {
      curl_setopt($curl, CURLOPT_URL, $url);
    } elseif ($method === 'POST') {
      curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
      ]);
    } else {
      throw new \InvalidArgumentException("Metodo HTTP non supportato: $method");
    }
    $response = curl_exec($curl);
    if (curl_errno($curl)) {
      \Drupal::logger('utils_controller')->error('Errore cURL: @error', ['@error' => curl_error($curl)]);
      curl_close($curl);
      return null;
    }
    curl_close($curl);
    return $response;
  }

  /**
  * This method allows you to load the results of a Drupal View
  *
  * @param String $view_id View ID machine_name.
  * @param String $view_display View DISPLAY machine_name.
  * @param array $args Arguments to pass to the view.
  * @param array $filters Arguments to pass to the view.
  *
  * @return array $response View Results.
  */
  public static function loadView(string $view_id, string $view_display, array $args = [], array $filters = []): array {
    $view = Views::getView($view_id, $view_display, $args);
    !empty($args) ? $view->setArguments($args) : '';
    !empty($filters) && is_array($filters) ? $view->setExposedInput($filters) : '';
    $view->setDisplay($view_display);
    $view->execute();
    return !empty($view->result) ? $view->result : [];
  }

  /**
  * This method allows to check if a specific entity exists in Drupal
  *
  * @param String $entity_type The entity type machine_name.
  * @param array $field Specific entity field to match.
  *
  * @return Integer|null $entity The entity ID or FALSE if entity doesn't exists.
  *
  */
  public static function checkIfEntityExists(string $entity_type, array $field): int|null {
    $entities = \Drupal::entityQuery($entity_type)
    ->accessCheck(TRUE)
    ->range(0, 1)
    ->condition($field["machine_name"], $field["value"])
    ->execute();

    foreach($entities as $entity) {
      return $entity;
    }
    return FALSE;
  }

  /**
  * This method allows to load the roles of a specific user.
  *
  * @param Integer $uid User ID.
  *
  * @return array $user The user's roles.
  *
  */
  public static function getUserRoles(int $uid): array {
    $user = User::load($uid);
    return $user ? $user->getRoles() : [];
  }

  /**
  * This method allows you to get the current language.
  *
  * @return string
  *   Return current language ID.
  */
  function getLangcode(): string {
    return \Drupal::languageManager()->getCurrentLanguage()->getId();
  }
}
