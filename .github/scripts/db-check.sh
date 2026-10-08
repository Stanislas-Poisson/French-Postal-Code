#!/usr/bin/env bash
# Reads the database of the compose stack to check a build in the CI.
#
#   db-check.sh schema        prints a hash of the columns of the dataset tables
#   db-check.sh identifiers   prints one line per row (table, id, natural key, date), sorted, for every table
#                             that has identifiers the package loads by
#
# The build restores the SQL of the latest release, then updates it. The schema of the restored
# tables has to be the one of the migrations, and no identifier may change or disappear.
set -euo pipefail

COMPOSE="docker compose -p frenchpostalcode"

sql() {
  $COMPOSE exec -T -e MYSQL_PWD=root mysql mysql -uroot -N -B frenchpostalcode -e "$1"
}

case "${1:-}" in
  schema)
    sql "SELECT table_name, column_name, column_type, is_nullable
         FROM information_schema.columns
         WHERE table_schema = 'frenchpostalcode'
           AND table_name IN ('regions', 'departments', 'communes', 'cities', 'commune_successions', 'reference_changes')
         ORDER BY table_name, ordinal_position" | md5sum | cut -d' ' -f1
    ;;
  identifiers)
    sql "SELECT 'regions', id, code, valid_from FROM regions
         UNION ALL SELECT 'departments', id, code, valid_from FROM departments
         UNION ALL SELECT 'communes', id, insee_code, valid_from FROM communes
         UNION ALL SELECT 'cities', id, CONCAT(commune_id, '/', postal_code), valid_from FROM cities
         UNION ALL SELECT 'commune_successions', id, CONCAT(from_code, '/', COALESCE(to_code, ''), '/', kind), effective_date FROM commune_successions
         UNION ALL SELECT 'reference_changes', id, CONCAT(entity_type, '/', entity_code, '/', change_type), detected_at FROM reference_changes" | LC_ALL=C sort
    ;;
  *)
    echo "usage: db-check.sh schema|identifiers" >&2
    exit 1
    ;;
esac
