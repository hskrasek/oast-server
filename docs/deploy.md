# Deploy runbook

1. One-time: `cd infra/bootstrap && tofu init && tofu apply` (local AWS creds).
2. One-time: create a Cloudflare API token (Zone DNS edit + Account Cloudflare Tunnel edit),
   `export CLOUDFLARE_API_TOKEN=…`; write `infra/prod.auto.tfvars` with `cloudflare_account_id` +
   `cloudflare_zone_id`.
3. `cd infra && tofu init && tofu apply` → note outputs (`github_deploy_role_arn`,
   `ecr_repository_url`, `ses_dkim_tokens`).
4. One-time: `aws secretsmanager put-secret-value --secret-id oast/app-key --secret-string "base64:$(openssl rand -base64 32)"`.
5. One-time: push a first image manually (deploy workflow needs one to exist): `docker build`,
   `docker tag`, ECR login, push `:latest`, then `aws ecs update-service --force-new-deployment`.
6. One-time: SES production access request (console → SES → Account dashboard → Request
   production access) — until granted, confirmation emails only deliver to verified addresses.
7. GitHub repo variables: `AWS_DEPLOY_ROLE_ARN`, `AWS_REGION`, `ECR_REPOSITORY`.
8. Steady state: merge to main → GHA tests → image → deploy. Publications changes are code, so
   publishing a review deploys like any commit.
9. Rollback: task definition revisions do **not** pin images in this setup (every revision's
   container image is `:latest`), so re-registering an old task-def revision does nothing — the
   image tag resolves to whatever `:latest` currently points at regardless of revision. Use one of:
   - **Instant** — re-point `:latest` at the last-good SHA already in ECR, then redeploy:
     ```
     MANIFEST=$(aws ecr batch-get-image --repository-name oast-server --image-ids imageTag=<GOOD_SHA> --output text --query 'images[0].imageManifest')
     aws ecr put-image --repository-name oast-server --image-tag latest --image-manifest "$MANIFEST"
     aws ecs update-service --cluster oast --service oast-app --force-new-deployment
     ```
   - **Clean** — `git revert` the bad commit and push to `main`; CI rebuilds and deploys under a
     new SHA (full pipeline latency).
