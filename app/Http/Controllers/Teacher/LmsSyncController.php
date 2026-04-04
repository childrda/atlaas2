<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Services\Lms\LmsSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LmsSyncController extends Controller
{
    public function store(Request $request, LmsSyncService $lms): RedirectResponse
    {
        abort_unless($request->user()->hasRole('district_admin'), 403);

        $result = $lms->syncDistrict($request->user()->district_id);

        return back()->with(
            $result['ok'] ? 'success' : 'error',
            $result['message'].' (rows touched: '.$result['touched'].')'
        );
    }
}
