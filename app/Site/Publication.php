<?php

declare(strict_types=1);

namespace App\Site;

use Carbon\CarbonImmutable;

final readonly class Publication
{
    /**
     * @param  list<string>  $panelists
     * @param  array<array-key, mixed>  $findings
     * @param  array<array-key, mixed>  $metrics
     */
    private function __construct(
        public string $slug,
        public string $headline,
        public string $commentaryMd,
        public string $specName,
        public string $specSourceUrl,
        public string $specLicense,
        public string $dimension,
        public array $panelists,
        public string $judge,
        public array $findings,
        public array $metrics,
        public CarbonImmutable $reviewedAt,
        public CarbonImmutable $publishedAt,
    ) {}

    /**
     * @param  array<string, string|array>  $data
     */
    public static function fromArray(array $data): self
    {
        $slug = self::asString($data['slug'] ?? '');
        $headline = self::asString($data['headline'] ?? '');
        $commentaryMd = self::asString($data['commentary_md'] ?? '');
        $specName = self::asString($data['spec_name'] ?? '');
        $specSourceUrl = self::asString($data['spec_source_url'] ?? '');
        $specLicense = self::asString($data['spec_license'] ?? '');
        $dimension = self::asString($data['dimension'] ?? '');
        $judge = self::asString($data['judge'] ?? '');
        $findings = is_array($data['findings'] ?? null) ? $data['findings'] : [];
        $metrics = is_array($data['metrics'] ?? null) ? $data['metrics'] : [];
        $reviewedAtStr = self::asString($data['reviewed_at'] ?? '');
        $publishedAtStr = self::asString($data['published_at'] ?? '');

        return new self(
            slug: $slug,
            headline: $headline,
            commentaryMd: $commentaryMd,
            specName: $specName,
            specSourceUrl: $specSourceUrl,
            specLicense: $specLicense,
            dimension: $dimension,
            panelists: array_values(array_map(fn(mixed $v): string => self::asString($v), (array) ($data['panelists'] ?? []))),
            judge: $judge,
            findings: $findings,
            metrics: $metrics,
            reviewedAt: CarbonImmutable::parse($reviewedAtStr),
            publishedAt: CarbonImmutable::parse($publishedAtStr),
        );
    }

    /**
     * @return array{blocker: int, should-fix: int, consider: int}
     */
    public function findingCounts(): array
    {
        $counts = ['blocker' => 0, 'should-fix' => 0, 'consider' => 0];

        foreach ($this->findings as $finding) {
            $severity = is_array($finding) ? ($finding['severity'] ?? null) : null;

            if (is_string($severity) && array_key_exists($severity, $counts)) {
                $counts[$severity]++;
            }
        }

        return $counts;
    }

    public function totalCostUsd(): ?float
    {
        foreach ($this->metrics as $metric) {
            if (is_array($metric) && is_numeric($metric['total_cost_usd'] ?? null)) {
                return (float) $metric['total_cost_usd'];
            }
        }

        return null;
    }
    /**
     * @return string
     */
    private static function asString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_null($value) || is_array($value)) {
            return '';
        }

        if (is_bool($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }
}
