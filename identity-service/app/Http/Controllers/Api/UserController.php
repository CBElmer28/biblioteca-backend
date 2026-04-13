<?php

namespace App\Http\Controllers;

// =============================================================================
// UserController — CRUD de usuarios (solo para roles admin/support)
// Los usuarios regulares solo pueden ver/editar su propio perfil via AuthController.
// =============================================================================

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    // GET /api/v1/users — Listar usuarios con paginación y filtros
    public function index(Request $request): JsonResponse
    {
        $users = User::with(['roles', 'profile'])
            ->when($request->search, fn($q) =>
                $q->where('name', 'ilike', "%{$request->search}%")  // ilike = case-insensitive en PG
                  ->orWhere('email', 'ilike', "%{$request->search}%")
            )
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->role, fn($q) => $q->role($request->role))  // Scope de Spatie
            ->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 15);

        return response()->json(['success' => true, 'data' => $users]);
    }

    // GET /api/v1/users/{id}
    public function show(string $id): JsonResponse
    {
        $user = User::with(['roles', 'permissions', 'profile'])->findOrFail($id);
        return response()->json(['success' => true, 'data' => $user]);
    }

    // PUT /api/v1/users/{id}
    public function update(Request $request, string $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $data = $request->validate([
            'name'   => ['sometimes', 'string', 'max:100'],
            'phone'  => ['sometimes', 'nullable', 'string', 'max:20'],
            'status' => ['sometimes', 'in:active,inactive,suspended'],
        ]);

        $user->update($data);

        return response()->json(['success' => true, 'data' => $user]);
    }

    // DELETE /api/v1/users/{id} — Soft delete (GDPR compliant)
    public function destroy(string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $user->delete();  // SoftDelete: no borra físicamente

        return response()->json(['success' => true, 'message' => 'Usuario eliminado.']);
    }

    // POST /api/v1/users/{id}/roles — Asignar/reemplazar roles
    public function assignRole(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'roles' => ['required', 'array'],
            'roles.*' => ['string', 'exists:roles,name'],
        ]);

        $user = User::findOrFail($id);
        $user->syncRoles($request->roles);  // Reemplaza todos los roles actuales

        return response()->json([
            'success' => true,
            'message' => 'Roles actualizados.',
            'roles'   => $user->getRoleNames(),
        ]);
    }
}