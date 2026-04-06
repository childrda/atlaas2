<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\TeacherTool;
use App\Models\ToolRun;
use App\Services\AI\OpenAiChatParameters;
use App\Services\AI\OpenAiUserFacingMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use OpenAI\Laravel\Facades\OpenAI;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ToolkitController extends Controller
{
    public function index(): Response
    {
        $tools = TeacherTool::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('district_id')
                    ->orWhere('district_id', auth()->user()->district_id);
            })
            ->orderByDesc('is_built_in')
            ->orderBy('name')
            ->get();

        $recentRuns = ToolRun::query()
            ->where('teacher_id', auth()->id())
            ->with('tool:id,name,icon,slug')
            ->latest()
            ->limit(5)
            ->get();

        return Inertia::render('Teacher/Toolkit/Index', [
            'tools' => $tools,
            'recentRuns' => $recentRuns,
        ]);
    }

    public function show(TeacherTool $tool): Response
    {
        abort_unless($tool->isAccessibleByUser(auth()->user()), 403);

        return Inertia::render('Teacher/Toolkit/Show', [
            'tool' => $tool,
        ]);
    }

    public function run(Request $request, TeacherTool $tool): StreamedResponse
    {
        abort_unless($tool->isAccessibleByUser(auth()->user()), 403);

        $request->validate(['inputs' => 'required|array']);

        $inputs = $request->input('inputs');
        foreach ($inputs as $key => $value) {
            if ($value === '') {
                $inputs[$key] = null;
            }
        }

        $this->validateInputsAgainstSchema($tool, $inputs);

        $prompt = $this->interpolatePrompt($tool, $inputs);
        $teacher = $request->user();

        return response()->stream(
            function () use ($prompt, $tool, $inputs, $teacher) {
                if (function_exists('apache_setenv')) {
                    @apache_setenv('no-gzip', '1');
                }
                @ini_set('output_buffering', 'off');
                @ini_set('zlib.output_compression', '0');
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }

                $fullOutput = '';

                try {
                    $stream = OpenAI::chat()->createStreamed(
                        OpenAiChatParameters::withMaxOutputTokens([
                            'model' => config('openai.model'),
                            'messages' => [['role' => 'user', 'content' => $prompt]],
                            'temperature' => 0.7,
                        ], 1500)
                    );

                    foreach ($stream as $response) {
                        $chunk = $response->choices[0]->delta->content ?? '';
                        if ($chunk !== '') {
                            $fullOutput .= $chunk;
                            echo 'data: '.json_encode(['type' => 'chunk', 'content' => $chunk])."\n\n";
                            if (ob_get_level() > 0) {
                                ob_flush();
                            }
                            flush();
                        }
                    }

                    ToolRun::query()->create([
                        'teacher_id' => $teacher->id,
                        'tool_id' => $tool->id,
                        'inputs' => $inputs,
                        'output' => $fullOutput,
                    ]);

                    echo 'data: '.json_encode(['type' => 'done'])."\n\n";
                } catch (\Throwable $e) {
                    report($e);
                    echo 'data: '.json_encode([
                        'type' => 'error',
                        'message' => OpenAiUserFacingMessage::from($e),
                    ])."\n\n";
                }

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            },
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
                'Connection' => 'keep-alive',
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    private function validateInputsAgainstSchema(TeacherTool $tool, array $inputs): void
    {
        $schema = $tool->input_schema ?? [];
        $rules = [];

        foreach ($schema as $field) {
            $name = $field['name'] ?? null;
            if (! is_string($name) || $name === '') {
                continue;
            }

            $required = (bool) ($field['required'] ?? false);
            $type = $field['type'] ?? 'text';

            if ($type === 'checkbox_group') {
                $rules[$name] = $required ? ['required', 'array', 'min:1'] : ['nullable', 'array'];
            } elseif ($type === 'number') {
                $rules[$name] = $required ? ['required', 'numeric'] : ['nullable', 'numeric'];
            } else {
                $rules[$name] = $required ? ['required', 'string'] : ['nullable', 'string'];
            }
        }

        $validator = Validator::make($inputs, $rules);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    private function interpolatePrompt(TeacherTool $tool, array $inputs): string
    {
        $prompt = $tool->system_prompt_template;

        foreach ($inputs as $key => $value) {
            $replacement = is_array($value) ? implode(', ', $value) : (string) $value;
            $prompt = str_replace('{{'.$key.'}}', $replacement, $prompt);
        }

        $prompt = preg_replace('/\{\{[^}]+\}\}/', '', $prompt) ?? $prompt;

        return trim($prompt);
    }
}
