<?php

namespace App\Services\Ai;

use App\Models\User;
use Anthropic\Client;

/**
 * Sklapa agenta za korisnika: alati iz config('ai.tools') i config('ai.proposals') kojima korisnik ima pravo
 * (Tool::allowedFor), prompt iz config('ai.prompt') + promenljivi deo (datum, korisnik, uloga).
 */
class Toolbox
{
    public static function agent(User $user, ?int $conversationId = null): Agent
    {
        $tools = [];
        foreach (config('ai.tools') as $class) {
            if ($class::allowedFor($user)) $tools[] = new $class($user);
        }
        foreach (config('ai.proposals') as $class) {
            if ($class::allowedFor($user)) $tools[] = new $class($user, $conversationId);
        }

        return new Agent(new Client(apiKey: config('ai.key')), config('ai.model'), config('ai.effort'), $tools, config('ai.max_steps'));
    }

    public static function stablePrompt(): string
    {
        return strtr(config('ai.prompt'), [':app' => config('app.name'), ':org' => config('brand.org', '')]);
    }

    public static function volatilePrompt(User $user): string
    {
        $scope = $user->canManage() ? 'vidi sve zapise' : 'vidi samo svoje zapise';

        return 'Danas je ' . now()->format('d.m.Y.') . ". Korisnik: {$user->name} — uloga {$user->role} ($scope). Adresa aplikacije: " . url('/') . '.';
    }

    public static function find(string $class, User $user, ?int $conversationId = null): ProposalTool
    {
        abort_unless(in_array($class, config('ai.proposals'), true), 404);

        return new $class($user, $conversationId);
    }
}
