<?php

declare(strict_types=1);

namespace Modules\Example\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Example\Enums\ExampleStatus;
use Modules\Example\Models\Example;

/**
 * @extends Factory<Example>
 */
class ExampleFactory extends Factory
{
    protected $model = Example::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->sentence(3);

        return [
            'user_id' => null,
            'title' => $title,
            'slug' => Str::slug($title).'-'.fake()->unique()->numberBetween(1, 99999),
            'body' => fake()->paragraph(),
            'status' => fake()->randomElement(ExampleStatus::cases())->value,
            'is_featured' => fake()->boolean(20),
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => ['status' => ExampleStatus::Published->value]);
    }
}
