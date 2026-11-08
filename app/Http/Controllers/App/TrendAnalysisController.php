<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Trend\RequestTrendAnalysis;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TrendAnalysisController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('update', $workspace);

        $profile = $workspace->trendProfile;

        abort_if($profile === null, 404);

        RequestTrendAnalysis::execute($profile);

        return back();
    }
}
