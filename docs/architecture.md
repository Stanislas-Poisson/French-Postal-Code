# Architecture of the rework (ticket #55)

This document describes the decisions made for the rework of French-postal-code. It was written **before the code** and then validated. The figures quoted were measured on the real sources on 2026-10-01. §8 describes what is implemented and the differences with the plan, §9 the results of the first real import.

## 1. Goals

- Produce a clean dataset of the regions, departments, communes and postal codes of France (metropolitan France, DROM and COM), with GPS coordinates.
- Fetch and update the source files automatically.
- Keep the history of changes (mergers, creations, code changes, deletions) so that a user can migrate their own records: from an old code, know which code(s) to point to today, with the matching postal code(s).
- Export to CSV, JSON and SQL, history included.
- Serve as a **foreign key target**: an application must be able to store an address (`3 rue Jules Massenet`) and attach it through a foreign key to the "37200 Tours" entry (see D13). Identifiers must therefore stay stable over time.

## 2. Data sources (verified)

| Source | Content | Format | Cadence | Detecting a new version |
| :--- | :--- | :--- | :--- | :--- |
| **INSEE – COG** (data.gouv.fr, dataset `58c984b088ee386cdb1261f3`) | Regions, departments, communes, COM, **commune events since 1943** | UTF-8 CSV | Yearly (2026 vintage published on 2026-02-24) | No ETag or Last-Modified. Read the data.gouv.fr API of the dataset: a new vintage adds "Millésime YYYY" resources |
| **La Poste – Base officielle des codes postaux** (`laposte-hexasmal`) | Link commune ↔ postal code (39,192 lines, 35,007 communes) | `;` CSV in **cp1252**, Licence Ouverte | Twice a year | `Last-Modified` header and `dataUpdatedAt` of the data-fair API |
| **geo.api.gouv.fr** | Current communes with GPS centre and postal codes | JSON | Continuous | `ETag` header |
| **BAN – Base Adresse Nationale** (`adresse.data.gouv.fr`, one file per department) | Addresses with postal code, INSEE code (and former commune) and coordinates: used to compute **one GPS point per commune + postal code pair** | `;` CSV gzip UTF-8, Licence Ouverte | Daily | `Last-Modified` header of each file (one per department) |

COG files kept (vintage M): `v_region_M`, `v_departement_M`, `v_commune_M`, `v_commune_comer_M`, `v_comer_M`, `v_mvt_commune_M` (events) and `v_commune_depuis_1943` (validity intervals of each code).

### Findings that shape the design

1. **geo.api.gouv.fr returns all communes in a single request** (34,969 communes, 5.6 MB, 0.3 s) with GPS centre and postal codes. It replaces the 35,000 per-commune calls of the old version, but **its point is the commune's, not the postal code's**: it is therefore not enough (see 7).
2. **Commune events already exist in an official source**: `v_mvt_commune_2026.csv` holds 13,734 events from 1943 to 2026, with the INSEE modality codes (10 name change, 20 creation, 21 reinstatement, 30 deletion, 31 simple merger, 32 creation of a new commune, 33 association merger, 34 association merger → simple merger, 35 deletion of a delegated commune, 41 code change (department), 50 code change (chief town), 70 to 72 delegated communes). For communes, **the history does not have to be rebuilt by comparison**.
3. **An INSEE code can change meaning over time.** Saint-Florent-des-Bois case: on 2016-01-01, the new commune "Rives de l'Yon" took over the code **85213**, which was Saint-Florent-des-Bois's, and Chaillé-sous-les-Ormeaux (85043) was absorbed. The code 85213 still exists but designates another entity. An `old code → new code` table is therefore not enough: reasoning must be done on **(code, date)**.
4. **INSEE publishes no event for departments, regions or postal codes.** They are tracked by comparing two snapshots (see §5.3). This is less reliable and only starts at our first import.
5. **Postal codes are an N-N relation.** 387 communes have several postal codes (up to 21), and 4,205 postal codes out of 6,328 cover several communes. The old model duplicated the commune per postal code.
6. **geo.api.gouv.fr and La Poste diverge** on 9 communes, all overseas (98xxx). 5 communes in geo have no postal code. 46 La Poste codes are municipal arrondissements (Marseille, Lyon, Paris) absent from geo's communes, which exposes them separately (`type=arrondissement-municipal`, 45 entries).
7. **One GPS point per postal code is needed, not only per commune.** Tours (37261) example: 37000, 37100 and 37200 must each have their own point. Two sources tested on 2026-10-01:
   - La Poste (`_geopoint`) gives the **same point for the three postal codes** (47.3943, 0.6949): unusable.
   - The **BAN** (Base Adresse Nationale), per department, gives distinct and consistent points by taking the median of the addresses of each postal code: 37000 → 47.3858, 0.6886 (18,746 addresses), 37100 → 47.4164, 0.6930 (10,489), 37200 → 47.3661, 0.7044 (1,007). The file of department 37 weighs 9.9 MB (275,168 addresses), the whole of France 940 MB compressed.
   - The BAN covers Corsica, the DROM and the COM 975, 977, 978, 987 and 988. The files 984, 986 and 989 are empty: a fallback is needed.
   - BAN addresses also carry `code_insee_ancienne_commune`: a postal code can be attached to a former merged commune, which directly serves the historical resolution.
8. **Delegated and associated communes** (COMD, COMA: 2,576 lines) are not returned in bulk by geo.api. They serve the history and the resolution, not the main dataset.

## 3. Decisions

### D1 – Sources kept

- Structure and codes: **INSEE COG** (authority on codes, names, attachments and events).
- Postal codes: **La Poste** (authority on the commune ↔ postal code link), with geo.api as a check.
- **Per postal code** coordinates: **BAN** (median of the addresses of each commune + postal code pair).
- **Commune** coordinates: **geo.api.gouv.fr** (centre), for the communes and as a fallback when the BAN has no address for a postal code.
- **Removed**: the manual INSEE files from 2018 (`storage/builder`), regexes, HTML scraping of the COM, Google Maps, and the 35,000 per-commune calls.

### D2 – Scope of the main dataset

Regions, departments (COM included, as collectivities), communes (type `COM`) and municipal arrondissements (type `ARM`). Delegated and associated communes are not part of it, but are kept in the history.

### D3 – GPS: one point per commune + postal code pair

Decision chain, from the most reliable to the least precise:

1. **BAN** (median of the addresses of the commune + postal code pair): main source.
2. **Nominatim** (`postalcode` + `city` search) for pairs with no BAN address.
3. **Centre of the commune** (geo.api.gouv.fr) as the last fallback.

The `coordinate_source` column (`ban`, `nominatim` or `commune_centre`) always says where the point comes from, and `address_count` gives the number of BAN addresses used.

**Why the BAN first, and not the APIs.** The Tours test (2026-10-01) gives:

| Postal code | BAN (median) | Nominatim (`postalcode`) | Géoplateforme (`municipality` + `postcode`) |
| :--- | :--- | :--- | :--- |
| 37000 | 47.3858, 0.6886 | 47.3847, 0.6905 | 47.3955, 0.6958 |
| 37100 | 47.4164, 0.6930 | 47.4189, 0.7024 | 47.3955, 0.6958 |
| 37200 | 47.3661, 0.7044 | 47.3659, 0.6889 | 47.3955, 0.6958 |

- The **Géoplateforme ignores the postal code** and returns the centre of the commune for all three: unusable here.
- **Nominatim** gives distinct points close to the BAN (200 m to 1.5 km apart), but it is limited to **1 request per second**. For the 39,192 pairs that means about **11 hours**, against one pass over 101 files for the BAN. It also depends on the OpenStreetMap coverage, and its policy discourages bulk usage.
- The **BAN** is the official address base: the point is derived from real addresses of that postal code in that commune, deterministically and reproducibly, with a quality indicator. These are not assumed points: they are addresses geocoded by the State.
- Nominatim remains useful as a **fallback** for the few pairs with no BAN address. It then only handles a small number of cases, one job per pair, rate limited to 1 request per second.

**Hierarchical attachment**: each `City` is linked to its `Commune`, then to the `Department`, then to the `Region`. The tree is walked up and down with Eloquent relations.

### D4 – Data model

**Principle: an identifier is never deleted or recycled.** An entity that disappears or changes meaning has its validity period closed (`valid_to`) and, if it has a successor, a link to it. This is what lets an application keep its foreign keys and re-attach them afterwards.

- `regions`: id, code, name, slug, valid_from, valid_to.
- `departments`: id, region_id, code, name, slug, valid_from, valid_to.
- `communes`: id, department_id, insee_code, type (`COM` or `ARM`), name, slug, centre_latitude, centre_longitude, valid_from, valid_to.
- `cities`: **one row per commune + postal code pair**, that is "37200 Tours". id (stable), commune_id, postal_code, label (delivery label), latitude, longitude, address_count, coordinate_source, valid_from, valid_to, replaced_by_city_id (nullable).
- `snapshots`: id, source, version, checksum, fetched_at, imported_at, complete (boolean, see D8).
- `commune_events`: raw INSEE events (modality, effective date, type and code before, type and code after, labels), fed by `v_mvt_commune`.
- `commune_successions`: derived table used by the resolution (origin code, origin validity, destination code, kind: renamed, replaced, absorbed, split, deleted, code reused, effective date).
- `reference_changes`: changes detected by comparison for departments, regions and postal codes.

A simple name change updates the row in place (the old name stays in `commune_events`). A merger, a split, a code reuse or a postal code change closes the row and opens a new one.

Foreign keys as `*_id`, plural tables, singular models (`Region`, `Department`, `Commune`, `City`), following the handbook. `Commune` is the official INSEE entity; `City` is the postal entry (commune + postal code) that addresses reference.

#### Relations diagram

```
                 ┌───────────┐ 1     n ┌──────────────┐ 1     n ┌───────────┐ 1     n ┌──────────┐
  Dataset        │  regions  │────────<│ departments  │────────<│ communes  │────────<│  cities  │
  (this repo)    └───────────┘         └──────────────┘         └───────────┘         └────┬─────┘
                  code, name            code, name               insee_code, type          │ id (stable)
                  valid_from/to         region_id                department_id             │ postal_code
                                                                 valid_from/to             │ lat / lon
                                                                                           │ replaced_by_city_id
                                                                                           │
  Third-party    ┌───────────────┐ n     1 ┌────────────────────────────────────────────────┘
  application    │   addresses   │>────────┘
                 └───────────────┘
                  line "3 rue Jules Massenet"
                  city_id  (foreign key to cities.id)

  History        commune_events ──► commune_successions       (by INSEE code and date, no foreign key)
  (this repo)    reference_changes   (departments, regions, postal codes, compared from one import to the next)
                 snapshots           (imported source file: version, checksum, complete or not)
```

- The four tables at the top are **the dataset**: they read from top to bottom (a region has several departments, etc.) and from bottom to top (`city->commune->department->region`).
- `addresses` is not in this repository: it is the table of the application that uses the dataset.
- The history tables are not linked to the entities by a foreign key: INSEE codes can be reused or deleted, so they are found by (code, date).

### D5 – Algorithm to resolve an old code

Input: a code (INSEE or postal), an optional date (default: the oldest known). Output: a list of results.

1. Find the entity that held the code at the given date.
2. Apply the successions after that date chronologically, following the A → B → C chains.
3. Classify each branch: unchanged, renamed, replaced (1 → 1), absorbed (N → 1), split (1 → N), **code reused by another entity** (case 85213), **deleted without a successor**.
4. For each destination commune, join its current postal codes.

**Going back in time.** The validity periods make it possible to find the state at any date (`as_of`). For communes, departments and regions, the COG provides the whole period since 1943 (`v_commune_depuis_1943`, `v_mvt_commune`) and the older COG vintages (1999 to 2024) can be downloaded: everything can be rebuilt. For postal codes, the history starts at the first snapshot kept; the import will look, at implementation time, for possible archived versions of the La Poste base to go back further, without promising it.

A disappearance without a successor is always reported explicitly (never returned as "unknown"). The results carry the effective date and the kind of change.

### D6 – Postal code tracking

No official events. At each La Poste import, compare with the previous snapshot and record in `reference_changes`: code appeared, code disappeared, commune that changes code, code that changes commune. **The postal code history starts at the first snapshot kept.** The README must say so, so as not to suggest exhaustive tracking.

### D7 – Update pipeline

1. **Detection**: query data.gouv.fr (COG), `Last-Modified` (La Poste) and `ETag` (geo.api). No action if the checksums are identical.
2. **Download**: files saved by version in `storage/app/sources/{source}/{version}/`, with a checksum. For the BAN, one file per department, downloaded again only if its `Last-Modified` changed.
3. **Parsing**: streaming CSV and JSON readers, one per source, behind a common interface. Handling of La Poste's cp1252.
4. **Import**: idempotent (can be replayed without duplicates). Entities, events and successions are imported in a transaction. BAN coordinates are computed **department by department** (see D8), then written to `cities`.
5. **Comparison**: computation of `reference_changes`.
6. **Export**: see D9.

All of it is started by a single command, schedulable with the Laravel scheduler (daily check, import only if a source changed).

### D8 – Jobs, Redis and Horizon

**A job handles one unit of work**, with two levels depending on the source:

- **BAN: one job per department** (110 jobs at the first real import: the departments and the overseas collectivities). The natural unit is the department file: download, streaming read, computation of the medians for all the commune + postal code pairs of that department.
- **Nominatim fallback: one job per pair** commune + postal code with no BAN address. Rate limited to 1 request per second (`Redis::throttle`).
- The steps are chained: official sources → entity import → BAN batch → fallback batch → changes computation → exports.

**Guaranteed completeness**: with concurrency, nothing must be lost silently.

1. Before launching the jobs, the **expected list** is built: all the (INSEE code, postal code) pairs from La Poste crossed with the COG, and all the departments and regions of the COG.
2. Each job records its result (point, source or explicit failure); no pair is "forgotten".
3. At the end of a batch (`then` / `finally` of `Bus::batch`), a **reconciliation** check compares the expected list with the result: each pair must have a point (BAN, Nominatim or commune centre), and each expected department and region must be present. The gaps are listed.
4. `snapshots.complete` only becomes true if the reconciliation is total. As long as it is not, the import is not published and the exports are not regenerated.

**Horizon**: kept to follow a batch of more than 100 jobs (progress, failures, retry), with a **Redis** service. The jobs are idempotent and resumable.

### D9 – Exports

In `Exports/`:

- Current dataset: `regions`, `departments`, `communes`, `cities` (CSV, JSON, SQL). `cities` holds one row per commune + postal code pair with latitude, longitude, number of addresses and source of the point, attached to the commune, the department and the region.
- History: `commune_successions` (CSV, JSON): *old code, validity period, new code, kind, effective date*.
- Changes per vintage: `changes/{YYYY}` (created, deleted, renamed, merged, replaced).
- Postal code changes: `city_changes` (CSV, JSON), with the old and the new `City` identifier.

A `dataset:resolve {code} {--date=}` command lets you test the resolution. The README describes how a user migrates their data with these files.

### D10 – Code layout

```
app/
  Console/Commands/       # orchestration only (update, export, resolve)
  Contracts/              # SourceClient, SourceParser, Exporter
  Data/                   # readonly DTOs (CommuneRecord, EventRecord, ...)
  Enums/                  # EventModality, SuccessionKind, EntityType
  Jobs/                   # one job per pipeline step
  Models/                 # Region, Department, Commune, City, ...
  Services/Sources/       # InseeCogClient, LaPosteClient, GeoApiClient
  Services/Parsers/       # streaming CSV and JSON parsers
  Actions/                # ImportCog, ImportPostalCodes, ComputeChanges, ResolveCode, ExportDataset
```

Rules: `declare(strict_types=1)`, `final` classes, explicit types, thin commands, logic in Actions (`execute()`) and injectable Services.

### D11 – Tooling

- **PHP 8.4**, `zairakai/php` Docker image (`latest-dev` locally, `latest-test` in CI with PCOV coverage), no web server.
- **zairakai/laravel-dev-tools**: generated Makefile, Pint, PHPStan max level, Rector, PHPInsights, git hooks. A project Makefile reduced to the strict minimum under the include.
- **GitHub Actions CI** calling the make targets of the package, job named `ci`.
- **Redis** service in `docker-compose.yml` (unless the `database` queue is kept).

### D12 – Cleanup

Removal of `config/auth.php`, `mail.php`, `session.php`, of the `database/export/*.sql` duplicates, of `storage/builder`, of `CHANGELOG.md` (replaced by the GitHub releases generated from the Conventional Commits), of the unused `storage/framework` folders, and of any `dd()` or `DB::statement` changing the schema on the fly.

### D13 – Usage target: attaching addresses

The end goal is for an application to store addresses and link them to the matching postal entry:

```
addresses                              cities
  id                                     id            (stable, never recycled)
  line  "3 rue Jules Massenet"           commune_id
  city_id  ───── foreign key ───────►    postal_code   "37200"
                                         label         "TOURS"
                                         latitude / longitude
```

- `cities.id` is **stable**: a row is never erased, its validity is closed (D4). An address stored today therefore keeps a valid key tomorrow.
- When an entry evolves (merged commune, modified postal code), `replaced_by_city_id` and `commune_successions` give the new `id`. The resolution command produces the "old `city_id` → new `city_id`" list to update the addresses (a simple `UPDATE ... JOIN`).
- From an address, you walk up `city` → `commune` → `department` → `region`, and back down, with Eloquent relations.

**Example setup** (third-party Laravel application):

```php
// 1. Store an address: find the "37200 Tours" entry, then create the address.
$city = City::current()
    ->where('postal_code', '37200')
    ->whereRelation('commune', 'insee_code', '37261')
    ->firstOrFail();                                   // id 4821, for example

$address = Address::create([
    'line'    => '3 rue Jules Massenet',
    'city_id' => $city->id,
]);

// 2. Walk up the tree.
$address->city->postal_code;                           // 37200
$address->city->commune->name;                         // Tours
$address->city->commune->department->name;             // Indre-et-Loire
$address->city
    ->commune
    ->department
    ->region
    ->name;                                            // Centre-Val de Loire
$address->city->latitude;                              // 47.3661 (point of 37200)

// 3. Later, after a dataset update: re-point the addresses.
//    Row 4821 was closed (valid_to set) and replaced by row 9100.
$remap = CityResolver::remap();                        // [4821 => 9100, ...]; a disappearance without a successor is listed apart
Address::whereIn('city_id', array_keys($remap))->each(
    fn (Address $a) => $a->update(['city_id' => $remap[$a->city_id]]),
);
```

For the `addresses.city_id → cities.id` foreign key to work, **`cities` must live in the same database as `addresses`**. Hence the distribution question (D14).

- The `addresses` table belongs to the consuming application, not to this dataset. This repository provides the `cities` and the migration tools. See question 7 of §7.

### D14 – Dataset distribution

Three different usages, hence three possible channels:

| Channel | For whom | Content | Foreign key possible? |
| :--- | :--- | :--- | :---: |
| **CSV / JSON / SQL files** (GitHub releases, data.gouv.fr) | Everyone, any language | The tables of the dataset | No (outside the database) |
| **Composer package** (Packagist, Zairakai namespace) | Laravel applications like the addresses one | Models, migrations, import, resolution; the data is loaded into the application's database | **Yes** |
| **npm package** | JS interfaces (postal code autocompletion) | JSON only | No |

Recommendation:

1. **This repository** remains the *builder*: it imports, computes, versions and publishes the files. The exports are **attached to the GitHub releases** (generated by the CI at the tag) and published on data.gouv.fr, rather than committed in `Exports/`: a CSV of several MB re-committed at each update weighs down the git history.
2. **Later**, a **Composer package** extracts the models, migrations and import for Laravel applications. It is the one that answers the need for addresses with a foreign key. It will be decided after the first real import, once the model is stabilised.
3. **npm: not for now.** To be considered only if a need for browser-side autocompletion appears.

### D15 – Distribution as a Composer package

D14 planned a Composer package for the applications that want the data in their own database. It is a separate project, `stanislas-poisson/french-postal-code` (repository `French-Postal-Code-Package`): the data, the models and relations for Laravel (Eloquent) and Symfony (Doctrine), and a command that loads the data. It does not resolve old codes, which stays here.

This repository only produces its data: `dataset:export --package` writes, to `<path>/package`, the columns of the tables with the **identifiers of the relations** (`region_id`, `department_id`, `commune_id`, `replaced_by_city_id`) and a `manifest.json` (versions of the sources, rows and columns of each file). The package inserts the rows with these identifiers, so that the foreign keys of an application stay valid from one version to the next. `make release-files` adds them to the release as `french-postal-code-x.y.z-package.zip`.

The composer name of this project is `stanislas-poisson/french-postal-code-builder`: it is never published, and the short name belongs to the package.

## 4. Risks and limits

- **Partial postal code history**: it starts at the first snapshot (D6).
- **cp1252 and `;` separator** for La Poste: plan a dedicated encoding test.
- **geo.api and La Poste diverge** on a few overseas communes: the D1 rule (La Poste is authoritative for postal codes) must be documented, with a report of the gaps at each import.
- **5 communes without a postal code** in geo.api: to be reported at import rather than hidden.
- **Dependence on the structure of the INSEE files**: a change of columns from one vintage to the next must make the import fail with a clear message (header validation).
- **BAN volume**: 940 MB compressed in total. Downloads are cached per department and only redone if the file changed; the coordinates update can be monthly rather than daily.
- **Quality of BAN points**: a postal code with very few addresses gives an unreliable point. `address_count` makes it visible and a fallback threshold can be defined (to be set at implementation).
- **Areas without a BAN file** (984, 986, 989) and postal codes without addresses: Nominatim fallback then commune centre, flagged by `coordinate_source`.
- **Nominatim**: 1 request per second and bulk usage discouraged; it only serves as a targeted fallback.
- **Licences**: COG, La Poste and the BAN are under Licence Ouverte; mention them in the README and the exports.

## 5. Implementation plan

1. PHP 8.4, `zairakai/php` image, Redis (or not), basic cleanup.
2. Installation of `laravel-dev-tools`, Makefile, GitHub Actions CI.
3. Schema, models and migrations.
4. Clients and parsers of the three sources, with tests on small test datasets.
5. COG import and construction of the events and successions.
6. La Poste and geo.api import, computation of the per postal code coordinates (BAN per department, Nominatim fallback, commune centre fallback), completeness reconciliation, computation of `reference_changes`.
7. Resolution and `dataset:resolve` command.
8. Exports, README and migration documentation for users.
9. First full real import, comparison with the published dataset, then update of `Exports/`.

## 6. Out of scope

- Postal code history before the first snapshot.
- Coordinates of departments and regions (hierarchical attachment only), unless requested otherwise.
- Web interface or HTTP API.

## 7. Decisions to validate

1. **GPS**: BAN first, Nominatim as a fallback, commune centre as the last fallback: agreed?
2. **Model**: `Commune` (INSEE entity) and `City` (commune + postal code, target of the addresses' foreign keys), with stable identifiers and validity closing rather than deletion: agreed?
3. **Queue**: Redis with Horizon, one job per department for the BAN and one job per pair for the fallback: agreed?
4. **Scope**: communes and municipal arrondissements in the main dataset, delegated and associated communes in the history only: agreed?
5. **Postal code history** partial (it starts at the first snapshot, unless archives are found): agreed?
6. **Hierarchy**: `City` → `Commune` → `Department` → `Region` attachment without coordinates for departments and regions: agreed?
7. **Addresses**: does the `addresses` table stay in the consuming applications (recommended), or is an example `Address` model also needed in this repository?
8. **Distribution**: files in GitHub releases and data.gouv.fr now, Composer package later, npm only on demand (D14): agreed?

## 8. Implementation status

Everything described above is implemented, with the following clarifications and differences.

- **Class names**: the models are `Region`, `Department`, `Commune`, `City`, `Snapshot`, `CommuneEvent`, `CommuneSuccession` and `ReferenceChange`.
- **Commands**: `dataset:update` (`--sync`, `--force`, `--skip-coordinates`), `dataset:status`, `dataset:resolve` and `dataset:export`.
- **Pipeline**: `FetchSources` → `ImportOfficialSources` (COG, La Poste, geo.api centres, linking of replaced cities) → BAN batch (one job per department) → Nominatim batch (one job per city without a point) → `ReconcileDataset`. The long steps go through the Redis queue, supervised by Horizon (a `horizon` service in `docker-compose.yml`).
- **Nominatim rate limiting**: a job waits for its turn (`ThrottleNominatim` middleware) instead of being released. The `nominatim` queue has a single process, so waiting bothers nobody, and the same code works with the `sync` queue.
- **Horizon dashboard closed**: the project has no web interface. Horizon is used to supervise the workers (`make horizon-status`, `make horizon-logs`).
- **Temporary download errors**: a connection failure, a 429 or a 5xx is retried with a delay that doubles from 2 s up to 60 s, and no retry starts after a 5-minute budget, so an outage of a source never holds an update or a CI job for long. A missing file (4xx) fails at once. The values are in `config/sources.php` (`SOURCES_DOWNLOAD_*` variables).
- **Exports**: CSV and JSON streamed by `dataset:export`, SQL dump by `mysqldump` (`make export`). The files are written to `storage/app/exports` and are no longer committed.
- **Communes without a postal entry**: Paris, Lyon and Marseille (their arrondissements carry the postal codes) and six territories without a postal code. This is flagged by the reconciliation report without blocking the publication.
- **Overseas communes (COM)**: they have no history before the first import, because the INSEE history file only covers metropolitan France and the DROM. They are imported with a validity starting in 1943.
- **Former departments**: a closed commune whose department no longer exists in the current COG is imported without a department (empty `department_id`).
- **Remaining work**: the Composer package for Laravel applications (D14), the search for archives of the La Poste base to go back in the postal code history, and the computation of changes between two COG vintages for older vintages.

## 9. First real import (2026-10-01)

| Step | Result |
| :--- | :--- |
| COG 2026 import (regions, departments, communes, 13,734 events, 8,480 successions) | 3.2 seconds |
| Open communes | 35,015 (34,875 communes + 45 arrondissements + 95 overseas communes) |
| Cities (commune + postal code) | 35,510 for 35,511 distinct La Poste pairs |
| BAN batch | 110 jobs, 3 workers, 0 failures |
| Points from the BAN | 35,288 (99.4%) |
| Points from Nominatim | 186 |
| Points at the commune centre | 36 |
| Cities without a point | 0 |
| Total update duration | about 15 minutes |

The points obtained for Tours (37000: 47.3858, 0.6886 — 37100: 47.4164, 0.6930 — 37200: 47.3661, 0.7044) are identical to the medians computed during the study and consistent with those of the old version (a few hundred metres apart).
