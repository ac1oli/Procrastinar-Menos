<?php

namespace App\Modules\Routine\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Routine\Http\Requests\RoutineBlockRequest;
use App\Modules\Routine\Http\Resources\RoutineBlockResource;
use App\Modules\Routine\Models\RoutineBlock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class RoutineBlockController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $blocks = RoutineBlock::ownedBy($request->user())
            ->orderBy('start_time')
            ->get();

        return RoutineBlockResource::collection($blocks);
    }

    public function store(RoutineBlockRequest $request): JsonResponse
    {
        $block = $request->user()->routineBlocks()->create($request->validated());

        return RoutineBlockResource::make($block->refresh())
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(RoutineBlock $routineBlock): RoutineBlockResource
    {
        Gate::authorize('view', $routineBlock);

        return RoutineBlockResource::make($routineBlock);
    }

    public function update(RoutineBlockRequest $request, RoutineBlock $routineBlock): RoutineBlockResource
    {
        Gate::authorize('update', $routineBlock);

        $routineBlock->update($request->validated());

        return RoutineBlockResource::make($routineBlock->refresh());
    }

    public function destroy(RoutineBlock $routineBlock): Response
    {
        Gate::authorize('delete', $routineBlock);

        $routineBlock->delete();

        return response()->noContent();
    }
}
