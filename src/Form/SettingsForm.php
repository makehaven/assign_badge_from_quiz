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
      '#markup' => $this->t(
        '<p>This message appears above the normal quiz results (the normal results are not suppressed).</p>' .
        '<p>It only shows when:<ul>' .
        '<li>The quiz type is enabled below,</li>' .
        '<li>The quiz node has the required taxonomy reference (the same field used by the badge assignment logic), and</li>' .
        '<li>A badge is present/assigned for that quiz.</li></ul></p>' .
        '<p>If these conditions are not met, only the standard quiz results appear.</p>' .
        '<p><strong>Available tokens:</strong><br>' .
        '[quiz:nid], [quiz:title], [quiz:type], [user:uid], [user:display_name], [badge:nid], [badge:title], [site:base_url]<br>' .
        '(Missing tokens resolve to empty.)</p>'
      ),
    ];

    $types = ['badge_quiz' => 'badge_quiz', 'quiz' => 'quiz'];

    $form['enabled_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Show custom message for these quiz types'),
      '#options' => $types,
      '#default_value' => $enabled,
    ];

    foreach ($types as $type_key => $label) {
      $template = $templates[$type_key] ?? [];
      $form['html_templates_'.$type_key] = [
        '#type' => 'text_format',
        '#title' => $this->t('HTML for @type', ['@type' => $type_key]),
        '#format' => $template['format'] ?? 'full_html',
        '#default_value' => $template['value'] ?? '',
        '#states' => [
          'visible' => [
            ':input[name="enabled_types['.$type_key.']"]' => ['checked' => TRUE],
          ],
        ],
      ];
    }

    $form['show_badge_details'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Display detailed badge information (including facilitator schedule) below the custom message.'),
        '#default_value' => $cfg->get('show_badge_details'),
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
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

    $this->config('assign_badge_from_quiz.settings')
      ->set('enabled_types', $enabled)
      ->set('html_templates', $templates)
      ->set('show_badge_details', (bool)$form_state->getValue('show_badge_details'))
      ->save();

    parent::submitForm($form, $form_state);
  }
}
