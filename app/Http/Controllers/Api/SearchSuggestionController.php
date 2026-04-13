<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SearchSuggestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchSuggestionController extends Controller
{
    /**
     * Display a listing of search suggestions.
     */
    public function index(Request $request): JsonResponse
    {
        $query = $request->query('query');
        $limit = min(20, max(1, (int) $request->query('limit', 10)));

        $suggestions = SearchSuggestion::toBase()
            ->when($query, function ($q) use ($query) {
                $q->where('term', 'like', "%{$query}%");
            })
            ->orderByDesc('hits')
            ->limit($limit)
            ->get(['id', 'term', 'hits']);

        return $this->actionSuccess('search_suggestions_fetched', $suggestions);
    }
}
