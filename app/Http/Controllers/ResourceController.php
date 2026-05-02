<?php

namespace App\Http\Controllers;

use App\Models\Resource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ResourceController extends Controller
{
    /**
     * Tampilkan daftar resources (dompet) user
     * GET /api/resources
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->attributes->get('auth_user');
        if (!$user) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 401);
        }

        $resources = Resource::where('idUser', $user->idUser)
            ->orderBy('createdAt', 'asc')
            ->get();

        return response()->json([
            'message' => 'Daftar resources berhasil diambil',
            'data' => $resources,
        ]);
    }

    /**
     * Tampilkan detail resource
     * GET /api/resources/{idResource}
     */
    public function show(Request $request, $idResource): JsonResponse
    {
        $user = $request->attributes->get('auth_user');
        if (!$user) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 401);
        }

        $resource = Resource::where('idUser', $user->idUser)
            ->where('idResource', $idResource)
            ->first();

        if (!$resource) {
            return response()->json([
                'message' => 'Resource not found',
            ], 404);
        }

        return response()->json([
            'message' => 'Detail resource berhasil diambil',
            'data' => $resource,
        ]);
    }

    /**
     * Buat resource baru
     * POST /api/resources
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->attributes->get('auth_user');
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:80'],
            'initialBalance' => ['nullable', 'numeric'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $name = $request->string('name')->trim()->toString();
        
        // Check for duplicate name (case insensitive)
        $exists = Resource::where('idUser', $user->idUser)
            ->whereRaw('LOWER(source) = ?', [strtolower($name)])
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Dompet dengan nama tersebut sudah ada.'], 400);
        }

        $resource = Resource::create([
            'idUser' => $user->idUser,
            'source' => $name,
            'balance' => $request->input('initialBalance', 0),
        ]);

        return response()->json([
            'message' => 'Resource berhasil dibuat',
            'data' => $resource,
        ], 201);
    }

    /**
     * Update resource
     * PUT /api/resources/{idResource}
     */
    public function update(Request $request, $idResource): JsonResponse
    {
        $user = $request->attributes->get('auth_user');
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $resource = Resource::where('idUser', $user->idUser)
            ->where('idResource', $idResource)
            ->first();

        if (!$resource) {
            return response()->json(['message' => 'Resource not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['nullable', 'string', 'max:80'],
            'initialBalance' => ['nullable', 'numeric'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $payload = [];
        if ($request->has('name')) {
            $newName = $request->string('name')->trim()->toString();
            
            // Check if name is taken by ANOTHER resource of the same user
            $exists = Resource::where('idUser', $user->idUser)
                ->where('idResource', '!=', $idResource)
                ->whereRaw('LOWER(source) = ?', [strtolower($newName)])
                ->exists();

            if ($exists) {
                return response()->json(['message' => 'Nama dompet sudah digunakan oleh dompet lain.'], 400);
            }
            $payload['source'] = $newName;
        }

        if ($request->has('initialBalance')) {
            $payload['balance'] = $request->input('initialBalance');
        }

        $resource->update($payload);

        return response()->json([
            'message' => 'Resource berhasil diperbarui',
            'data' => $resource,
        ]);
    }

    /**
     * Hapus resource
     * DELETE /api/resources/{idResource}
     */
    public function destroy(Request $request, $idResource): JsonResponse
    {
        $user = $request->attributes->get('auth_user');
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $resource = Resource::where('idUser', $user->idUser)
            ->where('idResource', $idResource)
            ->first();

        if (!$resource) {
            return response()->json(['message' => 'Resource not found'], 404);
        }

        $resource->delete();

        return response()->json([
            'message' => 'Resource berhasil dihapus',
        ]);
    }

    /**
     * Increment balance resource
     * Internal method untuk digunakan saat transaction income ditambahkan
     */
    public static function incrementBalance($idResource, $amount): bool
    {
        $resource = Resource::find($idResource);
        
        if (!$resource) {
            return false;
        }

        $resource->increment('balance', $amount);
        return true;
    }

    /**
     * Decrement balance resource
     * Internal method untuk digunakan saat transaction expense didiscount dari resource
     */
    public static function decrementBalance($idResource, $amount): bool
    {
        $resource = Resource::find($idResource);
        
        if (!$resource) {
            return false;
        }

        // Cek apakah balance cukup
        if ($resource->balance < $amount) {
            return false;
        }

        $resource->decrement('balance', $amount);
        return true;
    }

    /**
     * Get ringkasan resources user
     * GET /api/resources/summary
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->attributes->get('auth_user');
        if (!$user) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 401);
        }

        $resources = Resource::where('idUser', $user->idUser)
            ->get();

        $totalBalance = $resources->sum('balance');

        return response()->json([
            'message' => 'Ringkasan resources berhasil diambil',
            'data' => [
                'resources' => $resources,
                'totalBalance' => $totalBalance,
                'resourceCount' => $resources->count(),
            ],
        ]);
    }
}
