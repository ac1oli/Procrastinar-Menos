<?php

namespace App\Modules\Privacy\Jobs;

use App\Modules\Privacy\Models\DataExport;
use App\Modules\Privacy\NexoDataExporter;
use App\Modules\Shared\Database\DatabaseUserScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Gera o pacote de exportação. O arquivo fica cifrado com a APP_KEY em
 * storage/app/private (nunca público) até o download ou a expiração.
 */
class ExportUserData implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $userId,
        public readonly string $exportId,
    ) {}

    public function handle(NexoDataExporter $exporter): void
    {
        DatabaseUserScope::run($this->userId, function () use ($exporter) {
            $export = DataExport::find($this->exportId);
            if ($export === null || $export->status !== 'pending') {
                return;
            }

            try {
                $path = "exports/{$export->id}.zip.enc";
                Storage::disk('local')->put($path, Crypt::encryptString($exporter->zip($export->user)));
                $export->update(['status' => 'ready', 'path' => $path]);
            } catch (Throwable $e) {
                $export->update(['status' => 'failed']);
                report($e);
            }
        });
    }
}
