<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['permissions' => Permission::orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:permissions,name'],
            'slug' => ['required', 'string', 'max:120', 'alpha_dash', 'unique:permissions,slug'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json(['message' => 'Permission created.', 'permission' => Permission::create($validated)], 201);
    }

    public function update(Request $request, Permission $permission): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120', 'unique:permissions,name,'.$permission->id],
            'slug' => ['sometimes', 'required', 'string', 'max:120', 'alpha_dash', 'unique:permissions,slug,'.$permission->id],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);
        $permission->update($validated);

        return response()->json(['message' => 'Permission updated.', 'permission' => $permission]);
    }

    public function destroy(Permission $permission): JsonResponse
    {
        $permission->delete();

        return response()->json(['message' => 'Permission deleted.']);
    }
}
