#!/usr/bin/env bash
set -xeo pipefail

if [[ "$(pwd)" == "$(cd "$(dirname "$0")"; pwd -P)" ]]; then
  echo "Can only be executed from project root!"
  exit 1
fi

declare -x COVERAGE
[[ -z "${COVERAGE}" ]] && COVERAGE="false"

readonly BASE_DIR="$(pwd)"

wait_for_storage() {
  local storage_type="${1}"
  case "${storage_type}" in
    ceph)
        wait-for-it -t 120 ceph:80
      ;;
    scality)
        wait-for-it -t 120 scality:8000
      ;;
    minio)
        wait-for-it -t 120 minio:9000
      ;;
    *)
      echo "Unknown service \"${storage_type}\"!"
      exit 1
      ;;
  esac
}

main () {
  # wait for storage to be ready
  wait_for_storage "${STORAGE}"

  # prepare config
  cp "tests/drone/configs/config.${STORAGE}.php" "tests/unit/config.php"

  # go to server root dir
  core_path="$(dirname "$(dirname "${BASE_DIR}")")"
  cd "${core_path}"

  # enable apps
  php occ config:app:set core enable_external_storage --value=yes
  php occ app:enable files_external
  php occ app:enable files_external_s3

  # run unit tests
  if [[ "${COVERAGE}" == "true" ]]; then
    phpdbg -d memory_limit=4096M -rr ./lib/composer/bin/phpunit --configuration "${BASE_DIR}/phpunit.xml"
  else
    ./lib/composer/bin/phpunit --configuration "${BASE_DIR}/phpunit.xml"
  fi

}

main