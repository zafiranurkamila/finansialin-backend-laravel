<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        return response()->json(
            UserNotification::query()
                ->where('idUser', $user->idUser)
                ->orderByDesc('createdAt')
                ->get()
        );
    }

    public function unread(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        return response()->json(
            UserNotification::query()
                ->where('idUser', $user->idUser)
                ->where('read', false)
                ->orderByDesc('createdAt')
                ->get()
        );
    }

    public function unreadCount(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $count = UserNotification::query()
            ->where('idUser', $user->idUser)
            ->where('read', false)
            ->count();

        return response()->json(['count' => $count]);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        UserNotification::query()
            ->where('idNotification', $id)
            ->where('idUser', $user->idUser)
            ->update(['read' => true]);

        return response()->json([
            'message' => 'Notifikasi telah ditandai sebagai dibaca',
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        UserNotification::query()
            ->where('idUser', $user->idUser)
            ->where('read', false)
            ->update(['read' => true]);

        return response()->json([
            'message' => 'Semua notifikasi telah ditandai sebagai dibaca',
        ]);
    }
}
