<?php

namespace Database\Seeders;

use App\Models\IllOfferingMapping;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class IllOfferingMappingSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::getDefaultConnection() === 'catalog') {
            throw new LogicException('ILL mappings must use the middleware database.');
        }

        $addons = [
            ['id' => '1303', 'name' => 'Home 5 Mbps 1500'],
            ['id' => '1306', 'name' => 'Home 7 Mbps 2100'],
            ['id' => '1307', 'name' => 'Enterprise 50 Mbps 13750'],
            ['id' => '1308', 'name' => 'Home 10 Mbps 3000'],
            ['id' => '1309', 'name' => 'Home 15 Mbps 4500'],
            ['id' => '1310', 'name' => 'Home 20 Mbps 6000'],
            ['id' => '1311', 'name' => 'Enterprise 70 Mbps 19250'],
            ['id' => '1312', 'name' => 'Enterprise 100 Mbps 27500'],
            ['id' => '1313', 'name' => 'Enterprise 300 Mbps 82500'],
            ['id' => '1314', 'name' => 'Enterprise 400 Mbps 110000'],
            ['id' => '1315', 'name' => 'Enterprise 800 Mbps 220000'],
            ['id' => '1337', 'name' => 'Home 20 Mbps 3000'],
        ];

        foreach ($addons as $addon) {
            IllOfferingMapping::updateOrCreate(
                ['base_plan_id' => '109', 'addon_id' => $addon['id']],
                ['base_plan_name' => 'ILL Main Offering', 'addon_name' => $addon['name']],
            );
        }
    }
}
