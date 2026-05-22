<?php

declare(strict_types=1);

namespace Challenge\Tests\Integration;

final class SecurityTest extends IntegrationTestCase
{
    // -------------------------------------------------------------------------
    // Security response headers
    // -------------------------------------------------------------------------

    public function testResponseAlwaysIncludesSecurityHeaders(): void
    {
        $response = $this->request('GET', '/health');

        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
    }

    public function testSecurityHeadersPresentOnApiEndpoints(): void
    {
        $response = $this->request('GET', '/api/accounts/1/visitors/active', [
            'from' => '2026-05-01',
            'to'   => '2026-05-15',
        ]);

        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
    }

    public function testSecurityHeadersPresentOnValidationErrorResponses(): void
    {
        $response = $this->request('POST', '/api/accounts/1/segments/preview', json: ['limit' => 10]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    // -------------------------------------------------------------------------
    // Route constraints
    // -------------------------------------------------------------------------

    public function testNonNumericAccountIdIsRejectedByRouter(): void
    {
        $response = $this->request('GET', '/api/accounts/abc/visitors/active', [
            'from' => '2026-05-01',
            'to'   => '2026-05-15',
        ]);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testNonNumericAccountIdIsRejectedOnSegmentPreview(): void
    {
        $response = $this->request('POST', '/api/accounts/abc/segments/preview', json: []);

        self::assertSame(404, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Invalid request body
    // -------------------------------------------------------------------------

    public function testSegmentPreviewRejectsRequestWithoutParsableBody(): void
    {
        // Without Content-Type: application/json the body parsing middleware
        // will not attempt JSON decoding, getParsedBody() returns null,
        // and the handler must return 400 instead of silently treating it as [].
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())
            ->createServerRequest('POST', '/api/accounts/1/segments/preview');

        $response = \Challenge\AppFactory::create()->handle($request);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_request_body', $this->json($response)['error']);
    }
}
