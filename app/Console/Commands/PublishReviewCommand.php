<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Review;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Override;

final class PublishReviewCommand extends Command
{
    #[Override]
    protected $signature = 'site:publish {review : Review id} {slug : URL slug}
        {--headline= : Publication headline}
        {--commentary= : Path to a markdown commentary file}
        {--spec-name= : Human name of the reviewed spec}
        {--spec-url= : Source URL of the reviewed spec}
        {--spec-license= : License of the reviewed spec}';

    #[Override]
    protected $description = 'Export a completed review as a publication JSON file.';

    public function handle(): int
    {
        $review = Review::query()->find((int) $this->argument('review'));

        if ($review === null || $review->status !== 'complete') {
            $this->error('Review not found or not complete.');

            return self::FAILURE;
        }

        $dir = config()->string('site.publications_path');
        $slug = (string) $this->argument('slug');
        $target = $dir . '/' . $slug . '.json';

        if (File::exists($target)) {
            $this->error('Slug already published: ' . $slug);

            return self::FAILURE;
        }

        $commentaryPath = $this->option('commentary');
        $commentary = is_string($commentaryPath) && is_file($commentaryPath)
            ? (string) file_get_contents($commentaryPath)
            : '';

        File::ensureDirectoryExists($dir);
        File::put($target, (string) json_encode([
            'slug' => $slug,
            'headline' => (string) ($this->option('headline') ?? $slug),
            'commentary_md' => $commentary,
            'spec_name' => (string) ($this->option('spec-name') ?? ($review->spec_ref ?? 'Unknown spec')),
            'spec_source_url' => (string) ($this->option('spec-url') ?? ''),
            'spec_license' => (string) ($this->option('spec-license') ?? ''),
            'dimension' => $review->dimension,
            'panelists' => $review->panelists,
            'judge' => config()->string('oast.judge'),
            'findings' => $review->findings,
            'metrics' => $review->metrics,
            'reviewed_at' => $review->created_at?->toIso8601String(),
            'published_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info('Published ' . $target);

        return self::SUCCESS;
    }
}
