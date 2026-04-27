<?php

namespace App\Http\Controllers;

use App\Models\Resource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            ->findOrFail($idResource);

        return response()->json([
            'message' => 'Detail resource berhasil diambil',
            'data' => $resource,
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
