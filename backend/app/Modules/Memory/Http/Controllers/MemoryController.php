<?php

namespace App\Modules\Memory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Memory\Enums\MemoryStatus;
use App\Modules\Memory\Http\Requests\MemoryRequest;
use App\Modules\Memory\Http\Resources\MemoryResource;
use App\Modules\Memory\MemoryService;
use App\Modules\Memory\Models\Memory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Consultar, criar, editar, revisar e excluir memórias (R3, R7). */
class MemoryController extends Controller
{
    public function __construct(private readonly MemoryService $memories) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(MemoryStatus::class)],
            'q' => ['nullable', 'string', 'max:200'],
        ]);

        $memories = Memory::ownedBy($request->user())
            ->with('embedding')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when(filled($filters['q'] ?? null), fn ($q) => $q
                ->whereRaw("content_tsv @@ websearch_to_tsquery('portuguese', ?)", [$filters['q']]))
            ->orderByDesc('pinned')
            ->latest()
            ->limit(500)
            ->get();

        return MemoryResource::collection($memories);
    }

    public function store(MemoryRequest $request): JsonResponse
    {
        $memory = $this->memories->create($request->user(), $request->validated());

        return MemoryResource::make($memory->refresh()->load('embedding'))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Memory $memory): MemoryResource
    {
        Gate::authorize('view', $memory);

        return MemoryResource::make($memory->load('embedding'));
    }

    public function update(MemoryRequest $request, Memory $memory): MemoryResource
    {
        Gate::authorize('update', $memory);

        return MemoryResource::make($this->memories->update($request->user(), $memory, $request->validated())->load('embedding'));
    }

    public function review(Memory $memory): MemoryResource
    {
        Gate::authorize('update', $memory);

        return MemoryResource::make($this->memories->review($memory)->load('embedding'));
    }

    public function destroy(Request $request, Memory $memory): Response
    {
        Gate::authorize('delete', $memory);

        $this->memories->delete($request->user(), $memory);

        return response()->noContent();
    }

    public function destroyAll(Request $request): JsonResponse
    {
        $request->validate(['confirm' => ['accepted']]);

        return response()->json(['data' => ['deleted_memories' => $this->memories->deleteAll($request->user())]]);
    }
}
