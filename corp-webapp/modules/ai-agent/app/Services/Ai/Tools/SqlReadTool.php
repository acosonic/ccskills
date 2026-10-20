<?php

namespace App\Services\Ai\Tools;

use App\Models\User;
use App\Services\Ai\Tool;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SELECT nad bazom aplikacije za pitanja koja ostali alati ne pokrivaju. Samo za korisnike koji vide sve
 * (inače bi zaobišao filter „samo moje"). Zaštite: jedan SELECT/WITH, transakcija READ ONLY (upis puca čak i
 * kad nalog baze ima više prava), zabranjene tabele, najviše 200 redova. Još bolje: AI_DB_* nalog sa samo SELECT pravom.
 *
 * U opisu NABROJ glavne tabele i kolone aplikacije (config('ai.sql_schema')) — inače model pogađa šemu.
 */
class SqlReadTool extends Tool
{
    public function name(): string { return 'sql'; }

    public function progress(): string { return __('ai.p_sql'); }

    public function description(): string
    {
        return 'SELECT upit nad bazom aplikacije (samo čitanje, najviše 200 redova) za pitanja koja ostali alati ne pokrivaju. '
            . 'Baza: ' . DB::connection(config('ai.sql_connection'))->getDriverName() . '. Tabele: ' . config('ai.sql_schema');
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']];
    }

    public static function allowedFor(User $user): bool
    {
        return $user->canManage();
    }

    public function run(array $in): array
    {
        $sql = trim((string) ($in['query'] ?? ''));
        $low = strtolower($sql);
        if (! preg_match('/^(select|with)\b/', $low) || str_contains(rtrim($sql, "; \n"), ';')) {
            throw new RuntimeException('Dozvoljen je samo jedan SELECT upit.');
        }
        foreach (config('ai.sql_forbidden') as $t) {
            if (preg_match('/\b' . preg_quote($t, '/') . '\b/', $low)) throw new RuntimeException("Tabela $t nije dostupna.");
        }
        $pdo = DB::connection(config('ai.sql_connection'))->getPdo();
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') $pdo->exec('START TRANSACTION READ ONLY');
        elseif ($driver === 'pgsql') $pdo->exec('BEGIN READ ONLY');
        else $pdo->beginTransaction();
        try {
            $st = $pdo->query($sql);
            $rows = [];
            while (count($rows) < 200 && ($r = $st->fetch(\PDO::FETCH_ASSOC))) $rows[] = $r;
        } finally {
            $pdo->inTransaction() ? $pdo->rollBack() : $pdo->exec('ROLLBACK');
        }

        return ['rows_count' => count($rows), 'rows' => $rows, 'note' => count($rows) === 200 ? 'prikazano prvih 200' : null];
    }
}
