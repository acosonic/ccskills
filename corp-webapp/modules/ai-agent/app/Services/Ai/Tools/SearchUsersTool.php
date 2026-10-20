<?php

namespace App\Services\Ai\Tools;

use App\Models\User;
use App\Services\Ai\TextExtractor;
use App\Services\Ai\Tool;

/**
 * Primer alata za čitanje nad tabelom koja postoji u starteru (users). Po istom obrascu pravi alate za tabele
 * aplikacije: dozvole → filtri → pretraga po korenu reči (pismo i padeži) → kratki redovi sa 'link'.
 */
class SearchUsersTool extends Tool
{
    public function name(): string { return 'search_users'; }

    public function progress(): string { return __('ai.p_search_users'); }

    public function description(): string
    {
        return 'Pretraga korisnika aplikacije po imenu, korisničkom imenu ili mejlu (ćirilica ili latinica). '
            . 'Vraća id, ime, korisničko ime, ulogu, da li je aktivan, AD ili lokalni nalog i link.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => [
            'query' => ['type' => 'string', 'description' => 'reči iz imena; prazno = svi'],
            'role' => ['type' => 'string', 'enum' => ['admin', 'supervisor', 'worker']],
            'active' => ['type' => 'boolean'],
            'limit' => ['type' => 'integer', 'description' => 'najviše redova, podrazumevano 30'],
        ]];
    }

    /** Korisnike vide samo admin i supervizor — kao stranica Korisnici. */
    public static function allowedFor(User $user): bool
    {
        return $user->canManage();
    }

    public function run(array $in): array
    {
        $q = User::query()->orderBy('name');
        if (! empty($in['role'])) $q->where('role', $in['role']);
        if (isset($in['active'])) $q->where('is_active', (bool) $in['active']);
        $roots = TextExtractor::roots((string) ($in['query'] ?? ''));
        $limit = max(1, min(200, (int) ($in['limit'] ?? 30)));

        $rows = []; $total = 0;
        foreach ($q->cursor() as $u) {
            if (! TextExtractor::matches($u->name . ' ' . $u->username . ' ' . $u->email, $roots)) continue;
            $total++;
            if (count($rows) >= $limit) continue;
            $rows[] = ['id' => $u->id, 'name' => $u->name, 'username' => $u->username, 'role' => $u->role,
                       'active' => (bool) $u->is_active, 'account' => $u->ldap_user ? 'AD' : 'lokalni',
                       'link' => route('users.edit', $u)];
        }

        return ['total_found' => $total, 'shown' => count($rows), 'users' => $rows];
    }
}
