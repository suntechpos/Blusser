<?php

namespace App\Modules\Queues\Http;

use App\Modules\Queues\Services\VisitRecommendations;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class VisitRecommendationController
{
    public function __construct(private VisitRecommendations $recommendations) {}

    public function show(Request $request)
    {
        if (count($request->query()) > 0) {
            throw ValidationException::withMessages(['request' => 'Unknown fields are not allowed.']);
        }
        return response()->json($this->recommendations->show(
            $request->user(), $this->tenant($request), $request->route('visit'),
        ))->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request)
    {
        if (array_diff(array_keys($request->all()), ['expected_version', 'recommendation_text'])) {
            throw ValidationException::withMessages(['request' => 'Unknown fields are not allowed.']);
        }
        $data = validator($request->all(), [
            'expected_version' => 'required|integer|min:0',
            'recommendation_text' => 'present|nullable|string|max:10000',
        ])->validate();
        $data['recommendation_text'] = $data['recommendation_text'] ?? '';
        return response()->json($this->recommendations->save(
            $request->user(), $this->tenant($request), $request->route('visit'), $data,
        ))->header('Cache-Control', 'private, no-store');
    }

    private function tenant(Request $request): string
    {
        $tenant = $request->session()->get('active_tenant_id');
        abort_unless(is_string($tenant), 409, 'Select an active clinic.');
        return $tenant;
    }
}
