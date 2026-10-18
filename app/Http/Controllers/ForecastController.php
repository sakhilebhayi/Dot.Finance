<?php

namespace App\Http\Controllers;

use App\Services\ForecastService;
use Illuminate\Http\Request;

class ForecastController extends Controller
{
    public function __invoke(Request $request, ForecastService $forecast)
    {
        return view('forecast.index', ['forecast' => $forecast->project($request->user()->id)]);
    }
}
