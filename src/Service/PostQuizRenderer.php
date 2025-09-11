<?php

namespace Drupal\assign_badge_from_quiz\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Security\TrustedCallbackInterface;

final class PostQuizRenderer implements TrustedCallbackInterface {
  public function __construct(private readonly ConfigFactoryInterface $cfg) {}

  public function build(array $ctx): ?array {
    $conf = $this->cfg->get('assign_badge_from_quiz.settings');
    $enabled = $conf->get('enabled_types') ?: [];
    $templates = $conf->get('html_templates') ?: [];

    $type = (string) ($ctx['quiz_type'] ?? '');
    if (!$type || !in_array($type, $enabled, TRUE)) return NULL;

    // Keep existing "related term present" logic via caller:
    if (empty($ctx['has_related_term'])) return NULL;

    // Require badge.
    if (empty($ctx['badge'])) return NULL;

    $tplRaw = $templates[$type] ?? '';
    $tpl = is_array($tplRaw) ? (string) ($tplRaw['value'] ?? '') : (string) $tplRaw;
    if ($tpl === '') return NULL;

    $r = [
      '[quiz:nid]' => htmlspecialchars((string) ($ctx['quiz_nid'] ?? ''), ENT_QUOTES, 'UTF-8'),
      '[quiz:title]' => htmlspecialchars((string) ($ctx['quiz_title'] ?? ''), ENT_QUOTES, 'UTF-8'),
      '[quiz:type]' => htmlspecialchars($type, ENT_QUOTES, 'UTF-8'),
      '[user:uid]' => htmlspecialchars((string) ($ctx['user']['uid'] ?? ''), ENT_QUOTES, 'UTF-8'),
      '[user:display_name]' => htmlspecialchars((string) ($ctx['user']['display_name'] ?? ''), ENT_QUOTES, 'UTF-8'),
      '[badge:nid]' => htmlspecialchars((string) ($ctx['badge']['nid'] ?? ''), ENT_QUOTES, 'UTF-8'),
      '[badge:title]' => htmlspecialchars((string) ($ctx['badge']['title'] ?? ''), ENT_QUOTES, 'UTF-8'),
      '[badge:url]' => htmlspecialchars((string) ($ctx['badge']['url'] ?? ''), ENT_QUOTES, 'UTF-8'),
      '[site:base_url]' => htmlspecialchars((string) ($ctx['base_url'] ?? ''), ENT_QUOTES, 'UTF-8'),
      '[badge:checklist_url]' => htmlspecialchars((string) ($ctx['badge']['checklist_url'] ?? ''), ENT_QUOTES, 'UTF-8'),
      '[badge:checkout_minutes]' => htmlspecialchars((string) ($ctx['badge']['checkout_minutes'] ?? ''), ENT_QUOTES, 'UTF-8'),
    ];

    $html = strtr($tpl, $r);
    $html = $this->processConditionalTokens($html, $ctx);

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['post-quiz-message']],
      'content' => ['#markup' => Markup::create($html)],
      '#weight' => -100, // ensure it shows above normal results
    ];
  }

  private function processConditionalTokens(string $html, array $ctx): string {
    $checkout_req = $ctx['badge']['checkout_requirement'] ?? 'no';
    $has_checklist = $ctx['badge']['has_checklist'] ?? FALSE;

    $logic = [
      'badge_requires_checkout' => in_array($checkout_req, ['yes', 'class']),
      'badge_is_earned' => $checkout_req === 'no',
      'badge_has_checklist' => $has_checklist,
    ];

    $previous_html = '';
    while ($html !== $previous_html) {
        $previous_html = $html;
        $html = preg_replace_callback(
            '/\[if:(\w+)\](.*?)\[\/if:\1\]/s',
            function ($matches) use ($logic) {
                $key = $matches[1];
                $content = $matches[2];
                return $logic[$key] ?? FALSE ? $content : '';
            },
            $html
        );
    }

    return $html;
  }

  public function buildFailure(array $ctx): ?array {
    $conf = $this->cfg->get('assign_badge_from_quiz.settings');
    $template = $conf->get('failure_template') ?: [];
    if (empty($template['value'])) return NULL;

    // Keep existing "related term present" logic via caller:
    if (empty($ctx['has_related_term'])) return NULL;

    // Require badge.
    if (empty($ctx['badge'])) return NULL;

    $tpl = (string) $template['value'];

    $r = [
      '[quiz:nid]' => htmlspecialchars((string) ($ctx['quiz_nid'] ?? ''), ENT_QUOTES, 'UTF-8'),
      '[quiz:title]' => htmlspecialchars((string) ($ctx['quiz_title'] ?? ''), ENT_QUOTES, 'UTF-8'),
      '[quiz:type]' => htmlspecialchars((string) ($ctx['quiz_type'] ?? ''), ENT_QUOTES, 'UTF-8'),
      '[user:uid]' => htmlspecialchars((string) ($ctx['user']['uid'] ?? ''), ENT_QUOTES, 'UTF-8'),
      '[user:display_name]' => htmlspecialchars((string) ($ctx['user']['display_name'] ?? ''), ENT_QUOTES, 'UTF-8'),
      '[badge:nid]' => htmlspecialchars((string) ($ctx['badge']['nid'] ?? ''), ENT_QUOTES, 'UTF-8'),
      '[badge:title]' => htmlspecialchars((string) ($ctx['badge']['title'] ?? ''), ENT_QUOTES, 'UTF-8'),
      '[badge:url]' => htmlspecialchars((string) ($ctx['badge']['url'] ?? ''), ENT_QUOTES, 'UTF-8'),
      '[site:base_url]' => htmlspecialchars((string) ($ctx['base_url'] ?? ''), ENT_QUOTES, 'UTF-8'),
      '[badge:checklist_url]' => htmlspecialchars((string) ($ctx['badge']['checklist_url'] ?? ''), ENT_QUOTES, 'UTF-8'),
      '[badge:checkout_minutes]' => htmlspecialchars((string) ($ctx['badge']['checkout_minutes'] ?? ''), ENT_QUOTES, 'UTF-8'),
    ];

    $html = strtr($tpl, $r);
    // Failure messages don't have conditionals, so we don't call processConditionalTokens.

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['post-quiz-failure-message']],
      'content' => ['#markup' => Markup::create($html)],
      '#weight' => -100, // ensure it shows above normal results
    ];
  }

  public static function trustedCallbacks() {
    return ['build', 'buildFailure'];
  }
}
