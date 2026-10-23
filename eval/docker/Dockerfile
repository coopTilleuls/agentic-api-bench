# Blank agent images: the claude CLI (native standalone binary, no Node.js), a
# shell with curl/jq, and the stub of the external services (static Go binary).
# No general-purpose interpreter is installed in the base, so the runtime agent
# cannot write its own server. No host configuration is copied in; HOME is a
# tmpfs at run time. One shared stage ("base") so the stacks cannot drift:
#   --target runtime      llmapi-eval-agent       (the caddy binary stack)
#   --target nextjs       llmapi-eval-nextjs      (base + Node.js 22 and npm)
#   --target apiplatform  llmapi-eval-apiplatform (php + Symfony CLI)
#   --target laravel      llmapi-eval-laravel     (php: base + PHP 8.4, Composer)
#   --target django       llmapi-eval-django      (base + Python 3, pip, venv; psycopg from binary wheels)
#   --target go           llmapi-eval-go          (base + Go toolchain, official tarball)
#   --target rust         llmapi-eval-rust        (base + rustup stable toolchain, cargo, a C linker)
FROM golang:1.25-alpine AS stub
WORKDIR /src
COPY docker/stub/ ./
RUN CGO_ENABLED=0 go build -trimpath -ldflags='-s -w' -o /stub .

FROM alpine:3.22 AS claude
ARG CLAUDE_CODE_VERSION=2.1.296
RUN apk add --no-cache curl jq
# Release manifest of the native build, checksum verified. linux-*-musl: Alpine.
RUN case "$(uname -m)" in x86_64) platform=linux-x64-musl ;; aarch64) platform=linux-arm64-musl ;; *) exit 1 ;; esac \
 && base="https://downloads.claude.ai/claude-code-releases/${CLAUDE_CODE_VERSION}" \
 && sum="$(curl -fsSL "$base/manifest.json" | jq -r --arg p "$platform" '.platforms[$p].checksum')" \
 && curl -fsSL -o /claude "$base/$platform/claude" \
 && echo "$sum  /claude" | sha256sum -c - \
 && chmod 755 /claude

FROM alpine:3.22 AS base
RUN apk add --no-cache bash curl jq ca-certificates libgcc libstdc++ ripgrep
COPY --from=claude /claude /usr/local/bin/claude
COPY --from=stub /stub /opt/stub/stub
COPY stub/quotes.json /opt/stub/
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/claude-settings.json /opt/claude/settings.json

ENV HOME=/home/agent \
    CLAUDE_CONFIG_DIR=/home/agent/.claude \
    USE_BUILTIN_RIPGREP=0 \
    DISABLE_AUTOUPDATER=1 \
    DISABLE_TELEMETRY=1 \
    DISABLE_ERROR_REPORTING=1 \
    CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC=1 \
    CLAUDE_CODE_DISABLE_OFFICIAL_MARKETPLACE_AUTOINSTALL=1 \
    ENABLE_CLAUDEAI_MCP_SERVERS=false \
    CLAUDE_CODE_DISABLE_BUNDLED_SKILLS=1 \
    CLAUDE_CODE_DISABLE_CLAUDE_MDS=1

WORKDIR /work
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

FROM base AS runtime

FROM base AS nextjs
RUN apk add --no-cache nodejs npm

FROM base AS php
RUN apk add --no-cache git unzip php84 php84-pdo php84-pdo_pgsql php84-pgsql php84-intl \
      php84-zip php84-opcache php84-xml php84-dom php84-simplexml php84-xmlreader \
      php84-xmlwriter php84-mbstring php84-ctype php84-iconv php84-tokenizer php84-phar \
      php84-openssl php84-curl php84-fileinfo php84-session php84-sodium php84-pcntl php84-posix \
 && ln -s php84 /usr/bin/php
COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer

FROM php AS apiplatform
ARG SYMFONY_CLI_VERSION=5.22.2
RUN arch="$(uname -m | sed 's/x86_64/amd64/; s/aarch64/arm64/')" \
 && curl -fsSL "https://github.com/symfony-cli/symfony-cli/releases/download/v${SYMFONY_CLI_VERSION}/symfony-cli_linux_${arch}.tar.gz" \
      | tar -xz -C /usr/local/bin symfony

FROM php AS laravel

FROM base AS django
RUN apk add --no-cache python3 py3-pip

FROM base AS go
ARG GO_VERSION=1.25.14
ARG GO_SHA256_AMD64=a21ae5633a269bcd7e90cf767e48225633795e99d831742cbf3397064fee7712
ARG GO_SHA256_ARM64=9bf234ea70ffec9347fdf6b22ce4add51717d3386a38a441e8c8743fceb5eaee
RUN arch="$(uname -m | sed 's/x86_64/amd64/; s/aarch64/arm64/')" \
 && case "$arch" in amd64) sum="$GO_SHA256_AMD64" ;; arm64) sum="$GO_SHA256_ARM64" ;; *) exit 1 ;; esac \
 && curl -fsSL -o /tmp/go.tgz "https://go.dev/dl/go${GO_VERSION}.linux-${arch}.tar.gz" \
 && echo "$sum  /tmp/go.tgz" | sha256sum -c - \
 && tar -C /usr/local -xzf /tmp/go.tgz \
 && rm /tmp/go.tgz
# The module and build caches live in /work, not in the tmpfs HOME: the agent
# container, the dependency install and the scoring container are different
# containers, and /work is the only thing they share (scoring has no network).
# -modcacherw keeps the cache deletable by the host user.
ENV PATH=/usr/local/go/bin:$PATH \
    GOTOOLCHAIN=local \
    GOPATH=/work/.gopath \
    GOCACHE=/work/.cache/go-build \
    GOFLAGS="-modcacherw -buildvcs=false" \
    CGO_ENABLED=0

FROM base AS rust
ARG RUST_VERSION=1.90.0
RUN apk add --no-cache gcc musl-dev openssl-dev openssl-libs-static pkgconf
# rustup-init (musl build, checksum verified) installs the pinned toolchain
# into /opt/rustup; rustup itself is then dropped and the toolchain bin
# directory goes on PATH, so nothing needs a writable RUSTUP_HOME. CARGO_HOME
# (registry, downloaded crates) and the target directory live in /work for the
# same reason as GOPATH in the go stage.
RUN arch="$(uname -m)" \
 && base="https://static.rust-lang.org/rustup/dist/${arch}-unknown-linux-musl" \
 && curl -fsSL -o /tmp/rustup-init "$base/rustup-init" \
 && sum="$(curl -fsSL "$base/rustup-init.sha256" | cut -d' ' -f1)" \
 && echo "$sum  /tmp/rustup-init" | sha256sum -c - \
 && chmod 755 /tmp/rustup-init \
 && RUSTUP_HOME=/opt/rustup CARGO_HOME=/opt/cargo /tmp/rustup-init -y --no-modify-path --profile minimal --default-toolchain "$RUST_VERSION" \
 && rm -rf /tmp/rustup-init /opt/cargo /opt/rustup/downloads /opt/rustup/tmp \
 && ln -s "$(echo /opt/rustup/toolchains/*)" /opt/rust \
 && chmod -R a+rX /opt/rustup
ENV PATH=/opt/rust/bin:$PATH \
    CARGO_HOME=/work/.cargo-home
