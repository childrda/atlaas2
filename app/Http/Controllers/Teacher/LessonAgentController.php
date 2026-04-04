<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\ClassroomLesson;
use App\Models\LessonAgent;
use App\Services\Classroom\PromptAddendumSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LessonAgentController extends Controller
{
    public function update(Request $request, ClassroomLesson $lesson, LessonAgent $agent): RedirectResponse
    {
        $this->authorize('update', $lesson);
        abort_unless($agent->lesson_id === $lesson->id, 404);

        $data = $request->validate([
            'system_prompt_addendum' => 'nullable|string|max:2500',
            'display_name' => 'sometimes|string|max:50',
            'is_active' => 'sometimes|boolean',
        ]);

        if (array_key_exists('system_prompt_addendum', $data)) {
            $data['system_prompt_addendum'] = PromptAddendumSanitizer::sanitize($data['system_prompt_addendum']);
        }

        $agent->update($data);

        return back()->with('success', 'Agent settings updated.');
    }
}
