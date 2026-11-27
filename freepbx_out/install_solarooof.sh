#!/usr/bin/env bash
set -euo pipefail

PACKAGE_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ASTERISK_SOUNDS="/var/lib/asterisk/sounds"
ASTERISK_CONF="/etc/asterisk"
INCLUDE_FILE="extensions_custom_solarooof.conf"
CUSTOM_CONF="${ASTERISK_CONF}/extensions_custom.conf"

install -d -o asterisk -g asterisk "${ASTERISK_SOUNDS}/solarooof"
cp -a "${PACKAGE_DIR}/sounds/solarooof/." "${ASTERISK_SOUNDS}/solarooof/"
chown -R asterisk:asterisk "${ASTERISK_SOUNDS}/solarooof"

install -o root -g asterisk -m 0640 \
    "${PACKAGE_DIR}/extensions_custom.conf" \
    "${ASTERISK_CONF}/${INCLUDE_FILE}"

touch "${CUSTOM_CONF}"
if ! grep -Fqx "#include ${INCLUDE_FILE}" "${CUSTOM_CONF}"; then
    cp -p "${CUSTOM_CONF}" "${CUSTOM_CONF}.bak.$(date +%Y%m%d%H%M%S)"
    printf '\n#include %s\n' "${INCLUDE_FILE}" >> "${CUSTOM_CONF}"
fi

asterisk -rx "dialplan reload"
printf 'Installed Solarooof prompts and dialplan include.\n'
