<?php

namespace App\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class LeasedlineCatalogRepository
{
    private const CACHE_TTL = 43200;

    /** @return Collection<int, array{id: int, crm_offering_id: mixed, name: string, bandwidth: int|float, service_type: string, start_date: ?string, created_at: ?string}> */
    public function getNormalOfferings(): Collection
    {
        return collect(Cache::remember('ill.normal.offerings', self::CACHE_TTL, fn (): array => $this->fetchNormalOfferings()));
    }

    /** @return Collection<int, array<string, mixed>> */
    public function getPostpaidFwaOfferings(string $category): Collection
    {
        if (! in_array($category, ['4G ILL', '5G ILL'], true)) {
            throw new InvalidArgumentException('Unsupported postpaid FWA category.');
        }

        return collect(Cache::remember('fwa.postpaid.offerings.'.$category, self::CACHE_TTL, fn (): array => $this->fetchPostpaidFwaOfferings($category)));
    }

    /** @return list<array<string, mixed>> */
    private function fetchNormalOfferings(): array
    {
        try {
            $rows = DB::connection('leasedline')->table('crm_ill_offering')
                ->select('Id', 'CRMOfferingId', 'Name', 'StartDate', 'CreatedAt')
                ->orderBy('Name')->orderBy('Id')->get();
        } catch (Throwable $exception) {
            $this->sourceFailure('crm_ill_offering', $exception);
        }

        $plans = $rows->map(function (object $row): ?array {
            $name = (string) $row->Name;
            if (preg_match('/^(\d+(?:\.\d+)?)\s+Mbps\s+(Standard|Premium|GIN)(?: |$)/i', $name, $matches)) {
                $bandwidth = $matches[1];
                $serviceType = strtoupper($matches[2]) === 'GIN' ? 'GIN' : ucfirst(strtolower($matches[2]));
            } elseif (preg_match('/^GIN\s+(\d+(?:\.\d+)?)\s+Mbps(?: |$)/i', $name, $matches)) {
                $bandwidth = $matches[1];
                $serviceType = 'GIN';
            } else {
                return null;
            }

            if ((float) $bandwidth <= 0 || ! is_finite((float) $bandwidth)) {
                return null;
            }

            return [
                'id' => (int) $row->Id,
                'crm_offering_id' => $row->CRMOfferingId,
                'name' => $name,
                'bandwidth' => str_contains($bandwidth, '.') ? (float) $bandwidth : (int) $bandwidth,
                'service_type' => $serviceType,
                'start_date' => $row->StartDate,
                'created_at' => $row->CreatedAt,
            ];
        })->filter();

        $pairCounts = $plans->countBy(fn (array $plan): string => $plan['bandwidth'].' '.$plan['service_type']);
        $selectable = $plans->filter(fn (array $plan): bool => trim((string) $plan['crm_offering_id']) !== ''
            && $pairCounts[$plan['bandwidth'].' '.$plan['service_type']] === 1);

        $this->logExcludedRows('crm_ill_offering', $rows->pluck('Id')->diff($selectable->pluck('id'))->values()->all());

        return $selectable->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function fetchPostpaidFwaOfferings(string $category): array
    {
        try {
            $connection = DB::connection('leasedline');
            $rows = $connection->table('crm_ill_offering_4g5g')
                ->select('Id', 'CBSId', 'CRMOfferingId', 'Name', '5GOr4G', 'Is_PO', 'FWANewPlan', 'MaxGB', 'MaxSpeed')
                ->where('5GOr4G', $category)->where('Is_PO', 'No')->where('FWANewPlan', 'Y')
                ->orderBy('Name')->orderBy('Id')->get();
            $duplicates = $connection->table('crm_ill_offering_4g5g')
                ->select('CBSId')->where('5GOr4G', $category)->whereNotNull('CBSId')
                ->groupBy('CBSId')->havingRaw('COUNT(*) > 1')->pluck('CBSId')
                ->map(fn (mixed $id): string => (string) $id);
        } catch (Throwable $exception) {
            $this->sourceFailure('crm_ill_offering_4g5g', $exception);
        }

        $selectable = $rows->filter(fn (object $row): bool => $row->{'5GOr4G'} === $category
            && $row->Is_PO === 'No' && $row->FWANewPlan === 'Y'
            && preg_match('/(?:_| )Postpaid$/i', (string) $row->Name) === 1
            && is_numeric($row->MaxGB) && is_finite((float) $row->MaxGB) && (float) $row->MaxGB > 0
            && preg_match('/^[1-9]\d*$/', (string) $row->CBSId) === 1
            && ! $duplicates->contains((string) $row->CBSId));

        $this->logExcludedRows('crm_ill_offering_4g5g', $rows->pluck('Id')->diff($selectable->pluck('Id'))->values()->all());

        return $selectable->map(fn (object $row): array => [
            ...(array) $row,
            'Id' => (int) $row->Id,
            'id' => (int) $row->Id,
            'sim_type' => 'Postpaid',
            'base_plan' => $category === '5G ILL' ? '5G' : '4G',
            'is_booster' => 'N',
            'status' => 'new',
            'data_cap' => $row->MaxGB,
            'max_speed' => $row->MaxSpeed,
            'price' => null,
            'default_speed' => null,
        ])->values()->all();
    }

    private function sourceFailure(string $table, Throwable $exception): never
    {
        Log::error('Leased-line catalog read failed.', ['table' => $table, 'exception_type' => $exception::class]);

        throw new RuntimeException('Unable to retrieve leased-line catalog. Please try again later.');
    }

    /** @param list<int|string> $ids */
    private function logExcludedRows(string $table, array $ids): void
    {
        if ($ids !== []) {
            Log::warning('Unusable leased-line catalog rows excluded.', ['table' => $table, 'source_ids' => $ids]);
        }
    }
}
