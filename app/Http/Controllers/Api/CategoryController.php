<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\CategoryRequest;
use App\Models\Category;

class CategoryController extends Controller
{
    public function index()
    {
        return response()->json(Category::withCount('books')->orderBy('name')->get());
    }

    public function store(CategoryRequest $request)
    {
        $category = Category::create($request->validated());
        return response()->json(['message' => 'Category created.', 'category' => $category], 201);
    }

    public function update(CategoryRequest $request, Category $category)
    {
        $category->update($request->validated());
        return response()->json(['message' => 'Category updated.', 'category' => $category]);
    }

    public function destroy(Category $category)
    {
        if ($category->books()->exists()) {
            return response()->json(['message' => 'Cannot delete: category still has books.'], 422);
        }
        $category->delete();
        return response()->json(['message' => 'Category deleted.']);
    }
}