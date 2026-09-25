# API reference

All endpoints use the `/api/v1` prefix. The lookup and catalog endpoints require a Sanctum bearer token. Obtain one from the login endpoint and send it as `Authorization: Bearer <token>` with `Accept: application/json`.

The refresh and sync endpoints are intentionally omitted from this reference.

## Authentication

### Log in

```http
POST /api/v1/login
Content-Type: application/json
Accept: application/json
```

Request body:

```json
{
  "name": "operator",
  "password": "your-password"
}
```

Both fields are required strings. A successful login returns a Sanctum token:

```json
{
  "success": true,
  "token_type": "Bearer",
  "access_token": "1|example-token-value"
}
```

Use `access_token` as the bearer token on protected endpoints. Invalid credentials return HTTP 401:

```json
{
  "success": false,
  "message": "Invalid credentials"
}
```

### Get the authenticated user

```http
GET /api/v1/user
Authorization: Bearer <token>
Accept: application/json
```

Example response:

```json
{
  "success": true,
  "user": {
    "id": 12,
    "name": "operator"
  }
}
```

Unauthenticated requests return HTTP 401.

## Mobile catalog

### Get eligible mobile plans

```http
GET /api/v1/catalog?service_id=12345678
Authorization: Bearer <token>
Accept: application/json
```

`service_id` is required, must be a string, and may contain at most 20 characters. The service looks up the subscriber's connection type, primary offering, and 4G eligibility, then returns eligible plans grouped by category.

Example response (plan list shortened):

```json
{
  "poId": 1694642726,
  "dataPlans": [
    {
      "Id": 3,
      "Category": "Normal Plans",
      "DisplayOrder": 1,
      "Plans": [
        {
          "Id": 4101,
          "Name": "Monthly Data 10GB",
          "AddOn": "N",
          "ShortName": "10GB",
          "Price": "Nu. 199",
          "DataBucket": "10 GB",
          "Validity": "30 days"
        }
      ]
    }
  ]
}
```

| Field | Meaning |
| --- | --- |
| `poId` | Subscriber's primary offering ID from CRM. |
| `dataPlans` | Eligible plan groups, ordered by category display order. |
| `dataPlans[].Id` | Catalog category ID. |
| `dataPlans[].Category` | Category name shown to the operator. |
| `dataPlans[].DisplayOrder` | Category ordering value. |
| `dataPlans[].Plans` | Plans in this category. |
| `Plans[].Id` | Plan offering ID. |
| `Plans[].Name` | Plan name. |
| `Plans[].AddOn` | Catalog add-on marker. |
| `Plans[].ShortName` | Short plan name, when present. |
| `Plans[].Price` | Catalog price string. |
| `Plans[].DataBucket` | Data amount or allowance. |
| `Plans[].Validity` | Plan validity period. |

Unsupported subscription types, unavailable CRM information, or other eligibility lookup failures return HTTP 422 with `success: false` and a message.

## FWA plans

### Unified prepaid or postpaid lookup

```http
GET /api/v1/fwa/plans?service_id=12345678
Authorization: Bearer <token>
Accept: application/json
```

`service_id` is required, must be a string, and may contain at most 20 characters. CRM connection type selects the flow. Postpaid responses contain `subscription: "Postpaid"`; prepaid responses contain `primary_offering_id` and `plan_type` and do not include a `subscription` field.

#### Prepaid sample response

```json
{
  "success": true,
  "service_id": "12345678",
  "primary_offering_id": 702186264,
  "plan_type": "5G",
  "GST": "5%",
  "plans": [
    {
      "plan_id": 123456789,
      "plan_name": "5G Home Plan",
      "amount": 1000,
      "data_cap": "300 GB",
      "max_speed": "15 Mbps",
      "gstAmount": 50,
      "totalAmount": 1050
    }
  ],
  "prepaidILLPlans": [1000, 500]
}
```

| Field | Meaning |
| --- | --- |
| `success` | The lookup completed successfully. |
| `service_id` | The requested service number. |
| `primary_offering_id` | CRM primary offering used to identify prepaid FWA. `702186264` means 5G FWA; `797820976` means 4G FWA. Other IDs are rejected. |
| `plan_type` | Detected FWA technology, either `4G` or `5G`. |
| `GST` | GST rate applied to prepaid plan totals. |
| `plans` | Active local FWA plans matching `plan_type`, ordered from highest to lowest amount. |
| `plans[].plan_id` | CBS plan ID from the local plan record's `cbs_id`; it may be `null` when no CBS ID is stored. |
| `plans[].plan_name` | Recharge plan name. |
| `plans[].amount` | Price before GST. |
| `plans[].data_cap` | Data allowance. |
| `plans[].max_speed` | Maximum speed. |
| `plans[].gstAmount` | GST amount calculated from `amount`. |
| `plans[].totalAmount` | Price including GST. |
| `prepaidILLPlans` | Legacy list of amounts for all active local FWA plan rows, regardless of 4G/5G type. |

An empty `plans` array means no active local FWA plans were found for the detected plan type.

#### Postpaid sample response

```json
{
  "success": true,
  "service_id": "12345678",
  "subscription": "Postpaid",
  "BasePlan": "5G Unlimited",
  "bandwidth": "5GHome 1477_Postpaid",
  "subscriptions": [
    {
      "planId": "1801771352",
      "planName": "5GHome 1477_Postpaid",
      "status": "1",
      "is_booster": "N"
    }
  ],
  "currentBasePlan": {
    "Id": 1801771352,
    "Name": "5GHome 1477_Postpaid",
    "sim_type": "Postpaid",
    "base_plan": "5G",
    "is_booster": "N",
    "status": "new",
    "price": "Nu. 1477",
    "data_cap": "300 GB",
    "max_speed": "15 Mbps",
    "default_speed": "2 Mbps"
  },
  "basePlanOfferings": [
    {
      "Id": 1201771411,
      "Name": "5GHome 1777_Postpaid",
      "sim_type": "Postpaid",
      "base_plan": "5G",
      "is_booster": "N",
      "status": "new",
      "price": "Nu. 1777",
      "data_cap": "370 GB",
      "max_speed": "30 Mbps",
      "default_speed": "2 Mbps"
    }
  ],
  "addOnOfferings": [
    {
      "Id": 1304191889,
      "Name": "Booster 150_Postpaid",
      "sim_type": "Postpaid",
      "base_plan": null,
      "is_booster": "Y",
      "status": "new",
      "price": "Nu. 150",
      "data_cap": "30 GB",
      "max_speed": null,
      "default_speed": null
    }
  ],
  "usage": [
    {
      "type": "5G FWA",
      "freeUnitType": "C_Free_FluX_National_NoRoam_GPRS_5GFWA",
      "offeringId": "1801771352",
      "planName": "5GHome 1477_Postpaid",
      "dataCap": "300 GB",
      "initialAmountRaw": 60,
      "remainingAmountRaw": 40,
      "initialAmount": "60 GB",
      "remainingAmount": "40 GB",
      "showAddOnPlans": false
    }
  ]
}
```

The sample arrays are shortened. Each field is described below; offering objects in `currentBasePlan`, `basePlanOfferings`, and `addOnOfferings` share the same catalog fields.

| Field | Meaning |
| --- | --- |
| `success` | The lookup completed successfully. |
| `service_id` | The requested service number. |
| `subscription` | CRM connection type; `Postpaid` for this branch. |
| `BasePlan` | CRM rate plan name. `5G Unlimited` maps to 5G alternatives; `4G Home Unlimited Postpaid` maps to 4G alternatives. |
| `bandwidth` | Current bandwidth plan name derived from CRM subscriptions. `No Active Bandwidth` means no qualifying plan name was identified. |
| `subscriptions` | CRM subscription entries recognized from the subscriber's subscriptions. |
| `subscriptions[].planId` | CRM external plan/offering ID. |
| `subscriptions[].planName` | CRM plan name. |
| `subscriptions[].status` | CRM subscription status value. |
| `subscriptions[].is_booster` | Catalog classification, `Y` for booster and `N` for base plan; may be absent for Mbps entries. |
| `currentBasePlan` | Catalog offering whose `Name` exactly equals `bandwidth`, or `null` if no exact match exists. |
| `basePlanOfferings` | New alternative base offerings matching the derived technology and subscription type, excluding the current bandwidth name. |
| `addOnOfferings` | New postpaid booster offerings. |
| `usage` | FWA and add-on usage entries returned from CBS. |
| Offering `.Id` | Catalog offering ID. |
| Offering `.Name` | Catalog offering name. |
| Offering `.sim_type` | Subscription type, such as `Postpaid`. |
| Offering `.base_plan` | Technology group (`4G` or `5G`); boosters may have `null`. |
| Offering `.is_booster` | `Y` for booster or `N` for base plan. |
| Offering `.status` | Catalog status. Alternative lists include `new` offerings. |
| Offering `.price` | Price string from the catalog. |
| Offering `.data_cap` | Data allowance, when provided. |
| Offering `.max_speed` | Maximum speed, when provided. |
| Offering `.default_speed` | Default speed, when provided. |
| `usage[].type` | Display group such as `4G FWA`, `5G FWA`, or an FWA add-on. |
| `usage[].freeUnitType` | CBS free-unit code identifying the allowance. |
| `usage[].offeringId` | CBS offering ID associated with the usage entry, if available. |
| `usage[].planName` | Catalog plan name resolved from the offering ID, if available. |
| `usage[].dataCap` | Catalog allowance resolved from the offering, if available. |
| `usage[].initialAmountRaw` | Initial allowance in GB as a number. |
| `usage[].remainingAmountRaw` | Remaining allowance in GB as a number. |
| `usage[].initialAmount` | Initial allowance formatted for display. |
| `usage[].remainingAmount` | Remaining allowance formatted for display. |
| `usage[].showAddOnPlans` | `true` when remaining allowance is 1 GB or less. |

`basePlanOfferings` and `addOnOfferings` are `false` if the CRM base plan is unsupported or the current bandwidth did not exactly match a catalog offering. A successful lookup can therefore have no alternatives or add-ons.

### Postpaid-only lookup

```http
GET /api/v1/fwa/postpaid/plans?service_id=12345678
Authorization: Bearer <token>
Accept: application/json
```

This endpoint returns the same postpaid response shape described above. It is retained for direct postpaid lookups. Unlike `/fwa/plans`, it calls the postpaid service directly rather than selecting prepaid or postpaid from the CRM connection type.

FWA lookup failures return HTTP 400 with:

```json
{
  "success": false,
  "message": "Unsupported subscription type for FWA."
}
```

Missing or invalid `service_id` returns HTTP 422 validation errors.

## ILL leased-line offerings

### Get add-ons mapped to a base plan

```http
GET /api/v1/ill/offerings/109/addons?base_plan_name=ILL%20Main%20Offering
Authorization: Bearer <token>
Accept: application/json
```

`base_plan_name` is optional; when supplied it must be a string of at most 255 characters. The base plan ID is authoritative. If a known ID is supplied with a different name, the request returns HTTP 422.

Example response (add-on list shortened):

```json
{
  "success": true,
  "base_plan": {
    "id": "109",
    "name": "ILL Main Offering"
  },
  "addons": [
    {
      "id": "1303",
      "name": "Home 5 Mbps 1500"
    }
  ]
}
```

| Field | Meaning |
| --- | --- |
| `success` | The mapping lookup completed. |
| `base_plan.id` | Requested base plan ID. |
| `base_plan.name` | Stored name for a known ID, otherwise the supplied name or `null`. |
| `addons` | Locally mapped optional add-ons. |
| `addons[].id` | Add-on offering ID. |
| `addons[].name` | Add-on offering name. |

An unknown base plan ID returns HTTP 200 with an empty `addons` array.

### Get the eligible subscriber's ILL catalog

```http
GET /api/v1/ill/catalog?service_id=12345678
Authorization: Bearer <token>
Accept: application/json
```

`service_id` is required, must be a string, and may contain at most 20 characters. CRM eligibility is determined by the exact base plan `ILL_Main_Offering`; the returned local mapping uses ID `109`.

Example response (add-on list shortened):

```json
{
  "success": true,
  "service_id": "12345678",
  "subscription": "Postpaid",
  "crm_base_plan": "ILL_Main_Offering",
  "base_plan": {
    "id": "109",
    "name": "ILL Main Offering"
  },
  "addons": [
    {
      "id": "1303",
      "name": "Home 5 Mbps 1500"
    }
  ]
}
```

| Field | Meaning |
| --- | --- |
| `success` | CRM eligibility and local mapping lookup completed. |
| `service_id` | The requested service number. |
| `subscription` | Subscriber connection type reported by CRM. |
| `crm_base_plan` | CRM rate plan name used for ILL eligibility. |
| `base_plan` | Local mapped base-plan ID and name. |
| `addons` | Add-ons mapped to the eligible base plan; each item contains `id` and `name`. |

An ineligible subscriber returns HTTP 422 with `success: false` and a message. Unexpected failures return HTTP 500 with a generic message.

## Common request errors

Protected endpoints require a valid Sanctum bearer token; otherwise they return HTTP 401. Validation failures use HTTP 422 and include a `message` plus an `errors` object keyed by the invalid field. Error messages for CRM-backed operations vary by endpoint as described above.
