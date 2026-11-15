<?php

namespace App\Http\Controllers;

use App\Support\Search\BooksSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The suggestions under the header search: JSON for the shared dropdown, the open ledger only, role-aware (see BooksSearch). */
class SearchController extends Controller
{
    public function suggest(Request $request, BooksSearch $search): JsonResponse
    {
        // A typed query that is too long is cut, not refused: a person pasting a paragraph into the box still gets a search (BooksSearch caps it).
        $query = $request->query('q');

        return response()->json(['groups' => $search->run($request->user(), is_string($query) ? $query : '')])
            ->header('Cache-Control', 'no-store, private');
    }
}
