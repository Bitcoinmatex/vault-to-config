# VaultToConfig

A CLI tool (a classic Nette application: Bootstrap + DI container, Latte, Symfony Console) that:

1. reads secrets from **HashiCorp Vault** based on the environment (HTTP API, KV v1/v2),
2. injects them as variables into a **Latte** template,
3. validates the output as **NEON** and writes e.g. `local.neon` for deploying any Nette application.

## Structure

```
VaultToConfig/
├── app/
│   ├── Bootstrap.php              # Nette Configurator + DI container
│   ├── Command/
│   │   └── CompileLatteCommand.php
│   ├── Vault/
│   │   ├── VaultClient.php        # KV v1/v2 HTTP client
│   │   └── VaultException.php
│   └── Latte/
│       └── PlainTextFileLoader.php
├── bin/
│   └── console                    # entry point (container boot)
├── config/
│   └── common.neon                # DI services (command + Console Application)
├── examples/
│   └── config.latte               # example input template
├── log/  temp/                     # writable for Nette/Tracy
├── install.sh
└── composer.json
```

(There is no `www/` web directory - this is a purely console application.)

## Download, run, test

```bash
chmod +x install.sh
./install.sh
```

Run:

```bash
export VAULT_ADDR="https://vault.internal:8200"
export VAULT_TOKEN="hvs.****"
php bin/console compile:latte prod examples/config.latte config/local.neon --dry-run
```

## Usage

```bash
export VAULT_TOKEN="hvs.****"
php bin/console compile:latte <environment> <input.latte> <output.neon>
```

## Environment variables

| Variable            | Default                  | Meaning                                             |
|---------------------|--------------------------|-----------------------------------------------------|
| `VAULT_TOKEN`       | (required)               | Vault token (`X-Vault-Token`). Not logged.          |
| `VAULT_ADDR`        | `https://127.0.0.1:8200` | Vault address.                                      |
| `VAULT_KV_MOUNT`    | `secret`                 | KV mount.                                           |
| `VAULT_KV_VERSION`  | `2`                      | KV engine version (`1` or `2`).                     |
| `VAULT_SECRET_PATH` | `{env}`                  | Path template under the mount; `{env}` = 1st arg.   |
| `VAULT_NAMESPACE`   | (optional)               | Vault Enterprise namespace.                         |

Each one also has a flag: `--vault-addr`, `--mount`, `--kv-version`, `--secret-path`.

### Path and layering

The path is relative to **under the mount** (the client inserts `/data/` for KV v2 itself).
`{env}` is replaced by the environment. You can merge multiple comma-separated paths
(later ones override earlier):

```bash
export VAULT_SECRET_PATH="apps/myapp/common,apps/myapp/{env}"
# KV v2 reads: secret/data/apps/myapp/common + secret/data/apps/myapp/prod
```

## Latte template

The template starts with `{contentType text}` (if missing, it is added automatically -> no HTML
escaping). Available variables:

- `{$environment}` - the environment,
- `{$vault['key']}` - any key (even with dashes),
- `{$key}` - shorthand, if the name is a valid PHP identifier,
- the **`|neon`** filter - safely encodes the value as a NEON scalar (quotes + escaping).
  It is recommended for all values from Vault: `password: {$db_password|neon}`.

## Security / compliance

- Secret values are **never logged** - verbose (`-v`) prints only the key names.
  (DORA art. 9/11 - audit trail without sensitive data.)
- The output has `0640` permissions and is in `.gitignore`; delete it after deploy on CI.
- NEON is validated before writing - a template error never reaches production.
- Diagnostics go to **stderr**, the payload (`--dry-run`) to **stdout**.
