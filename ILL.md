# ILL offerings

## Operator UI

Sign in to the operations workspace.

- **Plan workspace → Service → ILL leased line**: enter a service number and select **Find plans**. CRM customer lookup determines eligibility, then Normal ILL plans show their full name, source plan ID, CRM offering ID, parsed bandwidth, and service type. **Select plan** shows a review summary without submitting a service change.
- **Plan workspace → Service → FWA broadband**: CRM determines prepaid/postpaid and 4G/5G automatically. Postpaid target cards show the source plan ID, category, allowance, maximum speed, CBS ID, and CRM offering ID. **Select plan** only updates the review summary. Current-plan details, usage, and boosters remain available. Prepaid behavior is unchanged.
- **ILL offerings** in the sidebar: browse local mappings by base plan ID. The page opens with `109`. An optional base plan name must match the stored name exactly. An unknown ID shows an empty state. This page does not contact CRM, CBS, or the external catalog database.
- **API directory**: describes the ILL subscriber catalog, local mapping lookup, and cache refresh endpoints.

These screens are read-only. They do not activate offerings, create subscriptions, or edit mappings.

## Local mappings versus subscriber eligibility

`ill_offering_mappings` is stored in the middleware's default database. Each row links a base plan to one optional add-on, with a unique constraint on `(base_plan_id, addon_id)`. External IDs are strings of up to 50 characters; names allow 255 characters. Internal row IDs and timestamps are not returned by the lookup APIs.

The mapping lookup treats the base plan ID as authoritative. It never finds a different ID by name. Unknown IDs return `addons: []`, preserving the requested ID and supplied name (or `null` when omitted). A supplied name that differs from a known stored name produces a validation error.

`IllCatalogService` first calls `SubscriberService::getIllCustomerData(service_id)` and requires the exact CRM base-plan name `ILL_Main_Offering`. The response retains base `109` / `ILL Main Offering`, but its selectable `addons` now come from `DB::connection('leasedline')->table('crm_ill_offering')`, ordered by `Name`, then `Id`. The local mapping table/service remains available for pair validation and the separate mapping lookup; its 12 mapped rows are no longer the subscriber catalog. This does not query CRM's live optional add-ons under base offering `109`.

## API authentication

All ILL API routes require the project's Sanctum authentication. Obtain a token through `POST /api/v1/login` and send:

```http
Accept: application/json
Authorization: Bearer <token>
```

The browser UI uses the existing authenticated web session; operators do not need to paste tokens into the UI.

## Local add-on lookup

```http
GET /api/v1/ill/offerings/109/addons
GET /api/v1/ill/offerings/109/addons?base_plan_name=ILL%20Main%20Offering
```

Response shape (add-ons shortened here; seeded base 109 returns all 12):

```json
{
  "success": true,
  "base_plan": { "id": "109", "name": "ILL Main Offering" },
  "addons": [
    { "id": "1303", "name": "Home 5 Mbps 1500" }
  ]
}
```

Add-ons are sorted lexically by their string `addon_id`. An unknown base returns HTTP 200 with an empty array. Missing authentication returns 401. A mismatched name, non-string name, or name longer than 255 characters returns 422 with validation errors under `base_plan_name`.

## Subscriber catalog

```http
GET /api/v1/ill/catalog?service_id=12345678
```

`service_id` is a required string of at most 20 characters. A successful response includes `success`, `service_id`, `subscription`, `crm_base_plan`, `base_plan`, and `addons`:

```json
{
  "success": true,
  "service_id": "12345678",
  "subscription": "Postpaid",
  "crm_base_plan": "ILL_Main_Offering",
  "base_plan": { "id": "109", "name": "ILL Main Offering" },
  "addons": [
    {
      "id": 34,
      "crm_offering_id": 28,
      "name": "20 Mbps Standard 13500 New",
      "bandwidth": 20,
      "service_type": "Standard",
      "start_date": "0000-00-00",
      "created_at": "2023-09-11"
    }
  ]
}
```

The example list is shortened. An ineligible subscriber is rejected with 422. Source failures return a controlled message with no SQL, connection details, or stack trace.

### Normal ILL parsing and selection

The repository reads `Id`, `CRMOfferingId`, `Name`, `StartDate`, and `CreatedAt`. Dates are returned as source strings, including legacy zero dates; they do not filter eligibility.

Names are parsed case-insensitively at the beginning of the name: a positive numeric bandwidth immediately followed by `Mbps`, then `Standard`, `Premium`, or `GIN`. `GIN <bandwidth> Mbps` is also supported. Service types are returned canonically. For example, `20 Mbps Standard 13500 New` becomes `bandwidth: 20`, `service_type: Standard`; `50 Mbps Premium ...` becomes `50` / `Premium`; both `100 Mbps GIN ...` and `GIN 100 Mbps ...` become `100` / `GIN`. No prices are inferred.

Unparseable names, missing CRM offering IDs, and all rows in ambiguous bandwidth/service-type pairs are excluded and logged by source row ID. Home/Enterprise names are never mapped to Standard/Premium. A unique match with a nonempty CRM offering ID is required for selection; the application must reject missing or ambiguous matches rather than take the first row.

The Normal ILL response `addons[].id` is `crm_ill_offering.Id`. A future bandwidth-change submission will use the selected plan's `bandwidth` as `NewBandwidth` and `service_type` as `NewServiceType`. No submission endpoint or request is implemented here.

### Postpaid 4G/5G FWA targets

`FwaPlanResolver` still takes only `service_id`, uses CRM subscriber information, and dispatches prepaid requests to the unchanged prepaid flow. For postpaid:

| CRM BasePlan | Resolved category |
| --- | --- |
| `5G Unlimited` | `5G ILL` |
| `4G Home Unlimited Postpaid` | `4G ILL` |

`PostpaidFwaPlanService` fetches targets from `DB::connection('leasedline')->table('crm_ill_offering_4g5g')`. Only these two categories may reach the repository query. The query requires the resolved `5GOr4G`, `Is_PO = No`, and `FWANewPlan = Y`, ordered by `Name`, then `Id`.

Targets must also end in `_Postpaid` or ` Postpaid`, have positive numeric `MaxGB`, and have a positive integer `CBSId` unique across the entire resolved category (including nonselectable rows). All targets sharing a duplicate CBS ID are excluded. Invalid candidates are logged by source ID. The current bandwidth name is excluded from alternatives. A missing current-plan match in the legacy catalog does not suppress valid new targets.

Existing outer fields remain: `success`, `service_id`, `subscription`, `BasePlan`, `bandwidth`, `subscriptions`, `currentBasePlan`, `basePlanOfferings`, `addOnOfferings`, and `usage`. Targets populate `basePlanOfferings`; current-plan details and boosters still use the existing legacy repository. Unsupported base plans retain the existing `false` offerings response. Prepaid subscribers are rejected by the dedicated postpaid endpoint.

Example target (other metadata omitted):

```json
{
  "Id": 27,
  "id": 27,
  "CBSId": 1201771411,
  "CRMOfferingId": 921,
  "Name": "5GHome 1777_Postpaid",
  "5GOr4G": "5G ILL",
  "Is_PO": "No",
  "FWANewPlan": "Y",
  "MaxGB": "240",
  "MaxSpeed": "30 Mbps",
  "data_cap": "240",
  "max_speed": "30 Mbps"
}
```

Both `basePlanOfferings[].id` and the compatible `basePlanOfferings[].Id` equal **`crm_ill_offering_4g5g.Id`**. Future downstream submission must send **`FWANewPlan: 27`** for this example. The response's `FWANewPlan: Y` is a source eligibility flag, not the submission value. Missing price/default speed metadata is `null`; neither is guessed from names.

| Identifier | Meaning | Usage |
| --- | --- | --- |
| `Id` | Row ID in the external catalog table | Postpaid FWA future `FWANewPlan` submission value |
| `CBSId` | CBS offering identifier | Additional metadata; never substitute for `Id` |
| `CRMOfferingId` | CRM offering identifier | Additional metadata; never substitute for `Id` |

The IDs belong to distinct namespaces. Do not use a CBS ID, CRM ID, old legacy offering ID, or array position as the new FWA plan ID.

Both external tables are read-only: no migrations, seeds, inserts, updates, or deletes are performed. Prepaid `fwa_plans`, eligibility constants, GST, amounts, and response contract are unchanged. SIM/mobile catalog behavior is unchanged.

## Confirmed mappings

All rows belong to `109` / `ILL Main Offering`.

| Add-on ID | Offering name |
| --- | --- |
| 1303 | Home 5 Mbps 1500 |
| 1306 | Home 7 Mbps 2100 |
| 1307 | Enterprise 50 Mbps 13750 |
| 1308 | Home 10 Mbps 3000 |
| 1309 | Home 15 Mbps 4500 |
| 1310 | Home 20 Mbps 6000 |
| 1311 | Enterprise 70 Mbps 19250 |
| 1312 | Enterprise 100 Mbps 27500 |
| 1313 | Enterprise 300 Mbps 82500 |
| 1314 | Enterprise 400 Mbps 110000 |
| 1315 | Enterprise 800 Mbps 220000 |
| 1337 | Home 20 Mbps 3000 |

## Installation and updates

Run from the deployed project root, with the middleware's default database configured:

```bash
php artisan migrate --path=database/migrations/2026_09_25_111505_create_ill_offering_mappings_table.php --force --no-interaction
php artisan db:seed --class=IllOfferingMappingSeeder --force --no-interaction
npm run build
```

The migration and seeder refuse the `catalog` connection. The dedicated seeder uses `updateOrCreate`: rerunning it refreshes the confirmed names without duplicate pairs and preserves mappings not listed in the seed. It is not automatically called by `DatabaseSeeder`.

## Pair lookup for future integrations

```php
$addon = $mappingService->findAddon('109', '1303');
// ['id' => '1303', 'name' => 'Home 5 Mbps 1500']

$missing = $mappingService->findAddon('different-base', '1303');
// null
```

Inject `App\Services\IllOfferingMappingService` into the caller. The underlying repository constrains both external IDs. This provides lookup capability only; no activation endpoint is implemented.

## Separate leased-line cache

Normal ILL uses `ill.normal.offerings`. Postpaid FWA uses `fwa.postpaid.offerings.4G ILL` and `fwa.postpaid.offerings.5G ILL`. Each caches catalog data for 43,200 seconds (12 hours), reloading on the next read after expiry. Subscriber-specific CRM information is not stored in these caches. Failed source fetches are not cached; valid warm catalogs continue to work during source outages. They are separate from `ill.offerings` and `catalog.base_data`.

`POST /api/v1/ill/refresh` and the dashboard's **Refresh ILL cache** action continue to refresh the existing legacy `ill.offerings` cache for current-plan details and boosters. They do not refresh the new catalogs or update `ill_offering_mappings`. Local mappings are read directly and do not require a cache refresh.

The read-only source inspection during implementation found 127 Normal ILL rows: 126 safely parsed unique plans and one excluded row, `Id=128`, `500 Mbps 142500`, which has no supported service type. No GIN rows were present, but both GIN formats are tested. Ten postpaid FWA targets passed all compatibility rules (seven 5G, three 4G), with no duplicate CBS IDs in the supported categories. Other FWA rows fail the category/primary-offering/new-plan filters and are intentionally not targets.

## Verification and troubleshooting

```bash
php artisan test --compact tests/Feature/LeasedlineCatalogTest.php tests/Feature/IllOfferingMappingTest.php tests/Feature/FwaPlansTest.php tests/Feature/DashboardTest.php tests/Feature/CatalogClassificationTest.php
node --test tests/Feature/PlanCards.test.mjs
php artisan test
npm run build
```

Catalog and mapping tests use in-memory SQLite and require no live database or CRM calls. UI card tests use Node's built-in runner without additional dependencies.

- Empty local mapping lookup for base 109: confirm the mapping migration and dedicated seed ran against the middleware database. The subscriber catalog instead requires the read-only `leasedline` connection and safely usable source rows.
- Name mismatch: use the stored display name `ILL Main Offering`; `ILL_Main_Offering` is the separate CRM name.
- Subscriber lookup fails: verify the service number and CRM eligibility with the integration operator.
- UI changes missing: rebuild frontend assets or run `npm run dev` locally.
