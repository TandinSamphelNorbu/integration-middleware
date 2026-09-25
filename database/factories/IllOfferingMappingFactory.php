<?php

namespace Database\Factories;

use App\Models\IllOfferingMapping;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IllOfferingMapping> */
class IllOfferingMappingFactory extends Factory
{
    /** @return array{base_plan_id: string, base_plan_name: string, addon_id: string, addon_name: string} */
    public function definition(): array
    {
        return [
            'base_plan_id' => fake()->unique()->numerify('base-#####'),
            'base_plan_name' => fake()->words(3, true),
            'addon_id' => fake()->unique()->numerify('addon-#####'),
            'addon_name' => fake()->words(3, true),
        ];
    }
}
