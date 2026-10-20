<?php

namespace App\Console\Commands;

use App\Services\Ai\SiteIndex;
use Illuminate\Console\Command;

/** php artisan ai:index-site [url] — indeks javnog sajta za asistenta sajta (cron dnevno ili posle objave). */
class AiIndexSite extends Command
{
    protected $signature = 'ai:index-site {url? : početna adresa sajta (podrazumevano AI_SITE_URL)} {--max=500}';

    protected $description = 'Indeksira javni sajt (sitemap → FTS5) za AI asistenta sajta';

    public function handle(): int
    {
        $url = $this->argument('url') ?: config('ai.site.url');
        if (! $url) { $this->error('Nema adrese: AI_SITE_URL u .env ili argument.'); return self::FAILURE; }
        $n = SiteIndex::crawl($url, (int) $this->option('max'), fn ($l) => $this->line($l));
        $this->info("Indeksirano strana: $n");

        return $n ? self::SUCCESS : self::FAILURE;
    }
}
