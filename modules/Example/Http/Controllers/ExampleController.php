<?php

declare(strict_types=1);

namespace Modules\Example\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Example\Http\Requests\SearchExampleRequest;
use Modules\Example\Http\Requests\StoreExampleRequest;
use Modules\Example\Http\Requests\UpdateExampleRequest;
use Modules\Example\Http\Resources\ExampleResource;
use Modules\Example\Models\Example;
use Modules\Example\Services\ExampleService;

/**
 * Thin controller — delegates all business logic to ExampleService.
 */
class ExampleController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ExampleService $service,
    ) {}

    public function index(SearchExampleRequest $request): JsonResponse
    {
        $examples = $this->service->list(
            filters: $request->validated(),
            perPage: (int) $request->integer('per_page', 15),
        );

        return $this->collection(ExampleResource::collection($examples));
    }

    public function store(StoreExampleRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['user_id'] = $request->user()?->id;

        $example = $this->service->create($data);

        return $this->resource(
            new ExampleResource($example->load('author')),
            __('example::messages.created'),
            201,
        );
    }

    public function show(Example $example): JsonResponse
    {
        return $this->resource(new ExampleResource($example->load('author')));
    }

    public function update(UpdateExampleRequest $request, Example $example): JsonResponse
    {
        $example = $this->service->update($example, $request->validated());

        return $this->resource(
            new ExampleResource($example->load('author')),
            __('example::messages.updated'),
        );
    }

    public function destroy(Example $example): JsonResponse
    {
        $this->service->delete($example);

        return $this->success(message: __('example::messages.deleted'));
    }
}
