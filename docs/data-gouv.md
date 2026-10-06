# The dataset on data.gouv.fr

The dataset is published on [data.gouv.fr](https://www.data.gouv.fr/datasets/regions-departements-villes-et-villages-de-france-et-doutre-mer) (identifier `5a2d54f288ee382771f2cf4f`, the slug changes with the title, the identifier never does). Its resources come from the files of a GitHub release, and the script `.github/scripts/publish_data_gouv.py` keeps them in step with it.

## From the files to the resources

| File | Where it is made | In the release | On data.gouv.fr | How |
| :--- | :--- | :--- | :--- | :--- |
| `csv/cities.csv` | `make export` | inside `french-postal-code-X-csv.zip` | resource "Villes : une ligne par commune et code postal (CSV)" | file hosted by data.gouv.fr, sent again with the schema `cities.schema.json` |
| `csv/communes.csv` | `make export` | inside the CSV archive | resource "Communes et arrondissements (CSV)" | the same, schema `communes.schema.json` |
| `csv/commune_successions.csv` | `make export` | inside the CSV archive | resource "Successions de codes de commune (CSV)" | the same, schema `commune_successions.schema.json` |
| `csv/regions.csv`, `departments.csv`, `reference_changes.csv` | `make export` | inside the CSV archive | none (too small to be worth a resource) | |
| the six CSV files | `make release-files` | `french-postal-code-X-csv.zip` | resource "CSV (zip) - French-postal-code X" | link to the asset of the release, SHA-256 |
| the six JSON files | `make release-files` | `french-postal-code-X-json.zip` | resource "JSON (zip) - French-postal-code X" | link, SHA-256 |
| `sql/dataset.sql` | `make export` | `french-postal-code-X-sql.zip` | resource "SQL (zip) - French-postal-code X" | link, SHA-256 |
| `statistics.json` | `make export` | `statistics.json` | resource "Statistiques du jeu de données (statistics.json)" | link, SHA-256 |
| checksums | `make release-files` | `SHA256SUMS` | resource "Sommes de contrôle (SHA256SUMS)" (type documentation) | link |
| `package/*` | `make export --package` | `french-postal-code-X-package.zip` | none: it is the data of the Composer package, not a file to open | |

The three CSV files hosted by data.gouv.fr are identical, byte for byte, to the ones of the CSV archive (same SHA-1 on both sides, checked for 4.0.0). The schemas are the files of the branch `schemas` of this repository.

The titles, the descriptions and the numbers they hold (rows of the three tables, counts of `statistics.json`, the version) are written in `.github/data-gouv/resources.json`. For 4.0.0 the script gives back exactly the text that is published today.

## Publishing a release

The workflow `Publish on data.gouv.fr` starts when a release is published. It only prints what it would change until the repository variable `DATAGOUV_AUTO_PUBLISH` is `true`. From the Actions tab, it can be started for any tag, with `apply` to really change the dataset. The secret `DATAGOUV_API_KEY` is the API key of the account that owns the dataset.

By hand:

```bash
GH_TOKEN=$(gh auth token) python3 .github/scripts/publish_data_gouv.py 4.1.0          # prints the changes
DATAGOUV_API_KEY=... GH_TOKEN=$(gh auth token) python3 .github/scripts/publish_data_gouv.py 4.1.0 --apply
```

The script checks the archives against `SHA256SUMS` before anything is changed, then, for each resource: sends the CSV files again, sets the link, the title, the checksum and the description, and ends the temporal coverage of the dataset on the date of the generation.

## What stays manual

The description of the dataset itself holds a few figures and years that are not generated: the share of the points that come from the BAN, the year of the COG and the start of the tracking of the postal codes. Read it after each new vintage of the sources. The discussions of the dataset are answered on the site.
