<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $lesson->title }} — ATLAAS lesson export</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 800px; margin: 2rem auto; color: #1a1a1a; }
        h1 { font-size: 1.5rem; }
        h2 { font-size: 1.1rem; margin-top: 2rem; border-bottom: 1px solid #ddd; padding-bottom: 0.25rem; }
        .meta { color: #666; font-size: 0.9rem; margin-bottom: 2rem; }
        .scene { margin: 1rem 0; padding: 1rem; background: #f9fafb; border-radius: 8px; }
        .agent { margin: 0.5rem 0; padding: 0.5rem; border-left: 3px solid #1E3A5F; }
        pre { white-space: pre-wrap; font-size: 0.85rem; background: #fff; padding: 0.75rem; border-radius: 4px; overflow: auto; }
    </style>
</head>
<body>
    <h1>{{ $lesson->title }}</h1>
    <div class="meta">
        @if($lesson->subject) Subject: {{ $lesson->subject }} · @endif
        @if($lesson->grade_level) Grade {{ $lesson->grade_level }} · @endif
        Language: {{ $lesson->language }}
    </div>

    <h2>Agents</h2>
    @forelse($lesson->agents as $agent)
        <div class="agent">
            <strong>{{ $agent->display_name }}</strong> ({{ $agent->role }}) — {{ $agent->archetype }}
            @if($agent->is_active === false) <em>inactive</em> @endif
            <div style="margin-top:0.5rem;font-size:0.9rem;">{{ \Illuminate\Support\Str::limit($agent->persona_text, 400) }}</div>
            @if($agent->system_prompt_addendum)
                <p style="margin-top:0.5rem;font-size:0.85rem;"><strong>Addendum:</strong> {{ $agent->system_prompt_addendum }}</p>
            @endif
        </div>
    @empty
        <p>No agents.</p>
    @endforelse

    <h2>Scenes</h2>
    @forelse($lesson->scenes as $scene)
        <div class="scene">
            <strong>{{ $scene->sequence_order + 1 }}. {{ $scene->title }}</strong>
            <span style="color:#666;">({{ $scene->scene_type }})</span>
            @if($scene->learning_objective)
                <p style="margin:0.5rem 0;font-size:0.9rem;">Objective: {{ $scene->learning_objective }}</p>
            @endif
            @if($scene->content)
                <pre>{{ json_encode($scene->content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            @endif
        </div>
    @empty
        <p>No scenes.</p>
    @endforelse

    <p style="margin-top:3rem;font-size:0.75rem;color:#999;">Exported from ATLAAS · {{ now()->toDateTimeString() }}</p>
</body>
</html>
