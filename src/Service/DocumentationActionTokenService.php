<?php

namespace Drupal\assign_badge_from_quiz\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Generates and validates HMAC-signed tokens for one-click email actions.
 *
 * Tokens encode the documentation webform submission ID + action + expiry,
 * signed with a per-site secret stored in module config. This lets the staff
 * review email include direct Approve / Reject links that do not require a
 * Drupal login. The links land on a confirmation page (see
 * DocumentationActionConfirmForm) rather than acting on GET, so an email
 * security scanner that pre-fetches the link cannot silently approve a
 * submission.
 *
 * Mirrors makerspace_material_store's ReorderActionTokenService.
 */
class DocumentationActionTokenService {

  public const ACTIONS = ['approve', 'reject'];

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
  ) {}

  /**
   * Generate a signed token. Returns base64url(payload).base64url(signature).
   */
  public function generate(int $submission_id, string $action, int $ttl_seconds = 2592000): string {
    if (!in_array($action, self::ACTIONS, TRUE)) {
      throw new \InvalidArgumentException('Unknown action: ' . $action);
    }
    $expires = $this->time->getRequestTime() + $ttl_seconds;
    $payload = $this->b64urlEncode(json_encode(['s' => $submission_id, 'a' => $action, 'e' => $expires]));
    $sig = $this->b64urlEncode(hash_hmac('sha256', $payload, $this->getSecret(), TRUE));
    return $payload . '.' . $sig;
  }

  /**
   * Validate a token. Returns the decoded payload or NULL on failure.
   */
  public function validate(string $token): ?array {
    if (substr_count($token, '.') !== 1) {
      return NULL;
    }
    [$payload, $sig] = explode('.', $token, 2);

    $expected = $this->b64urlEncode(hash_hmac('sha256', $payload, $this->getSecret(), TRUE));
    if (!hash_equals($expected, $sig)) {
      return NULL;
    }

    $data = json_decode($this->b64urlDecode($payload), TRUE);
    if (!is_array($data) || !isset($data['s'], $data['a'], $data['e'])) {
      return NULL;
    }

    if (!in_array($data['a'], self::ACTIONS, TRUE)) {
      return NULL;
    }

    if ($data['e'] < $this->time->getRequestTime()) {
      return NULL;
    }

    return $data;
  }

  /**
   * Get (or lazily create) the per-site HMAC secret.
   */
  protected function getSecret(): string {
    $config = $this->configFactory->getEditable('assign_badge_from_quiz.settings');
    $secret = $config->get('doc_action_secret');
    if (!$secret) {
      $secret = bin2hex(random_bytes(32));
      $config->set('doc_action_secret', $secret)->save();
    }
    return $secret;
  }

  /**
   * URL-safe base64 encode (no padding).
   */
  protected function b64urlEncode(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
  }

  /**
   * Decode a URL-safe base64 string produced by b64urlEncode().
   */
  protected function b64urlDecode(string $str): string {
    $padded = $str . str_repeat('=', (4 - strlen($str) % 4) % 4);
    return base64_decode(strtr($padded, '-_', '+/'));
  }

}
