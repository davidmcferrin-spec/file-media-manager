#!/usr/bin/env bash
# =============================================================
# setup.sh — Media Manager
# NewsNation Broadcast Archive System
# Target: Debian 13 (Trixie) / Apache 2.4 / PHP 8.4+
# =============================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${SCRIPT_DIR}"
WEB_ROOT="/var/www/file-media-manager"
APACHE_CONF="/etc/apache2/sites-available/media-manager.conf"
BOOTSTRAP_VERSION="5.3.3"
BOOTSTRAP_URL="https://github.com/twbs/bootstrap/releases/download/v${BOOTSTRAP_VERSION}/bootstrap-${BOOTSTRAP_VERSION}-dist.zip"

# ── Colors ────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; NC='\033[0m'; BOLD='\033[1m'

info()    { echo -e "${CYAN}[INFO]${NC}  $*"; }
success() { echo -e "${GREEN}[OK]${NC}    $*"; }
warn()    { echo -e "${YELLOW}[WARN]${NC}  $*"; }
error()   { echo -e "${RED}[ERROR]${NC} $*" >&2; exit 1; }

echo ""
echo -e "${BOLD}========================================${NC}"
echo -e "${BOLD}  Media Manager — Setup${NC}"
echo -e "${BOLD}  NewsNation Broadcast Archive System${NC}"
echo -e "${BOLD}========================================${NC}"
echo ""

# ── Root check ────────────────────────────────────────────────
if [[ $EUID -ne 0 ]]; then
    error "This script must be run as root. Use: sudo ./setup.sh"
fi

# ── Confirm ───────────────────────────────────────────────────
read -rp "Install Media Manager to ${WEB_ROOT}? [y/N] " CONFIRM
[[ "${CONFIRM,,}" == "y" ]] || { echo "Aborted."; exit 0; }

# ── 1. System packages ────────────────────────────────────────
info "Updating package lists..."
apt-get update -qq

info "Installing PHP 8.4, Apache, FFmpeg, PostgreSQL, and utilities..."
apt-get install -y -qq \
    apache2 \
    php8.4 \
    php8.4-cli \
    php8.4-pgsql \
    php8.4-mbstring \
    php-json \
    php8.4-fileinfo \
    php8.4-curl \
    postgresql \
    postgresql-client \
    ffmpeg \
    unzip \
    curl

success "System packages installed."

# ── 2. Enable Apache modules ──────────────────────────────────
info "Enabling Apache modules..."
a2enmod rewrite
a2enmod headers
success "Apache modules enabled."

# ── 3. Deploy application files ───────────────────────────────
info "Deploying application to ${WEB_ROOT}..."
if [[ "$APP_DIR" != "$WEB_ROOT" ]]; then
    mkdir -p "$WEB_ROOT"
    rsync -a --exclude='.git' --exclude='storage/thumbnails/*' \
          --exclude='storage/logs/*' --exclude='storage/backups/*' \
          "${APP_DIR}/" "${WEB_ROOT}/"
else
    info "Already in web root, skipping rsync."
fi
success "Application deployed."

# ── 4. Bootstrap vendor assets ────────────────────────────────
VENDOR_DIR="${WEB_ROOT}/public/vendor/bootstrap"
if [[ ! -f "${VENDOR_DIR}/css/bootstrap.min.css" ]]; then
    info "Downloading Bootstrap ${BOOTSTRAP_VERSION}..."
    TMP_ZIP=$(mktemp /tmp/bootstrap-XXXXXX.zip)
    curl -sL "${BOOTSTRAP_URL}" -o "${TMP_ZIP}"
    mkdir -p "${VENDOR_DIR}" /tmp/bs-extract/
    unzip -q -d /tmp/bs-extract/ "${TMP_ZIP}" \
        "bootstrap-${BOOTSTRAP_VERSION}-dist/css/bootstrap.min.css" \
        "bootstrap-${BOOTSTRAP_VERSION}-dist/js/bootstrap.bundle.min.js"
    mkdir -p "${VENDOR_DIR}/css"
    cp "/tmp/bs-extract/bootstrap-${BOOTSTRAP_VERSION}-dist/css/bootstrap.min.css" \
       "${VENDOR_DIR}/css/bootstrap.min.css"
    mkdir -p "${VENDOR_DIR}/js"
    cp "/tmp/bs-extract/bootstrap-${BOOTSTRAP_VERSION}-dist/js/bootstrap.bundle.min.js" \
       "${VENDOR_DIR}/js/bootstrap.bundle.min.js"
    rm -rf "${TMP_ZIP}" /tmp/bs-extract/
    success "Bootstrap ${BOOTSTRAP_VERSION} vendored."
else
    success "Bootstrap already present, skipping."
fi

# ── 5. Create storage directories ─────────────────────────────
info "Creating storage directories..."
mkdir -p "${WEB_ROOT}/storage/thumbnails"
mkdir -p "${WEB_ROOT}/storage/logs"
mkdir -p "${WEB_ROOT}/storage/backups"
mkdir -p "${WEB_ROOT}/storage/tmp"
mkdir -p "${WEB_ROOT}/storage/media"
mkdir -p "${WEB_ROOT}/uploads"

# .gitkeep placeholders
touch "${WEB_ROOT}/storage/thumbnails/.gitkeep"
touch "${WEB_ROOT}/storage/logs/.gitkeep"
touch "${WEB_ROOT}/storage/backups/.gitkeep"
touch "${WEB_ROOT}/storage/tmp/.gitkeep"
touch "${WEB_ROOT}/uploads/.gitkeep"

success "Storage directories created."

# ── 6. Permissions ────────────────────────────────────────────
info "Setting permissions..."
chown -R www-data:www-data "${WEB_ROOT}"
chmod -R 755 "${WEB_ROOT}"
chmod -R 775 "${WEB_ROOT}/storage" \
             "${WEB_ROOT}/uploads"
success "Permissions set."

# ── 7. .env ───────────────────────────────────────────────────
ENV_FILE="${WEB_ROOT}/.env"
if [[ ! -f "${ENV_FILE}" ]]; then
    info "Creating .env from .env.example..."
    cp "${WEB_ROOT}/.env.example" "${ENV_FILE}"
    chown www-data:www-data "${ENV_FILE}"
    chmod 640 "${ENV_FILE}"
    warn ".env created — review and edit before going live."
else
    info ".env already exists, skipping."
fi

# ── 8. Apache site config ─────────────────────────────────────
info "Installing Apache site config..."
cp "${WEB_ROOT}/apache/media-manager-port.conf" /etc/apache2/conf-available/media-manager-port.conf
a2enconf media-manager-port
cp "${WEB_ROOT}/apache/media-manager.conf" "${APACHE_CONF}"
a2ensite media-manager.conf
success "Apache site enabled on port 81."

# ── 9. PostgreSQL provisioning ────────────────────────────────
info "Configuring PostgreSQL..."

set -a
# shellcheck disable=SC1090
source "${ENV_FILE}"
set +a

db_host="${DB_HOST:-127.0.0.1}"
db_name="${DB_NAME:-media_manager}"
db_user="${DB_USER:-media_manager}"
db_password="${DB_PASSWORD:-}"

if [[ -z "${db_name}" || -z "${db_user}" || -z "${db_password}" ]]; then
    error "DB_NAME, DB_USER, and DB_PASSWORD must be set in ${ENV_FILE}"
fi

if [[ "$db_host" == "127.0.0.1" || "$db_host" == "localhost" || "$db_host" == "::1" ]]; then
    db_name_b64="$(printf '%s' "$db_name" | base64 | tr -d '\n')"
    db_user_b64="$(printf '%s' "$db_user" | base64 | tr -d '\n')"
    db_password_b64="$(printf '%s' "$db_password" | base64 | tr -d '\n')"

    if ! sudo -u postgres psql -d postgres -v ON_ERROR_STOP=1 <<SQL
\\set db_user_b64 '${db_user_b64}'
\\set db_password_b64 '${db_password_b64}'
WITH vars AS (
  SELECT
    convert_from(decode(:'db_user_b64', 'base64'), 'UTF8') AS db_user,
    convert_from(decode(:'db_password_b64', 'base64'), 'UTF8') AS db_password
)
SELECT format(
  CASE
    WHEN EXISTS (SELECT 1 FROM pg_roles WHERE rolname = vars.db_user)
      THEN 'ALTER ROLE %I WITH LOGIN PASSWORD %L'
    ELSE 'CREATE ROLE %I LOGIN PASSWORD %L'
  END,
  vars.db_user,
  vars.db_password
)
FROM vars \\gexec
SQL
    then
        error "Failed to create or update PostgreSQL role \"${db_user}\"."
    fi

    if ! sudo -u postgres psql -d postgres -v ON_ERROR_STOP=1 <<SQL
\\set db_name_b64 '${db_name_b64}'
\\set db_user_b64 '${db_user_b64}'
WITH vars AS (
  SELECT
    convert_from(decode(:'db_name_b64', 'base64'), 'UTF8') AS db_name,
    convert_from(decode(:'db_user_b64', 'base64'), 'UTF8') AS db_user
)
SELECT format('CREATE DATABASE %I OWNER %I', vars.db_name, vars.db_user)
FROM vars
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = vars.db_name) \\gexec

WITH vars AS (
  SELECT
    convert_from(decode(:'db_name_b64', 'base64'), 'UTF8') AS db_name,
    convert_from(decode(:'db_user_b64', 'base64'), 'UTF8') AS db_user
)
SELECT format('ALTER DATABASE %I OWNER TO %I', vars.db_name, vars.db_user)
FROM vars
WHERE EXISTS (SELECT 1 FROM pg_database WHERE datname = vars.db_name)
  AND (SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname = vars.db_name) <> vars.db_user \\gexec
SQL
    then
        error "Failed to create or update PostgreSQL database \"${db_name}\"."
    fi
fi

success "PostgreSQL role and database ready."

# ── 10. Run database migrations ───────────────────────────────
info "Running database migrations..."
sudo -u www-data php8.4 "${WEB_ROOT}/scripts/migrate.php"
success "Database migrations applied."

# ── 11. Create admin user ─────────────────────────────────────
info "Creating admin user..."

# Load env values for admin seed
ADMIN_EMAIL=$(grep '^SEED_ADMIN_EMAIL=' "${ENV_FILE}" | cut -d'=' -f2 | tr -d '"' || echo "admin@newsnation.com")
ADMIN_PASS=$(grep '^SEED_ADMIN_PASSWORD=' "${ENV_FILE}" | cut -d'=' -f2 | tr -d '"' || echo "")

if [[ -z "${ADMIN_PASS}" ]]; then
    ADMIN_PASS=$(head -c 1024 /dev/urandom | tr -dc 'A-Za-z0-9!@#$%' | cut -c1-18)
    GENERATED_PASS=true
else
    GENERATED_PASS=false
fi

sudo -u www-data php8.4 -r "
    require '${WEB_ROOT}/src/bootstrap.php';
    use MediaManager\Auth\Auth;
    use MediaManager\Database;
    \$pdo = Database::connection();
    \$existing = \$pdo->prepare('SELECT id FROM users WHERE email = ?');
    \$existing->execute(['${ADMIN_EMAIL}']);
    if (\$existing->fetch()) {
        echo 'Admin user already exists, skipping.' . PHP_EOL;
    } else {
        Auth::createUser('${ADMIN_EMAIL}', '${ADMIN_PASS}', 'Administrator', 'admin');
        echo 'Admin user created.' . PHP_EOL;
    }
"

# ── 12. Broadcast continuity engine ───────────────────────────
info "Installing broadcast continuity engine (local)..."
CONTINUITY_MODEL="$(grep -E '^CONTINUITY_CHECK_MODEL=' "${ENV_FILE}" 2>/dev/null | cut -d'=' -f2- | tr -d '"' || true)"
CONTINUITY_MODEL="${CONTINUITY_MODEL:-llama3.2:3b}"

if ! command -v ollama >/dev/null 2>&1; then
    curl -fsSL https://ollama.com/install.sh | sh
fi

if command -v ollama >/dev/null 2>&1; then
    systemctl enable ollama >/dev/null 2>&1 || true
    systemctl start ollama >/dev/null 2>&1 || true
    info "Waiting for continuity engine..."
    for _ in $(seq 1 45); do
        if curl -sf http://127.0.0.1:11434/api/tags >/dev/null 2>&1; then
            break
        fi
        sleep 1
    done
    if curl -sf http://127.0.0.1:11434/api/tags >/dev/null 2>&1; then
        info "Loading continuity pack (${CONTINUITY_MODEL})..."
        if ollama pull "${CONTINUITY_MODEL}"; then
            success "Broadcast continuity engine ready."
        else
            warn "Continuity pack pull failed — Scan still works; enable again after network is available."
        fi
    else
        warn "Continuity engine did not become ready — Scan still works without it."
    fi
else
    warn "Continuity engine binary missing — Scan still works without it."
fi

# Ensure continuity env keys exist (do not overwrite admin edits)
ensure_env_key() {
    local key="$1"
    local value="$2"
    if ! grep -qE "^${key}=" "${ENV_FILE}" 2>/dev/null; then
        printf '\n%s=%s\n' "${key}" "${value}" >> "${ENV_FILE}"
    fi
}
ensure_env_key "CONTINUITY_CHECK_ENABLED" "true"
ensure_env_key "CONTINUITY_CHECK_URL" "http://127.0.0.1:11434"
ensure_env_key "CONTINUITY_CHECK_MODEL" "${CONTINUITY_MODEL}"
ensure_env_key "CONTINUITY_CHECK_TIMEOUT_SECONDS" "60"
ensure_env_key "CONTINUITY_CHECK_KEEP_ALIVE" "24h"
ensure_env_key "CONTINUITY_CHECK_CONCURRENCY" "4"

# Allow Ollama to process multiple continuity requests at once (match app concurrency)
if command -v systemctl >/dev/null 2>&1 && systemctl list-unit-files | grep -q '^ollama\.service'; then
    OLLAMA_DROPIN_DIR="/etc/systemd/system/ollama.service.d"
    OLLAMA_DROPIN="${OLLAMA_DROPIN_DIR}/parallel.conf"
    mkdir -p "${OLLAMA_DROPIN_DIR}"
    if [[ ! -f "${OLLAMA_DROPIN}" ]]; then
        cat > "${OLLAMA_DROPIN}" <<'EOF'
[Service]
Environment="OLLAMA_NUM_PARALLEL=4"
Environment="OLLAMA_KEEP_ALIVE=24h"
EOF
        systemctl daemon-reload >/dev/null 2>&1 || true
        systemctl restart ollama >/dev/null 2>&1 || true
        info "Configured Ollama OLLAMA_NUM_PARALLEL=4 for concurrent continuity evals."
    fi
fi

# Upgrade stale short timeouts from earlier installs (8s is too low for cold loads)
if grep -qE '^CONTINUITY_CHECK_TIMEOUT_SECONDS=([0-9]+)' "${ENV_FILE}" 2>/dev/null; then
    cur_timeout="$(grep -E '^CONTINUITY_CHECK_TIMEOUT_SECONDS=' "${ENV_FILE}" | head -1 | cut -d'=' -f2- | tr -d '"' | tr -d "'")"
    if [[ "${cur_timeout}" =~ ^[0-9]+$ ]] && (( cur_timeout < 60 )); then
        sed -i -E 's/^CONTINUITY_CHECK_TIMEOUT_SECONDS=.*/CONTINUITY_CHECK_TIMEOUT_SECONDS=60/' "${ENV_FILE}"
        info "Raised CONTINUITY_CHECK_TIMEOUT_SECONDS from ${cur_timeout} to 60."
    fi
fi

sudo -u www-data php8.4 -r "
    require '${WEB_ROOT}/src/bootstrap.php';
    (new MediaManager\Repositories\SystemRepository())->set('continuity_check_enabled', 'true');
    echo 'Continuity setting enabled.' . PHP_EOL;
" || warn "Could not persist continuity setting (non-fatal)."

# ── 13. Background workers (systemd) ──────────────────────────
info "Installing scan + caption extract systemd workers..."
ensure_env_key "WORKER_MODE" "daemon"
ensure_env_key "WORKER_POLL_SECONDS" "5"

PHP_BIN="$(command -v php8.4 || command -v php || true)"
if [[ -z "${PHP_BIN}" ]]; then
    warn "PHP binary not found — skip systemd workers."
elif command -v systemctl >/dev/null 2>&1; then
    UNIT_DIR="/etc/systemd/system"
    for unit in media-manager-scan media-manager-caption-extract; do
        src="${WEB_ROOT}/deploy/systemd/${unit}.service"
        if [[ -f "${src}" ]]; then
            # Rewrite paths/PHP binary for this install
            sed -e "s|/var/www/media-manager|${WEB_ROOT}|g" \
                -e "s|/usr/bin/php8.4|${PHP_BIN}|g" \
                "${src}" > "${UNIT_DIR}/${unit}.service"
            systemctl daemon-reload >/dev/null 2>&1 || true
            systemctl enable "${unit}.service" >/dev/null 2>&1 || true
            systemctl restart "${unit}.service" >/dev/null 2>&1 || true
            if systemctl is-active --quiet "${unit}.service"; then
                success "${unit}.service active."
            else
                warn "${unit}.service not active — check: journalctl -u ${unit} -n 50"
            fi
        fi
    done
else
    warn "systemctl unavailable — start workers manually: php scripts/scan_worker.php"
fi

# ── 14. Restart Apache ────────────────────────────────────────
info "Restarting Apache..."
systemctl reload apache2
success "Apache reloaded."

# ── Done ──────────────────────────────────────────────────────
echo ""
echo -e "${BOLD}${GREEN}========================================${NC}"
echo -e "${BOLD}${GREEN}  Setup complete!${NC}"
echo -e "${BOLD}${GREEN}========================================${NC}"
echo ""
echo -e "  URL:      ${CYAN}http://$(hostname -I | awk '{print $1}'):81/dashboard${NC}"
echo -e "  Email:    ${CYAN}${ADMIN_EMAIL}${NC}"
if [[ "${GENERATED_PASS}" == "true" ]]; then
echo -e "  Password: ${YELLOW}${ADMIN_PASS}${NC}  ← save this now!"
fi
echo ""
echo -e "  Edit ${CYAN}${ENV_FILE}${NC} to configure NAS mount paths"
echo -e "  and other settings before first use."
echo -e "  Workers:  ${CYAN}media-manager-scan${NC} + ${CYAN}media-manager-caption-extract${NC}"
echo -e "  Logs:     journalctl -u media-manager-scan -f"
echo -e "            journalctl -u media-manager-caption-extract -f"
echo -e "  Broadcast continuity check runs quietly during Scan/Reclassify."
echo ""
