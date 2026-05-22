<?php

declare(strict_types=1);

namespace Challenge\Tests\Support;

/**
 * Immutable builder for segment preview request payloads.
 *
 * Each mutator returns a new instance (wither pattern), allowing tests to
 * share a base configuration without risk of cross-test contamination.
 *
 * Usage:
 *   $payload = SegmentPayloadBuilder::defaults()->identifiedOnly()->limit(2)->build();
 */
final class SegmentPayloadBuilder
{
    private function __construct(
        private readonly string $visitedPath    = '/pricing',
        private readonly int    $minPageViews   = 1,
        private readonly bool   $identifiedOnly = false,
        private readonly string $from           = '2026-05-01',
        private readonly string $to             = '2026-05-15',
        private readonly int    $limit          = 25,
    ) {
    }

    public static function defaults(): self
    {
        return new self();
    }

    public function visitedPath(string $path): self
    {
        return new self($path, $this->minPageViews, $this->identifiedOnly, $this->from, $this->to, $this->limit);
    }

    public function minPageViews(int $count): self
    {
        return new self($this->visitedPath, $count, $this->identifiedOnly, $this->from, $this->to, $this->limit);
    }

    public function identifiedOnly(bool $value = true): self
    {
        return new self($this->visitedPath, $this->minPageViews, $value, $this->from, $this->to, $this->limit);
    }

    public function from(string $date): self
    {
        return new self($this->visitedPath, $this->minPageViews, $this->identifiedOnly, $date, $this->to, $this->limit);
    }

    public function to(string $date): self
    {
        return new self($this->visitedPath, $this->minPageViews, $this->identifiedOnly, $this->from, $date, $this->limit);
    }

    public function limit(int $limit): self
    {
        return new self($this->visitedPath, $this->minPageViews, $this->identifiedOnly, $this->from, $this->to, $limit);
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        return [
            'rules' => [
                'visited_path'    => $this->visitedPath,
                'min_page_views'  => $this->minPageViews,
                'identified_only' => $this->identifiedOnly,
                'from'            => $this->from,
                'to'              => $this->to,
            ],
            'limit' => $this->limit,
        ];
    }

    /** @return array<string, mixed> */
    public function buildWithoutLimit(): array
    {
        return ['rules' => $this->build()['rules']];
    }
}
