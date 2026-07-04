<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Reviews\CreateReviewAction;
use App\Council\Dimension;
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

        // The panel/judge pipeline now runs as a dispatched job batch (Task 5)
        // instead of synchronously in this call, so the review returned here
        // is queued/running, not yet carrying findings. Live progress and a
        // results view land with the async CLI rework in Task 7.
        $result = $review(File::get($path), $mode, $path, $dimension);

        $this->info(sprintf('Review #%d %s.', $result->id, $result->status));

        return self::SUCCESS;
    }
}
