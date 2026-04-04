<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\LearningSpace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StudentJoinController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Student/Join');
    }

    public function join(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => 'required|string|max:10']);
        $code = strtoupper(trim($data['code']));

        $classroom = Classroom::withoutGlobalScope('district')
            ->where('join_code', $code)
            ->where('district_id', $request->user()->district_id)
            ->first();

        if ($classroom) {
            $classroom->students()->syncWithoutDetaching([
                $request->user()->id => ['enrolled_at' => now()],
            ]);

            return redirect()->route('student.dashboard')
                ->with('success', "Joined {$classroom->name}!");
        }

        $space = LearningSpace::withoutGlobalScope('district')
            ->where('join_code', $code)
            ->where('district_id', $request->user()->district_id)
            ->where('is_published', true)
            ->where('is_archived', false)
            ->first();

        if ($space) {
            return redirect()->route('student.spaces.show', $space);
        }

        return back()->withErrors(['code' => 'Code not found. Check with your teacher.']);
    }
}
