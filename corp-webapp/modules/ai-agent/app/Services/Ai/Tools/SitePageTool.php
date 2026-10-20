<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\SiteIndex;
use App\Services\Ai\Tool;
use RuntimeException;

/** Varijanta B: ceo tekst jedne indeksirane strane (samo URL-ovi iz pretrage — ne otvara proizvoljne adrese). */
class SitePageTool extends Tool
{
    public function name(): string { return 'read_page'; }

    public function progress(): string { return __('ai.p_site_page'); }

    public function description(): string
    {
        return 'Ceo tekst jedne strane sajta, po URL-u iz rezultata search_site.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => ['url' => ['type' => 'string']], 'required' => ['url']];
    }

    public function run(array $in): array
    {
        $p = SiteIndex::page((string) ($in['url'] ?? '')) ?? throw new RuntimeException('Ta strana nije u indeksu sajta — koristi search_site.');
        $p['text'] = mb_substr($p['text'], 0, 30000);

        return $p;
    }
}
