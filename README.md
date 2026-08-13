# SCS Manager Integration (`scs_manager_integration`)

Nextcloud app that bridges **SCS Manager** with Team Folders (Group Folders):

- Register project Team Folders with a **stable `machineName`** (Keycloak project group ID) and an editable **display label** (mount point, e.g. `Projekt 1`)
- Attach Nextcloud groups (auto-created if missing) for ACL
- Expose an OCS API for listing folders and reading activity under those mounts
- Soft UI protection for managed folder roots

## Requirements

- Nextcloud 30–34
- Apps: `groupfolders`, `activity` (for feeds)
- Admin credentials or OIDC Bearer for Manager orchestration

## OCS API

Base: `/ocs/v2.php/apps/scs_manager_integration/api/v1`

| Method | Path | Auth | Role |
|--------|------|------|------|
| GET | `/folders` | user | List folders the user can access (admins see all) |
| POST | `/folders` | **admin** | Create/register Team Folder |
| GET | `/folders/{id}` | user | Details (ACL-gated) |
| PATCH | `/folders/{id}` | **admin** | Update label and/or groups (`machineName` immutable) |
| DELETE | `/folders/{id}` | **admin** | Deregister; `?deleteStorage=1` removes Team Folder |
| GET | `/folders/{id}/feed` | user | Activity items (`limit`, `since`) |

Feed items include `subject` (formatted sentence), `subjectKey` (raw activity
subject, e.g. `created_self`), `type`, `timestamp`, and `object`.

### Create body

```json
{
  "name": "Projekt 1",
  "label": "Projekt 1",
  "machineName": "10001",
  "externalProjectId": "42",
  "groups": ["10001"],
  "createGroupFolder": true
}
```

- `name` or `label`: visible mount point in Files
- `machineName`: stable id (defaults to `externalProjectId` if omitted)
- `externalProjectId`: Drupal project id (unique)

### Update body (PATCH)

```json
{
  "label": "My research project",
  "groups": ["10001"]
}
```

## Platform share

```bash
occ scs_manager_integration:ensure-platform-share --admin=admin -g admin -g keycloak-scs_user
```

Creates/registers Team Folder `SCS-Share` with `externalProjectId=scs-platform-share` and `machineName=scs-platform-share`.

### Migrate private Skeleton SCS-Share (existing users)

```bash
occ scs_manager_integration:migrate-private-scs-share --dry-run --all
occ scs_manager_integration:migrate-private-scs-share --all
```

Moves private home `SCS-Share` contents into the Team Folder under
`SCS-Share/<uid>/`, then renames the private folder to
`SCS-Share-legacy-YYYYMMDD` (never deletes). See the deployment plan
`plans/06-scs-share-private-to-teamfolder.md`.

## Deploy

1. Volume-mount this directory to `/var/www/html/custom_apps/scs_manager_integration`.
2. `occ app:enable scs_manager_integration`
3. Optionally run `occ scs_manager_integration:ensure-platform-share`.

If migrating from the former `scs_project_feeds` app: disable/remove the old app, enable this one, and re-run the platform-share command.

If OCS `GET /folders` returns **500** and Nextcloud logs
`Class "OCA\Scs_manager_integration\Controller\ApiController" does not exist`,
the bind mount is stale (often `findmnt` shows `//deleted` after the host
directory was replaced). Recreate the FPM container from the deployment root:

```bash
docker compose up -d --force-recreate nextcloud--nextcloud
```

Confirm `lib/` is visible in the container and `occ list scs_manager_integration` loads.
