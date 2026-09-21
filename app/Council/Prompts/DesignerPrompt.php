<?php

declare(strict_types=1);

namespace App\Council\Prompts;

final class DesignerPrompt
{
    /**
     * @param  array<int, array{role: string, content: string}>  $history
     */
    public static function userPrompt(array $history, string $spec, string $message): string
    {
        $transcript = implode("\n\n", array_map(
            fn(array $turn): string => mb_strtoupper($turn['role']) . ': ' . $turn['content'],
            $history,
        ));

        $current = $spec === '' ? '(empty: this is a new spec)' : $spec;

        return "Conversation so far:\n\n" . $transcript
            . "\n\nCurrent spec:\n\n```yaml\n" . $current . "\n```"
            . "\n\nUSER: " . $message;
    }
}
