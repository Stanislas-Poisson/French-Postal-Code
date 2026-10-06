<div align="center">

# French Postal Code

**Regions, departments, communes and postal codes of France, with one GPS point per postal code and the history of changes.**

[![CI][badge-ci]][ci]
[![Release][badge-release]][releases]
[![License: MIT][badge-license]][license]
[![PHP 8.4][badge-php]][composer]
[![Laravel 13][badge-laravel]][composer]
[![PHPStan max][badge-phpstan]][phpstan]

</div>

---

Dataset of the regions, departments, communes and postal codes of France (metropolitan France, DROM and COM), with **one GPS point per postal code** and the **history of changes** (mergers, code changes, creations, deletions).

It is built from official open sources, updated automatically, and designed so that an application can attach its addresses to it through a foreign key.

## What the dataset contains

| Table | Content |
| :--- | :--- |
| `regions` | The regions (code, name). |
| `departments` | The departments and the overseas collectivities (code, name, region). |
| `communes` | The communes and the municipal arrondissements, with their INSEE code, their department and the centre of the commune. |
| `cities` | **One row per commune and per postal code**, for example "37200 Tours", with its GPS point. |
| `commune_successions` | For an old INSEE code, the code that succeeds it, the nature of the change and its date. |
| `reference_changes` | The changes detected between two updates (regions, departments, postal codes). |

Every table carries a validity period (`valid_from`, `valid_to`): a row is never deleted, its validity is closed. Identifiers are therefore stable over time.

```
regions ──< departments ──< communes ──< cities <── addresses (table of your application)
```

### One GPS point per postal code

Tours (37261) has three postal codes, and each one has its own point:

| Postal code | Latitude | Longitude | Addresses used |
| :--- | :--- | :--- | :--- |
| 37000 | 47.3858 | 0.6886 | 18,746 |
| 37100 | 47.4164 | 0.6930 | 10,489 |
| 37200 | 47.3661 | 0.7044 | 1,007 |

The point of a postal code is the **median of the address positions** of the [Base Adresse Nationale][ban] (BAN) for that postal code in that commune. The `coordinate_source` column always tells where the point comes from:

1. `ban`: median of the BAN addresses (the vast majority of cities).
2. `nominatim`: search of the postal code and the commune on [Nominatim][nominatim], for cities with no address in the BAN.
3. `commune_centre`: centre of the commune, as the last fallback.

`address_count` gives the number of addresses used: a postal code with very few addresses is less reliable.

## Sources and licences

| Source | Usage | Licence |
| :--- | :--- | :--- |
| [INSEE, Code officiel géographique][insee-cog] | Regions, departments, communes, history since 1943 and commune events. | Licence Ouverte |
| [La Poste, base officielle des codes postaux][laposte] | Link between a commune and its postal codes. | Licence Ouverte |
| [geo.api.gouv.fr][geo-api] | Centre of each commune. | Licence Ouverte |
| [Base Adresse Nationale][ban] | GPS point of each postal code. | Licence Ouverte |
| [Nominatim][nominatim] (OpenStreetMap) | Fallback for cities with no address in the BAN. | ODbL |

## Attaching addresses

An application stores its addresses with a foreign key to `cities`:

```php
$city = City::current()
    ->where('postal_code', '37200')
    ->whereRelation('commune', 'insee_code', '37261')
    ->firstOrFail();

$address = Address::create([
    'line'    => '3 rue Jules Massenet',
    'city_id' => $city->id,
]);

$address->city
    ->commune
    ->department
    ->region
    ->name; // Centre-Val de Loire
```

For the `addresses.city_id` foreign key to work, the `cities` table must live in the same database as `addresses`.

## Migrating old codes

When a commune merges or changes its code, its old codes keep pointing to it through `commune_successions`.  
Example: Saint-Florent-des-Bois merged in 2016 into "Rives de l'Yon", which took over the code **85213**, while Chaillé-sous-les-Ormeaux (85043) was absorbed.

```bash
make resolve CODE=85043 POSTAL_CODE=85310
# 2016-01-01  absorbed: 85043 -> 85213
# 2016-01-01  code_reused: 85213 -> 85213
# Current communes: 85213
# city #33125  85310  RIVES DE L YON  (46.590263, -1.333875)
```

The result follows succession chains (A to B to C), reports a commune that **disappeared without a successor**, lists every successor of a split commune and warns when the original postal code is no longer in use. The same information is in the exported files (`commune_successions`, and `replaced_by_city_id` in `cities`) so you can migrate your data without going through this repository.

### Limits of the history

- For communes, departments and regions, the history goes back to 1943 (the overseas COM have none before the first import).
- INSEE publishes no event for **postal codes**: their tracking relies on the comparison between two updates and **starts at the first import kept**.

## Usage

Requirements: [Docker][docker] and [Make][make].

```bash
make start          # starts PHP 8.4, MySQL 8.4, Redis and Horizon, installs the dependencies, migrates the database
make update         # updates the dataset (official files, then GPS points), on the queue
make update-sync    # same, in this terminal with a progress bar
make update-force   # same, importing every file again even if it did not change
make status         # state of the dataset and of the last update
make export         # exports to CSV, JSON and SQL in storage/app/exports
make build-dataset  # update then export in one go (build-dataset-force imports every file again)
make stop           # stops the project and removes its containers and volumes
```

`make update` downloads the official files and only re-imports the ones that changed. The GPS points are computed by parallel jobs, one per department, then a check verifies that **every city has a point**, that **every department was processed** and that no job failed. A full update takes about 15 minutes.

The matching `php artisan` commands:

- `dataset:update` _(`--sync`, `--force`, `--skip-coordinates`)_
- `dataset:status`
- `dataset:resolve`
- `dataset:export`.

## Published files

The exports are attached to the [releases][releases] of the repository and published on [data.gouv.fr][data-gouv]. They contain one file per table, in CSV, JSON and SQL.

![Content of the dataset and downloads by format][img-dataset]

![Usage statistics of the dataset and the repository][img-stats]

## Development

```bash
make quality    # Pint, PHPStan (max level), Rector, PHPInsights, Markdownlint
make test       # PHPUnit
```

The repository uses [`zairakai/laravel-dev-tools`][dev-tools] (quality tools, git hooks, Makefile).  
Commits follow Conventional Commits with the ticket number (`type(scope): #123 subject`) and the repository only accepts merge commits on rebased branches.  
The architecture is described in [`docs/architecture.md`][architecture] and how to publish a version in [`docs/release.md`][release].  
See also [`CONTRIBUTING.md`][contributing], [`SECURITY.md`][security] and the [code of conduct][conduct].

## Licence

[MIT][license] for the code. The data remain subject to the licences of their sources (see above).

---

<div align="center">

Made by Stanislas Poisson _(Zairakai)_

[![GitHub][badge-github]][github]
[![GitLab][badge-gitlab]][gitlab]
[![LinkedIn][badge-linkedin]][linkedin]
[![Twitch][badge-twitch]][twitch]
[![Linktree][badge-linktree]][linktree]
[![Support the stream][badge-support]][support]

</div>

[ban]: https://adresse.data.gouv.fr/
[nominatim]: https://nominatim.org/
[insee-cog]: https://www.insee.fr/fr/information/8377162
[laposte]: https://data.laposte.fr/datasets/laposte-hexasmal
[geo-api]: https://geo.api.gouv.fr/
[docker]: https://www.docker.com/
[make]: https://www.gnu.org/software/make/
[releases]: https://github.com/Stanislas-Poisson/French-postal-code/releases
[data-gouv]: https://www.data.gouv.fr/datasets/regions-departements-communes-et-codes-postaux-de-france-avec-un-point-gps-par-code-postal-et-lhistorique-des-changements
[dev-tools]: https://packagist.org/packages/zairakai/laravel-dev-tools
[architecture]: docs/architecture.md
[release]: docs/release.md
[license]: LICENSE
[contributing]: CONTRIBUTING.md
[security]: SECURITY.md
[conduct]: CODE_OF_CONDUCT.md

[ci]: https://github.com/Stanislas-Poisson/French-postal-code/actions/workflows/ci.yml
[composer]: composer.json
[phpstan]: https://phpstan.org/user-guide/rule-levels
[github]: https://github.com/Stanislas-Poisson
[gitlab]: https://gitlab.com/Stanislas-Poisson
[linkedin]: https://www.linkedin.com/in/stanislasp/
[twitch]: https://twitch.tv/zairakai
[linktree]: https://linktr.ee/Zairakai
[support]: https://pots.lydia.me/collect/pots?id=18363-dons-stream
[badge-ci]: https://img.shields.io/github/actions/workflow/status/Stanislas-Poisson/French-postal-code/ci.yml?branch=main&label=ci&style=flat-square&logo=githubactions&logoColor=white
[badge-release]: https://img.shields.io/github/v/release/Stanislas-Poisson/French-postal-code?style=flat-square&logo=github&logoColor=white
[badge-license]: https://img.shields.io/badge/license-MIT-2ea44f?style=flat-square
[badge-php]: https://img.shields.io/badge/php-8.4-777BB4?style=flat-square&logo=php&logoColor=white
[badge-laravel]: https://img.shields.io/badge/laravel-13-FF2D20?style=flat-square&logo=laravel&logoColor=white
[badge-phpstan]: https://img.shields.io/badge/phpstan-max-4F5B93?style=flat-square
[badge-github]: https://img.shields.io/badge/GitHub-8b96a3?style=flat-square&logo=github&logoColor=white
[badge-gitlab]: https://img.shields.io/badge/GitLab-fc6d26?style=flat-square&logo=gitlab&logoColor=white
[badge-linkedin]: https://img.shields.io/badge/LinkedIn-0a66c2?style=flat-square&logo=data%3Aimage%2Fsvg%2Bxml%3Bbase64%2CPHN2ZyByb2xlPSJpbWciIHZpZXdCb3g9IjAgMCAyNCAyNCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48dGl0bGU%2BTGlua2VkSW48L3RpdGxlPjxwYXRoIGZpbGw9IiNmZmYiIGQ9Ik0yMC40NDcgMjAuNDUyaC0zLjU1NHYtNS41NjljMC0xLjMyOC0uMDI3LTMuMDM3LTEuODUyLTMuMDM3LTEuODUzIDAtMi4xMzYgMS40NDUtMi4xMzYgMi45Mzl2NS42NjdIOS4zNTFWOWgzLjQxNHYxLjU2MWguMDQ2Yy40NzctLjkgMS42MzctMS44NSAzLjM3LTEuODUgMy42MDEgMCA0LjI2NyAyLjM3IDQuMjY3IDUuNDU1djYuMjg2ek01LjMzNyA3LjQzM2MtMS4xNDQgMC0yLjA2My0uOTI2LTIuMDYzLTIuMDY1IDAtMS4xMzguOTItMi4wNjMgMi4wNjMtMi4wNjMgMS4xNCAwIDIuMDY0LjkyNSAyLjA2NCAyLjA2MyAwIDEuMTM5LS45MjUgMi4wNjUtMi4wNjQgMi4wNjV6bTEuNzgyIDEzLjAxOUgzLjU1NVY5aDMuNTY0djExLjQ1MnpNMjIuMjI1IDBIMS43NzFDLjc5MiAwIDAgLjc3NCAwIDEuNzI5djIwLjU0MkMwIDIzLjIyNy43OTIgMjQgMS43NzEgMjRoMjAuNDUxQzIzLjIgMjQgMjQgMjMuMjI3IDI0IDIyLjI3MVYxLjcyOUMyNCAuNzc0IDIzLjIgMCAyMi4yMjIgMGguMDAzeiIvPjwvc3ZnPg%3D%3D
[badge-twitch]: https://img.shields.io/badge/Twitch-9146ff?style=flat-square&logo=twitch&logoColor=white
[badge-linktree]: https://img.shields.io/badge/Linktree-43e55e?style=flat-square&logo=linktree&logoColor=white
[badge-support]: https://img.shields.io/badge/Support_the_stream-ff5a5f?style=flat-square&logo=githubsponsors&logoColor=white
[img-stats]: https://raw.githubusercontent.com/Stanislas-Poisson/Stanislas-Poisson/main/assets/projects/french-postal-code-usage.svg
[img-dataset]: https://raw.githubusercontent.com/Stanislas-Poisson/Stanislas-Poisson/main/assets/projects/french-postal-code-dataset.svg
