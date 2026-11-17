<?php

namespace App\Modules\Goals\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Goals\Http\Requests\GoalRequest;
use App\Modules\Goals\Http\Resources\GoalResource;
use App\Modules\Goals\Models\Goal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class GoalController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $goals = Goal::ownedBy($request->user())->latest()->get();

        return GoalResource::collection($goals);
    }

    public function store(GoalRequest $request): JsonResponse
    {
        $goal = $request->user()->goals()->create($request->validated());

        return GoalResource::make($goal->refresh())
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Goal $goal): GoalResource
    {
        Gate::authorize('view', $goal);

        return GoalResource::make($goal);
    }

    public function update(GoalRequest $request, Goal $goal): GoalResource
    {
        Gate::authorize('update', $goal);

        $goal->update($request->validated());

        return GoalResource::make($goal->refresh());
    }

    public function destroy(Goal $goal): Response
    {
        Gate::authorize('delete', $goal);

        $goal->delete();

        return response()->noContent();
    }
}
