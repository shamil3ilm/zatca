# TaxFly Deployment Guide for Masaar

This document provides TaxFly's DevOps team with everything needed to deploy and operate Masaar on TaxFly infrastructure.

## Deployment Model: TaxFly Hosts Everything

In this partnership model, **TaxFly provides 100% of the infrastructure**. Masaar provides:
- Docker image (pre-built or Dockerfile for building)
- Configuration templates
- Database migrations
- Technical support
- Software updates

### What TaxFly Provides

| Component | Specification | Notes |
|-----------|---------------|-------|
| **Compute** | 2+ vCPU, 4GB+ RAM | Per container instance |
| **Database** | MySQL 8.0+ | Managed service recommended |
| **Cache/Queue** | Redis 7+ | ElastiCache or equivalent |
| **Storage** | 50GB+ SSD | For logs and file storage |
| **Network** | Internal VPC | Isolated from public |
| **Load Balancer** | HTTPS termination | With valid SSL certificate |
| **DNS** | Subdomain | e.g., `api.masaar.taxfly.sa` |
| **Monitoring** | Health checks | `/api/health` endpoint |
| **Backup** | Daily automated | Database + Redis |

### What Masaar Provides

| Deliverable | Format | Description |
|-------------|--------|-------------|
| **Docker Image** | OCI image | Pre-built, versioned releases |
| **Dockerfile** | Source | For custom builds if needed |
| **docker-compose.yml** | Configuration | Complete stack definition |
| **Environment Template** | `.env.template` | All required variables |
| **Database Migrations** | Laravel migrations | Automated via artisan |
| **API Documentation** | OpenAPI 3.0 | Full endpoint reference |
| **Support** | Email/Slack | 24-hour response SLA |

---

## Quick Start Deployment

### Prerequisites

- Docker 24+ and Docker Compose v2
- Access to Masaar Docker registry (credentials provided)
- MySQL 8.0 database (empty, will be migrated)
- Redis 7+ instance
- Domain with SSL certificate

### Step 1: Pull the Docker Image

```bash
# Login to Masaar registry (credentials provided separately)
docker login registry.masaar.io

# Pull the latest stable image
docker pull registry.masaar.io/masaar/zatca-api:latest

# Or a specific version
docker pull registry.masaar.io/masaar/zatca-api:1.0.0
```

### Step 2: Configure Environment

```bash
# Create deployment directory
mkdir -p /opt/masaar
cd /opt/masaar

# Copy environment template
cp docker/.env.template .env

# Edit configuration
nano .env
```

**Required Variables:**

```env
# Generate with: docker run --rm masaar/zatca-api php artisan key:generate --show
APP_KEY=base64:YOUR_GENERATED_KEY_HERE

# Your domain
APP_URL=https://api.masaar.taxfly.sa

# Database (your MySQL server)
DB_HOST=your-mysql-host.taxfly.sa
DB_DATABASE=masaar
DB_USERNAME=masaar_user
DB_PASSWORD=SECURE_PASSWORD_HERE

# Redis (your Redis server)
REDIS_HOST=your-redis-host.taxfly.sa
REDIS_PASSWORD=REDIS_PASSWORD_HERE

# ZATCA (sandbox for testing, production for live)
ZATCA_ENVIRONMENT=sandbox
```

### Step 3: Deploy

```bash
# For simple deployment (without Traefik)
docker-compose up -d

# For production with SSL termination
docker-compose -f docker-compose.yml -f docker-compose.prod.yml up -d

# Run database migrations (first time only)
docker-compose exec app php artisan migrate --force
```

### Step 4: Verify Deployment

```bash
# Check container status
docker-compose ps

# Check application health
curl https://api.masaar.taxfly.sa/api/health

# Expected response:
# {"status":"ok","timestamp":"2026-02-03T12:00:00Z"}

# Check logs
docker-compose logs -f app
```

---

## Architecture Options

### Option A: TaxFly Managed Services (Recommended)

```
┌─────────────────────────────────────────────────────────────────┐
│                    TaxFly Saudi Arabia VPC                       │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ┌──────────────┐     ┌──────────────┐     ┌──────────────┐    │
│  │   TaxFly     │     │  Masaar   │     │  Masaar   │    │
│  │   Load       │────▶│  Container   │     │  Container   │    │
│  │   Balancer   │     │  (Replica 1) │     │  (Replica 2) │    │
│  │   (HTTPS)    │────▶│              │     │              │    │
│  └──────────────┘     └──────┬───────┘     └──────┬───────┘    │
│                              │                     │            │
│                              ▼                     ▼            │
│  ┌───────────────────────────────────────────────────────────┐ │
│  │                     TaxFly MySQL RDS                       │ │
│  │               (Multi-AZ, Automated Backups)                │ │
│  └───────────────────────────────────────────────────────────┘ │
│                              │                                  │
│  ┌───────────────────────────────────────────────────────────┐ │
│  │                    TaxFly ElastiCache                      │ │
│  │                        (Redis)                             │ │
│  └───────────────────────────────────────────────────────────┘ │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

**Benefits:**
- Automatic failover for database
- Managed backups and maintenance
- Scales with TaxFly's existing infrastructure
- Minimal operational overhead

### Option B: Self-Contained Docker Stack

If TaxFly prefers to run everything in Docker:

```bash
# Uses built-in MySQL and Redis containers
docker-compose up -d

# All services run in isolated network
# - app: Masaar application
# - db: MySQL 8.0
# - redis: Redis 7
```

**Benefits:**
- Completely isolated from TaxFly systems
- Easy to migrate or replicate
- Single deployment unit

---

## Operational Procedures

### Daily Operations

| Task | Command | Frequency |
|------|---------|-----------|
| Health check | `curl /api/health` | Every 30s (automated) |
| Log review | `docker-compose logs --tail=100 app` | As needed |
| Queue status | `docker-compose exec app php artisan queue:monitor` | Daily |

### Updates and Maintenance

#### Deploying New Version

```bash
# 1. Pull new image
docker pull registry.masaar.io/masaar/zatca-api:1.1.0

# 2. Update docker-compose.yml IMAGE_TAG
IMAGE_TAG=1.1.0

# 3. Rolling update (zero downtime)
docker-compose up -d --no-deps app

# 4. Run any new migrations
docker-compose exec app php artisan migrate --force

# 5. Verify
curl https://api.masaar.taxfly.sa/api/health
```

#### Rollback Procedure

```bash
# Revert to previous image
docker-compose down
IMAGE_TAG=1.0.0 docker-compose up -d

# Note: Database migrations may need manual rollback
# Coordinate with Masaar support before rolling back
```

### Backup Procedures

#### Database Backup

```bash
# Manual backup
docker-compose exec db mysqldump -u root -p masaar > backup_$(date +%Y%m%d).sql

# Restore from backup
docker-compose exec -T db mysql -u root -p masaar < backup_20260203.sql
```

#### Redis Backup

```bash
# Redis automatically persists to appendonly.aof
# Backup the volume:
docker run --rm -v masaar-redis:/data -v $(pwd):/backup alpine tar czf /backup/redis_backup.tar.gz /data
```

### Monitoring Endpoints

| Endpoint | Purpose | Expected Response |
|----------|---------|-------------------|
| `GET /api/health` | Application health | `{"status":"ok"}` |
| `GET /api/health/db` | Database connectivity | `{"status":"ok","latency_ms":5}` |
| `GET /api/health/redis` | Redis connectivity | `{"status":"ok"}` |
| `GET /api/health/zatca` | ZATCA API reachability | `{"status":"ok","environment":"sandbox"}` |

---

## Security Configuration

### Network Security

```yaml
# docker-compose security additions
services:
  app:
    security_opt:
      - no-new-privileges:true
    read_only: true
    tmpfs:
      - /tmp
      - /var/run
    cap_drop:
      - ALL
```

### Environment Variables Security

1. **Never commit `.env` files to version control**
2. **Use secrets management** (AWS Secrets Manager, HashiCorp Vault)
3. **Rotate credentials** quarterly
4. **Restrict file permissions**: `chmod 600 .env`

### Database Security

```sql
-- Create dedicated user with minimal privileges
CREATE USER 'masaar'@'%' IDENTIFIED BY 'secure_password';
GRANT SELECT, INSERT, UPDATE, DELETE ON masaar.* TO 'masaar'@'%';
GRANT CREATE, ALTER, INDEX ON masaar.* TO 'masaar'@'%';  -- For migrations
FLUSH PRIVILEGES;
```

---

## Troubleshooting

### Common Issues

#### Container Won't Start

```bash
# Check logs
docker-compose logs app

# Common causes:
# - Missing APP_KEY → Generate with php artisan key:generate
# - Database unreachable → Check DB_HOST and credentials
# - Port already in use → Change APP_PORT in .env
```

#### Database Connection Failed

```bash
# Test database connectivity
docker-compose exec app php artisan db:monitor

# Check from container
docker-compose exec app ping db

# Verify credentials
docker-compose exec db mysql -u masaar -p -e "SELECT 1"
```

#### Queue Jobs Not Processing

```bash
# Check queue worker status
docker-compose exec app php artisan queue:work --once

# Restart queue workers
docker-compose exec app supervisorctl restart all

# Check failed jobs
docker-compose exec app php artisan queue:failed
```

#### ZATCA API Errors

```bash
# Test ZATCA connectivity. There is no artisan command for this — the check
# lives in the Connectivity service, and the admin dashboard exposes it at
# GET /api/admin/dashboard/connectivity.
docker-compose exec app php artisan tinker
>>> app(App\Domains\Compliance\Fatoora\Services\Connectivity::class)->getDetailedStatus()

# Check environment. The config file is fatoora.php, not zatca.php, so
# config('zatca.environment') returns null rather than an answer.
docker-compose exec app php artisan tinker
>>> config('fatoora.environment')
```

### Log Locations

| Log | Location | Description |
|-----|----------|-------------|
| Application | `storage/logs/laravel.log` | Main application log |
| Queue Workers | `storage/logs/worker-*.log` | Background job logs |
| Nginx | `/var/log/nginx/` | Web server access/error logs |
| Supervisor | `/var/log/supervisor/` | Process manager logs |

---

## Support Contact

### For Technical Issues

- **Email**: support@masaar.io
- **Slack**: #masaar-taxfly (invite provided)
- **Response Time**: 24 hours (business days)

### For Emergencies

- **Hotline**: Provided in partnership agreement
- **Response Time**: 4 hours

### Information to Include

When reporting issues, please provide:

1. Container logs: `docker-compose logs app > logs.txt`
2. Health check output: `curl /api/health`
3. Environment (sandbox/production)
4. Steps to reproduce
5. Expected vs actual behavior

---

## Version History

| Version | Date | Changes |
|---------|------|---------|
| 1.0.0 | 2026-02-03 | Initial deployment guide |

---

**Document Owner**: Masaar Team
**Last Updated**: February 3, 2026
