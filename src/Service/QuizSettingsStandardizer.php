<?php

namespace Drupal\assign_badge_from_quiz\Service;

use Drupal\Core\Database\Connection;
use Drupal\quiz\Entity\Quiz;

/**
 * Builds and applies the established badge quiz settings standard.
 */
class QuizSettingsStandardizer {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a new QuizSettingsStandardizer.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * Returns quiz setting fields managed by the standard.
   */
  public function getSettingsFields(): array {
    return [
      'pass_rate',
      'allow_resume',
      'allow_skipping',
      'backwards_navigation',
      'repeat_until_correct',
      'show_passed',
      'allow_jumping',
      'allow_change',
      'allow_change_blank',
      'show_attempt_stats',
      'mark_doubtful',
    ];
  }

  /**
   * Derives standard settings from existing badge quizzes.
   *
   * Uses mode for pass_rate and majority value for booleans.
   */
  public function deriveSettingsStandard(): array {
    $fields = $this->getSettingsFields();
    $select_fields = implode(', ', array_map(static fn(string $field): string => "q.$field", $fields));
    $query = "SELECT $select_fields FROM {quiz} q WHERE q.type = 'badge_quiz'";
    $results = $this->database->query($query)->fetchAll();

    return $this->deriveSettingsStandardFromRows($results, $fields);
  }

  /**
   * Derives settings standard from a list of quiz rows.
   */
  public function deriveSettingsStandardFromRows(array $results, array $settings_fields): array {
    if (empty($results)) {
      return [];
    }

    $standard = [];
    foreach ($settings_fields as $field_name) {
      if ($field_name === 'pass_rate') {
        $counts = [];
        foreach ($results as $result) {
          $value = (int) ($result->{$field_name} ?? 0);
          $counts[$value] = ($counts[$value] ?? 0) + 1;
        }
        arsort($counts);
        $standard[$field_name] = (int) array_key_first($counts);
        continue;
      }

      $true_count = 0;
      foreach ($results as $result) {
        $true_count += ((bool) ($result->{$field_name} ?? FALSE)) ? 1 : 0;
      }
      $false_count = count($results) - $true_count;
      $standard[$field_name] = $true_count >= $false_count;
    }

    return $standard;
  }

  /**
   * Returns mismatched settings for a quiz against the current standard.
   */
  public function getMismatches(Quiz $quiz, ?array $standard = NULL): array {
    $standard = $standard ?? $this->deriveSettingsStandard();
    $mismatches = [];

    foreach ($standard as $setting_name => $expected_value) {
      $actual_value = $this->normalizeSettingValue($setting_name, $quiz->get($setting_name)->value ?? NULL);
      if ($actual_value !== $expected_value) {
        $mismatches[$setting_name] = [
          'actual' => $actual_value,
          'expected' => $expected_value,
        ];
      }
    }

    return $mismatches;
  }

  /**
   * Applies current standard settings to the provided quiz.
   *
   * Returns the changed settings.
   */
  public function applyStandard(Quiz $quiz, ?array $standard = NULL): array {
    $standard = $standard ?? $this->deriveSettingsStandard();
    $changes = [];

    foreach ($standard as $setting_name => $expected_value) {
      $actual_value = $this->normalizeSettingValue($setting_name, $quiz->get($setting_name)->value ?? NULL);
      if ($actual_value !== $expected_value) {
        $quiz->set($setting_name, $expected_value);
        $changes[$setting_name] = [
          'from' => $actual_value,
          'to' => $expected_value,
        ];
      }
    }

    if (!empty($changes)) {
      $quiz->save();
    }

    return $changes;
  }

  /**
   * Normalizes quiz setting values for stable comparison.
   */
  public function normalizeSettingValue(string $setting_name, $value) {
    if ($setting_name === 'pass_rate') {
      return (int) $value;
    }
    return (bool) $value;
  }

}
