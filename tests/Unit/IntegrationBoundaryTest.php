<?php
/**
 * @package HealthLens
 */

namespace HealthLens\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use HealthLens\Domain\CheckContext;
use HealthLens\Domain\CheckResult;
use HealthLens\Domain\IntegrationPayload;
use HealthLens\Infrastructure\WordPress\NullConnector;
use HealthLens\Infrastructure\WordPress\OptionalGatewayTransport;
use HealthLens\Infrastructure\WordPress\GatewayRetryPolicy;
use PHPUnit\Framework\TestCase;

final class IntegrationBoundaryTest extends TestCase {
	public function test_payload_is_minimized_and_deterministic(): void {
		$result = new CheckResult( CheckResult::STATE_ISSUE, CheckResult::SEVERITY_WARNING, 'integration.warning', new CheckContext( array( 'url' => 'https://private.example', 'safe_flag' => true ) ), new DateTimeImmutable( '2026-08-20 10:00:00', new DateTimeZone( 'UTC' ) ), 1 );
		$payload = new IntegrationPayload( array( 'integration' => $result ), new DateTimeImmutable( '2026-08-20 10:01:00', new DateTimeZone( 'UTC' ) ) );
		$json    = $payload->encode();

		$this->assertIsString( $json );
		$this->assertStringNotContainsString( 'private.example', $json );
		$this->assertStringNotContainsString( 'safe_flag', $json );
		$this->assertSame( IntegrationPayload::SCHEMA, $payload->to_array()['schema'] );
	}

	public function test_gateway_rejects_arbitrary_or_non_tls_endpoints(): void {
		$this->assertFalse( OptionalGatewayTransport::approved_endpoint( 'http://gateway.healthlens.example' ) );
		$this->assertFalse( OptionalGatewayTransport::approved_endpoint( 'https://evil.example' ) );
		$this->assertFalse( OptionalGatewayTransport::approved_endpoint( 'https://gateway.healthlens.example/path#fragment' ) );
		$this->assertFalse( ( new NullConnector() )->available() );
		$this->assertTrue( GatewayRetryPolicy::eligible( 2, 100, 100 ) );
		$this->assertFalse( GatewayRetryPolicy::eligible( 3, 100, 0 ) );
		$this->assertSame( 600, GatewayRetryPolicy::delay( 2 ) );
	}
}
