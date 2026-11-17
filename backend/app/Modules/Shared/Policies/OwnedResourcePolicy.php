<?php

namespace App\Modules\Shared\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Política padrão para recursos de um único dono.
 *
 * Recursos de outro usuário respondem 404 (e não 403) para não revelar
 * que o identificador existe.
 */
abstract class OwnedResourcePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, Model $resource): Response
    {
        return $this->ownership($user, $resource);
    }

    public function update(User $user, Model $resource): Response
    {
        return $this->ownership($user, $resource);
    }

    public function delete(User $user, Model $resource): Response
    {
        return $this->ownership($user, $resource);
    }

    protected function ownership(User $user, Model $resource): Response
    {
        return $resource->isOwnedBy($user)
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
