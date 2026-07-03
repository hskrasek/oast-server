<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Council\Dimension;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;

final readonly class Panelist implements Agent, HasTools
{
    use Promptable;

    public function __construct(
        private Dimension $dimension = Dimension::DomainModeling,
    ) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return File::get(resource_path(sprintf('prompts/panelist-%s.md', $this->dimension->value)));
    }

    /**
     * Get the tools available to the agent.
     *
     * @return Tool[]
     */
    public function tools(): iterable
    {
        return [];
    }
}
