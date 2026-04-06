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
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = $request->query('query');

        $suggestions = SearchSuggestion::toBase()
            ->when($query, function ($q) use ($query) {
                $q->where('term', 'like', "%{$query}%");
            })
            ->orderByDesc('hits')
            ->limit(10)
            ->get(['id', 'term', 'hits']);

        return $this->actionSuccess('search_suggestions_fetched', $suggestions);
    }
}
