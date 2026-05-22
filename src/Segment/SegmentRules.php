<?php

declare(strict_types=1);

namespace Challenge\Segment;

final class SegmentRules
{
    public function __construct(
        public readonly string $visitedPath,
        public readonly int    $minPageViews,
        public readonly bool   $identifiedOnly,
        public readonly string $from,
        public readonly string $to,
    ) {
    }
}
