<?php

namespace App\Modules\Privacy\Models;

use App\Modules\Privacy\Policies\DataExportPolicy;
use App\Modules\Shared\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** Pedido de exportação: arquivo cifrado, uso único, expira em 24 h. */
#[Fillable(['user_id', 'status', 'path', 'expires_at', 'downloaded_at'])]
#[Hidden(['path'])]
#[UsePolicy(DataExportPolicy::class)]
class DataExport extends Model
{
    use BelongsToUser, HasUuids;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'downloaded_at' => 'datetime',
        ];
    }

    public function isDownloadable(): bool
    {
        return $this->status === 'ready' && $this->path !== null && $this->expires_at->isFuture();
    }

    /** Remove o arquivo; o registro fica só até a rotina de retenção. */
    public function discardFile(): void
    {
        if ($this->path !== null) {
            Storage::disk('local')->delete($this->path);
        }
        $this->forceFill(['path' => null])->save();
    }
}
