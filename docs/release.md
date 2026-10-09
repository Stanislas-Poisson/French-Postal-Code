# Publishing a release

The dataset files are produced by `make export` and attached to the GitHub release. They are not committed to the repository.

A release is built by the workflow `Build dataset`, or by hand with the steps below. The workflow proposes a draft release: the maintainer reviews it and publishes it.

## Automatic build

The workflow `Build dataset` (Actions tab, or started by `Watch sources` when a source is newer) does the steps 1, 2, 4 and 5 below in the CI (the tag of the step 3 is created when the draft is published):

1. It restores the SQL of the baseline release (the latest one, or the `baseline` input) in a fresh database, after checking the `SHA256SUMS`. The identifiers of the dataset come from that SQL.
2. It stops if the schema of the restored tables is not the one of the migrations. After a migration that changes these tables, build the baseline by hand once.
3. It runs the update, then stops if an identifier of the restored data changed or disappeared (regions, departments, communes, cities, commune successions and reference changes: the package loads the files by identifier), or if the update is incomplete.
4. It exports and checks the CSV files against the Table Schemas of the branch `schemas` (it stops if one does not conform), then writes the release files (the version is the next minor one when a source of INSEE or La Poste is newer, the next patch one otherwise, see [Versioning](#versioning); `version` overrides it) and keeps them as an artifact for 14 days.
5. It compares the export with the CSV files of the baseline release, in every column (the points of the BAN are part of the dataset):
   - if anything differs, it creates a **draft** release with the files, a summary of the changes (rows added, removed, modified and the columns concerned), the notes and the status of the dataset, and comments the issue "A source of the dataset changed";
   - if nothing differs, it creates no draft: a newer source file does not mean that the data changed. It comments the issue and leaves a marker, so that `Watch sources` does not build the same sources again.

Started by hand, `dry_run` is on by default: the build and the checks run, but no draft is created. Check the draft, then publish it: that is the only manual step. The publication creates the tag on `main` and starts `Publish on data.gouv.fr`, which updates the resources, closes the issue of the sources and starts the update of French-Postal-Code-Package. The package then follows by itself: the pull request of the new data is merged when its CI passes, `develop` is brought to `main`, and the next minor version is tagged (signed) and published, see the README of the package. It stops, and says why, if the layout of the files changed: that is a major release, tagged by hand.

The secret `PACKAGE_DISPATCH_TOKEN` (a token with the right Actions: write on French-Postal-Code-Package) lets the publication start that update at once. Without it, the package finds the release on its weekly run.

## Steps by hand

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

6. Publish the same files on data.gouv.fr. The workflow `Publish on data.gouv.fr` does it when the release is published, see [docs/data-gouv.md](data-gouv.md) for what is sent where.

## Knowing when to build

The workflow `Watch sources` runs every day (and from the Actions tab). It compares the sources with the exact time of the last generation (`built_at` in `statistics.json` of the latest release, or the publication of the release for the older ones), without downloading them:

- INSEE COG: a newer vintage, or a file of the latest vintage that changed after the generation (read on the dataset of data.gouv.fr, because INSEE sends no `Last-Modified`);
- La Poste: the `Last-Modified` header of the file of postal codes.

When a source is newer it opens the issue "A source of the dataset changed: build a new version" (label `data-update`), updates it every day until a release is made, and starts the workflow `Build dataset` unless a draft release is waiting or a build is running. When nothing changed it does nothing, except every Monday (and with the input `force`): the points of the BAN move without any of these files changing, so the build is started anyway. It creates a draft, in patch version, only when its export differs from the latest release. The update works on the database that holds the history and the identifiers of the dataset, which is why the build restores the SQL of the latest release first: an empty database would give new identifiers.

## Versioning

The version number follows SemVer, from what changed:

- **Z** (4.0.0 to 4.0.1): only the points of the BAN changed (`latitude`, `longitude`, `address_count`), the files of INSEE and La Poste did not.
- **Y** (4.0.1 to 4.1.0): a file of INSEE (COG) or La Poste is newer. Z goes back to 0.
- **X** (4.1.0 to 5.0.0): the schema of the files changed, for example a column removed. Tagged by hand.

The workflow `Build dataset` chooses between Y and Z: it asks the check of `Watch sources` whether a source is newer than the latest release. The `version` input overrides it. French-Postal-Code-Package follows the same rule from the release of the builder its data come from.

## Statistics cards

The cards of the README are built every Monday by the workflow `Project cards` of the profile repository [Stanislas-Poisson/Stanislas-Poisson](https://github.com/Stanislas-Poisson/Stanislas-Poisson), with the cards of the other projects, and kept in its `assets/projects` directory. The views and clones of this repository are accumulated there, because GitHub keeps them for 14 days only, and reading them needs a token with push access to this repository (the secret `GH_TOKEN` of the profile repository).

The dataset card reads `statistics.json` (volumes, source of the GPS points, versions of the sources), which `make export` writes and which must be attached to each release. Until a release carries it, the card shows dashes. The downloads by format come from data.gouv.fr.
