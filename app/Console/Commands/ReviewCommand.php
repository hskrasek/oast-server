<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Reviews\CreateReviewAction;
use App\Council\Dimension;
use App\Council\ReviewMode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use Override;

final class ReviewCommand extends Command
{
    #[Override]
    protected $signature = 'oast:review {spec : Path to the OpenAPI spec file} {--baseline : Run a single-model baseline} {--dimension=domain-modeling : Review dimension (domain-modeling|resource-relationships|workflows)} {--timeout=900 : Seconds to wait for completion}';

    #[Override]
    protected $description = 'Convene the Council on an OpenAPI spec (or a single-model baseline).';

    public function handle(CreateReviewAction $review): int
    {
        $path = $this->argument('spec');

        if (! is_file($path)) {
            $this->error('Spec file not found: ' . $path);

            return self::FAILURE;
        }

        $dimension = Dimension::tryFrom((string) $this->option('dimension'));

        if (! $dimension instanceof Dimension) {
            $this->error('Unknown dimension: ' . $this->option('dimension'));

            return self::FAILURE;
        }

        $mode = $this->option('baseline') ? ReviewMode::Baseline : ReviewMode::Council;
        $this->info(sprintf('Convening %s review (%s) for %s ...', $mode->value, $dimension->value, $path));

        $created = $review(File::get($path), $mode, $path, $dimension);

        $cursor = 0;
        $deadline = now()->addSeconds((int) $this->option('timeout'));
        $terminal = null;

        while (true) {
            foreach ($created->events()->where('id', '>', $cursor)->orderBy('id')->get() as $event) {
                $cursor = $event->id;
                $this->line(sprintf('%s  %s', $event->event, json_encode($event->data)));

                if (in_array($event->event, ['review.completed', 'review.failed'], true)) {
                    $terminal = $event->event;
                }
            }

            if ($terminal !== null) {
                break;
            }

            if (now()->greaterThan($deadline)) {
                $this->error('Timed out waiting for the review to finish.');

                return self::FAILURE;
            }

            Sleep::for(500)->milliseconds();
        }

        if ($terminal === 'review.failed') {
            return self::FAILURE;
        }

        $created->refresh();
        $findings = $created->findings ?? [];
        $totalCost = array_sum(array_map(
            fn(mixed $m): float => is_array($m) && is_numeric($m['total_cost_usd'] ?? null) ? (float) $m['total_cost_usd'] : 0.0,
            $created->metrics ?? [],
        ));

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
            'Panel size: %d  |  Findings: %d  |  Cost: $%s  |  Review #%d',
            $created->panel_size,
            count($findings),
            number_format($totalCost, 4),
            $created->id,
        ));

        return self::SUCCESS;
    }

    private function cell(mixed $finding, string $key): string
    {
        $value = is_array($finding) ? ($finding[$key] ?? null) : null;

        return is_string($value) || is_numeric($value) ? (string) $value : '';
    }
}
