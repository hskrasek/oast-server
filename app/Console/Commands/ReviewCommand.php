<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Reviews\CreateReviewAction;
use App\Council\Dimension;
use App\Council\Exceptions\JudgeException;
use App\Council\Exceptions\PanelException;
use App\Council\ReviewMode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Override;

final class ReviewCommand extends Command
{
    #[Override]
    protected $signature = 'oast:review {spec : Path to the OpenAPI spec file} {--baseline : Run a single-model baseline} {--dimension=domain-modeling : Review dimension (domain-modeling|resource-relationships|workflows)}';

    #[Override]
    protected $description = 'Convene the Council on an OpenAPI spec (or a single-model baseline).';

    public function handle(CreateReviewAction $review): int
    {
        $path = $this->argument('spec');

        if (! is_file($path)) {
            $this->error('Spec file not found: ' . $path);

            return self::FAILURE;
        }

        $mode = $this->option('baseline') ? ReviewMode::Baseline : ReviewMode::Council;
        $dimension = Dimension::tryFrom((string) $this->option('dimension'));

        if (! $dimension instanceof Dimension) {
            $this->error('Unknown dimension: ' . $this->option('dimension'));

            return self::FAILURE;
        }

        $this->info(sprintf('Convening %s review (%s) for %s ...', $mode->value, $dimension->value, $path));

        try {
            $result = $review(File::get($path), $mode, $path, $dimension);
        } catch (PanelException|JudgeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $findings = $result->findings ?? [];

        $this->table(
            ['Severity', 'Confidence', 'Title', 'Location'],
            array_map(fn(mixed $finding): array => [
                $this->cell($finding, 'severity'),
                $this->cell($finding, 'confidence'),
                $this->cell($finding, 'title'),
                $this->cell($finding, 'location'),
            ], $findings),
        );

        $this->info(sprintf(
            'Panel size: %d  |  Findings: %d  |  Review #%d',
            $result->panel_size,
            count($findings),
            $result->id,
        ));

        return self::SUCCESS;
    }

    private function cell(mixed $finding, string $key): string
    {
        $value = is_array($finding) ? ($finding[$key] ?? null) : null;

        return is_string($value) || is_numeric($value) ? (string) $value : '';
    }
}
