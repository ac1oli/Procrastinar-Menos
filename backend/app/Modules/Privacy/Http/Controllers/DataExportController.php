<?php

namespace App\Modules\Privacy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\AuditLogger;
use App\Modules\Privacy\Jobs\ExportUserData;
use App\Modules\Privacy\Models\DataExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Exportação dos dados do NEXO pela própria pessoa (R11; LGPD art. 18, V).
 * Exige a senha, no máximo uma a cada 24 h, download único.
 */
class DataExportController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function store(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'string', 'current_password:web']]);
        $user = $request->user();

        $recent = DataExport::ownedBy($user)->where('created_at', '>', now()->subDay())->exists();
        abort_if($recent, Response::HTTP_TOO_MANY_REQUESTS, 'Você já pediu uma exportação nas últimas 24 horas.');

        $export = DataExport::create([
            'user_id' => $user->getKey(),
            'status' => 'pending',
            'expires_at' => now()->addHours((int) config('nexo.export.ttl_hours')),
        ]);

        ExportUserData::dispatch($user->getKey(), $export->id)->afterCommit();
        $this->audit->record('nexo.export.requested', $user, $export);

        return response()->json(['data' => $this->payload($export->refresh())], Response::HTTP_ACCEPTED);
    }

    public function show(DataExport $export): JsonResponse
    {
        Gate::authorize('view', $export);

        return response()->json(['data' => $this->payload($export)]);
    }

    /** Uso único: o arquivo é apagado logo após ser entregue. */
    public function download(Request $request, DataExport $export): SymfonyResponse
    {
        Gate::authorize('view', $export);
        abort_unless($export->isDownloadable(), Response::HTTP_GONE, 'Exportação indisponível, expirada ou já baixada.');

        $zip = Crypt::decryptString((string) Storage::disk('local')->get($export->path));
        $export->discardFile();
        $export->update(['status' => 'downloaded', 'downloaded_at' => now()]);
        $this->audit->record('nexo.export.downloaded', $request->user(), $export);

        return response($zip, Response::HTTP_OK, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="procrastinar-menos-dados-nexo.zip"',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function payload(DataExport $export): array
    {
        return [
            'id' => $export->id,
            'status' => $export->status,
            'expires_at' => $export->expires_at->toIso8601String(),
            'downloaded_at' => $export->downloaded_at?->toIso8601String(),
        ];
    }
}
