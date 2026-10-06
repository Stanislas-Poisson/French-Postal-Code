# Publishing a release

The dataset files are produced by `make export` and attached to the GitHub release. They are not committed to the repository.

## Steps

1. Update the dataset and export the files in one go.

   ```bash
   make build-dataset
   ```

   The update takes about 15 minutes. If it is incomplete (a city without a point, a department that failed, a failed job), the command stops before the export. `make build-dataset-force` imports every file again even if it did not change.

2. Check the report that the command prints.

   ```bash
   make status
   ```

   It must say `complete`: every city has a point, every department was processed and no job failed.

3. Create the version tag, reserved to maintainers and written `MAJOR.MINOR.PATCH` without a prefix (`4.0.0`), on `main`, once the CI is green.

4. Zip the exports and write the checksums.

   ```bash
   make release-files VERSION=4.0.0
   ```

   The files to attach are written to `storage/app/exports/release`: one zip archive per format (`french-postal-code-4.0.0-csv.zip`, `-json.zip` and `-sql.zip`), the files of the Composer package (`french-postal-code-4.0.0-package.zip`), `statistics.json` and `SHA256SUMS`. The package archive holds the tables with the identifiers of the relations and a manifest; the Composer package `stanislas-poisson/french-postal-code` loads it, it is not a file to open. `statistics.json` must keep that name: the dataset card of the README reads it from the latest release.

5. Create the GitHub release with these files as attachments. Write the release notes beforehand in a file, and keep `--generate-notes` to add the list of the merged pull requests.

   ```bash
   gh release create 4.0.0 --verify-tag --title "French-postal-code-4.0.0" \
     --notes-file RELEASE-NOTES.md --generate-notes --latest \
     storage/app/exports/release/*
   ```

   Add `--draft` to check the page before publishing it. The generated notes come from the titles of the merged pull requests, which is why their format matters.

6. Publish the same files on data.gouv.fr.

## Knowing when to build

The workflow `Watch sources` runs every day (and from the Actions tab). It compares the sources with the date of the last generation, read in `statistics.json` of the latest release, without downloading them:

- INSEE COG: a newer vintage, or a file of the latest vintage that changed after the generation (read on the dataset of data.gouv.fr, because INSEE sends no `Last-Modified`);
- La Poste: the `Last-Modified` header of the file of postal codes.

When a source is newer it opens the issue "A source of the dataset changed: build a new version" (label `data-update`) and updates it every day until a release is made. When nothing changed it does nothing. The build itself is still started by a maintainer, because the update works on the database that holds the history and the identifiers of the dataset: an empty database would give new identifiers.

## Versioning

The version number follows SemVer. A change to the schema of the files, for example removing a column, is a major change.

## Statistics cards

The cards of the README (`stats.svg` and `dataset.svg`) are built every Monday by the `Update stats` workflow and pushed to the `stats` branch. It can also be started by hand from the Actions tab.

GitHub keeps the traffic (views and clones) for 14 days only, so the daily values are accumulated in `stats.json` on that branch. Reading the traffic needs push access: add a repository secret named `STATS_TOKEN` (a token of a maintainer). Without it, the workflow still runs but leaves the views and clones out.

The dataset card reads `statistics.json` (volumes, source of the GPS points, versions of the sources), which `make export` writes and which must be attached to each release. Until a release carries it, the card shows dashes. The downloads by format come from data.gouv.fr.
