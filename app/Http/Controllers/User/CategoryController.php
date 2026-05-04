<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * Display a listing of active categories for users.
     */
    public function index(Request $request)
    {
        $categories = Category::where('status', 'active')
            ->orderBy('sort_order', 'asc')
            ->get();

        return response()->json([
            'data' => $categories,
            'meta' => [
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => $categories->count(),
                'total' => $categories->count(),
            ]
        ]);
    }

    /**
     * Display the specified category.
     */
    public function show(Category $category)
    {
        if ($category->status !== 'active') {
            return response()->json(['message' => 'Category not found'], 404);
        }

        return response()->json(['data' => $category]);
    }
}
