<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Http\Requests\OptionGroupRequest;
use App\Modules\Catalog\Http\Resources\OptionGroupResource;
use App\Modules\Catalog\Models\OptionGroup;
use App\Modules\Catalog\Services\OptionGroupWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OptionGroupController
{
    public function index(): AnonymousResourceCollection
    {
        return OptionGroupResource::collection(
            OptionGroup::query()->with('options.variantPrices.variant')->orderBy('name')->get()
        );
    }

    public function show(OptionGroup $optionGroup): OptionGroupResource
    {
        return new OptionGroupResource($optionGroup->load('options.variantPrices.variant'));
    }

    public function store(OptionGroupRequest $request, OptionGroupWriter $writer): JsonResponse
    {
        return (new OptionGroupResource($writer->save(null, $request->validated())))->response()->setStatusCode(201);
    }

    public function update(OptionGroupRequest $request, OptionGroup $optionGroup, OptionGroupWriter $writer): OptionGroupResource
    {
        return new OptionGroupResource($writer->save($optionGroup, $request->validated()));
    }

    public function destroy(OptionGroup $optionGroup): JsonResponse
    {
        $optionGroup->delete();

        return new JsonResponse(null, 204);
    }
}
