#!/usr/bin/env bash
# One-time migration from a pre-exact-commit Runner (notably 1.5.1) to 1.5.2.
# All later Dashboard upgrades are handled by LinuxUpgradePackageMaterializer.
set -Eeuo pipefail

WORKSPACE=""
EXPECTED_REPOSITORY=""
REMOTE_NAME="origin"
BRANCH=""
EXPECTED_COMMIT=""
EXPECTED_VERSION="1.5.2"
INSTALL_DIR="/opt/deployment-agent"
AGENT_MANAGED_RESTART=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --workspace) WORKSPACE="$2"; shift 2 ;;
        --expected-repository) EXPECTED_REPOSITORY="$2"; shift 2 ;;
        --remote) REMOTE_NAME="$2"; shift 2 ;;
        --branch) BRANCH="$2"; shift 2 ;;
        --expected-commit) EXPECTED_COMMIT="$2"; shift 2 ;;
        --expected-version) EXPECTED_VERSION="$2"; shift 2 ;;
        --install-dir) INSTALL_DIR="$2"; shift 2 ;;
        --agent-managed-restart) AGENT_MANAGED_RESTART=1; shift ;;
        *) echo "Unknown argument: $1" >&2; exit 64 ;;
    esac
done

[[ -d "$WORKSPACE" ]] || { echo "A readable deployment-system workspace is required." >&2; exit 64; }
[[ -n "$EXPECTED_REPOSITORY" ]] || { echo "The approved repository identity is required." >&2; exit 64; }
[[ "$REMOTE_NAME" =~ ^[A-Za-z0-9._-]+$ ]] || { echo "Invalid remote name." >&2; exit 64; }
[[ "$EXPECTED_COMMIT" =~ ^[0-9a-f]{40}$ ]] || { echo "Invalid expected commit." >&2; exit 64; }
[[ "$EXPECTED_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+([-+][A-Za-z0-9.-]+)?$ ]] || { echo "Invalid expected version." >&2; exit 64; }
[[ "$EXPECTED_VERSION" == "1.5.2" ]] || { echo "This historical bootstrap only supports target version 1.5.2." >&2; exit 64; }
git check-ref-format --branch "$BRANCH" >/dev/null 2>&1 || { echo "Invalid expected branch." >&2; exit 64; }
git -C "$WORKSPACE" rev-parse --is-inside-work-tree | grep -qx true || { echo "Workspace is not a Git work tree." >&2; exit 2; }

INSTALLED_AGENT="$INSTALL_DIR/agent.php"
[[ -f "$INSTALLED_AGENT" ]] || { echo "An installed Local Agent runtime is required." >&2; exit 64; }
INSTALLED_VERSION="$(sed -n "s/.*const LOCAL_AGENT_VERSION = '\([^']*\)'.*/\1/p" "$INSTALLED_AGENT" | head -n 1)"
[[ "$INSTALLED_VERSION" == "1.5.1" ]] \
    || { echo "This historical bootstrap only supports an installed Local Agent 1.5.1 runtime." >&2; exit 64; }

canonical_repository() {
    local value="${1//\\//}"
    value="${value%/}"
    value="${value%.git}"
    if [[ "$value" =~ ^git@([^:]+):(.+)$ ]]; then
        local host="${BASH_REMATCH[1],,}" path="${BASH_REMATCH[2],,}"
        [[ "$host" == "github.com" || "$host" == "github-safhe" ]] && { printf 'github.com/%s\n' "$path"; return; }
    fi
    if [[ "$value" =~ ^(https|ssh)://([^/@]+@)?([^/:]+)(:[0-9]+)?/(.+)$ ]]; then
        local host="${BASH_REMATCH[3],,}" path="${BASH_REMATCH[5],,}"
        [[ "$host" == "github.com" || "$host" == "ssh.github.com" ]] && { printf 'github.com/%s\n' "$path"; return; }
    fi
    printf '%s\n' "$value"
}

WORKSPACE_REMOTE="$(git -C "$WORKSPACE" remote get-url "$REMOTE_NAME")"
[[ "$(canonical_repository "$WORKSPACE_REMOTE")" == "$(canonical_repository "$EXPECTED_REPOSITORY")" ]] \
    || { echo "Workspace remote does not match the approved repository identity." >&2; exit 2; }

TMP_ROOT="$(mktemp -d)"
trap 'rm -rf -- "$TMP_ROOT"' EXIT
REPOSITORY="$TMP_ROOT/repository"
EXTRACTED="$TMP_ROOT/extracted"
ARCHIVE="$TMP_ROOT/deployment-local-agent.tar"
TREE_MANIFEST="$TMP_ROOT/deployment-local-agent.tree"
umask 077
mkdir "$REPOSITORY" "$EXTRACTED"
git -C "$REPOSITORY" init -q
git -C "$REPOSITORY" config core.autocrlf false
git -C "$REPOSITORY" config core.eol lf
git -C "$REPOSITORY" remote add origin "$WORKSPACE_REMOTE"
git -C "$REPOSITORY" fetch --no-tags --prune origin "refs/heads/$BRANCH"
git -C "$REPOSITORY" cat-file -e "${EXPECTED_COMMIT}^{commit}"
git -C "$REPOSITORY" merge-base --is-ancestor "$EXPECTED_COMMIT" FETCH_HEAD
PACKAGE_TREE_HASH="$(git -C "$REPOSITORY" rev-parse "${EXPECTED_COMMIT}:deployment-local-agent")"
[[ "$PACKAGE_TREE_HASH" =~ ^[0-9a-f]{40}$ ]] || { echo "Expected commit has no valid Agent package tree." >&2; exit 3; }
git -C "$REPOSITORY" ls-tree -r -z "$EXPECTED_COMMIT" -- deployment-local-agent >"$TREE_MANIFEST"
EXPECTED_FILES=0
while IFS= read -r -d '' entry; do
    metadata="${entry%%$'\t'*}"
    path="${entry#*$'\t'}"
    mode="${metadata%% *}"
    remainder="${metadata#* }"
    type="${remainder%% *}"
    blob="${remainder##* }"
    [[ ( "$mode" == "100644" || "$mode" == "100755" ) && "$type" == "blob" && "$blob" =~ ^[0-9a-f]{40}$ && "$path" == deployment-local-agent/* ]] \
        || { echo "Unsupported entry in exact-commit Agent package tree." >&2; exit 3; }
    ((EXPECTED_FILES += 1))
done <"$TREE_MANIFEST"
(( EXPECTED_FILES > 0 )) || { echo "Exact-commit Agent package tree is empty." >&2; exit 3; }
git -C "$REPOSITORY" archive --format=tar -o "$ARCHIVE" "$EXPECTED_COMMIT" -- deployment-local-agent
while IFS= read -r entry; do
    [[ "$entry" == deployment-local-agent/* && "$entry" != /* && "$entry" != *"../"* ]] \
        || { echo "Unsafe upgrade archive entry." >&2; exit 3; }
done < <(tar -tf "$ARCHIVE")
tar -xf "$ARCHIVE" -C "$EXTRACTED"

PACKAGE_ROOT="$EXTRACTED/deployment-local-agent"
ACTUAL_FILES=0
while IFS= read -r -d '' path; do
    [[ ! -L "$path" ]] || { echo "Extracted Agent package contains a symbolic link." >&2; exit 3; }
    ((ACTUAL_FILES += 1))
done < <(find "$PACKAGE_ROOT" -type f -print0)
[[ "$ACTUAL_FILES" -eq "$EXPECTED_FILES" ]] || { echo "Extracted Agent package file set does not match the exact commit." >&2; exit 3; }
while IFS= read -r -d '' entry; do
    metadata="${entry%%$'\t'*}"
    path="${entry#*$'\t'}"
    remainder="${metadata#* }"
    blob="${remainder##* }"
    extracted_path="$EXTRACTED/$path"
    [[ -f "$extracted_path" && ! -L "$extracted_path" ]] \
        || { echo "Exact-commit Agent package entry is missing or unsafe." >&2; exit 3; }
    [[ "$(git hash-object -- "$extracted_path")" == "$blob" ]] \
        || { echo "Extracted Agent package does not match the exact commit." >&2; exit 3; }
done <"$TREE_MANIFEST"

PACKAGE_AGENT="$PACKAGE_ROOT/agent/agent.php"
PACKAGE_UPGRADER="$PACKAGE_ROOT/upgrade-linux.sh"
[[ -f "$PACKAGE_AGENT" && -f "$PACKAGE_UPGRADER" && -d "$PACKAGE_ROOT/agent/lib" ]] \
    || { echo "Exact commit is missing the official Agent package." >&2; exit 3; }
PACKAGE_VERSION="$(sed -n "s/.*const LOCAL_AGENT_VERSION = '\([^']*\)'.*/\1/p" "$PACKAGE_AGENT" | head -n 1)"
[[ "$PACKAGE_VERSION" == "$EXPECTED_VERSION" ]] \
    || { echo "Upgrade package version ${PACKAGE_VERSION:-unknown} does not match expected version ${EXPECTED_VERSION}." >&2; exit 4; }
[[ -f "$PACKAGE_ROOT/agent/lib/DependencyRepositoryResolver.php" \
    && -f "$PACKAGE_ROOT/agent/lib/LinuxUpgradePackageMaterializer.php" ]] \
    || { echo "The 1.5.2 exact-commit transport contract is missing from the package." >&2; exit 4; }
grep -Fq '$checkoutKey' "$PACKAGE_ROOT/agent/lib/PinnedDependencyMaterializer.php" \
    || { echo "The 1.5.2 dependency checkout deduplication contract is missing." >&2; exit 4; }

echo "BOOTSTRAP_PACKAGE_OK version=$PACKAGE_VERSION source_commit=$EXPECTED_COMMIT package_tree_hash=$PACKAGE_TREE_HASH repository_mapping=deployment-system"
args=(--source-dir "$PACKAGE_ROOT/agent" --install-dir "$INSTALL_DIR" --expected-version "$EXPECTED_VERSION")
[[ "$AGENT_MANAGED_RESTART" == "1" ]] && args+=(--agent-managed-restart)
bash "$PACKAGE_UPGRADER" "${args[@]}"
