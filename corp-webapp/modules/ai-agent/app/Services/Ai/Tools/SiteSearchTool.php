<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\SiteIndex;
use App\Services\Ai\Tool;
use RuntimeException;

/** Varijanta B: pretraga indeksiranog javnog sajta (php artisan ai:index-site). */
class SiteSearchTool extends Tool
{
    public function name(): string { return 'search_site'; }

    public function progress(): string { return __('ai.p_site_search'); }

    public function description(): string
    {
        return 'Pretraga sadržaja sajta po rečima (ćirilica ili latinica, probaj i sinonime). Vraća odlomke sa naslovom i URL-om strane.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']];
    }

    public function run(array $in): array
    {
        if (trim((string) ($in['query'] ?? '')) === '') throw new RuntimeException('Unesite reči za pretragu.');

        return ['results' => SiteIndex::search((string) $in['query'])];
    }
}
