<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CategoriesController extends Controller
{
    public function suggest(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $type = (string) $request->query('type', 'expense');
        $description = trim((string) $request->query('description', ''));
        $source = trim((string) $request->query('source', ''));

        if (!in_array($type, ['income', 'expense'], true)) {
            return response()->json(['message' => 'Invalid type'], 422);
        }

        $categories = Category::query()
            ->where('type', $type)
            ->where(function ($query) use ($user) {
                $query->whereNull('idUser')->orWhere('idUser', $user->idUser);
            })
            ->orderBy('name')
            ->get();

        if ($categories->isEmpty()) {
            return response()->json([
                'suggested' => null,
                'confidence' => 0,
                'reason' => 'No categories available',
            ]);
        }

        $text = strtolower(trim($description . ' ' . $source));
        $scores = [];
        foreach ($categories as $category) {
            $scores[$category->idCategory] = 0.0;
        }

        $keywords = $this->keywordMap($type);
        foreach ($keywords as $name => $tokens) {
            foreach ($tokens as $token) {
                if ($token !== '' && str_contains($text, $token)) {
                    $matched = $categories->first(fn ($cat) => strtolower($cat->name) === strtolower($name));
                    if ($matched) {
                        $scores[$matched->idCategory] = max($scores[$matched->idCategory], 0.65);
                    }
                }
            }
        }

        if ($text !== '') {
            $history = Transaction::query()
                ->where('idUser', $user->idUser)
                ->where('type', $type)
                ->whereNotNull('idCategory')
                ->where(function ($query) use ($description, $source) {
                    if ($description !== '') {
                        $query->orWhere('description', 'like', '%' . $description . '%');
                    }
                    if ($source !== '') {
                        $query->orWhere('source', 'like', '%' . $source . '%');
                    }
                })
                ->selectRaw('"idCategory", COUNT(*) as total')
                ->groupBy('idCategory')
                ->orderByDesc('total')
                ->get();

            foreach ($history as $item) {
                $count = (int) $item->total;
                $historyScore = min(0.85, 0.35 + ($count * 0.1));
                if (isset($scores[$item->idCategory])) {
                    $scores[$item->idCategory] = max($scores[$item->idCategory], $historyScore);
                }
            }

            foreach ($categories as $category) {
                $name = strtolower($category->name);
                if ($name !== '' && str_contains($text, $name)) {
                    $scores[$category->idCategory] = max($scores[$category->idCategory], 0.7);
                }
            }
        }

        arsort($scores);
        $bestCategoryId = (int) array_key_first($scores);
        $confidence = (float) ($scores[$bestCategoryId] ?? 0.0);
        $bestCategory = $categories->firstWhere('idCategory', $bestCategoryId);

        return response()->json([
            'suggested' => $bestCategory ? [
                'idCategory' => $bestCategory->idCategory,
                'name' => $bestCategory->name,
                'type' => $bestCategory->type,
            ] : null,
            'confidence' => round($confidence, 2),
            'reason' => $confidence >= 0.65 ? 'Strong match from keyword/history' : 'Weak signal',
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $categories = Category::query()
            ->where(function ($query) use ($user) {
                $query->whereNull('idUser')->orWhere('idUser', $user->idUser);
            })
            ->orderBy('type')
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
            'type' => ['nullable', 'string', 'in:income,expense'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $type = $request->input('type', 'expense');
        $name = trim($request->string('name')->toString());

        $exists = Category::query()
            ->where('name', $name)
            ->where('type', $type)
            ->where(function ($query) use ($user) {
                $query->whereNull('idUser')->orWhere('idUser', $user->idUser);
            })
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Category already exists',
            ], 409);
        }

        $category = Category::create([
            'name' => $name,
            'type' => $type,
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

        if ($category->idUser === null) {
            return response()->json(['message' => 'Default categories cannot be modified'], 403);
        }

        if ($category->idUser !== $user->idUser) {
            return response()->json(['message' => 'Not allowed'], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'type' => ['nullable', 'string', 'in:income,expense'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $name = trim($request->string('name')->toString());
        $type = $request->input('type', $category->type ?? 'expense');

        $exists = Category::query()
            ->where('idCategory', '!=', $category->idCategory)
            ->where('name', $name)
            ->where('type', $type)
            ->where(function ($query) use ($user) {
                $query->whereNull('idUser')->orWhere('idUser', $user->idUser);
            })
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Category already exists',
            ], 409);
        }

        $category->update([
            'name' => $name,
            'type' => $type,
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

        if ($category->idUser === null) {
            return response()->json(['message' => 'Default categories cannot be deleted'], 403);
        }

        if ($category->idUser !== $user->idUser) {
            return response()->json(['message' => 'Not allowed'], 403);
        }

        $category->delete();

        return response()->json(['message' => 'Category deleted']);
    }

    private function keywordMap(string $type): array
    {
        if ($type === 'income') {
            return [
                'Salary' => ['gaji', 'salary', 'payroll'],
                'Bonus' => ['bonus', 'insentif', 'incentive'],
                'Gift' => ['gift', 'hadiah', 'angpao'],
            ];
        }

        return [
            'Food & Drinks' => ['makan', 'coffee', 'kopi', 'resto', 'food', 'grabfood', 'gofood'],
            'Transportation' => ['transport', 'fuel', 'bensin', 'tol', 'parkir', 'gojek', 'grab'],
            'Shopping' => ['shopee', 'tokopedia', 'belanja', 'shopping'],
            'Bills & Utilities' => ['listrik', 'pln', 'internet', 'wifi', 'tagihan', 'bill'],
            'Health' => ['obat', 'dokter', 'health', 'klinik', 'rumah sakit'],
            'Entertainment' => ['netflix', 'spotify', 'game', 'bioskop', 'entertainment'],
        ];
    }
}
