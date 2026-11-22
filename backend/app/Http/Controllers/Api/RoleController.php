<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['roles' => Role::with('permissions')->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80', 'unique:roles,name'],
            'slug' => ['required', 'string', 'max:80', 'alpha_dash', 'unique:roles,slug'],
            'description' => ['nullable', 'string', 'max:1000'],
            'permission_ids' => ['sometimes', 'array'],
            'permission_ids.*' => ['integer', 'distinct', 'exists:permissions,id'],
        ]);

        $role = Role::create([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'description' => $validated['description'] ?? null,
        ]);
        $role->permissions()->sync($validated['permission_ids'] ?? []);

        return response()->json(['message' => 'Role created.', 'role' => $role->load('permissions')], 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:80', 'unique:roles,name,'.$role->id],
            'slug' => ['sometimes', 'required', 'string', 'max:80', 'alpha_dash', 'unique:roles,slug,'.$role->id],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'permission_ids' => ['sometimes', 'array'],
            'permission_ids.*' => ['integer', 'distinct', 'exists:permissions,id'],
        ]);

        $permissionIds = $validated['permission_ids'] ?? null;
        $role->update(collect($validated)->except('permission_ids')->all());

        if ($permissionIds !== null) {
            $role->permissions()->sync($permissionIds);
        }

        return response()->json(['message' => 'Role updated.', 'role' => $role->load('permissions')]);
    }

    public function destroy(Role $role): JsonResponse
    {
        if ($role->users()->exists()) {
            return response()->json(['message' => 'Reassign this role’s users before deleting it.'], 422);
        }

        $role->delete();

        return response()->json(['message' => 'Role deleted.']);
    }
}
