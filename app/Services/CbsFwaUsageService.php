<?php

namespace App\Services;

use App\Repositories\CatalogRepository;
use App\Repositories\IllOfferingRepository;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CbsFwaUsageService
{
    public function __construct(
        private CatalogRepository $catalogRepository,
        private IllOfferingRepository $illOfferingRepository
    ) {}

    private const FREE_UNIT_5G_FWA =
        'C_Free_FluX_National_NoRoam_GPRS_5GFWA';

    private const FREE_UNIT_4G_FWA =
        'C_Free_FluX_National_NoRoam_GPRS_4GFWA';

    private const FREE_UNIT_ADD_ON =
        'C_Free_FluX_National_NoRoam_GPRS_FWAaddon';

    private function queryFreeUnits(string $serviceId): string
    {
        $url = config('services.cbs.url');
        $password = config('services.cbs.password');

        if (! $url || ! $password) {
            throw new RuntimeException(
                'CBS configuration is missing.'
            );
        }

        $messageSequence = now()->format('YmdHis')
            .$this->randomString(4);

        /*
        * Escape values inserted into XML.
        */
        $serviceIdXml = htmlspecialchars(
            $serviceId,
            ENT_XML1 | ENT_QUOTES,
            'UTF-8'
        );

        $passwordXml = htmlspecialchars(
            $password,
            ENT_XML1 | ENT_QUOTES,
            'UTF-8'
        );

        $messageSequenceXml = htmlspecialchars(
            $messageSequence,
            ENT_XML1 | ENT_QUOTES,
            'UTF-8'
        );

        $soapBody = <<<XML
            <soapenv:Envelope
                xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                xmlns:bbs="http://www.huawei.com/bme/cbsinterface/bbservices"
                xmlns:cbs="http://www.huawei.com/bme/cbsinterface/cbscommon"
                xmlns:bbc="http://www.huawei.com/bme/cbsinterface/bbcommon">
                <soapenv:Header/>
                <soapenv:Body>
                    <bbs:QueryFreeUnitRequestMsg>
                        <RequestHeader>
                            <cbs:Version>1</cbs:Version>
                            <cbs:MessageSeq>{$messageSequenceXml}</cbs:MessageSeq>
                            <cbs:OwnershipInfo>
                                <cbs:BEID>101</cbs:BEID>
                            </cbs:OwnershipInfo>
                            <cbs:AccessSecurity>
                                <cbs:LoginSystemCode>102</cbs:LoginSystemCode>
                                <cbs:Password>{$passwordXml}</cbs:Password>
                                <cbs:RemoteIP>127.0.0.1</cbs:RemoteIP>
                            </cbs:AccessSecurity>
                            <cbs:OperatorInfo>
                                <cbs:OperatorID>101</cbs:OperatorID>
                            </cbs:OperatorInfo>
                            <cbs:MsgLanguageCode>2002</cbs:MsgLanguageCode>
                            <cbs:TimeFormat>
                                <cbs:TimeType>1</cbs:TimeType>
                                <cbs:TimeZoneID>101</cbs:TimeZoneID>
                            </cbs:TimeFormat>
                        </RequestHeader>
                        <QueryFreeUnitRequest>
                            <bbs:QueryObj>
                                <bbs:SubAccessCode>
                                    <bbc:PrimaryIdentity>{$serviceIdXml}</bbc:PrimaryIdentity>
                                </bbs:SubAccessCode>
                            </bbs:QueryObj>
                        </QueryFreeUnitRequest>
                    </bbs:QueryFreeUnitRequestMsg>
                </soapenv:Body>
            </soapenv:Envelope>
            XML;

        $response = Http::withOptions([
            /*
            * Preserve the legacy CBS TLS behaviour.
            * The old implementation disabled certificate verification.
            */
            'verify' => false,
        ])
            ->withHeaders([
                'Content-Type' => 'text/xml',
                'cache-control' => 'no-cache',
            ])
            ->timeout(60)
            ->withBody($soapBody, 'text/xml')
            ->post($url);

        if (! $response->successful()) {
            throw new RuntimeException(
                'CBS QueryFreeUnit failed. HTTP status: '
                .$response->status()
            );
        }

        return $response->body();
    }

    private function randomString(int $length): string
    {
        $characters =
            '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

        $result = '';

        $max = strlen($characters) - 1;

        for ($i = 0; $i < $length; $i++) {
            $result .= $characters[random_int(0, $max)];
        }

        return $result;
    }

    public function getUsage(string $serviceId): array
    {
        $soapResponse = $this->queryFreeUnits($serviceId);

        libxml_use_internal_errors(true);

        $xml = simplexml_load_string($soapResponse);

        if ($xml === false) {
            libxml_clear_errors();

            throw new RuntimeException(
                'Unable to parse CBS QueryFreeUnit response.'
            );
        }

        $xml->registerXPathNamespace(
            'bbs',
            'http://www.huawei.com/bme/cbsinterface/bbservices'
        );

        $xml->registerXPathNamespace(
            'bbc',
            'http://www.huawei.com/bme/cbsinterface/bbcommon'
        );

        $items = $xml->xpath('//bbs:FreeUnitItem') ?: [];

        $plans = [];

        foreach ($items as $item) {
            /*
            * Access bbs-prefixed children explicitly.
            */
            $bbs = $item->children(
                'http://www.huawei.com/bme/cbsinterface/bbservices'
            );

            $freeUnitType = (string) $bbs->FreeUnitType;

            $displayGroup = match ($freeUnitType) {
                self::FREE_UNIT_5G_FWA => '5G FWA',
                self::FREE_UNIT_4G_FWA => '4G FWA',
                self::FREE_UNIT_ADD_ON => 'Add on',
                default => null,
            };

            if ($displayGroup === null) {
                continue;
            }

            foreach ($bbs->FreeUnitItemDetail as $detail) {
                $detailBbs = $detail->children(
                    'http://www.huawei.com/bme/cbsinterface/bbservices'
                );

                $initialBytes = (float) $detailBbs->InitialAmount;
                $remainingBytes = (float) $detailBbs->CurrentAmount;

                /*
                * Legacy calculation:
                * bytes → GB → round to 2 decimals.
                */
                $initialGb = round(
                    $initialBytes / 1073741824,
                    2
                );

                $remainingGb = round(
                    $remainingBytes / 1073741824,
                    2
                );

                /*
                * OfferingID is nested under:
                *
                * bbs:FreeUnitOrigin
                *   bbs:OfferingKey
                *     bbc:OfferingID
                */
                $detail->registerXPathNamespace(
                    'bbs',
                    'http://www.huawei.com/bme/cbsinterface/bbservices'
                );

                $detail->registerXPathNamespace(
                    'bbc',
                    'http://www.huawei.com/bme/cbsinterface/bbcommon'
                );

                $offeringIdNodes = $detail->xpath(
                    './/bbc:OfferingID'
                );

                $offeringId = isset($offeringIdNodes[0])
                    ? (string) $offeringIdNodes[0]
                    : null;

                $offering = $offeringId !== null
                    ? $this->resolveOffering($offeringId)
                    : null;

                $plans[] = [
                    'type' => $displayGroup,
                    'freeUnitType' => $freeUnitType,
                    'offeringId' => $offeringId,

                    'planName' => $offering['Name'] ?? null,
                    'dataCap' => $offering['DataBucket']
                        ?? $offering['data_cap']
                        ?? null,

                    'initialAmountRaw' => $initialGb,
                    'remainingAmountRaw' => $remainingGb,

                    'initialAmount' => $initialGb.' GB',
                    'remainingAmount' => $remainingGb.' GB',

                    'showAddOnPlans' => $remainingGb <= 1,
                ];
            }
        }

        libxml_clear_errors();

        return $plans;
    }

    private function resolveOffering(string $offeringId): ?array
    {
        /*
        * Legacy lookup order:
        *
        * 1. offerings
        * 2. leasedlineofferings + leasedlineofferingsdtls
        */
        $offering = $this->catalogRepository
            ->findOfferingById($offeringId);

        if ($offering !== null) {
            return $offering;
        }

        return $this->illOfferingRepository
            ->findOfferingById($offeringId);
    }
}
