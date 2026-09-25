<?php

namespace App\Repositories;

use App\Models\IllOfferingMapping;

class IllOfferingMappingRepository
{
    /** @return list<array{id: string, name: string}> */
    public function getAddonsForBasePlan(string $basePlanId): array
    {
        return IllOfferingMapping::query()
            ->where('base_plan_id', $basePlanId)
            ->orderBy('addon_id')
            ->get(['addon_id', 'addon_name'])
            ->map(fn (IllOfferingMapping $mapping): array => [
                'id' => $mapping->addon_id,
                'name' => $mapping->addon_name,
            ])->all();
    }

    /** @return array{id: string, name: string}|null */
    public function findAddon(string $basePlanId, string $addonId): ?array
    {
        $mapping = IllOfferingMapping::query()
            ->where('base_plan_id', $basePlanId)
            ->where('addon_id', $addonId)
            ->first(['addon_id', 'addon_name']);

        return $mapping === null ? null : [
            'id' => $mapping->addon_id,
            'name' => $mapping->addon_name,
        ];
    }

    public function getBasePlanName(string $basePlanId): ?string
    {
        return IllOfferingMapping::query()
            ->where('base_plan_id', $basePlanId)
            ->orderBy('addon_id')
            ->value('base_plan_name');
    }
}
