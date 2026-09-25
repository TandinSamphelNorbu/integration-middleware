# ILL offerings

## Operator UI

Sign in to the operations workspace.

- **Plan workspace ? Service ? ILL leased line**: enter a service number and select **Find plans**. This uses the existing CRM customer lookup to determine ILL eligibility, then displays the mapped optional add-ons. The result shows subscriber type, base plan, base plan ID, and add-on names/IDs. No price or speed fields are inferred from offering names.
- **ILL offerings** in the sidebar: browse local mappings by base plan ID. The page opens with `109`. An optional base plan name must match the stored name exactly. An unknown ID shows an empty state. This page does not contact CRM, CBS, or the external catalog database.
- **API directory**: describes the ILL subscriber catalog, local mapping lookup, and cache refresh endpoints.

These screens are read-only. They do not activate offerings, create subscriptions, or edit mappings.

## Local mappings versus subscriber eligibility

`ill_offering_mappings` is stored in the middleware's default database. Each row links a base plan to one optional add-on, with a unique constraint on `(base_plan_id, addon_id)`. External IDs are strings of up to 50 characters; names allow 255 characters. Internal row IDs and timestamps are not returned by the lookup APIs.

The mapping lookup treats the base plan ID as authoritative. It never finds a different ID by name. Unknown IDs return `addons: []`, preserving the requested ID and supplied name (or `null` when omitted). A supplied name that differs from a known stored name produces a validation error.

The existing `IllCatalogService` first calls `SubscriberService::getIllCustomerData(service_id)`. It accepts the exact CRM base-plan name `ILL_Main_Offering`, which maps to local base `109` / `ILL Main Offering`. The optional add-ons then come from the local mapping service. A local mapping alone does not establish subscriber eligibility.

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
    { "id": "1303", "name": "Home 5 Mbps 1500" }
  ]
}
```

The example list is shortened. An ineligible subscriber is rejected with 422. The UI presents a generic failure message rather than displaying underlying integration errors.

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

`POST /api/v1/ill/refresh` and the dashboard's **Refresh ILL cache** action refresh the existing `ill.offerings` cache from the source catalog, with a 12-hour lifetime. They do not seed or update `ill_offering_mappings`. Local mapping changes are read directly from the default database and do not require a cache refresh.

## Verification and troubleshooting

```bash
php artisan test --compact tests/Feature/IllOfferingMappingTest.php tests/Feature/DashboardTest.php
php artisan test
npm run build
```

Mapping tests use in-memory SQLite and require no external catalog database. Subscriber UI coverage fakes the CRM-facing service.

- Empty base 109: confirm the mapping migration and dedicated seed ran against the middleware database.
- Name mismatch: use the stored display name `ILL Main Offering`; `ILL_Main_Offering` is the separate CRM name.
- Subscriber lookup fails: verify the service number and CRM eligibility with the integration operator.
- UI changes missing: rebuild frontend assets or run `npm run dev` locally.
