<?php

namespace Drupal\assign_badge_from_quiz\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

final class SettingsForm extends ConfigFormBase {
  public function getFormId(): string { return 'assign_badge_from_quiz_settings_form'; }
  protected function getEditableConfigNames(): array { return ['assign_badge_from_quiz.settings']; }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $cfg = $this->config('assign_badge_from_quiz.settings');
    $enabled = $cfg->get('enabled_types') ?: [];
    $templates = $cfg->get('html_templates') ?: [];

    $form['help'] = [
      '#type' => 'item',
      '#markup' => $this->t('<p>This message appears <strong>above</strong> the normal quiz results only when the quiz node has the required taxonomy reference (same field used by the badge assignment logic) and a badge is present. If those conditions are not met, only the normal results appear.</p><p><strong>Tokens:</strong> [quiz:nid], [quiz:title], [quiz:type], [user:uid], [user:display_name], [badge:nid], [badge:title], [site:base_url]</p>'),
    ];

    $types = ['badge_quiz' => 'badge_quiz', 'quiz' => 'quiz'];

    $form['enabled_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Show custom message for these quiz types'),
      '#options' => $types,
      '#default_value' => array_combine($enabled, $enabled),
    ];

    foreach ($types as $type_key => $label) {
      $visible = in_array($type_key, $enabled, TRUE) || ($form_state->getValue(['enabled_types', $type_key]) === $type_key);
      $form['html_templates_'.$type_key] = [
        '#type' => 'text_format',
        '#title' => $this->t('HTML for @type', ['@type' => $type_key]),
        '#format' => 'full_html',
        '#default_value' => $templates[$type_key]['value'] ?? '',
        '#states' => [
          'visible' => [
            ':input[name="enabled_types['.$type_key.']"]' => ['checked' => TRUE],
          ],
        ],
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    parent::submitForm($form, $form_state);

    $enabled_raw = $form_state->getValue('enabled_types') ?? [];
    $enabled = array_values(array_filter(array_map(function($k,$v){ return $v ? $k : NULL; }, array_keys($enabled_raw), $enabled_raw)));

    $templates = [];
    foreach (['badge_quiz','quiz'] as $t) {
      $val = $form_state->getValue('html_templates_'.$t);
      if (isset($val['value']) && $val['value'] !== '') {
        $templates[$t] = [
          'value' => $val['value'],
          'format' => $val['format'],
        ];
      }
    }

    $this->configFactory()->getEditable('assign_badge_from_quiz.settings')
      ->set('enabled_types', $enabled)
      ->set('html_templates', $templates)
      ->save();
  }
}
