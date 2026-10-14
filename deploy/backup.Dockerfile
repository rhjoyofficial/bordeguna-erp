# Backup image for the Bor de Guna ERP: mysqldump (from the SAME mysql:8.0 the
# `db` service runs, so dump and server never disagree on format) plus a pinned
# restic binary for the off-server copy, plus tar and curl.
#
# Why one image and not two containers: a backup is only "successful" when the
# dump, the uploaded files, the checksum manifest AND the off-server snapshot
# have all been verified. Splitting that across two containers on two clocks is
# what let the old design call a night good when half of it never happened.
#
# Built on the VPS with the rest of the stack: `docker compose build backup`.
# The build context is deploy/ and backup.Dockerfile.dockerignore sends none of
# it, so deploy/.env can never end up in this image.

FROM restic/restic:0.17.3 AS restic

FROM mysql:8.0

COPY --from=restic /usr/bin/restic /usr/local/bin/restic

# Oracle Linux slim: install only what is missing (command:package). The
# version lines at the end fail the build if any tool the scripts use is absent.
RUN set -eux; \
    pkgs=""; \
    for pair in tar:tar curl:curl-minimal grep:grep find:findutils sed:sed gzip:gzip; do \
        command -v "${pair%%:*}" >/dev/null 2>&1 || pkgs="${pkgs} ${pair#*:}"; \
    done; \
    if [ -n "${pkgs}" ]; then microdnf install -y ${pkgs}; microdnf clean all; fi; \
    restic version; \
    tar --version | head -n 1; \
    curl --version | head -n 1; \
    grep --version | head -n 1; \
    find --version | head -n 1; \
    sha256sum --version | head -n 1; \
    gzip --version | head -n 1; \
    mysqldump --version; \
    date -u -d 'tomorrow 01:00' +%s

# The scripts are bind-mounted read-only by docker-compose.yml (as before), so
# a script fix needs `up -d`, not a rebuild. This image carries tools only.
