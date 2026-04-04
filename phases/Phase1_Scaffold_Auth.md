# ATLAAS — Phase 1: Scaffold & Auth

**Augmented Teaching & Learning Assistive AI System**

## Stop when this works: You can log in as a teacher and a student and see different dashboards

---

## What you're building in this phase
- Fresh Laravel 11 project with all core packages installed
- PostgreSQL database with users, districts, schools
- Role-based auth: District Admin, Teacher, Student
- Google OAuth via Socialite
- Two separate portal layouts (teacher shell, student shell)
- Seeded test data so you can log in immediately

**Do not build AI, Spaces, or real-time features yet.**

---

## Step 1 — Create the project

```bash
composer create-project laravel/laravel atlaas
cd atlaas

composer require \
  inertiajs/inertia-laravel \
  laravel/socialite \
  laravel/fortify \
  spatie/laravel-permission \
  spatie/laravel-activitylog \
  predis/predis

npm install
npm install \
  @inertiajs/react \
  react \
  react-dom \
  @types/react \
  @types/react-dom \
  typescript \
  tailwindcss \
  @tailwindcss/forms \
  lucide-react \
  clsx \
  tailwind-merge

php artisan inertia:middleware
php artisan fortify:install
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
```

Configure `vite.config.ts` and `tsconfig.json` for React + TypeScript + Inertia.
Configure `tailwind.config.js` with the content paths.
Set up `resources/js/app.tsx` as the Inertia entry point with `createInertiaApp`.

---

## Step 2 — Configure PostgreSQL

In `.env`:
```
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=atlaas
DB_USERNAME=postgres
DB_PASSWORD=secret
```

```bash
createdb atlaas
```

---

## Step 3 — Base Model and UUID trait

Before writing any other model, create these two files.
Every ATLAAS model uses one of these patterns for UUIDs.

### `app/Models/BaseModel.php`
All models except User extend this:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

abstract class BaseModel extends Model
{
    /**
     * Explicitly boot UUID generation so this works regardless of
     * Laravel version, DB driver, or HasUuids trait behavior.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });
    }

    protected $keyType    = 'string';
    public    $incrementing = false;
}
```

### `app/Traits/HasUuidPrimaryKey.php`
User must extend Authenticatable, so it uses this trait instead:
```php
<?php

namespace App\Traits;

use Illuminate\Support\Str;

trait HasUuidPrimaryKey
{
    protected static function bootHasUuidPrimaryKey(): void
    {
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });
    }

    public function getKeyType(): string    { return 'string'; }
    public function getIncrementing(): bool { return false; }
}
```

---

## Step 4 — Migrations (run in this exact order)

Delete the default Laravel users migration before running yours.

### 4a. Districts
```php
Schema::create('districts', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('name');
    $table->string('slug')->unique();
    $table->string('logo_url')->nullable();
    $table->string('primary_color', 7)->default('#1E3A5F');
    $table->string('accent_color', 7)->default('#F5A623');
    $table->string('sso_provider')->default('local'); // local|google|clever|classlink
    $table->boolean('allow_self_registration')->default(false);
    $table->timestamps();
});
```

### 4b. Schools
```php
Schema::create('schools', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('district_id')->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->timestamps();
});
```

### 4c. Users (replaces Laravel default)
```php
Schema::create('users', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('district_id')->constrained()->cascadeOnDelete();
    $table->foreignUuid('school_id')->nullable()->constrained()->nullOnDelete();
    $table->string('name');
    $table->string('email')->unique();
    $table->string('password')->nullable(); // null = SSO-only account
    $table->string('avatar_url')->nullable();
    $table->string('external_id')->nullable(); // Clever/Google user ID
    $table->string('grade_level')->nullable();
    $table->string('preferred_language', 10)->default('en');
    $table->boolean('is_active')->default(true);
    $table->timestamp('email_verified_at')->nullable();
    $table->rememberToken();
    $table->timestamps();
    $table->softDeletes();
    $table->index(['district_id', 'email']);    // scoped login lookups
    $table->index(['district_id', 'school_id']); // used heavily in Phase 2+ roster queries
});
```

### 4d. Run migrations and publish Spatie tables
```bash
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan migrate
```

---

## Step 5 — Models

### `app/Models/District.php`
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class District extends BaseModel
{
    protected $fillable = [
        'name', 'slug', 'logo_url', 'primary_color',
        'accent_color', 'sso_provider', 'allow_self_registration',
    ];

    protected function casts(): array
    {
        return ['allow_self_registration' => 'boolean'];
    }

    public function schools(): HasMany { return $this->hasMany(School::class); }
    public function users(): HasMany   { return $this->hasMany(User::class); }
}
```

### `app/Models/School.php`
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class School extends BaseModel
{
    protected $fillable = ['district_id', 'name'];

    public function district(): BelongsTo { return $this->belongsTo(District::class); }
    public function users(): HasMany      { return $this->hasMany(User::class); }
}
```

### `app/Models/User.php`
Replace the default entirely:
```php
<?php

namespace App\Models;

use App\Traits\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasUuidPrimaryKey, HasRoles, SoftDeletes;

    protected $fillable = [
        'district_id', 'school_id', 'name', 'email', 'password',
        'avatar_url', 'external_id', 'grade_level', 'preferred_language', 'is_active',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password'          => 'hashed',
            'is_active'         => 'boolean',
            'email_verified_at' => 'datetime',
        ];
    }

    public function district(): BelongsTo { return $this->belongsTo(District::class); }
    public function school(): BelongsTo   { return $this->belongsTo(School::class); }
}
```

---

## Step 6 — District scope note (enforce in Phase 2)

Add this comment block to `app/Providers/AppServiceProvider.php` as a reminder:

```php
public function boot(): void
{
    // ============================================================
    // DISTRICT SCOPING — CRITICAL — DO NOT SKIP
    // ============================================================
    // All queries on district-owned resources MUST be scoped to
    // the authenticated user's district_id.
    //
    // Add this to each model's booted() method in Phase 2+:
    //
    //   static::addGlobalScope('district', function ($query) {
    //       if (auth()->check()) {
    //           $query->where('district_id', auth()->user()->district_id);
    //       }
    //   });
    //
    // Models that need it: Classroom, LearningSpace, StudentSession,
    //                       Message, SafetyAlert, TeacherTool, ToolRun
    //
    // Models that do NOT: District, School, User (own scoping logic)
    //
    // Cross-district data leakage is a FERPA violation.
    // ============================================================
}
```

---

## Step 7 — Seeders

### `database/seeders/RolesAndPermissionsSeeder.php`
```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Role::create(['name' => 'district_admin']);
        Role::create(['name' => 'school_admin']);
        Role::create(['name' => 'teacher']);
        Role::create(['name' => 'student']);
        Role::create(['name' => 'parent']);
    }
}
```

### `database/seeders/TestDataSeeder.php`
```php
<?php

namespace Database\Seeders;

use App\Models\District;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TestDataSeeder extends Seeder
{
    public function run(): void
    {
        $district = District::create([
            'name'         => 'Demo School Division',
            'slug'         => 'demo-division',
            'sso_provider' => 'local',
        ]);

        $school = School::create([
            'district_id' => $district->id,
            'name'        => 'Riverside Elementary',
        ]);

        $admin = User::create([
            'district_id' => $district->id,
            'name'        => 'District Admin',
            'email'       => 'admin@demo.test',
            'password'    => Hash::make('password'),
        ]);
        $admin->assignRole('district_admin');

        $teacher = User::create([
            'district_id' => $district->id,
            'school_id'   => $school->id,
            'name'        => 'Ms. Taylor',
            'email'       => 'teacher@demo.test',
            'password'    => Hash::make('password'),
        ]);
        $teacher->assignRole('teacher');

        $student = User::create([
            'district_id' => $district->id,
            'school_id'   => $school->id,
            'name'        => 'Alex Student',
            'email'       => 'student@demo.test',
            'password'    => Hash::make('password'),
            'grade_level' => '5',
        ]);
        $student->assignRole('student');
    }
}
```

Update `DatabaseSeeder.php`:
```php
public function run(): void
{
    $this->call([
        RolesAndPermissionsSeeder::class,
        TestDataSeeder::class,
    ]);
}
```

```bash
php artisan db:seed
```

---

## Step 8 — Middleware

> **Laravel 11 note for Cursor:** Register middleware in `bootstrap/app.php`
> using the `->withMiddleware()` fluent API.
> **Do NOT create or modify `app/Http/Kernel.php` — it does not exist in Laravel 11.**

### `app/Http/Middleware/EnsureRole.php`
```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (!$request->user() || !$request->user()->hasRole($roles)) {
            abort(403, 'Unauthorized.');
        }

        return $next($request);
    }
}
```

### `bootstrap/app.php` additions
```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'role' => \App\Http\Middleware\EnsureRole::class,
    ]);

    $middleware->web(append: [
        \App\Http\Middleware\HandleInertiaRequests::class,
    ]);
})
```

---

## Step 9 — Auth controllers

### `app/Http/Controllers/Auth/LoginController.php`
```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        if (!Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()
                ->withErrors(['email' => 'Invalid credentials.'])
                ->withInput($request->only('email')); // repopulates email, not password
        }

        $request->session()->regenerate();

        return $this->redirectByRole(Auth::user());
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    // Public so SocialiteController can call the same logic without duplicating it
    public function redirectByRole(User $user): RedirectResponse
    {
        // Explicit per-role checks — easy to split into separate portals later
        if ($user->hasRole('district_admin')) {
            return redirect()->route('teacher.dashboard');
            // TODO: redirect()->route('district.dashboard') when Phase 8+ admin portal is built
        }

        if ($user->hasRole('school_admin')) {
            return redirect()->route('teacher.dashboard');
            // TODO: redirect()->route('admin.dashboard') when admin portal is built
        }

        if ($user->hasRole('teacher')) {
            return redirect()->route('teacher.dashboard');
        }

        if ($user->hasRole('student')) {
            return redirect()->route('student.dashboard');
        }

        Auth::logout();
        return redirect()->route('login')
            ->withErrors(['email' => 'Your account has no role assigned. Contact your administrator.']);
    }
}
```

### `app/Http/Controllers/Auth/SocialiteController.php`
```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class SocialiteController extends Controller
{
    public function redirect(string $provider): RedirectResponse
    {
        return Socialite::driver($provider)->redirect();
    }

    public function callback(string $provider): RedirectResponse
    {
        // stateless() avoids session/state mismatch errors behind load balancers
        // or reverse proxies — safe to use now, required in production
        $socialUser = Socialite::driver($provider)->stateless()->user();

        $user = User::where('email', $socialUser->getEmail())
                    ->where('is_active', true)
                    ->first();

        if (!$user) {
            return redirect()->route('login')
                ->withErrors(['email' => 'No active account found for this email. Contact your district administrator.']);
        }

        $user->update([
            'external_id' => $socialUser->getId(),
            'avatar_url'  => $socialUser->getAvatar(),
        ]);

        Auth::login($user);
        request()->session()->regenerate();

        // Reuse the same redirect logic as password login
        return app(LoginController::class)->redirectByRole($user);
    }
}
```

Configure Google in `config/services.php`:
```php
'google' => [
    'client_id'     => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'redirect'      => env('GOOGLE_REDIRECT_URI'),
],
```

---

## Step 10 — Routes

`routes/web.php`:
```php
<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\SocialiteController;
use App\Http\Controllers\Teacher\TeacherDashboardController;
use App\Http\Controllers\Student\StudentDashboardController;
use Illuminate\Support\Facades\Route;

// Auth
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->name('login.attempt');
    Route::get('/auth/{provider}', [SocialiteController::class, 'redirect'])->name('auth.redirect');
    Route::get('/auth/{provider}/callback', [SocialiteController::class, 'callback'])->name('auth.callback');
});

Route::post('/logout', [LoginController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

// Teacher portal
// TODO: split district_admin and school_admin into /admin portal in Phase 8+
Route::middleware(['auth', 'role:teacher,school_admin,district_admin'])
    ->prefix('teach')
    ->name('teacher.')
    ->group(function () {
        Route::get('/', [TeacherDashboardController::class, 'index'])->name('dashboard');
        // Phases 2–7 add routes here
    });

// Student portal
Route::middleware(['auth', 'role:student'])
    ->prefix('learn')
    ->name('student.')
    ->group(function () {
        Route::get('/', [StudentDashboardController::class, 'index'])->name('dashboard');
        // Phases 2–3 add routes here
    });
```

---

## Step 11 — Stub dashboard controllers

### `app/Http/Controllers/Teacher/TeacherDashboardController.php`
```php
<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class TeacherDashboardController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Teacher/Dashboard', [
            'user' => auth()->user()->load('school', 'district'),
        ]);
    }
}
```

### `app/Http/Controllers/Student/StudentDashboardController.php`
```php
<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class StudentDashboardController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Student/Dashboard', [
            'user' => auth()->user()->load('school', 'district'),
        ]);
    }
}
```

---

## Step 12 — Inertia shared data

`app/Http/Middleware/HandleInertiaRequests.php`:
```php
public function share(Request $request): array
{
    $user = $request->user();

    return [
        ...parent::share($request),
        'auth' => [
            'user' => $user ? [
                'id'         => $user->id,
                'name'       => $user->name,
                'email'      => $user->email,
                'avatar_url' => $user->avatar_url,
                'roles'      => $user->getRoleNames()->toArray(),
                'district'   => $user->district,
                'school'     => $user->school,
            ] : null,
        ],
        'flash' => [
            'success' => $request->session()->get('success'),
            'error'   => $request->session()->get('error'),
        ],
    ];
}
```

---

## Step 13 — TypeScript types and hooks

### `resources/js/types/models.ts`
```typescript
export interface District {
    id: string;
    name: string;
    primary_color: string;
    accent_color: string;
}

export interface School {
    id: string;
    name: string;
}

export interface User {
    id: string;
    name: string;
    email: string;
    avatar_url: string | null;
    roles: string[];
    district: District;
    school: School | null;
}
```

### `resources/js/hooks/useCurrentUser.ts`
```typescript
import { usePage } from '@inertiajs/react';
import type { User } from '@/types/models';

export function useCurrentUser(): User {
    const { auth } = usePage().props as { auth: { user: User } };
    return auth.user;
}
```

---

## Step 14 — React pages and layouts

### `resources/js/Layouts/TeacherLayout.tsx`
Sidebar layout. Navy `#1E3A5F` sidebar, amber `#F5A623` active indicator.

Nav links (render as greyed stubs with "coming soon" for now):
- Dashboard (active)
- My Spaces *(Phase 2)*
- Classrooms *(Phase 2)*
- Compass View *(Phase 5)*
- Toolkit *(Phase 6)*
- Discover *(Phase 7)*

Bottom: user avatar, name, role pill, logout button.
Top: district name (logo in Phase 8).

### `resources/js/Layouts/StudentLayout.tsx`
Top nav only. District name left, user name + logout right.
Clean white background, amber accents.

### `resources/js/Pages/Teacher/Dashboard.tsx`
```tsx
import { useCurrentUser } from '@/hooks/useCurrentUser';
import TeacherLayout from '@/Layouts/TeacherLayout';

export default function TeacherDashboard() {
    const user = useCurrentUser();

    return (
        <TeacherLayout>
            <div className="p-8">
                <h1 className="text-2xl font-medium text-gray-900">
                    Welcome back, {user.name}
                </h1>
                <p className="mt-1 text-gray-500">{user.district.name}</p>

                <div className="mt-8 grid grid-cols-3 gap-6">
                    {[
                        { label: 'Active Spaces',   value: 0 },
                        { label: 'Active Students', value: 0 },
                        { label: 'Open Alerts',     value: 0 },
                    ].map(stat => (
                        <div key={stat.label} className="rounded-lg border border-gray-200 bg-white p-6">
                            <p className="text-sm text-gray-500">{stat.label}</p>
                            <p className="mt-2 text-3xl font-medium text-gray-900">{stat.value}</p>
                        </div>
                    ))}
                </div>

                {/* Dev debug banner — import.meta.env.DEV ensures this never appears in production */}
                {import.meta.env.DEV && (
                    <div className="mt-8 rounded border border-amber-200 bg-amber-50 px-4 py-2 text-xs text-amber-800">
                        <span className="font-medium">Dev:</span>{' '}
                        Role: {user.roles.join(', ')} | District: {user.district.name}
                        {user.school && ` | School: ${user.school.name}`}
                    </div>
                )}
            </div>
        </TeacherLayout>
    );
}
```

### `resources/js/Pages/Student/Dashboard.tsx`
```tsx
import { useCurrentUser } from '@/hooks/useCurrentUser';
import StudentLayout from '@/Layouts/StudentLayout';

export default function StudentDashboard() {
    const user = useCurrentUser();

    return (
        <StudentLayout>
            <div className="mx-auto max-w-2xl px-6 py-16 text-center">
                <h1 className="text-4xl font-medium" style={{ color: '#F5A623' }}>
                    Hello, {user.name.split(' ')[0]}!
                </h1>
                <p className="mt-3 text-lg text-gray-500">
                    Your learning spaces will appear here.
                </p>

                {import.meta.env.DEV && (
                    <div className="mt-12 rounded border border-amber-200 bg-amber-50 px-4 py-2 text-xs text-amber-800">
                        <span className="font-medium">Dev:</span>{' '}
                        Role: {user.roles.join(', ')} | District: {user.district.name}
                    </div>
                )}
            </div>
        </StudentLayout>
    );
}
```

### `resources/js/Pages/Auth/Login.tsx`
- ATLAAS wordmark centered
- Email + password fields (email repopulates on failure via `old` prop)
- "Sign in" submit button
- Divider
- "Sign in with Google" button linking to `/auth/google`
- Display flash error if present via `usePage().props.errors`

---

## Step 15 — Run and verify

```bash
php artisan migrate:fresh --seed
npm run dev
php artisan serve
```

**Checklist — do not move to Phase 2 until all pass:**

Auth:
- [ ] `/login` renders without errors
- [ ] `teacher@demo.test` / `password` → `/teach` → teacher dashboard with sidebar
- [ ] `student@demo.test` / `password` → `/learn` → student dashboard, no sidebar
- [ ] `admin@demo.test` / `password` → `/teach` (teacher dashboard for now)
- [ ] Logout from any role → `/login`
- [ ] Failed login repopulates the email field but not the password

Role protection:
- [ ] Student visits `/teach` → 403
- [ ] Teacher visits `/learn` → 403
- [ ] Unauthenticated visit to `/teach` → redirect to `/login`

UI:
- [ ] Dev debug banner visible showing correct role and district name
- [ ] Banner does NOT appear when `APP_ENV=production`

UUID and hash checks (run in `php artisan tinker`):
- [ ] `App\Models\User::first()->id` returns a UUID string, not an integer
- [ ] `Hash::check('password', App\Models\User::first()->password)` returns `true`

---

## Phase 1 complete. Next: Phase 2 — Classrooms, Spaces, and Sessions.
