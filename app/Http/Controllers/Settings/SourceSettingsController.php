<?php

namespace App\Http\Controllers\Settings;

use App\Enums\MovieSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\SourceToggleRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SourceSettingsController extends Controller
{
    /**
     * Show the source preferences of the user.
     */
    public function edit(Request $request): Response
    {
        $preferences = $request->user()->sources ?? MovieSource::defaultPreferences();

        return Inertia::render('settings/sources', [
            'sources' => array_values(array_map(
                fn(MovieSource $source): array => [
                    'value' => $source->value,
                    'label' => $source->label(),
                    'legal' => $source->isLegal(),
                    'enabled' => (bool) ($preferences[$source->value] ?? false),
                    'globallyEnabled' => (bool) config("services.sources.{$source->value}.enabled", true),
                ],
                MovieSource::catalogSources(),
            )),
        ]);
    }

    /**
     * Persist the source preferences of the user.
     */
    public function update(SourceToggleRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated())->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sources updated.')]);

        return to_route('sources.edit');
    }
}
