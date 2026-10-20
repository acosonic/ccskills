<?php

namespace App\Services\Ai\Tools;

use App\Models\User;
use App\Services\Ai\ProposalTool;
use RuntimeException;

/**
 * Primer izmene uz potvrdu nad tabelom iz startera: uloga i aktivnost korisnika. Po istom obrascu: prepare()
 * proverava i opisuje „staro → novo", execute() ponovo proverava i upisuje istim putem kao forma (UserController).
 */
class ChangeUserProposal extends ProposalTool
{
    private const ROLES = ['admin', 'supervisor', 'worker'];

    public function name(): string { return 'propose_user_change'; }

    public function description(): string
    {
        return 'Ništa ne menja sam: pravi PREDLOG koji korisnik potvrđuje dugmetom ispod odgovora. '
            . 'Promena uloge (admin|supervisor|worker) ili aktiviranje/deaktiviranje korisnika. Pre predloga nađi korisnika alatom search_users.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => [
            'user_id' => ['type' => 'integer'],
            'role' => ['type' => 'string', 'enum' => self::ROLES],
            'active' => ['type' => 'boolean'],
            'reason' => ['type' => 'string', 'description' => 'kratko zašto (vidi se na kartici)'],
        ], 'required' => ['user_id']];
    }

    /** Korisnike menja samo admin (kao u aplikaciji). */
    public static function allowedFor(User $user): bool
    {
        return $user->isAdmin();
    }

    public function prepare(array $in): array
    {
        $u = User::find((int) $in['user_id']) ?? throw new RuntimeException('Korisnik ne postoji.');
        $data = ['user_id' => $u->id]; $lines = [];
        if (isset($in['role']) && $in['role'] !== $u->role) {
            if (! in_array($in['role'], self::ROLES, true)) throw new RuntimeException('Uloga: ' . implode('|', self::ROLES));
            if ($u->id === $this->user->id) throw new RuntimeException('Sopstvenu ulogu korisnik ne menja preko asistenta.');
            $data['role'] = $in['role']; $lines[] = "Uloga: {$u->role} → {$in['role']}";
        }
        if (isset($in['active']) && (bool) $in['active'] !== (bool) $u->is_active) {
            if ($u->id === $this->user->id) throw new RuntimeException('Sopstveni nalog se ne deaktivira preko asistenta.');
            $data['is_active'] = (bool) $in['active'];
            $lines[] = 'Nalog: ' . ($u->is_active ? 'aktivan' : 'neaktivan') . ' → ' . ($in['active'] ? 'aktivan' : 'neaktivan');
        }
        if (! $lines) throw new RuntimeException('Nema izmena — vrednosti su već takve.');
        if (! empty($in['reason'])) $lines[] = 'Razlog: ' . $in['reason'];

        return ['title' => "Korisnik {$u->name} ({$u->username})", 'lines' => $lines, 'data' => $data];
    }

    public function execute(array $data): array
    {
        if (! $this->user->isAdmin()) throw new RuntimeException('Samo administrator menja korisnike.');
        $u = User::find($data['user_id']) ?? throw new RuntimeException('Korisnik više ne postoji.');
        $u->fill(array_intersect_key($data, array_flip(['role', 'is_active'])))->save();
        logger()->info('AI asistent (potvrđeno): izmena korisnika', ['by' => $this->user->username, 'user' => $u->username, 'data' => $data]);

        return ['message' => 'Korisnik je izmenjen.', 'link' => route('users.edit', $u)];
    }
}
