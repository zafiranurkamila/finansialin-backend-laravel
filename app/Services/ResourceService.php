<?php

namespace App\Services;

use App\Http\Controllers\ResourceController;
use App\Models\Resource;
use App\Models\User;

class ResourceService
{
    /**
     * Inisialisasi 3 resources default untuk user baru
     * Resources: mbanking, emoney, cash
     */
    public static function initializeDefaultResources(User $user): array
    {
        $walletTypes = ['mbanking', 'emoney', 'cash'];
        $createdResources = [];

        foreach ($walletTypes as $type) {
            $resource = Resource::create([
                'idUser' => $user->idUser,
                'source' => $type,
                'balance' => 0,
            ]);

            $createdResources[] = $resource;
        }

        return $createdResources;
    }

    /**
     * Increment balance resource berdasarkan transaction income
     */
    public static function addIncomeToResource($idResource, $amount): bool
    {
        $resource = Resource::find($idResource);
        
        if (!$resource) {
            return false;
        }

        $resource->increment('balance', $amount);
        return true;
    }

    /**
     * Decrement balance resource berdasarkan transaction expense
     */
    public static function withdrawFromResource($idResource, $amount): bool
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
     * Get all resources for user
     */
    public static function getUserResources($idUser)
    {
        return Resource::where('idUser', $idUser)
            ->orderBy('createdAt', 'asc')
            ->get();
    }

    /**
     * Get resource detail
     */
    public static function getResource($idResource, $idUser = null)
    {
        $query = Resource::where('idResource', $idResource);
        
        if ($idUser) {
            $query->where('idUser', $idUser);
        }

        return $query->first();
    }
}

