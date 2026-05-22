<?php

declare(strict_types=1);

namespace Challenge\Repositories;

use Challenge\Segment\SegmentRules;
use PDO;

final class VisitorAnalyticsRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function activeVisitors(int $accountId, string $from, string $to): array
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT
                v.external_id AS visitor_id,
                latest_ie.email,
                latest_ie.company,
                COUNT(pv.id) AS page_view_count,
                MAX(pv.occurred_at) AS last_seen_at,
                COUNT(pv.id) + (CASE WHEN latest_ie.email IS NULL THEN 0 ELSE 10 END) AS engagement_score
            FROM visitors v
            INNER JOIN page_views pv
                ON pv.visitor_id = v.id
                AND pv.occurred_at BETWEEN :from_date AND :to_date
            LEFT JOIN LATERAL (
                SELECT email, company
                FROM identity_events
                WHERE visitor_id = v.id
                ORDER BY occurred_at DESC, id DESC
                LIMIT 1
            ) latest_ie ON TRUE
            WHERE v.account_id = :account_id
            GROUP BY v.id, v.external_id, latest_ie.email, latest_ie.company
            ORDER BY last_seen_at DESC, engagement_score DESC
            SQL
        );

        $statement->execute([
            'account_id' => $accountId,
            'from_date' => $from . ' 00:00:00',
            'to_date' => $to . ' 23:59:59',
        ]);

        return $statement->fetchAll();
    }

    /**
     * @return array{count: int, visitors: list<array<string, mixed>>}
     */
    public function segmentPreview(int $accountId, SegmentRules $rules, int $limit): array
    {
        $joinType = $rules->identifiedOnly ? 'INNER' : 'LEFT';

        $params = [
            'account_id'     => $accountId,
            'from_date'      => $rules->from . ' 00:00:00',
            'to_date'        => $rules->to . ' 23:59:59',
            'min_page_views' => $rules->minPageViews,
            'visited_path'   => $rules->visitedPath,
        ];

        $countSql = <<<SQL
            SELECT COUNT(*) AS total
            FROM (
                SELECT v.id
                FROM visitors v
                INNER JOIN page_views pv
                    ON pv.visitor_id = v.id
                    AND pv.occurred_at BETWEEN :from_date AND :to_date
                {$joinType} JOIN LATERAL (
                    SELECT email
                    FROM identity_events
                    WHERE visitor_id = v.id
                    ORDER BY occurred_at DESC, id DESC
                    LIMIT 1
                ) latest_ie ON TRUE
                WHERE v.account_id = :account_id
                GROUP BY v.id
                HAVING COUNT(pv.id) >= :min_page_views
                   AND SUM(pv.path = :visited_path) > 0
            ) matching
            SQL;

        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $dataSql = <<<SQL
            SELECT
                v.external_id AS visitor_id,
                latest_ie.email,
                latest_ie.company,
                COUNT(pv.id) AS page_view_count,
                MAX(pv.occurred_at) AS last_seen_at
            FROM visitors v
            INNER JOIN page_views pv
                ON pv.visitor_id = v.id
                AND pv.occurred_at BETWEEN :from_date AND :to_date
            {$joinType} JOIN LATERAL (
                SELECT email, company
                FROM identity_events
                WHERE visitor_id = v.id
                ORDER BY occurred_at DESC, id DESC
                LIMIT 1
            ) latest_ie ON TRUE
            WHERE v.account_id = :account_id
            GROUP BY v.id, v.external_id, latest_ie.email, latest_ie.company
            HAVING COUNT(pv.id) >= :min_page_views
               AND SUM(pv.path = :visited_path) > 0
            ORDER BY last_seen_at DESC, page_view_count DESC, v.external_id ASC
            LIMIT {$limit}
            SQL;

        $dataStmt = $this->pdo->prepare($dataSql);
        $dataStmt->execute($params);

        return [
            'count'    => $total,
            'visitors' => $dataStmt->fetchAll(),
        ];
    }
}
