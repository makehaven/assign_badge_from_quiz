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

    $template_data = $templates[$type] ?? [];
    $tpl = $template_data['value'] ?? '';
    if ($tpl === '') return NULL;

    $r = [
      '[quiz:nid]' => htmlspecialchars((string) ($ctx['quiz_nid'] ?? ''), ENT_QUOTES, 'UTF-8'),
      '[quiz:title]' => htmlspecialchars((string) ($ctx['quiz_title'] ?? ''), ENT_QUOTES, 'UTF-8'),
      '[quiz:type]' => htmlspecialchars($type, ENT_QUOTES, 'UTF-8'),
      '[user:uid]' => htmlspecialchars((string) ($ctx['user']['uid'] ?? ''), ENT_QUOTES, 'UTF-8'),
      '[user:display_name]' => htmlspecialchars((string) ($ctx['user']['display_name'] ?? ''), ENT_QUOTES, 'UTF-8'),
      '[badge:nid]' => htmlspecialchars((string) ($ctx['badge']['nid'] ?? ''), ENT_QUOTES, 'UTF-8'),
      '[badge:title]' => htmlspecialchars((string) ($ctx['badge']['title'] ?? ''), ENT_QUOTES, 'UTF-8'),
      '[site:base_url]' => htmlspecialchars((string) ($ctx['base_url'] ?? ''), ENT_QUOTES, 'UTF-8'),
    ];

    $html = strtr($tpl, $r);
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['post-quiz-message']],
      'content' => ['#markup' => Markup::create($html)],
      '#weight' => -100, // ensure it shows above normal results
    ];
  }

  public static function trustedCallbacks() {
    return ['build'];
  }
}
