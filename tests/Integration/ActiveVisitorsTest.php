<?php

declare(strict_types=1);

namespace Challenge\Tests\Integration;

final class ActiveVisitorsTest extends IntegrationTestCase
{
    // -------------------------------------------------------------------------
    // Core functionality
    // -------------------------------------------------------------------------

    public function testActiveVisitorsAreScopedDeduplicatedAndCorrectlyCounted(): void
    {
        // Arrange
        $from = '2026-05-01';
        $to   = '2026-05-15';

        // Act
        $response = $this->request('GET', '/api/accounts/1/visitors/active', ['from' => $from, 'to' => $to]);

        // Assert
        self::assertSame(200, $response->getStatusCode());

        $visitors = $this->json($response)['data'];

        self::assertSame(['v_1001', 'v_1002', 'v_1003'], array_column($visitors, 'visitor_id'));

        $byId = array_column($visitors, null, 'visitor_id');

        self::assertSame(4, (int) $byId['v_1001']['page_view_count']);
        self::assertSame('2026-05-14 12:30:00', $byId['v_1001']['last_seen_at']);
        self::assertSame('ana.silva@example.com', $byId['v_1001']['email']);

        self::assertArrayNotHasKey('v_2001', $byId, 'Visitors from other accounts must never leak into account 1.');
        self::assertArrayNotHasKey('v_1004', $byId, 'Visitors outside the requested date range should not be returned.');
    }

    public function testActiveVisitorsResponseShapeContainsAllRequiredFields(): void
    {
        $response = $this->request('GET', '/api/accounts/1/visitors/active', [
            'from' => '2026-05-01',
            'to'   => '2026-05-15',
        ]);

        $body = $this->json($response);
        self::assertArrayHasKey('data', $body);

        $visitor = $body['data'][0];
        foreach (['visitor_id', 'email', 'company', 'page_view_count', 'last_seen_at', 'engagement_score'] as $field) {
            self::assertArrayHasKey($field, $visitor, "Response is missing field: {$field}");
        }
    }

    public function testActiveVisitorsOrderedByLastSeenAtDescending(): void
    {
        $response = $this->request('GET', '/api/accounts/1/visitors/active', [
            'from' => '2026-05-01',
            'to'   => '2026-05-15',
        ]);

        $visitors = $this->json($response)['data'];

        $dates = array_column($visitors, 'last_seen_at');

        // Each date must be >= the one after it
        for ($i = 0; $i < count($dates) - 1; $i++) {
            self::assertGreaterThanOrEqual(
                $dates[$i + 1],
                $dates[$i],
                "Visitor at position {$i} has an earlier last_seen_at than the next visitor."
            );
        }
    }

    // -------------------------------------------------------------------------
    // Engagement score
    // -------------------------------------------------------------------------

    public function testActiveVisitorsEngagementScoreAddsIdentityBonus(): void
    {
        $response = $this->request('GET', '/api/accounts/1/visitors/active', [
            'from' => '2026-05-01',
            'to'   => '2026-05-15',
        ]);

        $byId = array_column($this->json($response)['data'], null, 'visitor_id');

        self::assertSame(14, (int) $byId['v_1001']['engagement_score']);
        self::assertSame(2, (int) $byId['v_1003']['engagement_score']);
        self::assertNull($byId['v_1003']['email']);
    }

    public function testActiveVisitorsEngagementScoreForAllVisitors(): void
    {
        // v_1001: 4 page views + identified  → 14
        // v_1002: 3 page views + identified  → 13
        // v_1003: 2 page views + anonymous   →  2
        $response = $this->request('GET', '/api/accounts/1/visitors/active', [
            'from' => '2026-05-01',
            'to'   => '2026-05-15',
        ]);

        $byId = array_column($this->json($response)['data'], null, 'visitor_id');

        self::assertSame(14, (int) $byId['v_1001']['engagement_score']);
        self::assertSame(13, (int) $byId['v_1002']['engagement_score']);
        self::assertSame(2, (int) $byId['v_1003']['engagement_score']);
    }

    // -------------------------------------------------------------------------
    // Identity data
    // -------------------------------------------------------------------------

    public function testActiveVisitorsLatestIdentityEventEmailIsUsed(): void
    {
        // v_1001 has two identity events; the latest one (2026-05-13) must be returned.
        $response = $this->request('GET', '/api/accounts/1/visitors/active', [
            'from' => '2026-05-01',
            'to'   => '2026-05-15',
        ]);

        $byId = array_column($this->json($response)['data'], null, 'visitor_id');

        self::assertSame('ana.silva@example.com', $byId['v_1001']['email']);
        self::assertSame('Acme', $byId['v_1001']['company']);
    }

    public function testActiveVisitorsIdentityFieldsPerVisitor(): void
    {
        $response = $this->request('GET', '/api/accounts/1/visitors/active', [
            'from' => '2026-05-01',
            'to'   => '2026-05-15',
        ]);

        $byId = array_column($this->json($response)['data'], null, 'visitor_id');

        // v_1002 identified with a single event
        self::assertSame('bruno@example.com', $byId['v_1002']['email']);
        self::assertSame('Orbit', $byId['v_1002']['company']);

        // v_1003 has no identity event
        self::assertNull($byId['v_1003']['email']);
        self::assertNull($byId['v_1003']['company']);
    }

    // -------------------------------------------------------------------------
    // Date range filtering
    // -------------------------------------------------------------------------

    public function testActiveVisitorsSingleDayRangeFiltersCorrectly(): void
    {
        // Only v_1001 has a page view on 2026-05-14 (/demo at 12:30:00).
        $response = $this->request('GET', '/api/accounts/1/visitors/active', [
            'from' => '2026-05-14',
            'to'   => '2026-05-14',
        ]);

        self::assertSame(200, $response->getStatusCode());

        $visitors = $this->json($response)['data'];

        self::assertCount(1, $visitors);
        self::assertSame('v_1001', $visitors[0]['visitor_id']);
        self::assertSame(1, (int) $visitors[0]['page_view_count']);
        self::assertSame('2026-05-14 12:30:00', $visitors[0]['last_seen_at']);
    }

    public function testActiveVisitorsFutureDateRangeReturnsEmptyData(): void
    {
        $response = $this->request('GET', '/api/accounts/1/visitors/active', [
            'from' => '2030-01-01',
            'to'   => '2030-12-31',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->json($response)['data']);
    }

    public function testActiveVisitorsPageViewCountOnlyCountsViewsInDateRange(): void
    {
        // v_1001 has 4 page views total, but 3 are before 2026-05-04.
        // Requesting only from 2026-05-04 to 2026-05-14 should return 2 views:
        // /pricing on 2026-05-04 and /demo on 2026-05-14.
        $response = $this->request('GET', '/api/accounts/1/visitors/active', [
            'from' => '2026-05-04',
            'to'   => '2026-05-14',
        ]);

        $byId = array_column($this->json($response)['data'], null, 'visitor_id');

        self::assertSame(2, (int) $byId['v_1001']['page_view_count']);
    }

    // -------------------------------------------------------------------------
    // Account isolation
    // -------------------------------------------------------------------------

    public function testActiveVisitorsForUnknownAccountReturnsEmptyList(): void
    {
        $response = $this->request('GET', '/api/accounts/999/visitors/active', [
            'from' => '2026-05-01',
            'to'   => '2026-05-15',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->json($response)['data']);
    }

    public function testActiveVisitorsEnforcesAccountIsolation(): void
    {
        $response = $this->request('GET', '/api/accounts/1/visitors/active', [
            'from' => '2026-05-01',
            'to'   => '2026-05-15',
        ]);

        $visitorIds = array_column($this->json($response)['data'], 'visitor_id');

        self::assertNotContains('v_2001', $visitorIds);
    }

    // -------------------------------------------------------------------------
    // Query param validation
    // -------------------------------------------------------------------------

    public function testActiveVisitorsRejectsMissingDateParams(): void
    {
        $response = $this->request('GET', '/api/accounts/1/visitors/active');

        self::assertSame(422, $response->getStatusCode());

        $body = $this->json($response);

        self::assertSame('validation_failed', $body['error']);
        self::assertArrayHasKey('from', $body['fields']);
        self::assertArrayHasKey('to', $body['fields']);
    }

    public function testActiveVisitorsRejectsMissingFromParam(): void
    {
        $response = $this->request('GET', '/api/accounts/1/visitors/active', ['to' => '2026-05-15']);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('from', $this->json($response)['fields']);
    }

    public function testActiveVisitorsRejectsMissingToParam(): void
    {
        $response = $this->request('GET', '/api/accounts/1/visitors/active', ['from' => '2026-05-01']);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('to', $this->json($response)['fields']);
    }

    public function testActiveVisitorsRejectsInvalidDateFormat(): void
    {
        $response = $this->request('GET', '/api/accounts/1/visitors/active', [
            'from' => 'not-a-date',
            'to'   => '2026-05-15',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('from', $this->json($response)['fields']);
    }

    public function testActiveVisitorsRejectsFromLaterThanTo(): void
    {
        $response = $this->request('GET', '/api/accounts/1/visitors/active', [
            'from' => '2026-05-15',
            'to'   => '2026-05-01',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('from', $this->json($response)['fields']);
    }

    // -------------------------------------------------------------------------
    // Account isolation
    // -------------------------------------------------------------------------

    public function testActiveVisitorsAccount2ReturnsOnlyItsOwnVisitors(): void
    {
        // Account 2 has v_2001 with 2 page views (/pricing, /demo) and one identity event.
        $response = $this->request('GET', '/api/accounts/2/visitors/active', [
            'from' => '2026-05-01',
            'to'   => '2026-05-31',
        ]);

        self::assertSame(200, $response->getStatusCode());

        $visitors = $this->json($response)['data'];

        self::assertCount(1, $visitors);
        self::assertSame('v_2001', $visitors[0]['visitor_id']);
        self::assertSame(2, (int) $visitors[0]['page_view_count']);
        self::assertSame('carla@example.com', $visitors[0]['email']);
        self::assertSame('Beta', $visitors[0]['company']);
        self::assertSame(12, (int) $visitors[0]['engagement_score']);

        self::assertNotContains('v_1001', array_column($visitors, 'visitor_id'));
    }
}
