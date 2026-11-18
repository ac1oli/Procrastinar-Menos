<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Http\Requests\UpdateEmailRequest;
use App\Modules\Identity\Http\Requests\UpdatePasswordRequest;
use App\Modules\Identity\Http\Requests\UpdateProfileRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Perfil da conta: nome de exibição, fuso, e-mail e senha.
 *
 * - O fuso vale a partir da próxima mensagem ao NEXO (horário local e rotina de hoje são
 *   calculados no fuso da conta a cada pedido). O nome nunca vai para a IA.
 * - E-mail e senha exigem a senha atual. Trocar a senha encerra as outras sessões.
 * - A auditoria registra quais campos mudaram, nunca os valores.
 */
class ProfileController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->fill($request->validated());
        $changed = array_keys($user->getDirty());

        if ($changed !== []) {
            $user->save();
            $this->audit->record('account.profile_updated', $user, metadata: ['fields' => $changed]);
        }

        return UserResource::make($user)->response()->setStatusCode(200);
    }

    public function updateEmail(UpdateEmailRequest $request): JsonResponse
    {
        $user = $request->user();
        $this->ensurePassword($request->validated('current_password'), $user->password);

        if ($user->email !== $request->validated('email')) {
            $user->email = $request->validated('email');
            $user->save();
            $this->audit->record('account.email_changed', $user);
        }

        return UserResource::make($user)->response()->setStatusCode(200);
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $this->ensurePassword($request->validated('current_password'), $user->password);

        $user->forceFill([
            'password' => $request->validated('password'),
            'remember_token' => null,
        ])->save();

        // Encerra as outras sessões do navegador; a atual ganha um id novo.
        $current = $request->hasSession() ? $request->session()->getId() : null;
        $ended = DB::table('sessions')->where('user_id', $user->getKey())
            ->when($current, fn ($q) => $q->where('id', '!=', $current))
            ->delete();
        if ($request->hasSession()) {
            $request->session()->migrate(true);
        }

        $this->audit->record('account.password_changed', $user, metadata: ['other_sessions_ended' => $ended]);

        return response()->json(['data' => ['other_sessions_ended' => $ended]]);
    }

    /** Fusos aceitos pelo backend (a mesma lista usada na validação). */
    public function timezones(): JsonResponse
    {
        return response()->json(['data' => DateTimeZone::listIdentifiers()])
            ->header('Cache-Control', 'private, max-age=86400');
    }

    private function ensurePassword(string $given, string $hash): void
    {
        if (! Hash::check($given, $hash)) {
            throw ValidationException::withMessages(['current_password' => 'A senha atual está incorreta.']);
        }
    }
}
