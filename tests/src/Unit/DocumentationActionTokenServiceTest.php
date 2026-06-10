<?php

namespace Drupal\Tests\assign_badge_from_quiz\Unit;

use Drupal\assign_badge_from_quiz\Service\DocumentationActionTokenService;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\assign_badge_from_quiz\Service\DocumentationActionTokenService
 * @group assign_badge_from_quiz
 */
class DocumentationActionTokenServiceTest extends UnitTestCase {

  /**
   * The service under test.
   *
   * @var \Drupal\assign_badge_from_quiz\Service\DocumentationActionTokenService
   */
  protected DocumentationActionTokenService $service;

  /**
   * Mocked time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $time;

  /**
   * Fixed "now" timestamp used by the mocked time service.
   *
   * @var int
   */
  protected int $now = 1700000000;

  /**
   * Test HMAC secret.
   *
   * @var string
   */
  protected string $secret = 'test-secret-do-not-use-in-prod';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $editable = $this->createMock(Config::class);
    $editable->method('get')->willReturn($this->secret);
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('getEditable')->willReturn($editable);

    $this->time = $this->createMock(TimeInterface::class);
    $this->time->method('getRequestTime')->willReturn($this->now);

    $this->service = new DocumentationActionTokenService($config_factory, $this->time);
  }

  /**
   * @covers ::generate @covers ::validate
   */
  public function testValidTokenRoundTrip(): void {
    $token = $this->service->generate(42, 'approve');
    $data = $this->service->validate($token);

    $this->assertNotNull($data);
    $this->assertSame(42, $data['s']);
    $this->assertSame('approve', $data['a']);
    $this->assertGreaterThan($this->now, $data['e']);
  }

  /**
   * @covers ::generate
   */
  public function testRejectActionAllowed(): void {
    $token = $this->service->generate(7, 'reject');
    $this->assertSame('reject', $this->service->validate($token)['a']);
  }

  /**
   * @covers ::validate
   */
  public function testExpiredTokenRejected(): void {
    $token = $this->service->generate(42, 'approve', 100);

    $time2 = $this->createMock(TimeInterface::class);
    $time2->method('getRequestTime')->willReturn($this->now + 1000);
    $editable = $this->createMock(Config::class);
    $editable->method('get')->willReturn($this->secret);
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('getEditable')->willReturn($editable);

    $svc2 = new DocumentationActionTokenService($config_factory, $time2);
    $this->assertNull($svc2->validate($token));
  }

  /**
   * @covers ::validate
   */
  public function testTamperedPayloadRejected(): void {
    $token = $this->service->generate(42, 'approve');
    [$payload, $sig] = explode('.', $token, 2);

    $decoded = base64_decode(strtr($payload, '-_', '+/') . str_repeat('=', (4 - strlen($payload) % 4) % 4));
    $changed = json_decode($decoded, TRUE);
    $changed['s'] = 9999;
    $tampered = rtrim(strtr(base64_encode(json_encode($changed)), '+/', '-_'), '=');

    $this->assertNull($this->service->validate($tampered . '.' . $sig));
  }

  /**
   * @covers ::validate
   */
  public function testTamperedSignatureRejected(): void {
    $token = $this->service->generate(42, 'approve');
    [$payload] = explode('.', $token, 2);
    $bad_sig = rtrim(strtr(base64_encode(str_repeat('x', 32)), '+/', '-_'), '=');

    $this->assertNull($this->service->validate($payload . '.' . $bad_sig));
  }

  /**
   * @covers ::generate
   */
  public function testUnknownActionRejected(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->service->generate(42, 'delete');
  }

  /**
   * @covers ::validate
   */
  public function testMalformedTokensRejected(): void {
    $this->assertNull($this->service->validate('no-dots-at-all'));
    $this->assertNull($this->service->validate('too.many.dots.here'));
    $this->assertNull($this->service->validate(''));
    $this->assertNull($this->service->validate('garbage.notjson'));
  }

  /**
   * @covers ::validate
   */
  public function testTokenSignedByDifferentSecretRejected(): void {
    $token = $this->service->generate(42, 'approve');

    $other_config = $this->createMock(Config::class);
    $other_config->method('get')->willReturn('different-secret');
    $other_factory = $this->createMock(ConfigFactoryInterface::class);
    $other_factory->method('getEditable')->willReturn($other_config);
    $other = new DocumentationActionTokenService($other_factory, $this->time);

    $this->assertNull($other->validate($token));
  }

}
