<?php

declare(strict_types=1);

namespace App\Actions\Design;

use App\Ai\Agents\Designer;
use App\Council\Exceptions\DesignerException;
use App\Council\Prompts\DesignerPrompt;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final readonly class RunDesignTurnAction
{
    private const int HISTORY_LIMIT = 20;

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array{spec: string, reply: string}
     */
    public function __invoke(string $spec, string $message, array $history): array
    {
        $history = array_slice($history, -self::HISTORY_LIMIT);
        $base = DesignerPrompt::userPrompt($history, $spec, $message);
        $errors = [];

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $prompt = $attempt === 0
                ? $base
                : $base . "\n\nYour previous spec did not parse: " . end($errors)
                . '. Return the complete, valid YAML spec.';

            $response = new Designer()->prompt(
                $prompt,
                provider: Lab::OpenRouter,
                model: $this->model(),
                timeout: config()->integer('oast.timeout'),
            );

            $structured = $response instanceof StructuredAgentResponse ? $response->toArray() : [];
            $draft = is_string($structured['spec'] ?? null) ? $structured['spec'] : '';
            $reply = is_string($structured['reply'] ?? null) ? $structured['reply'] : '';

            $error = $this->parseError($draft);
            if ($error === null) {
                return ['spec' => $draft, 'reply' => $reply];
            }

            $errors[] = $error;
        }

        throw DesignerException::invalidOutput($errors);
    }

    public function model(): string
    {
        $designer = config('oast.designer');

        return is_string($designer) && $designer !== '' ? $designer : config()->string('oast.judge');
    }

    private function parseError(string $draft): ?string
    {
        try {
            $document = Yaml::parse($draft);
        } catch (ParseException $parseException) {
            return $parseException->getMessage();
        }

        if (! is_array($document) || ! isset($document['openapi'])) {
            return 'document has no top-level openapi key';
        }

        return null;
    }
}
