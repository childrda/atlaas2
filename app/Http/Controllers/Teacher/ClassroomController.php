<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ClassroomController extends Controller
{
    public function index(Request $request): Response
    {
        $classrooms = Classroom::where('teacher_id', auth()->id())
            ->withCount('students')
            ->latest()
            ->paginate(20);

        return Inertia::render('Teacher/Classrooms/Index', [
            'classrooms' => $classrooms,
            'schools' => $request->user()->school_id === null
                ? School::where('district_id', $request->user()->district_id)->orderBy('name')->get(['id', 'name'])
                : [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'subject' => 'nullable|string|max:100',
            'grade_level' => 'nullable|string|max:20',
        ]);

        $schoolId = $request->user()->school_id;
        if ($schoolId === null) {
            $more = $request->validate([
                'school_id' => ['required', 'uuid', Rule::exists('schools', 'id')->where('district_id', $request->user()->district_id)],
            ]);
            $schoolId = $more['school_id'];
        }

        $classroom = Classroom::create([
            ...$data,
            'district_id' => $request->user()->district_id,
            'school_id' => $schoolId,
            'teacher_id' => $request->user()->id,
        ]);

        return redirect()->route('teacher.classrooms.show', $classroom)
            ->with('success', 'Classroom created.');
    }

    public function show(Classroom $classroom): Response
    {
        $this->authorize('view', $classroom);

        return Inertia::render('Teacher/Classrooms/Show', [
            'classroom' => $classroom->load('students', 'spaces'),
        ]);
    }

    public function update(Request $request, Classroom $classroom): RedirectResponse
    {
        $this->authorize('update', $classroom);

        $classroom->update($request->validate([
            'name' => 'required|string|max:100',
            'subject' => 'nullable|string|max:100',
            'grade_level' => 'nullable|string|max:20',
        ]));

        return back()->with('success', 'Classroom updated.');
    }

    public function destroy(Classroom $classroom): RedirectResponse
    {
        $this->authorize('delete', $classroom);
        $classroom->delete();

        return redirect()->route('teacher.classrooms.index')
            ->with('success', 'Classroom archived.');
    }

    public function addStudent(Request $request, Classroom $classroom): RedirectResponse
    {
        $this->authorize('update', $classroom);

        $data = $request->validate(['email' => 'required|email']);

        $student = User::where('email', $data['email'])
            ->where('district_id', $request->user()->district_id)
            ->whereHas('roles', fn ($q) => $q->where('name', 'student'))
            ->first();

        if (! $student) {
            return back()->withErrors(['email' => 'No student found with that email in your district.']);
        }

        $classroom->students()->syncWithoutDetaching([
            $student->id => ['enrolled_at' => now()],
        ]);

        return back()->with('success', "{$student->name} added to classroom.");
    }

    public function removeStudent(Classroom $classroom, User $student): RedirectResponse
    {
        $this->authorize('update', $classroom);
        $classroom->students()->detach($student->id);

        return back()->with('success', 'Student removed from classroom.');
    }
}
