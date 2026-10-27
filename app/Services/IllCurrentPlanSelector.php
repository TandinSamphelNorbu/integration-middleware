<?php

namespace App\Services;

class IllCurrentPlanSelector
{
    /** @return array<string, mixed> */
    public function select(mixed $response): array
    {
        $rows = is_array($response) ? ($response['subscriptions'] ?? null) : null;
        if (! is_array($rows) || ! array_is_list($rows) || $rows === []) {
            return $this->unresolved('missing_subscriptions');
        }

        $selected = null;
        $count = 0;
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['plan_name'] ?? null)) {
                return $this->unresolved('invalid_subscription_data');
            }
            $name = $row['plan_name'];
            $lower = strtolower($name);
            if (! str_contains($lower, 'standard') && ! str_contains($lower, 'premium') && ! str_contains($lower, 'gin')) {
                continue;
            }
            $count++;
            $type = str_contains($lower, 'gin') ? 'GIN' : (str_contains($lower, 'standard') ? 'Standard' : 'Premium');
            $bandwidth = $type === 'GIN'
                ? preg_replace('/[^0-9]/', '', explode(' mbps', $lower)[0])
                : explode(' Mbps', $name)[0];
            if ($selected === null && $bandwidth) {
                $selected = [
                    'planId' => $row['plan_id'] ?? null,
                    'subscriptionId' => $row['subscription_id'] ?? null,
                    'planName' => $name,
                    'serviceType' => $type,
                    'bandwidthMbps' => $bandwidth,
                ];
            }
        }
        if ($selected === null) {
            return $this->unresolved('no_matching_bandwidth_plan', $count);
        }
        $bandwidth = $selected['bandwidthMbps'];
        if (! is_numeric($bandwidth) || ! is_finite((float) $bandwidth) || (float) $bandwidth <= 0
            || ! $this->validId($selected['planId']) || ! $this->validId($selected['subscriptionId'])) {
            return $this->unresolved('invalid_selected_plan', $count);
        }
        $selected['bandwidthMbps'] = $bandwidth + 0;
        $selected['planId'] = (string) $selected['planId'];
        $selected['subscriptionId'] = (string) $selected['subscriptionId'];

        return [
            'state' => 'resolved', 'selectionPolicy' => 'legacy_first_match',
            'currentBandwidthPlan' => $selected, 'matchingSubscriptionCount' => $count,
            'diagnostics' => $count > 1 ? ['multiple_matching_subscriptions'] : [],
        ];
    }

    private function validId(mixed $id): bool
    {
        return (is_string($id) || is_int($id) || is_float($id)) && trim((string) $id) !== '';
    }

    /** @return array<string, mixed> */
    public function unresolved(string $reason, int $count = 0): array
    {
        return [
            'state' => 'unresolved', 'reason' => $reason, 'selectionPolicy' => 'legacy_first_match',
            'currentBandwidthPlan' => null, 'matchingSubscriptionCount' => $count,
            'diagnostics' => $count > 1 ? ['multiple_matching_subscriptions'] : [],
        ];
    }
}
