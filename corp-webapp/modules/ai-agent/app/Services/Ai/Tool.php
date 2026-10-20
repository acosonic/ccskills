<?php

namespace App\Services\Ai;

use App\Models\User;

/**
 * Alat agenta. Model ga bira samo po opisu — description kaže ŠTA vraća i KADA ga koristiti.
 *
 * Pravila: samo čitanje; dozvole u svakom alatu (isti uslov kao liste aplikacije — korisnik ne sme da vidi
 * više nego u aplikaciji); rezultat kratak, sa 'link' poljem uz svaki zapis (apsolutni URL); greška =
 * RuntimeException sa porukom koja pomaže modelu da se ispravi.
 */
abstract class Tool
{
    /** $user je null samo kod javnog asistenta sajta (varijanta B — nema prijave). */
    public function __construct(protected ?User $user = null) {}

    /** Kratko, ASCII, npr. 'search_records'. */
    abstract public function name(): string;

    abstract public function description(): string;

    /** JSON Schema ulaza: ['type' => 'object', 'properties' => [...], 'required' => [...]] */
    abstract public function schema(): array;

    /** Prikaz u UI dok alat radi („Tražim zapise" → „Tražim zapise…"). */
    public function progress(): string
    {
        return __('ai.working');
    }

    /** Vraća niz (ide modelu kao JSON) ili baca RuntimeException. */
    abstract public function run(array $input): array;

    /** Koji korisnik sme da dobije ovaj alat (npr. samo služba za SQL). */
    public static function allowedFor(User $user): bool
    {
        return true;
    }
}
