<?php

namespace App\Http\Controllers;

use App\Services\Ai\ProposalTool;
use App\Services\Ai\Toolbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * AI asistent: strana razgovora, pitanje (SSE: „step" za svaki alat, pa „done"), istorija, potvrda predloga.
 */
class AiController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(config('ai.enabled'), 404);

        return view('ai.index', ['conversations' => $this->list($request)]);
    }

    public function conversations(Request $request)
    {
        return response()->json(['conversations' => $this->list($request)]);
    }

    public function show(Request $request, int $id)
    {
        $c = $this->own($request, $id);
        $messages = DB::table('ai_messages')->where('conversation_id', $c->id)->orderBy('id')->get(['id', 'role', 'content']);
        $proposals = DB::table('ai_proposals')->where('conversation_id', $c->id)->orderBy('id')->get()
            ->map(fn ($p) => ['id' => $p->id, 'message_id' => $p->message_id, 'title' => $p->title, 'lines' => json_decode($p->lines, true),
                              'status' => $p->status, 'result' => $p->result, 'link' => $p->link])
            ->groupBy('message_id');

        return response()->json(['messages' => $messages, 'proposals' => $proposals]);
    }

    public function destroy(Request $request, int $id)
    {
        DB::table('ai_conversations')->where('id', $this->own($request, $id)->id)->delete();

        return response()->json(['ok' => true]);
    }

    /** Pitanje. Accept: text/event-stream → SSE; inače JSON (stari klijenti, testovi). */
    public function ask(Request $request)
    {
        abort_unless(config('ai.enabled'), 404);
        $data = $request->validate(['q' => 'required|string|max:4000', 'conversation_id' => 'nullable|integer']);
        $user = $request->user();
        $new = empty($data['conversation_id']);
        $cid = $new
            ? DB::table('ai_conversations')->insertGetId(['user_id' => $user->id, 'title' => mb_substr($data['q'], 0, 120), 'created_at' => now(), 'updated_at' => now()])
            : $this->own($request, (int) $data['conversation_id'])->id;
        // Istorija iz baze (ne iz pregledača — ne može se podmetnuti)
        $history = DB::table('ai_messages')->where('conversation_id', $cid)->orderByDesc('id')->limit(12)->get(['role', 'content'])
            ->reverse()->map(fn ($m) => (array) $m)->values()->all();
        $request->session()->save();   // dug zahtev ne drži sesiju zaključanu

        $run = function (?callable $emit) use ($user, $cid, $new, $data, $history) {
            ProposalTool::$created = [];
            try {
                $r = Toolbox::agent($user, $cid)->answer(Toolbox::stablePrompt(), Toolbox::volatilePrompt($user), $data['q'], $history,
                    $emit ? fn ($label) => $emit('step', ['label' => $label]) : null);
            } catch (Throwable $e) {
                // Novi razgovor bez odgovora se ne čuva (inače lista puni prazne stavke)
                if ($new && ! DB::table('ai_messages')->where('conversation_id', $cid)->exists()) DB::table('ai_conversations')->where('id', $cid)->delete();

                return ['error' => $e->getMessage()];
            }
            $now = now();
            DB::table('ai_messages')->insert(['conversation_id' => $cid, 'role' => 'user', 'content' => $data['q'], 'created_at' => $now, 'updated_at' => $now]);
            $mid = DB::table('ai_messages')->insertGetId(['conversation_id' => $cid, 'role' => 'assistant', 'content' => $r['answer'],
                'meta' => json_encode(['tools' => $r['tools'], 'usage' => $r['usage'], 'model' => config('ai.model')]), 'created_at' => $now, 'updated_at' => $now]);
            if (ProposalTool::$created) DB::table('ai_proposals')->whereIn('id', array_column(ProposalTool::$created, 'id'))->update(['message_id' => $mid]);
            DB::table('ai_conversations')->where('id', $cid)->update(['updated_at' => $now]);
            logger()->info('AI pitanje', ['user' => $user->username, 'tools' => $r['tools'], 'usage' => $r['usage']]);

            return ['ok' => true, 'answer' => $r['answer'], 'conversation_id' => $cid, 'proposals' => ProposalTool::$created];
        };

        if (! str_contains((string) $request->header('Accept'), 'text/event-stream')) {
            $out = $run(null);

            return response()->json($out, isset($out['error']) ? 422 : 200);
        }

        return new StreamedResponse(function () use ($run) {
            @set_time_limit(600);
            while (ob_get_level()) ob_end_flush();
            $emit = function (string $event, array $payload) {
                echo "event: $event\ndata: " . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
                @ob_flush(); flush();
            };
            $out = $run($emit);
            $emit(isset($out['error']) ? 'error' : 'done', $out);
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'X-Accel-Buffering' => 'no']);
    }

    /** Potvrdi / Odustani. Samo korisnik koji je dobio predlog; predlog se prvo zauzme (dvoklik ne izvrši dvaput). */
    public function decide(Request $request, int $id)
    {
        $decision = $request->validate(['decision' => 'required|in:confirm,cancel'])['decision'];
        $user = $request->user();
        $p = DB::table('ai_proposals')->where('id', $id)->where('user_id', $user->id)->first() ?? abort(404);
        if ($p->status !== 'proposed') return response()->json(['ok' => true, 'status' => $p->status, 'message' => $p->result, 'link' => $p->link]);

        $finish = function (string $status, string $message, ?string $link = null) use ($p) {
            DB::table('ai_proposals')->where('id', $p->id)->update(['status' => $status, 'result' => $message, 'link' => $link, 'resolved_at' => now(), 'updated_at' => now()]);
            if ($p->conversation_id) {
                $mark = ['done' => '✅', 'cancelled' => '✖', 'failed' => '⚠️'][$status];
                DB::table('ai_messages')->insert(['conversation_id' => $p->conversation_id, 'role' => 'assistant',
                    'content' => "$mark {$p->title} — $message", 'created_at' => now(), 'updated_at' => now()]);
            }

            return response()->json(['ok' => true, 'status' => $status, 'message' => $message, 'link' => $link]);
        };
        if ($decision === 'cancel') return $finish('cancelled', __('ai.nothing_changed'));
        if (! DB::table('ai_proposals')->where('id', $p->id)->where('status', 'proposed')->update(['status' => 'running'])) {
            return response()->json(['ok' => true, 'status' => 'running', 'message' => __('ai.already_running')]);
        }
        try {
            // Bez transakcije oko execute(): akcija sme da šalje mejl (SMTP ne sme da drži bazu zaključanu)
            $r = Toolbox::find($p->tool, $user, $p->conversation_id)->execute(json_decode($p->data, true));

            return $finish('done', $r['message'], $r['link'] ?? null);
        } catch (Throwable $e) {
            return $finish('failed', $e->getMessage());
        }
    }

    private function list(Request $request)
    {
        return DB::table('ai_conversations')->where('user_id', $request->user()->id)->orderByDesc('updated_at')->limit(50)->get(['id', 'title', 'updated_at']);
    }

    private function own(Request $request, int $id): object
    {
        return DB::table('ai_conversations')->where('id', $id)->where('user_id', $request->user()->id)->first() ?? abort(404);
    }
}
