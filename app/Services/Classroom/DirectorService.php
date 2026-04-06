<?php

namespace App\Services\Classroom;

use App\Models\ClassroomSession;
use App\Models\LessonAgent;
use App\Services\AI\ChatCompletionClient;
use Illuminate\Support\Facades\Log;

/**
 * Phase 3d + Phase3d_Refinements: cumulative turn guardrails and validated director JSON.
 */
class DirectorService
{
    public function __construct(
        private ChatCompletionClient $llm,
    ) {}

    /**
     * @param  list<LessonAgent>  $agents
     */
    public function nextAgentId(ClassroomSession $session, array $agents): ?string
    {
        $state = $session->director_state ?? [];
        $totalTurns = (int) ($state['turn_count'] ?? 0);
        $roundsEmpty = (int) ($state['rounds_without_input'] ?? 0);
        $spokenCount = count($state['agents_spoken'] ?? []);

        $maxTotal = (int) config('classroom.max_total_turns_per_session', 60);
        if ($totalTurns >= $maxTotal) {
            Log::info("[Director] Session turn cap reached ({$totalTurns}), ending");

            return null;
        }

        $maxRoundsNoInput = (int) config('classroom.max_rounds_without_input', 2);
        if ($roundsEmpty >= $maxRoundsNoInput) {
            Log::info("[Director] {$roundsEmpty} rounds without student input — forcing USER cue");

            return 'USER';
        }

        $maxPerRound = (int) config('classroom.max_turns_per_round', 3);
        if ($spokenCount >= $maxPerRound) {
            Log::info("[Director] Round turn limit ({$spokenCount}) reached — forcing USER cue");

            return 'USER';
        }

        $activeAgents = collect($agents)->where('is_active', true)->values();

        if ($activeAgents->count() <= 1) {
            return $this->singleAgentDecision($session, $activeAgents->first());
        }

        return $this->multiAgentDecision($session, $activeAgents->all(), $state);
    }

    private function singleAgentDecision(ClassroomSession $session, ?LessonAgent $agent): ?string
    {
        if (! $agent) {
            return null;
        }
        $state = $session->director_state ?? [];
        $spokenThisRound = count($state['agents_spoken'] ?? []);

        if ($spokenThisRound === 0) {
            return $agent->id;
        }

        return 'USER';
    }

    /**
     * @param  list<LessonAgent>  $agents
     */
    private function multiAgentDecision(ClassroomSession $session, array $agents, array $state): ?string
    {
        if (($state['turn_count'] ?? 0) === 0) {
            $teacher = collect($agents)->firstWhere('role', 'teacher');

            return $teacher?->id;
        }

        $prompt = $this->buildDirectorPrompt($session, $agents, $state);
        try {
            $response = $this->llm->complete(
                'You are the director of a multi-agent K-12 classroom. Output only a JSON object.',
                $prompt,
                120,
            );
        } catch (\Throwable $e) {
            Log::warning('[Director] LLM call failed, falling back to teacher', ['error' => $e->getMessage()]);

            return collect($agents)->firstWhere('role', 'teacher')?->id
                ?? ($agents[0]->id ?? null);
        }

        return $this->parseDirectorDecision($response, $agents);
    }

    /**
     * @param  list<LessonAgent>  $agents
     */
    private function buildDirectorPrompt(ClassroomSession $session, array $agents, array $state): string
    {
        $agentList = collect($agents)
            ->map(fn ($a) => "- id:\"{$a->id}\", name:\"{$a->display_name}\", role:{$a->role}, priority:{$a->priority}")
            ->implode("\n");

        $spoken = $state['agents_spoken'] ?? [];
        $spokenList = $spoken === []
            ? 'None yet.'
            : collect($spoken)
                ->map(fn ($s) => "- {$s['name']} ({$s['agent_id']}): \"{$s['preview']}\" [{$s['action_count']} actions]")
                ->implode("\n");

        $wbLedger = $state['whiteboard_ledger'] ?? [];
        $wbCount = count(array_filter($wbLedger, fn ($r) => str_starts_with((string) ($r['action_name'] ?? ''), 'wb_draw_')));
        $wbNote = $wbCount > 5
            ? "\n⚠ Whiteboard has {$wbCount} elements — prefer agents that organize rather than add more."
            : ($wbCount > 0 ? "\nWhiteboard elements: {$wbCount}" : '');

        $roundsEmpty = (int) ($state['rounds_without_input'] ?? 0);
        $totalTurns = (int) ($state['turn_count'] ?? 0);
        $spokenCount = count($spoken);

        $engagementHint = match (true) {
            $spokenCount >= 2 => "\n⚠ IMPORTANT: {$spokenCount} agents have spoken this round. You MUST output USER unless there is a very specific reason for one more agent.",
            $roundsEmpty >= 1 => "\n⚠ The student has not responded in {$roundsEmpty} round(s). Strongly consider cueing USER.",
            default => '',
        };

        return <<<PROMPT
# Available agents
{$agentList}

# Already spoke this round
{$spokenList}{$wbNote}{$engagementHint}

# Rules
1. Teacher speaks first if no one has spoken yet.
2. After teacher, one student agent may add a reaction (question, observation).
3. NEVER repeat an agent who already spoke this round.
4. Prefer brevity — 1-2 agents per round maximum.
5. Output USER when a direct question is posed to the student, or when discussion is complete.
6. Output END when the topic is fully covered and no agent would add value.
7. ROLE DIVERSITY: Do not dispatch two teacher-role agents in a row.
8. Current turn: {$totalTurns}. Rounds without student input: {$roundsEmpty}.

# Output (JSON only — nothing else)
{"next_agent":"<agent_id>"} or {"next_agent":"USER"} or {"next_agent":"END"}
PROMPT;
    }

    /**
     * @param  list<LessonAgent>  $agents
     */
    private function parseDirectorDecision(string $response, array $agents): ?string
    {
        if (preg_match('/\{[^}]*"next_agent"\s*:\s*"([^"]+)"[^}]*\}/s', $response, $m)) {
            $val = trim($m[1]);
            if ($val === 'END') {
                return null;
            }
            if ($val === 'USER') {
                return 'USER';
            }

            $validIds = collect($agents)->pluck('id')->all();
            if (in_array($val, $validIds, true)) {
                return $val;
            }

            Log::warning("[Director] Unknown agent ID '{$val}' — falling back to USER");

            return 'USER';
        }

        Log::warning('[Director] Could not parse decision from: '.mb_substr($response, 0, 200));

        return 'USER';
    }

    /**
     * @param  list<array<string, mixed>>  $wbActions
     */
    public function recordAgentTurn(
        ClassroomSession $session,
        string $agentId,
        string $agentName,
        string $contentPreview,
        int $actionCount,
        array $wbActions,
    ): void {
        $state = $session->director_state ?? [];
        $state['whiteboard_ledger'] = $state['whiteboard_ledger'] ?? [];

        $state['turn_count'] = ($state['turn_count'] ?? 0) + 1;
        $state['agents_spoken'][] = [
            'agent_id' => $agentId,
            'name' => $agentName,
            'preview' => mb_substr($contentPreview, 0, 100),
            'action_count' => $actionCount,
        ];
        foreach ($wbActions as $action) {
            $state['whiteboard_ledger'][] = $action;
        }

        $session->update(['director_state' => $state]);
    }

    public function startNewRound(ClassroomSession $session, bool $studentReplied): void
    {
        $state = $session->director_state ?? [];
        $agentSpokeLast = count($state['agents_spoken'] ?? []) > 0;

        if ($agentSpokeLast && ! $studentReplied) {
            $state['rounds_without_input'] = ($state['rounds_without_input'] ?? 0) + 1;
        } else {
            $state['rounds_without_input'] = 0;
        }

        $state['agents_spoken'] = [];

        $session->update(['director_state' => $state]);
    }
}
