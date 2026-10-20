<?php

namespace App\Services\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\RateLimitException;
use RuntimeException;
use Throwable;

/**
 * AI asistent kao agent: Claude (zvanični PHP SDK) + ručna petlja alata.
 *
 * Model sam bira alate i poziva ih koliko treba (pretraga → detalj → dokument → odgovor). Alati samo čitaju i
 * poštuju dozvole korisnika; izmene idu kroz ProposalTool (predlog → korisnik potvrdi u UI-ju).
 *
 *   $agent = new Agent(new Client(apiKey: …), 'claude-opus-5-5', 'medium', $alati, 14);
 *   $r = $agent->answer($stalniPrompt, $promenljiviPrompt, $pitanje, $istorija, fn (string $naziv) => …);
 *   // ['answer' => markdown, 'tools' => [imena], 'usage' => ['input', 'output', 'cache_read']]
 */
class Agent
{
    /** @param Tool[] $tools */
    public function __construct(
        private Client $client,
        private string $model,
        private string $effort,
        private array $tools,
        private int $maxSteps = 14,
    ) {
        $this->tools = [];
        foreach ($tools as $t) $this->tools[$t->name()] = $t;
    }

    /**
     * @param array $history  [['role' => 'user'|'assistant', 'content' => tekst], …] — prethodni krugovi iz baze (tekst)
     * @param callable|null $onTool fn(string $progressLabel, string $toolName, array $input) — napredak za UI (SSE)
     */
    public function answer(string $systemStable, string $systemVolatile, string $question, array $history = [], ?callable $onTool = null): array
    {
        $messages = $this->normalizeHistory($history);
        $messages[] = ['role' => 'user', 'content' => $question];

        // Alati + stabilni deo prompta čine keširani prefiks; datum/korisnik idu u drugi blok (ne kvare keš)
        $system = [
            ['type' => 'text', 'text' => $systemStable, 'cacheControl' => ['type' => 'ephemeral']],
            ['type' => 'text', 'text' => $systemVolatile],
        ];
        $defs = array_values(array_map(fn (Tool $t) => [
            'name' => $t->name(), 'description' => $t->description(), 'inputSchema' => $t->schema(),
        ], $this->tools));

        $used = []; $usage = ['input' => 0, 'output' => 0, 'cache_read' => 0];
        for ($step = 0; $step < $this->maxSteps; $step++) {
            $response = $this->call($system, $defs, $messages);
            $usage['input'] += $response->usage->inputTokens;
            $usage['output'] += $response->usage->outputTokens;
            $usage['cache_read'] += (int) $response->usage->cacheReadInputTokens;

            if ($response->stopReason === 'refusal') {
                throw new RuntimeException(__('ai.refused'));
            }
            // Sadržaj odgovora se vraća NEPROMENJEN (i thinking blokovi) — samo dopisujemo, nikad ne menjamo istoriju
            $messages[] = ['role' => 'assistant', 'content' => $response->content];

            $calls = array_values(array_filter($response->content, fn ($b) => $b->type === 'tool_use'));
            if ($response->stopReason !== 'tool_use' || ! $calls) {
                $text = implode("\n\n", array_map(fn ($b) => $b->text, array_filter($response->content, fn ($b) => $b->type === 'text')));
                if ($response->stopReason === 'max_tokens') $text .= "\n\n_(" . __('ai.truncated') . ')_';

                return ['answer' => trim($text) ?: __('ai.no_answer'), 'tools' => $used, 'usage' => $usage];
            }

            // Svi rezultati jednog kruga u JEDNOJ korisničkoj poruci (paralelni pozivi alata)
            $results = [];
            foreach ($calls as $call) {
                $tool = $this->tools[$call->name] ?? null;
                $onTool && $onTool($tool?->progress() ?? $call->name, $call->name, $call->input);
                $used[] = $call->name;
                try {
                    if (! $tool) throw new RuntimeException("Nepoznat alat {$call->name}.");
                    $out = $tool->run($call->input);
                    $results[] = ['type' => 'tool_result', 'toolUseID' => $call->id,
                                  'content' => json_encode($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)];
                } catch (Throwable $e) {
                    // Greška je informacija za model — sam se ispravi (druge reči, drugi alat)
                    $results[] = ['type' => 'tool_result', 'toolUseID' => $call->id, 'content' => $e->getMessage(), 'isError' => true];
                }
            }
            $messages[] = ['role' => 'user', 'content' => $results];
        }

        return ['answer' => __('ai.too_many_steps'), 'tools' => $used, 'usage' => $usage];
    }

    private function call(array $system, array $defs, array $messages): object
    {
        try {
            return $this->client->beta->messages->create(
                model: $this->model,
                maxTokens: 16000,
                system: $system,
                tools: $defs,
                messages: $messages,
                outputConfig: ['effort' => $this->effort],
                fallbacks: 'default',                         // odbijen zahtev se ponovi na preporučenom modelu
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (RateLimitException) {
            throw new RuntimeException(__('ai.busy'));
        } catch (APIConnectionException $e) {
            throw new RuntimeException(__('ai.connection') . ' ' . $e->getMessage());
        } catch (APIStatusException $e) {
            throw new RuntimeException(__('ai.api_error') . ' ' . $e->getMessage());
        }
    }

    /** Samo tekst, naizmenične uloge, ne počinje asistentom i ne završava se korisnikom (sledi novo pitanje). */
    private function normalizeHistory(array $history): array
    {
        $out = [];
        foreach (array_slice($history, -12) as $h) {
            $role = $h['role'] ?? ''; $text = trim((string) ($h['content'] ?? ''));
            if (! in_array($role, ['user', 'assistant'], true) || $text === '') continue;
            if ($out && end($out)['role'] === $role) $out[count($out) - 1]['content'] .= "\n\n" . $text;
            else $out[] = ['role' => $role, 'content' => $text];
        }
        if ($out && $out[0]['role'] === 'assistant') array_shift($out);
        if ($out && end($out)['role'] === 'user') array_pop($out);

        return $out;
    }
}
