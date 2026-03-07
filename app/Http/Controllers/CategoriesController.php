<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CategoriesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $categories = Category::query()
            ->where(function ($query) use ($user) {
                $query->whereNull('idUser')->orWhere('idUser', $user->idUser);
            })
            ->orderBy('name')
            ->get();

        return response()->json($categories);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $category = Category::create([
            'name' => $request->string('name')->toString(),
            'idUser' => $user->idUser,
        ]);

        return response()->json($category->fresh(), 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $category = Category::query()
            ->where('idCategory', $id)
            ->where(function ($query) use ($user) {
                $query->whereNull('idUser')->orWhere('idUser', $user->idUser);
            })
            ->first();

        if (!$category) {
            return response()->json(['message' => 'Category not found'], 404);
        }

        return response()->json($category);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $category = Category::query()->where('idCategory', $id)->first();
        if (!$category) {
            return response()->json(['message' => 'Category not found'], 404);
        }

        if ($category->idUser !== null && $category->idUser !== $user->idUser) {
            return response()->json(['message' => 'Not allowed'], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $category->update([
            'name' => $request->string('name')->toString(),
        ]);

        return response()->json($category);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $category = Category::query()->where('idCategory', $id)->first();
        if (!$category) {
            return response()->json(['message' => 'Category not found'], 404);
        }

        if ($category->idUser !== null && $category->idUser !== $user->idUser) {
            return response()->json(['message' => 'Not allowed'], 403);
        }

        $category->delete();

        return response()->json(['message' => 'Category deleted']);
    }
}
