# oast.sh Pre-Launch Infra Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Everything needed to run oast.sh on AWS: a FrankenPHP container image, an OpenTofu estate (ECS Fargate + cloudflared tunnel sidecar, ECR, IAM/OIDC, Secrets, SES identity + contact list, Cloudflare DNS/tunnel), and GitHub Actions for test-gated deploys.

**Architecture:** No ALB and no public ingress — a cloudflared sidecar in the Fargate task carries oast.sh in via Cloudflare Tunnel. The image is stateless: publications JSON and built assets are baked at build time; SES is the only AWS API the task role can call. GHA assumes an OIDC role (no long-lived keys): test gate → build/push ECR → force new deployment. `tofu apply` stays manual.

**Tech Stack:** OpenTofu ≥1.8 (AWS provider ~>6.0, Cloudflare provider ~>5.0), Docker + FrankenPHP (php8.5), GitHub Actions, aws cli v2.

## Global Constraints

- Nothing account-specific committed: account ids, zone ids, tokens live in `*.auto.tfvars` (gitignored) / GH repo variables / Secrets Manager. Repo may go public (AGPL).
- Region us-east-1. All resource names prefixed `oast-`.
- Prod task env: `APP_ENV=production`, `APP_DEBUG=false`, `OAST_API_ENABLED=false`, `MAIL_MAILER=ses`, `LOG_CHANNEL=stderr`, `SESSION_DRIVER=cookie`, `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`, `DB_CONNECTION=sqlite`, `DB_DATABASE=/tmp/database.sqlite` (empty; nothing reads it with the API off). NO `OPENROUTER_API_KEY`.
- Secrets in the task definition come from Secrets Manager: `APP_KEY`, tunnel token.
- Health check: Laravel's `/up`.
- `tofu fmt -check` and `tofu validate` clean at every commit; `docker build` succeeds locally.
- Apply is MANUAL and gated by Hunter — no workflow applies automatically.

---

### Task 1: Dockerfile (FrankenPHP, multi-stage) + .dockerignore

**Files:**
- Create: `Dockerfile`, `.dockerignore`
- Modify: none

**Interfaces:**
- Produces: image serving the Laravel app on **port 8080** (plain HTTP; Cloudflare terminates TLS at the edge, the tunnel carries it to localhost), entry `frankenphp php-server`. Later tasks reference container name `app`, port 8080, health `/up`.

- [ ] **Step 1: Write the files**

```dockerfile
# Dockerfile
# --- assets: build Tailwind/Vite bundle with Bun + Vite Plus ---
FROM oven/bun:1 AS assets
WORKDIR /build
COPY package.json bun.lock .npmrc vite.config.js ./
RUN bun install --frozen-lockfile
COPY resources ./resources
RUN bun run vp build

# --- vendor: production composer deps ---
FROM composer:2 AS vendor
WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --optimize --no-dev

# --- runtime ---
FROM dunglas/frankenphp:1-php8.5 AS runtime
WORKDIR /app
COPY --from=vendor /build /app
COPY --from=assets /build/public/build /app/public/build
RUN php artisan config:clear \
 && mkdir -p storage/framework/{cache,sessions,views} \
 && chown -R www-data:www-data storage bootstrap/cache
ENV SERVER_NAME=:8080
EXPOSE 8080
USER www-data
ENTRYPOINT ["frankenphp", "php-server", "--root", "/app/public", "--listen", ":8080"]
```

```
# .dockerignore
.git
.github
node_modules
vendor
tests
storage/logs/*
database/database.sqlite
.env*
infra
docs
fixtures
.superpowers
```

Note: `database/publications/*.json` is NOT ignored — publications bake into the image. If `bun run vp build` isn't a defined script, use `bunx vp build` (check `package.json` scripts and use whichever exists — record the choice in the report).

- [ ] **Step 2: Verify locally**

```bash
docker build -t oast-server:dev .
docker run --rm -d -p 8080:8080 -e APP_KEY=base64:$(openssl rand -base64 32) -e OAST_API_ENABLED=false --name oast-dev oast-server:dev
sleep 3 && curl -fsS http://localhost:8080/up && curl -fsS http://localhost:8080/ | head -c 200
docker rm -f oast-dev
```

Expected: `/up` returns 200; `/` returns the homepage HTML.

- [ ] **Step 3: Commit** — `git add Dockerfile .dockerignore && git commit -m "feat: Add FrankenPHP production image"`

---

### Task 2: OpenTofu bootstrap (state backend) + root module skeleton

**Files:**
- Create: `infra/bootstrap/main.tf`, `infra/main.tf`, `infra/variables.tf`, `infra/providers.tf`, `infra/outputs.tf`, `infra/.gitignore`

**Interfaces:**
- Produces: S3 state bucket + DynamoDB lock table (bootstrap, local state); root module with aws + cloudflare providers and the variable set every later task uses: `aws_region` (default `us-east-1`), `app_name` (default `oast`), `domain` (default `oast.sh`), `cloudflare_account_id` (string, no default), `cloudflare_zone_id` (string, no default), `github_repository` (default `hskrasek/oast-server`).

- [ ] **Step 1: Write bootstrap**

```hcl
# infra/bootstrap/main.tf
terraform {
  required_providers {
    aws = { source = "hashicorp/aws", version = "~> 6.0" }
  }
}

provider "aws" {
  region = "us-east-1"
}

resource "aws_s3_bucket" "state" {
  bucket = "oast-tofu-state"
}

resource "aws_s3_bucket_versioning" "state" {
  bucket = aws_s3_bucket.state.id
  versioning_configuration { status = "Enabled" }
}

resource "aws_s3_bucket_public_access_block" "state" {
  bucket                  = aws_s3_bucket.state.id
  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_dynamodb_table" "lock" {
  name         = "oast-tofu-lock"
  billing_mode = "PAY_PER_REQUEST"
  hash_key     = "LockID"
  attribute {
    name = "LockID"
    type = "S"
  }
}
```

- [ ] **Step 2: Write root skeleton**

```hcl
# infra/providers.tf
terraform {
  required_version = ">= 1.8"
  backend "s3" {
    bucket         = "oast-tofu-state"
    key            = "prod/terraform.tfstate"
    region         = "us-east-1"
    dynamodb_table = "oast-tofu-lock"
  }
  required_providers {
    aws        = { source = "hashicorp/aws", version = "~> 6.0" }
    cloudflare = { source = "cloudflare/cloudflare", version = "~> 5.0" }
    random     = { source = "hashicorp/random", version = "~> 3.6" }
  }
}

provider "aws" {
  region = var.aws_region
}

provider "cloudflare" {} # CLOUDFLARE_API_TOKEN from env
```

```hcl
# infra/variables.tf
variable "aws_region" {
  type    = string
  default = "us-east-1"
}

variable "app_name" {
  type    = string
  default = "oast"
}

variable "domain" {
  type    = string
  default = "oast.sh"
}

variable "cloudflare_account_id" {
  type = string
}

variable "cloudflare_zone_id" {
  type = string
}

variable "github_repository" {
  type    = string
  default = "hskrasek/oast-server"
}
```

`infra/main.tf`: just a comment header for now (resources arrive in Tasks 3-4). `infra/outputs.tf`: empty placeholder comment. `infra/.gitignore`:

```
*.tfvars
.terraform/
*.tfstate*
```

- [ ] **Step 3: Validate** — `cd infra/bootstrap && tofu init -backend=false && tofu validate && tofu fmt -check` then same in `infra/` (root `init -backend=false` since the bucket may not exist yet). Expected: both valid.
- [ ] **Step 4: Commit** — `git add infra && git commit -m "feat: Add tofu bootstrap and root module skeleton"`

---

### Task 3: ECR, IAM (task roles + GHA OIDC), Secrets Manager

**Files:**
- Create: `infra/ecr.tf`, `infra/iam.tf`, `infra/secrets.tf`; extend `infra/outputs.tf`

**Interfaces:**
- Produces (consumed by Task 4/5): `aws_ecr_repository.app` (name `oast-server`), `aws_iam_role.task_execution`, `aws_iam_role.task` (SES-scoped), `aws_iam_role.github_deploy` (OIDC, outputs `github_deploy_role_arn`), `aws_secretsmanager_secret.app_key` (name `oast/app-key`), `aws_secretsmanager_secret.tunnel_token` (name `oast/tunnel-token`).

- [ ] **Step 1: Write resources**

```hcl
# infra/ecr.tf
resource "aws_ecr_repository" "app" {
  name                 = "oast-server"
  image_tag_mutability = "MUTABLE"
  force_delete         = true
}

resource "aws_ecr_lifecycle_policy" "app" {
  repository = aws_ecr_repository.app.name
  policy = jsonencode({
    rules = [{
      rulePriority = 1
      description  = "keep last 10"
      selection    = { tagStatus = "any", countType = "imageCountMoreThan", countNumber = 10 }
      action       = { type = "expire" }
    }]
  })
}
```

```hcl
# infra/secrets.tf
resource "aws_secretsmanager_secret" "app_key" {
  name = "oast/app-key"
}

resource "aws_secretsmanager_secret" "tunnel_token" {
  name = "oast/tunnel-token"
}

resource "aws_secretsmanager_secret_version" "tunnel_token" {
  secret_id     = aws_secretsmanager_secret.tunnel_token.id
  secret_string = cloudflare_zero_trust_tunnel_cloudflared.oast.tunnel_token
}
# APP_KEY version is set manually once:
#   aws secretsmanager put-secret-value --secret-id oast/app-key --secret-string "base64:..."
```

```hcl
# infra/iam.tf
data "aws_caller_identity" "current" {}

resource "aws_iam_role" "task_execution" {
  name = "oast-task-execution"
  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{ Effect = "Allow", Principal = { Service = "ecs-tasks.amazonaws.com" }, Action = "sts:AssumeRole" }]
  })
}

resource "aws_iam_role_policy_attachment" "task_execution" {
  role       = aws_iam_role.task_execution.name
  policy_arn = "arn:aws:iam::aws:policy/service-role/AmazonECSTaskExecutionRolePolicy"
}

resource "aws_iam_role_policy" "task_execution_secrets" {
  name = "read-oast-secrets"
  role = aws_iam_role.task_execution.id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect   = "Allow"
      Action   = ["secretsmanager:GetSecretValue"]
      Resource = [aws_secretsmanager_secret.app_key.arn, aws_secretsmanager_secret.tunnel_token.arn]
    }]
  })
}

resource "aws_iam_role" "task" {
  name = "oast-task"
  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{ Effect = "Allow", Principal = { Service = "ecs-tasks.amazonaws.com" }, Action = "sts:AssumeRole" }]
  })
}

resource "aws_iam_role_policy" "task_ses" {
  name = "ses-contacts-and-send"
  role = aws_iam_role.task.id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Effect   = "Allow"
        Action   = ["sesv2:CreateContact", "sesv2:UpdateContact", "sesv2:GetContact"]
        Resource = aws_sesv2_contact_list.launch.arn
      },
      {
        Effect   = "Allow"
        Action   = ["ses:SendEmail", "ses:SendRawEmail"]
        Resource = "*"
        Condition = { StringEquals = { "ses:FromAddress" = "hello@${var.domain}" } }
      }
    ]
  })
}

resource "aws_iam_openid_connect_provider" "github" {
  url             = "https://token.actions.githubusercontent.com"
  client_id_list  = ["sts.amazonaws.com"]
  thumbprint_list = ["6938fd4d98bab03faadb97b34396831e3780aea1"]
}

resource "aws_iam_role" "github_deploy" {
  name = "oast-github-deploy"
  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect    = "Allow"
      Principal = { Federated = aws_iam_openid_connect_provider.github.arn }
      Action    = "sts:AssumeRoleWithWebIdentity"
      Condition = {
        StringEquals = { "token.actions.githubusercontent.com:aud" = "sts.amazonaws.com" }
        StringLike   = { "token.actions.githubusercontent.com:sub" = "repo:${var.github_repository}:ref:refs/heads/main" }
      }
    }]
  })
}

resource "aws_iam_role_policy" "github_deploy" {
  name = "deploy"
  role = aws_iam_role.github_deploy.id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      { Effect = "Allow", Action = ["ecr:GetAuthorizationToken"], Resource = "*" },
      {
        Effect   = "Allow"
        Action   = ["ecr:BatchCheckLayerAvailability", "ecr:PutImage", "ecr:InitiateLayerUpload", "ecr:UploadLayerPart", "ecr:CompleteLayerUpload", "ecr:BatchGetImage", "ecr:GetDownloadUrlForLayer"]
        Resource = aws_ecr_repository.app.arn
      },
      { Effect = "Allow", Action = ["ecs:UpdateService", "ecs:DescribeServices"], Resource = aws_ecs_service.app.id },
    ]
  })
}
```

Add to `infra/outputs.tf`:

```hcl
output "github_deploy_role_arn" {
  value = aws_iam_role.github_deploy.arn
}

output "ecr_repository_url" {
  value = aws_ecr_repository.app.repository_url
}
```

- [ ] **Step 2: Validate** — `cd infra && tofu init -backend=false && tofu validate` — note: references to `aws_sesv2_contact_list.launch`, `cloudflare_zero_trust_tunnel_cloudflared.oast`, and `aws_ecs_service.app` will not validate until Task 4 lands. If validating standalone, expect *undeclared resource* errors listing exactly those three — acceptable at this commit, or land Tasks 3+4 in one PR-sized commit pair and validate at Task 4. State the choice in the report.
- [ ] **Step 3: Commit** — `git add infra && git commit -m "feat: Add ECR, IAM roles, and secrets"`

---

### Task 4: SES, Cloudflare tunnel + DNS, ECS cluster/task/service

**Files:**
- Create: `infra/ses.tf`, `infra/cloudflare.tf`, `infra/ecs.tf`; extend `infra/outputs.tf`

**Interfaces:**
- Consumes: Task 3 roles/secrets/ECR.
- Produces: `aws_sesv2_contact_list.launch` (name `oast-launch`), `aws_sesv2_email_identity.domain`, `cloudflare_zero_trust_tunnel_cloudflared.oast`, ECS `oast` cluster / `oast-app` task family / `aws_ecs_service.app`.

- [ ] **Step 1: Write resources**

```hcl
# infra/ses.tf
resource "aws_sesv2_email_identity" "domain" {
  email_identity = var.domain
}

resource "aws_sesv2_contact_list" "launch" {
  contact_list_name = "oast-launch"
  description       = "oast.sh launch notification list"
}

resource "cloudflare_dns_record" "ses_dkim" {
  count   = 3
  zone_id = var.cloudflare_zone_id
  name    = "${aws_sesv2_email_identity.domain.dkim_signing_attributes[0].tokens[count.index]}._domainkey"
  type    = "CNAME"
  content = "${aws_sesv2_email_identity.domain.dkim_signing_attributes[0].tokens[count.index]}.dkim.amazonses.com"
  ttl     = 1
  proxied = false
}
```

```hcl
# infra/cloudflare.tf
resource "random_password" "tunnel_secret" {
  length  = 64
  special = false
}

resource "cloudflare_zero_trust_tunnel_cloudflared" "oast" {
  account_id    = var.cloudflare_account_id
  name          = "oast-prod"
  config_src    = "cloudflare"
  tunnel_secret = base64encode(random_password.tunnel_secret.result)
}

resource "cloudflare_zero_trust_tunnel_cloudflared_config" "oast" {
  account_id = var.cloudflare_account_id
  tunnel_id  = cloudflare_zero_trust_tunnel_cloudflared.oast.id
  config = {
    ingress = [
      { hostname = var.domain, service = "http://localhost:8080" },
      { service = "http_status:404" },
    ]
  }
}

resource "cloudflare_dns_record" "root" {
  zone_id = var.cloudflare_zone_id
  name    = "@"
  type    = "CNAME"
  content = "${cloudflare_zero_trust_tunnel_cloudflared.oast.id}.cfargotunnel.com"
  ttl     = 1
  proxied = true
}
```

(Provider v5 attribute names shift between minors — if `tunnel_token`/`tunnel_secret`/`config` blocks fail validate, consult `tofu providers schema -json` and adjust names, recording the delta in the report. The tunnel token for the sidecar is exposed as an attribute on the tunnel resource; Task 3's secret version references it.)

```hcl
# infra/ecs.tf
data "aws_vpc" "default" {
  default = true
}

data "aws_subnets" "default" {
  filter {
    name   = "vpc-id"
    values = [data.aws_vpc.default.id]
  }
}

resource "aws_security_group" "app" {
  name   = "oast-app"
  vpc_id = data.aws_vpc.default.id
  egress {
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }
  # no ingress rules at all — the tunnel dials out
}

resource "aws_cloudwatch_log_group" "app" {
  name              = "/ecs/oast-app"
  retention_in_days = 30
}

resource "aws_ecs_cluster" "oast" {
  name = "oast"
}

resource "aws_ecs_task_definition" "app" {
  family                   = "oast-app"
  requires_compatibilities = ["FARGATE"]
  network_mode             = "awsvpc"
  cpu                      = "256"
  memory                   = "512"
  execution_role_arn       = aws_iam_role.task_execution.arn
  task_role_arn            = aws_iam_role.task.arn

  container_definitions = jsonencode([
    {
      name      = "app"
      image     = "${aws_ecr_repository.app.repository_url}:latest"
      essential = true
      portMappings = [{ containerPort = 8080, protocol = "tcp" }]
      environment = [
        { name = "APP_ENV", value = "production" },
        { name = "APP_DEBUG", value = "false" },
        { name = "APP_URL", value = "https://${var.domain}" },
        { name = "OAST_API_ENABLED", value = "false" },
        { name = "MAIL_MAILER", value = "ses" },
        { name = "MAIL_FROM_ADDRESS", value = "hello@${var.domain}" },
        { name = "MAIL_FROM_NAME", value = "oast" },
        { name = "LOG_CHANNEL", value = "stderr" },
        { name = "SESSION_DRIVER", value = "cookie" },
        { name = "CACHE_STORE", value = "array" },
        { name = "QUEUE_CONNECTION", value = "sync" },
        { name = "DB_CONNECTION", value = "sqlite" },
        { name = "DB_DATABASE", value = "/tmp/database.sqlite" },
        { name = "AWS_DEFAULT_REGION", value = var.aws_region },
        { name = "OAST_SES_CONTACT_LIST", value = aws_sesv2_contact_list.launch.contact_list_name },
      ]
      secrets = [
        { name = "APP_KEY", valueFrom = aws_secretsmanager_secret.app_key.arn },
      ]
      healthCheck = {
        command  = ["CMD-SHELL", "curl -fsS http://localhost:8080/up || exit 1"]
        interval = 30
        timeout  = 5
        retries  = 3
      }
      logConfiguration = {
        logDriver = "awslogs"
        options = {
          awslogs-group         = aws_cloudwatch_log_group.app.name
          awslogs-region        = var.aws_region
          awslogs-stream-prefix = "app"
        }
      }
    },
    {
      name      = "cloudflared"
      image     = "cloudflare/cloudflared:latest"
      essential = true
      command   = ["tunnel", "--no-autoupdate", "run"]
      secrets = [
        { name = "TUNNEL_TOKEN", valueFrom = aws_secretsmanager_secret.tunnel_token.arn },
      ]
      logConfiguration = {
        logDriver = "awslogs"
        options = {
          awslogs-group         = aws_cloudwatch_log_group.app.name
          awslogs-region        = var.aws_region
          awslogs-stream-prefix = "cloudflared"
        }
      }
    }
  ])
}

resource "aws_ecs_service" "app" {
  name            = "oast-app"
  cluster         = aws_ecs_cluster.oast.id
  task_definition = aws_ecs_task_definition.app.arn
  desired_count   = 1
  launch_type     = "FARGATE"

  network_configuration {
    subnets          = data.aws_subnets.default.ids
    security_groups  = [aws_security_group.app.id]
    assign_public_ip = true # egress only; SG has zero ingress rules
  }
}
```

Add outputs:

```hcl
output "ses_dkim_tokens" {
  value = aws_sesv2_email_identity.domain.dkim_signing_attributes[0].tokens
}

output "cluster_name" {
  value = aws_ecs_cluster.oast.name
}

output "service_name" {
  value = aws_ecs_service.app.name
}
```

- [ ] **Step 2: Validate** — `cd infra && tofu init -backend=false && tofu validate && tofu fmt -check` — now the whole module must validate clean (Task 3's forward references resolve).
- [ ] **Step 3: Commit** — `git add infra && git commit -m "feat: Add SES, Cloudflare tunnel, and ECS service"`

---

### Task 5: GitHub Actions + deploy runbook

**Files:**
- Create: `.github/workflows/deploy.yml`, `.github/workflows/tofu-plan.yml`, `docs/deploy.md`

**Interfaces:**
- Consumes: repo **variables** `AWS_DEPLOY_ROLE_ARN`, `AWS_REGION` (`us-east-1`), `ECR_REPOSITORY` (from tofu outputs). No repo secrets needed for deploy (OIDC).

- [ ] **Step 1: Write workflows**

```yaml
# .github/workflows/deploy.yml
name: deploy
on:
  push:
    branches: [main]
permissions:
  id-token: write
  contents: read
concurrency: deploy
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.5'
          coverage: xdebug
      - uses: oven-sh/setup-bun@v2
      - run: bun install --frozen-lockfile
      - run: composer install --prefer-dist --no-interaction
      - run: composer test
  deploy:
    needs: test
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: aws-actions/configure-aws-credentials@v4
        with:
          role-to-assume: ${{ vars.AWS_DEPLOY_ROLE_ARN }}
          aws-region: ${{ vars.AWS_REGION }}
      - uses: aws-actions/amazon-ecr-login@v2
        id: ecr
      - run: |
          docker build -t "$REPO:$GITHUB_SHA" -t "$REPO:latest" .
          docker push "$REPO:$GITHUB_SHA"
          docker push "$REPO:latest"
        env:
          REPO: ${{ steps.ecr.outputs.registry }}/oast-server
      - run: aws ecs update-service --cluster oast --service oast-app --force-new-deployment
```

```yaml
# .github/workflows/tofu-plan.yml
name: tofu-plan
on:
  pull_request:
    paths: ['infra/**']
permissions:
  contents: read
jobs:
  validate:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: opentofu/setup-opentofu@v1
      - run: tofu -chdir=infra fmt -check -recursive
      - run: tofu -chdir=infra init -backend=false
      - run: tofu -chdir=infra validate
```

(Plan-with-credentials intentionally omitted — validate/fmt only in CI; real `tofu plan/apply` run locally with Hunter's credentials, per the manual-apply constraint.)

- [ ] **Step 2: Write `docs/deploy.md`** — the runbook, verbatim sections:
  1. One-time: `cd infra/bootstrap && tofu init && tofu apply` (local AWS creds).
  2. One-time: create a Cloudflare API token (Zone DNS edit + Account Cloudflare Tunnel edit), `export CLOUDFLARE_API_TOKEN=…`; write `infra/prod.auto.tfvars` with `cloudflare_account_id` + `cloudflare_zone_id`.
  3. `cd infra && tofu init && tofu apply` → note outputs (`github_deploy_role_arn`, `ecr_repository_url`, `ses_dkim_tokens`).
  4. One-time: `aws secretsmanager put-secret-value --secret-id oast/app-key --secret-string "base64:$(openssl rand -base64 32)"`.
  5. One-time: push a first image manually (deploy workflow needs one to exist): `docker build`, `docker tag`, ECR login, push `:latest`, then `aws ecs update-service --force-new-deployment`.
  6. One-time: SES production access request (console → SES → Account dashboard → Request production access) — until granted, confirmation emails only deliver to verified addresses.
  7. GitHub repo variables: `AWS_DEPLOY_ROLE_ARN`, `AWS_REGION`, `ECR_REPOSITORY`.
  8. Steady state: merge to main → GHA tests → image → deploy. Publications changes are code, so publishing a review deploys like any commit.
  9. Rollback: `aws ecs update-service` pinning the previous task definition revision, or revert the commit.

- [ ] **Step 3: Validate** — `bunx yaml-lint .github/workflows/*.yml` or `python3 -c "import yaml,glob; [yaml.safe_load(open(f)) for f in glob.glob('.github/workflows/*.yml')]"`; re-run `tofu -chdir=infra fmt -check`.
- [ ] **Step 4: Commit** — `git add .github docs/deploy.md && git commit -m "feat: Add deploy pipeline and runbook"`

---

## Self-review notes (applied)

- Task 3 forward-references Task 4 resources (SES list ARN, tunnel token, ECS service ARN in IAM policies); the validate-at-Task-4 note makes the ordering explicit rather than reshuffling the files people expect to find things in.
- Cloudflare provider v5 resource/attribute naming is the flagged risk; the plan pins the intent (remote-managed config, token-run sidecar) and instructs schema-check adjustment with a recorded delta rather than guessing silently.
- `curl` must exist in the FrankenPHP image for the ECS health check — if absent, switch the health check to `["CMD-SHELL", "php -r 'exit(0);'"]`-style or install curl in the runtime stage (`apt-get update && apt-get install -y curl`); record which.
