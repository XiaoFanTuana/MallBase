#!/bin/bash
set -e

echo ">>> Initializing MallBase database..."

for sql_file in /docker-entrypoint-initdb.d/sql/mb_*.sql; do
    if [ -f "$sql_file" ]; then
        echo "  Importing: $(basename "$sql_file")"
        mysql -u root -p"${MYSQL_ROOT_PASSWORD}" "${MYSQL_DATABASE}" < "$sql_file"
    fi
done

echo ">>> MallBase database initialized successfully."
