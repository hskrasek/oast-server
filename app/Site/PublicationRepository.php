<?php

declare(strict_types=1);

namespace App\Site;

use Throwable;
use DomainException;

final class PublicationRepository
{
    /** @var list<Publication>|null */
    private ?array $loaded = null;

    private readonly string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? base_path('database/publications');
    }

    /**
     * @return list<Publication>
     */
    public function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        $publications = [];

        foreach (glob($this->path . '/*.json') ?: [] as $file) {
            try {
                $data = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($data)) {
                    report(new DomainException('Publication JSON decoded to non-array in ' . $file));

                    continue;
                }

                // Narrow type: keep only string-keyed entries
                $stringKeyed = array_filter($data, is_string(...), ARRAY_FILTER_USE_KEY);
                $publications[] = Publication::fromArray($stringKeyed);

            } catch (Throwable $exception) {
                report($exception); // a bad publication must never 500 the site
            }
        }

        usort($publications, fn(Publication $a, Publication $b): int => $b->publishedAt <=> $a->publishedAt);

        return $this->loaded = $publications;
    }

    public function find(string $slug): ?Publication
    {
        return array_find($this->all(), fn(Publication $p): bool => $p->slug === $slug);
    }
}
