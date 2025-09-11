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
    $show_details = $cfg->get('show_badge_details') ?: [];
    $failure_template = $cfg->get('failure_template') ?: [];

    $form['help'] = [
      '#type' => 'item',
      '#markup' => $this->t(
        '<p>This message appears above the normal quiz results (the normal results are not suppressed).</p>' .
        '<p>It only shows when:<ul>' .
        '<li>The quiz type is enabled below (for success messages),</li>' .
        '<li>The quiz node has the required taxonomy reference (the same field used by the badge assignment logic), and</li>' .
        '<li>A badge is present/assigned for that quiz.</li></ul></p>' .
        '<p>If these conditions are not met, only the standard quiz results appear.</p>' .
        '<p><strong>Available tokens:</strong><br>' .
        '[quiz:nid], [quiz:title], [quiz:type], [user:uid], [user:display_name], [badge:nid], [badge:title], [badge:url], [site:base_url], [badge:checklist_url], [badge:checkout_minutes]<br>' .
        '<strong>Conditional tokens (for success messages):</strong><br>' .
        '[if:badge_requires_checkout]...[/if:badge_requires_checkout]<br>' .
        '[if:badge_is_earned]...[/if:badge_is_earned]<br>' .
        '[if:badge_has_checklist]...[/if:badge_has_checklist]<br>' .
        '(Missing tokens resolve to empty.)</p>'
      ),
    ];

    $types = ['badge_quiz' => 'badge_quiz', 'quiz' => 'quiz'];

    $form['success_message_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Success Message Settings'),
      '#open' => TRUE,
    ];

    $form['success_message_settings']['enabled_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Show custom success message for these quiz types'),
      '#options' => $types,
      '#default_value' => $enabled,
    ];

    $default_template =
      '<h2 style="color:#4CAF50;">Congratulations! You passed the quiz!</h2>' .
      '[if:badge_requires_checkout]' .
      '<p>The next step is to complete a practical checkout with a facilitator to earn the <strong>[badge:title]</strong> badge.</p><hr>' .
      '[if:badge_has_checklist]' .
      '<a href="[badge:checklist_url]" class="btn btn-info" target="_blank" style="margin-right: 10px;">View Checkout Checklist</a>' .
      '[/if:badge_has_checklist]' .
      '<p style="display: inline-block; margin-left: 15px;"><strong>Estimated time:</strong> [badge:checkout_minutes] minutes</p>' .
      '[/if:badge_requires_checkout]' .
      '[if:badge_is_earned]' .
      '<p>You have earned the <strong>[badge:title]</strong> badge and can now use the associated tools.</p><hr>' .
      '[/if:badge_is_earned]' .
      '<a href="[badge:url]" class="btn btn-light" style="margin-right: 10px;">Return to [badge:title] Page</a>';

    foreach ($types as $type_key => $label) {
      $template = $templates[$type_key] ?? [];
      $form['success_message_settings']['html_templates_'.$type_key] = [
        '#type' => 'text_format',
        '#title' => $this->t('HTML for @type', ['@type' => $type_key]),
        '#format' => $template['format'] ?? 'full_html',
        '#default_value' => $template['value'] ?? $default_template,
        '#states' => [
          'visible' => [
            ':input[name="enabled_types['.$type_key.']"]' => ['checked' => TRUE],
          ],
        ],
      ];
    }

    $form['success_message_settings']['show_badge_details'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('For which quiz types should we show the facilitator schedule?'),
        '#description' => $this->t('This is only applicable for badges that require checkout.'),
        '#options' => $types,
        '#default_value' => $show_details,
    ];

    $form['failure_message_settings'] = [
        '#type' => 'details',
        '#title' => $this->t('Failure Message Settings'),
        '#open' => TRUE,
    ];

    $default_failure_template = '<h3>You must have 100% correct to pass.</h3>' .
        '<p>You did not pass the [quiz:title] quiz.</p>' .
        '<p>Please review the materials for the <strong>[badge:title]</strong> badge and try again.</p>' .
        '<a href="[badge:url]" class="btn btn-primary">Review Materials</a>';

    $form['failure_message_settings']['failure_template'] = [
        '#type' => 'text_format',
        '#title' => $this->t('Custom message for failed quizzes'),
        '#description' => $this->t('This message will be shown if a user does not get a score of 100%. All tokens are available.'),
        '#format' => $failure_template['format'] ?? 'full_html',
        '#default_value' => $failure_template['value'] ?? $default_failure_template,
    ];


    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $enabled_raw = $form_state->getValue('enabled_types') ?? [];
    $enabled = array_values(array_filter(array_map(function($k,$v){ return $v ? $k : NULL; }, array_keys($enabled_raw), $enabled_raw)));

    $show_details_raw = $form_state->getValue('show_badge_details') ?? [];
    $show_details = array_values(array_filter(array_map(function($k,$v){ return $v ? $k : NULL; }, array_keys($show_details_raw), $show_details_raw)));

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

    $failure_template_val = $form_state->getValue('failure_template');
    $failure_template = [];
    if (isset($failure_template_val['value']) && $failure_template_val['value'] !== '') {
        $failure_template = [
          'value' => $failure_template_val['value'],
          'format' => $failure_template_val['format'],
        ];
    }

    $this->config('assign_badge_from_quiz.settings')
      ->set('enabled_types', $enabled)
      ->set('html_templates', $templates)
      ->set('show_badge_details', $show_details)
      ->set('failure_template', $failure_template)
      ->save();

    parent::submitForm($form, $form_state);
  }
}
