# Data-at-Rest Encryption

## Objective

Protect database files and recoverable database copies when storage media,
snapshots, or backup archives are accessed outside the running application.
This phase does not change database columns, indexes, search, filtering, or
sorting.

## Current Implementation

| Data copy | Project control | Current requirement |
| --- | --- | --- |
| Encrypted logical backups | Enforced by repository tooling | Use `scripts/backup-database-encrypted.sh` with an `age` public recipient |
| Production MySQL data volume | Hosting-provider control | Obtain written confirmation that the volume is encrypted at rest |
| Provider snapshots and replicas | Hosting-provider control | Obtain written confirmation that every copy is encrypted |
| cPanel or provider-managed backups | Hosting-provider control | Verify encryption, key separation, retention, and deletion |
| Local Docker `db-data` volume | Developer-machine control | Store Docker's data root on an encrypted host disk |

The production environment is shared HostGator/cPanel hosting. The application
deployment user cannot enable or verify physical disk encryption from Laravel,
MySQL, Docker Compose, or a migration. Until the hosting provider confirms the
items above, production volume, snapshot, replica, and provider-backup
encryption must be treated as **not verified**.

Database encryption does not cover files under Laravel's private storage disk.
Migrant supporting documents, document revisions, generated ARCO archives, and
temporary exports require a separate storage-encryption phase.

## Encrypted Logical Backups

The backup script streams `mysqldump` through `gzip` and directly into `age`.
It does not create an intermediate plaintext dump. `age` provides authenticated
public-key encryption, so the application server only needs a public recipient;
the private recovery identity must remain on a separate trusted system.
Tablespace metadata is excluded so the backup account does not need MySQL's
global `PROCESS` privilege.

### Prerequisites

- `age`, `mysqldump`, `gzip`, and `sha256sum`
- An age identity generated on an offline administrative workstation
- A MySQL client options file stored outside the repository with mode `600`
- A backup destination that is not web-accessible

Generate the recovery identity outside the application server:

```bash
age-keygen -o casamonarca-backup-identity.txt
chmod 600 casamonarca-backup-identity.txt
```

Record the printed `age1...` public recipient in the server's protected
operational configuration. Do not copy
`casamonarca-backup-identity.txt` to the application server.

Create a server-side MySQL options file such as
`/home/casamonarca/.config/casamonarca/backup-mysql.cnf`:

```ini
[client]
host=localhost
port=3306
user=BACKUP_USER
password=BACKUP_PASSWORD
```

Restrict it:

```bash
chmod 600 /home/casamonarca/.config/casamonarca/backup-mysql.cnf
```

Run a backup:

```bash
DB_BACKUP_DATABASE=casamonarca_api \
DB_BACKUP_AGE_RECIPIENT=age1REPLACE_WITH_PUBLIC_RECIPIENT \
MYSQL_DEFAULTS_FILE=/home/casamonarca/.config/casamonarca/backup-mysql.cnf \
DB_BACKUP_DIR=/home/casamonarca/backups/database \
DB_BACKUP_RETENTION_DAYS=30 \
./scripts/backup-database-encrypted.sh
```

Only `.sql.gz.age` files and their SHA-256 checksums should leave the server.
The checksum detects accidental corruption before recovery; authenticity and
confidentiality are provided by `age`.

### Scheduling and Off-Site Copies

Schedule the command with the hosting control panel or cron only after a manual
backup and recovery test succeeds. Put the public recipient and non-secret
paths in a deployment-user-owned wrapper or scheduler configuration; keep the
MySQL password only in the protected client options file.

Run at least one backup daily, alert when the command exits unsuccessfully, and
transfer the resulting encrypted archive plus checksum to independently
controlled off-site storage. A backup retained only on the application server
does not protect against server loss or destructive compromise. Apply the
documented retention policy to both the server copy and every off-site copy.

### Recovery Test

Verify the checksum before decrypting:

```bash
sha256sum --check database-YYYYMMDDTHHMMSSZ.sql.gz.age.sha256
```

Restore into an isolated recovery database, never directly over production:

```bash
age --decrypt \
  --identity casamonarca-backup-identity.txt \
  database-YYYYMMDDTHHMMSSZ.sql.gz.age \
  | gzip --decompress \
  | mysql --defaults-extra-file=recovery-mysql.cnf casamonarca_recovery
```

Perform and document a recovery test at least quarterly and after changing the
database version, backup script, encryption recipient, or hosting provider.

## Provider Verification Checklist

Request written answers from the hosting provider:

1. Are MySQL data volumes encrypted at rest?
2. Are all automatic backups, snapshots, temporary copies, and replicas encrypted?
3. Are encryption keys managed separately from stored data and backups?
4. Who can access the keys, and are key accesses audited?
5. What encryption and key-management services are used?
6. What are backup retention and secure-deletion periods?
7. Can customer-created unencrypted cPanel exports be disabled or restricted?
8. What happens to encrypted copies and keys when the service is terminated?

If the provider cannot meet or attest to these requirements, move production
MySQL to infrastructure where encrypted disks, snapshots, replicas, backup
policies, and keys are under organizational control.

## Protection Boundary

This phase protects against:

- Theft or unintended disclosure of encrypted backup archives
- Offline access to encrypted provider storage, once provider encryption is confirmed
- Disposal or reassignment of encrypted physical media without its keys

It does not protect against:

- A compromised Laravel process or database account
- SQL injection or misuse by an authorized application user
- Data displayed, downloaded, exported, cached, queued, or logged in plaintext
- Destructive changes, ransomware, or deletion
- Theft of both encrypted data and its decryption keys
- Private document files stored outside MySQL

Authorization, passkey gates, audit trails, least-privilege database accounts,
tested recovery, and secure endpoint behavior remain required.

## Future Encryption Plan

### Phase 1: Transport and Copy Inventory

- Require verified TLS for every non-local Laravel-to-MySQL connection.
- Inventory production, staging, local, backup, snapshot, replica, export,
  queue, cache, log, and temporary-file copies.
- Assign an owner, retention period, and deletion mechanism to each copy.
- Confirm that ARCO cancellation and backup-retention policies are compatible;
  purged records may remain recoverable until encrypted backups expire.

### Phase 2: Application-Level Field Encryption

- Classify migrant fields by sensitivity and operational search requirements.
- Encrypt the highest-risk fields before they reach MySQL.
- Use versioned envelope encryption backed by a managed KMS or HSM.
- Keep master keys outside the database, source repository, deployment `.env`,
  and application host wherever the platform permits.
- Bind ciphertext to the record and field using authenticated additional data
  so values cannot be silently moved between records.

Current accent-insensitive partial search cannot operate directly over encrypted
names or addresses. Before encrypting searchable fields, explicitly choose
between retaining selected plaintext search fields, exact-match blind indexes,
leakier normalized search projections, or removing partial search. Document the
information leakage of any searchable projection.

### Phase 3: Private File Encryption

- Encrypt migrant documents, document revisions, generated ARCO bundles, and
  temporary exports independently from the database.
- Use per-object data-encryption keys wrapped by a managed master key.
- Store key identifiers and algorithms with object metadata, never private keys.
- Decrypt only after the existing authorization and passkey checks succeed.
- Ensure downloaded and generated plaintext files are not retained on disk.

### Phase 4: Key Lifecycle and Migration

- Define key creation, activation, rotation, revocation, escrow, and recovery.
- Separate key administration from database and application administration.
- Add dual-read and controlled backfill support for existing plaintext rows.
- Verify the backfill before removing plaintext columns or copies.
- Make rotation resumable and auditable without changing business data.
- Preserve signing and ARCO integrity: key rotation must not change canonical
  payload hashes or invalidate existing cryptographic receipts.

### Phase 5: Validation and Operations

- Test backup recovery, unavailable-key behavior, rotation interruption, and
  disaster recovery.
- Add automated checks that secrets and plaintext exports are not committed.
- Redact sensitive values from logs, exceptions, audit metadata, queues, and
  monitoring tools.
- Alert on unusual bulk reads, exports, decryptions, and key access.
- Reassess the threat model and provider attestations annually.

## Key-Loss Warning

Encryption keys are part of the recovery system. Losing the only private backup
identity or managed encryption key permanently destroys access to the data.
Maintain controlled, tested, offline recovery copies with documented custody.
