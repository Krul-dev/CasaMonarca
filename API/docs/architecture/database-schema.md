# Database Schema Graph

Source: Laravel migrations in `database/migrations`.

Regenerated: 2026-06-11.

Open `docs/architecture/database-schema.mmd` in any Mermaid renderer, or view this Markdown in a Mermaid-enabled viewer.

```mermaid
erDiagram
    users {
        bigint id PK
        string name
        string email UK
        string role
        string status
        timestamp email_verified_at
        string password
        boolean two_factor_enabled
        text two_factor_secret
        timestamp suspended_at
        bigint suspended_by_user_id FK
        text suspension_reason
        timestamp last_sign_in_at
        string remember_token
        timestamp created_at
        timestamp updated_at
    }

    password_reset_tokens {
        string email PK
        string token
        timestamp created_at
    }

    sessions {
        string id PK
        bigint user_id
        string ip_address
        text user_agent
        longtext payload
        integer last_activity
    }

    webauthn_credentials {
        bigint id PK
        bigint user_id FK
        string credential_id UK
        longtext public_key
        integer public_key_algorithm
        string name
        bigint sign_count
        json transports
        longtext attestation_object
        longtext client_data_json
        timestamp last_used_at
        timestamp created_at
        timestamp updated_at
    }

    user_devices {
        bigint id PK
        bigint user_id FK
        string device_identifier_hash
        string alias
        text user_agent
        ip last_ip_address
        timestamp first_seen_at
        timestamp last_seen_at
        timestamp last_login_at
        timestamp trusted_at
        timestamp revoked_at
        timestamp created_at
        timestamp updated_at
    }

    documents {
        bigint id PK
        string title
        string status
        string confidentiality
        bigint owner_user_id FK
        bigint uploaded_by_user_id FK
        bigint current_revision_id FK
        timestamp approved_at
        bigint approved_by_user_id FK
        text approval_note
        boolean signature_order_enforced
        timestamp created_at
        timestamp updated_at
    }

    document_revisions {
        bigint id PK
        bigint document_id FK
        bigint parent_revision_id FK
        bigint created_by_user_id FK
        integer revision_number
        string storage_disk
        string storage_path
        string original_file_name
        string mime_type
        bigint size_bytes
        string sha256
        string signature_status
        json diff_metadata
        timestamp created_at
        timestamp updated_at
    }

    document_signatures {
        bigint id PK
        bigint document_revision_id FK
        bigint signed_by_user_id FK
        string signature_type
        string verification_status
        timestamp signed_at
        string signature_hash
        json metadata
        timestamp created_at
        timestamp updated_at
    }

    document_signature_requirements {
        bigint id PK
        bigint document_id FK
        integer sequence
        string signer_role
        bigint signer_user_id FK
        bigint fulfilled_by_signature_id FK
        timestamp fulfilled_at
        timestamp created_at
        timestamp updated_at
    }

    document_tombstones {
        bigint id PK
        bigint original_document_id
        string title
        bigint deleted_by_user_id FK
        timestamp deleted_at
        string last_sha256
        integer revision_count
        json metadata
    }

    audit_events {
        uuid id PK
        timestamp occurred_at
        bigint actor_user_id FK
        string actor_role
        string event_type
        string resource_type
        bigint resource_id
        bigint document_id
        bigint revision_id
        string outcome
        string request_id
        string ip_address
        text user_agent
        string session_id_hash
        json metadata
    }

    account_invites {
        bigint id PK
        string email
        string role
        bigint invited_by_user_id FK
        string token_hash UK
        timestamp expires_at
        timestamp verified_out_of_band_at
        bigint verified_out_of_band_by_user_id FK
        string verification_method
        text verification_note
        timestamp issued_at
        timestamp used_at
        timestamp revoked_at
        timestamp created_at
        timestamp updated_at
    }

    security_challenge_intents {
        uuid id PK
        string purpose
        string status
        bigint actor_user_id FK
        string target_type
        bigint target_id
        string challenge_hash
        json payload
        string origin
        string rp_id
        timestamp expires_at
        timestamp completed_at
        timestamp cancelled_at
        text failure_reason
        timestamp created_at
        timestamp updated_at
    }

    cache {
        string key PK
        mediumtext value
        bigint expiration
    }

    cache_locks {
        string key PK
        string owner
        bigint expiration
    }

    jobs {
        bigint id PK
        string queue
        longtext payload
        tinyint attempts
        integer reserved_at
        integer available_at
        integer created_at
    }

    job_batches {
        string id PK
        string name
        integer total_jobs
        integer pending_jobs
        integer failed_jobs
        longtext failed_job_ids
        mediumtext options
        integer cancelled_at
        integer created_at
        integer finished_at
    }

    failed_jobs {
        bigint id PK
        string uuid UK
        text connection
        text queue
        longtext payload
        longtext exception
        timestamp failed_at
    }

    users ||--o{ users : suspends
    users ||--o{ webauthn_credentials : has
    users ||--o{ user_devices : has
    users ||--o{ documents : owns
    users ||--o{ documents : uploads
    users ||--o{ documents : approves
    users ||--o{ document_revisions : creates
    users ||--o{ document_signatures : signs
    users ||--o{ document_signature_requirements : assigned
    users ||--o{ document_tombstones : deletes
    users ||--o{ audit_events : acts
    users ||--o{ account_invites : invites
    users ||--o{ account_invites : verifies
    users ||--o{ security_challenge_intents : initiates

    documents ||--o{ document_revisions : has
    documents ||--o{ document_signature_requirements : requires
    document_revisions ||--o{ document_revisions : parent
    document_revisions ||--o{ document_signatures : has
    document_revisions ||--o{ documents : current
    document_signatures ||--o| document_signature_requirements : fulfills
```

## Legacy Approval Columns

- The document approval columns and `document_signature_requirements` table remain for migration compatibility and historical data.
- The active workflow publishes new uploads directly to VCS and does not create admin approval or signature-requirement records.

## Notes

- `sessions.user_id` is indexed but does not have a declared foreign key in the Laravel migration.
- `audit_events.document_id`, `audit_events.revision_id`, `audit_events.resource_type`, and `audit_events.resource_id` are audit references, not declared foreign keys.
- `security_challenge_intents.target_type` and `target_id` are polymorphic target references, not declared foreign keys.
- `document_tombstones.original_document_id` preserves the deleted document id and is not declared as a foreign key.
- `document_signature_requirements.signer_role` stores role-based requirements; `signer_user_id` stores explicit user requirements. One or the other is expected for each configured requirement.
