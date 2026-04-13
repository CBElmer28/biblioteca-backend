<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// =============================================================================
// RoleController — Gestión de roles y permisos del sistema
// Endpoints accesibles solo para el rol "admin"
// =============================================================================

class RoleController extends Controller
{
    // GET /api/v1/roles
    public function index(): JsonResponse
    {
        $roles = Role::with('permissions')->get();
        return response()->json(['success' => true, 'data' => $roles]);
    }

    // POST /api/v1/roles
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'        => ['required', 'string', 'unique:roles,name'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $role = Role::create(['name' => $request->name, 'guard_name' => 'api']);

        if ($request->permissions) {
            $role->syncPermissions($request->permissions);
        }

        return response()->json(['success' => true, 'data' => $role->load('permissions')], 201);
    }

    // PUT /api/v1/roles/{id}/permissions — Sincronizar permisos de un rol
    public function syncPermissions(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'permissions'   => ['required', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $role = Role::findOrFail($id);
        $role->syncPermissions($request->permissions);

        // Limpiar caché de Spatie inmediatamente
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        return response()->json([
            'success'     => true,
            'role'        => $role->name,
            'permissions' => $role->permissions->pluck('name'),
        ]);
    }

    // GET /api/v1/permissions — Listar todos los permisos disponibles
    public function permissions(): JsonResponse
    {
        $permissions = Permission::all()->groupBy(fn($p) => explode('.', $p->name)[0]);
        return response()->json(['success' => true, 'data' => $permissions]);
    }
}