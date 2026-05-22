<?php

declare(strict_types=1);

namespace Challenge\Tests\Integration;

use Challenge\Tests\Support\SegmentPayloadBuilder;

final class SegmentPreviewTest extends IntegrationTestCase
{
    // -------------------------------------------------------------------------
    // Core business logic
    // -------------------------------------------------------------------------

    public function testSegmentPreviewFiltersVisitorsByRules(): void
    {
        // Arrange
        $payload = SegmentPayloadBuilder::defaults()
            ->identifiedOnly()
            ->minPageViews(2)
            ->build();

        // Act
        $response = $this->request('POST', '/api/accounts/1/segments/preview', json: $payload);

        // Assert
        self::assertSame(200, $response->getStatusCode());

        $body = $this->json($response);

        self::assertSame(2, $body['count']);
        self::assertSame(['v_1001', 'v_1002'], array_column($body['visitors'], 'visitor_id'));
        self::assertSame(4, (int) $body['visitors'][0]['page_view_count']);
        self::assertSame('ana.silva@example.com', $body['visitors'][0]['email']);
    }

    public function testSegmentPreviewResponseShapeIsComplete(): void
    {
        $response = $this->request('POST', '/api/accounts/1/segments/preview', json:
            SegmentPayloadBuilder::defaults()->build()
        );

        $body = $this->json($response);

        self::assertArrayHasKey('count', $body);
        self::assertArrayHasKey('visitors', $body);
        self::assertIsInt($body['count']);
        self::assertIsArray($body['visitors']);

        $visitor = $body['visitors'][0];
        foreach (['visitor_id', 'email', 'company', 'page_view_count', 'last_seen_at'] as $field) {
            self::assertArrayHasKey($field, $visitor, "Visitor response is missing field: {$field}");
        }

        // engagement_score is specific to the active-visitors endpoint, not here
        self::assertArrayNotHasKey('engagement_score', $visitor);
    }

    public function testSegmentPreviewCountReflectsTotalNotLimit(): void
    {
        $payload = SegmentPayloadBuilder::defaults()->limit(1)->build();

        $response = $this->request('POST', '/api/accounts/1/segments/preview', json: $payload);

        $body = $this->json($response);

        self::assertSame(3, $body['count']);
        self::assertCount(1, $body['visitors']);
    }

    // -------------------------------------------------------------------------
    // Ordering & limit
    // -------------------------------------------------------------------------

    public function testSegmentPreviewUsesDeterministicOrderingAndLimit(): void
    {
        $payload = SegmentPayloadBuilder::defaults()->limit(2)->build();

        $response = $this->request('POST', '/api/accounts/1/segments/preview', json: $payload);

        self::assertSame(200, $response->getStatusCode());

        $body = $this->json($response);

        self::assertSame(3, $body['count']);
        self::assertSame(['v_1001', 'v_1002'], array_column($body['visitors'], 'visitor_id'));
    }

    public function testSegmentPreviewOrderedByLastSeenAtThenPageViewCount(): void
    {
        // With no limit applied (limit=25) and 3 matching visitors, the order must be
        // deterministic: last_seen_at DESC, page_view_count DESC, visitor_id ASC.
        $response = $this->request('POST', '/api/accounts/1/segments/preview', json:
            SegmentPayloadBuilder::defaults()->build()
        );

        $visitors = $this->json($response)['visitors'];
        $dates    = array_column($visitors, 'last_seen_at');

        for ($i = 0; $i < count($dates) - 1; $i++) {
            self::assertGreaterThanOrEqual(
                $dates[$i + 1],
                $dates[$i],
                "Visitor at position {$i} is not ordered by last_seen_at DESC."
            );
        }
    }

    // -------------------------------------------------------------------------
    // Visitor identity fields
    // -------------------------------------------------------------------------

    public function testSegmentPreviewLatestIdentityEventEmailIsUsed(): void
    {
        // v_1001 has two identity events; only the latest (ana.silva@) must appear.
        $response = $this->request('POST', '/api/accounts/1/segments/preview', json:
            SegmentPayloadBuilder::defaults()->identifiedOnly()->build()
        );

        $byId = array_column($this->json($response)['visitors'], null, 'visitor_id');

        self::assertSame('ana.silva@example.com', $byId['v_1001']['email']);
        self::assertSame('Acme', $byId['v_1001']['company']);
    }

    public function testSegmentPreviewAnonymousVisitorHasNullIdentityFields(): void
    {
        // v_1003 has no identity event; email and company must be null.
        $response = $this->request('POST', '/api/accounts/1/segments/preview', json:
            SegmentPayloadBuilder::defaults()->identifiedOnly(false)->minPageViews(1)->build()
        );

        $byId = array_column($this->json($response)['visitors'], null, 'visitor_id');

        self::assertNull($byId['v_1003']['email']);
        self::assertNull($byId['v_1003']['company']);
    }

    // -------------------------------------------------------------------------
    // identified_only rule
    // -------------------------------------------------------------------------

    public function testSegmentPreviewWithIdentifiedOnlyFalseIncludesAnonymousVisitors(): void
    {
        $response = $this->request('POST', '/api/accounts/1/segments/preview', json:
            SegmentPayloadBuilder::defaults()->identifiedOnly(false)->minPageViews(1)->build()
        );

        $visitorIds = array_column($this->json($response)['visitors'], 'visitor_id');
        self::assertContains('v_1003', $visitorIds);
    }

    public function testSegmentPreviewWithIdentifiedOnlyTrueExcludesAnonymousVisitors(): void
    {
        $response = $this->request('POST', '/api/accounts/1/segments/preview', json:
            SegmentPayloadBuilder::defaults()->identifiedOnly()->minPageViews(1)->build()
        );

        $visitorIds = array_column($this->json($response)['visitors'], 'visitor_id');
        self::assertNotContains('v_1003', $visitorIds);
    }

    // -------------------------------------------------------------------------
    // min_page_views rule
    // -------------------------------------------------------------------------

    public function testSegmentPreviewHighMinPageViewsFiltersCorrectly(): void
    {
        // v_1001 has 4 page views in range (exactly the threshold); v_1002 and v_1003 have fewer.
        $response = $this->request('POST', '/api/accounts/1/segments/preview', json:
            SegmentPayloadBuilder::defaults()->identifiedOnly(false)->minPageViews(4)->build()
        );

        $body = $this->json($response);

        self::assertSame(1, $body['count']);
        self::assertSame('v_1001', $body['visitors'][0]['visitor_id']);
    }

    public function testSegmentPreviewMinPageViewsOfOneMatchesAllVisitorsWithAnyView(): void
    {
        $response = $this->request('POST', '/api/accounts/1/segments/preview', json:
            SegmentPayloadBuilder::defaults()->identifiedOnly(false)->minPageViews(1)->build()
        );

        self::assertSame(3, $this->json($response)['count']);
    }

    // -------------------------------------------------------------------------
    // visited_path rule
    // -------------------------------------------------------------------------

    public function testSegmentPreviewWithNoMatchingPathReturnsEmptyResult(): void
    {
        $response = $this->request('POST', '/api/accounts/1/segments/preview', json:
            SegmentPayloadBuilder::defaults()->visitedPath('/nonexistent-page')->build()
        );

        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $body['count']);
        self::assertSame([], $body['visitors']);
    }

    // -------------------------------------------------------------------------
    // Date range rule
    // -------------------------------------------------------------------------

    public function testSegmentPreviewExcludesVisitorsOutsideDateRange(): void
    {
        // v_1004 only has a /pricing view on 2026-04-25, outside the May range.
        $response = $this->request('POST', '/api/accounts/1/segments/preview', json:
            SegmentPayloadBuilder::defaults()->from('2026-05-01')->to('2026-05-15')->build()
        );

        $visitorIds = array_column($this->json($response)['visitors'], 'visitor_id');
        self::assertNotContains('v_1004', $visitorIds);
    }

    public function testSegmentPreviewWithFutureDateRangeReturnsEmpty(): void
    {
        $response = $this->request('POST', '/api/accounts/1/segments/preview', json:
            SegmentPayloadBuilder::defaults()->from('2030-01-01')->to('2030-12-31')->build()
        );

        $body = $this->json($response);

        self::assertSame(0, $body['count']);
        self::assertSame([], $body['visitors']);
    }

    // -------------------------------------------------------------------------
    // Account isolation
    // -------------------------------------------------------------------------

    public function testSegmentPreviewEnforcesAccountIsolation(): void
    {
        $response = $this->request('POST', '/api/accounts/1/segments/preview', json:
            SegmentPayloadBuilder::defaults()->build()
        );

        self::assertNotContains('v_2001', array_column($this->json($response)['visitors'], 'visitor_id'));
    }

    public function testSegmentPreviewOnAccount2ReturnsOnlyItsOwnVisitors(): void
    {
        // v_2001 visited /pricing on 2026-05-12 and is identified (carla@example.com).
        $response = $this->request('POST', '/api/accounts/2/segments/preview', json:
            SegmentPayloadBuilder::defaults()->identifiedOnly(false)->minPageViews(1)->build()
        );

        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $body['count']);
        self::assertSame('v_2001', $body['visitors'][0]['visitor_id']);
        self::assertSame('carla@example.com', $body['visitors'][0]['email']);
        self::assertSame('Beta', $body['visitors'][0]['company']);

        self::assertNotContains('v_1001', array_column($body['visitors'], 'visitor_id'));
    }

    // -------------------------------------------------------------------------
    // Limit handling
    // -------------------------------------------------------------------------

    public function testSegmentPreviewDefaultsLimitToTwentyFive(): void
    {
        $response = $this->request('POST', '/api/accounts/1/segments/preview', json:
            SegmentPayloadBuilder::defaults()->buildWithoutLimit()
        );

        self::assertSame(200, $response->getStatusCode());
    }

    public function testSegmentPreviewAcceptsLimitAtMinimumBoundary(): void
    {
        $response = $this->request('POST', '/api/accounts/1/segments/preview', json:
            SegmentPayloadBuilder::defaults()->limit(1)->build()
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $this->json($response)['visitors']);
    }

    public function testSegmentPreviewAcceptsLimitAtMaximumBoundary(): void
    {
        $response = $this->request('POST', '/api/accounts/1/segments/preview', json:
            SegmentPayloadBuilder::defaults()->limit(100)->build()
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(3, $this->json($response)['count']);
    }

    // -------------------------------------------------------------------------
    // Validation — field-level errors
    // -------------------------------------------------------------------------

    public function testSegmentPreviewRejectsInvalidPayload(): void
    {
        $payload = [
            'rules' => [
                'visited_path'    => '',
                'min_page_views'  => 0,
                'identified_only' => 'yes',
                'from'            => '2026-05-15',
                'to'              => '2026-05-01',
            ],
            'limit' => 500,
        ];

        $response = $this->request('POST', '/api/accounts/1/segments/preview', json: $payload);

        self::assertSame(422, $response->getStatusCode());

        $body = $this->json($response);

        self::assertSame('validation_failed', $body['error']);
        self::assertArrayHasKey('rules.visited_path', $body['fields']);
        self::assertArrayHasKey('rules.min_page_views', $body['fields']);
        self::assertArrayHasKey('rules.identified_only', $body['fields']);
        self::assertArrayHasKey('rules.from', $body['fields']);
        self::assertArrayHasKey('limit', $body['fields']);
    }

    public function testSegmentPreviewRejectsMissingRulesKey(): void
    {
        $response = $this->request('POST', '/api/accounts/1/segments/preview', json: ['limit' => 10]);

        self::assertSame(422, $response->getStatusCode());

        $body = $this->json($response);

        self::assertSame('validation_failed', $body['error']);
        self::assertArrayHasKey('rules.visited_path', $body['fields']);
    }

    public function testSegmentPreviewRejectsEmptyVisitedPath(): void
    {
        $payload = SegmentPayloadBuilder::defaults()->build();
        $payload['rules']['visited_path'] = '';

        $response = $this->request('POST', '/api/accounts/1/segments/preview', json: $payload);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('rules.visited_path', $this->json($response)['fields']);
    }

    public function testSegmentPreviewRejectsNonStringVisitedPath(): void
    {
        $payload = SegmentPayloadBuilder::defaults()->build();
        $payload['rules']['visited_path'] = 123;

        $response = $this->request('POST', '/api/accounts/1/segments/preview', json: $payload);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('rules.visited_path', $this->json($response)['fields']);
    }

    public function testSegmentPreviewRejectsNegativeMinPageViews(): void
    {
        $payload = SegmentPayloadBuilder::defaults()->build();
        $payload['rules']['min_page_views'] = -1;

        $response = $this->request('POST', '/api/accounts/1/segments/preview', json: $payload);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('rules.min_page_views', $this->json($response)['fields']);
    }

    public function testSegmentPreviewRejectsFromDateInWrongFormat(): void
    {
        $payload = SegmentPayloadBuilder::defaults()->build();
        $payload['rules']['from'] = '05-2026-01';

        $response = $this->request('POST', '/api/accounts/1/segments/preview', json: $payload);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('rules.from', $this->json($response)['fields']);
    }

    public function testSegmentPreviewRejectsImpossibleDate(): void
    {
        // February 30 does not exist.
        $payload = SegmentPayloadBuilder::defaults()->build();
        $payload['rules']['from'] = '2026-02-30';

        $response = $this->request('POST', '/api/accounts/1/segments/preview', json: $payload);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('rules.from', $this->json($response)['fields']);
    }

    public function testSegmentPreviewRejectsFromLaterThanTo(): void
    {
        $response = $this->request('POST', '/api/accounts/1/segments/preview', json:
            SegmentPayloadBuilder::defaults()->from('2026-05-15')->to('2026-05-01')->build()
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('rules.from', $this->json($response)['fields']);
    }

    public function testSegmentPreviewRejectsLimitBelowMinimum(): void
    {
        $payload = SegmentPayloadBuilder::defaults()->build();
        $payload['limit'] = 0;

        $response = $this->request('POST', '/api/accounts/1/segments/preview', json: $payload);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('limit', $this->json($response)['fields']);
    }

    public function testSegmentPreviewRejectsLimitAboveMaximum(): void
    {
        $payload = SegmentPayloadBuilder::defaults()->build();
        $payload['limit'] = 101;

        $response = $this->request('POST', '/api/accounts/1/segments/preview', json: $payload);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('limit', $this->json($response)['fields']);
    }
}
