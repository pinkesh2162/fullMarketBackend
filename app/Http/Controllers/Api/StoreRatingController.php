<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\Rating;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class StoreRatingController extends Controller
{
    public function rate(Request $request, Store $store): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), self::HTTP_UNPROCESSABLE_ENTITY);
        }

        $rating = Rating::updateOrCreate(
            ['user_id' => auth()->id(), 'store_id' => $store->id],
            ['rating' => $request->rating, 'comment' => $request->comment]
        );

        return $this->actionSuccess('store_rated');
    }
}
