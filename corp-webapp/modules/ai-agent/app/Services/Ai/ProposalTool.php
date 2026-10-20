<?php

namespace App\Services\Ai;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Izmena uz potvrdu. Model poziva alat, ali NIŠTA se ne upisuje: prepare() proveri predlog i opiše ga
 * („polje: staro → novo"), predlog ide u ai_proposals, korisnik ispod odgovora vidi karticu Potvrdi / Odustani.
 * Tek na Potvrdi: execute() PONOVO proveri dozvole i stanje (prošlo je vreme) i upiše istim putem kao forma
 * aplikacije (isti servis/model, isti log).
 *
 * Ne pravi: brisanje, promenu dozvola i naloga, masovne izmene jednim predlogom, slanje van sistema bez pregleda.
 */
abstract class ProposalTool extends Tool
{
    /** Predlozi napravljeni u ovom pitanju — kontroler ih vraća u event „done" (kartice) i veže za poruku. */
    public static array $created = [];

    public function __construct(User $user, protected ?int $conversationId = null)
    {
        parent::__construct($user);
    }

    /**
     * Provera + opis. Vraća ['title' => …, 'lines' => ['Status: novo → u toku', …], 'data' => normalizovani podaci].
     * Baca RuntimeException kad predlog nije moguć (model se ispravi).
     */
    abstract public function prepare(array $input): array;

    /** Ponovna provera + upis. Vraća ['message' => …, 'link' => ?url]. */
    abstract public function execute(array $data): array;

    public function progress(): string
    {
        return __('ai.preparing_change');
    }

    /** Samo korisnici koji smeju da menjaju dobijaju alate za izmene. */
    public static function allowedFor(User $user): bool
    {
        return $user->canManage();
    }

    final public function run(array $input): array
    {
        $p = $this->prepare($input);
        $id = DB::table('ai_proposals')->insertGetId([
            'user_id' => $this->user->id, 'conversation_id' => $this->conversationId, 'tool' => static::class,
            'title' => $p['title'], 'lines' => json_encode($p['lines'], JSON_UNESCAPED_UNICODE),
            'data' => json_encode($p['data'], JSON_UNESCAPED_UNICODE), 'status' => 'proposed',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        self::$created[] = ['id' => $id, 'title' => $p['title'], 'lines' => $p['lines'], 'status' => 'proposed'];

        return ['proposal_id' => $id, 'state' => 'ČEKA POTVRDU — ništa nije upisano; korisnik vidi karticu i dugme Potvrdi.', 'lines' => $p['lines']];
    }
}
