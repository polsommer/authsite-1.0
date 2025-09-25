# SWG Plus Command Center

## Overview
SWG Plus Command Center is a PHP web portal built for Star Wars Galaxies private server communities. It combines player account management, live infrastructure telemetry, and rich themed presentation to support onboarding and day-to-day operations for the SWG+ shard. The site renders a cinematic landing page that surfaces login/game/database status and live population data directly from your galaxy database, alongside navigation into account tools and community resources.【F:index.php†L1-L200】

## Key Features
- **Live service monitoring.** The home page pings configurable login, game, and database ports and queries online character counts so staff and players can see server health at a glance.【F:index.php†L11-L34】【F:index.php†L121-L163】
- **Hardened account lifecycle.** Registration, login, and profile flows enforce CSRF protection, password complexity, rate limiting, IP bans, and security event logging to defend against abuse while keeping the experience smooth.【F:includes/security.php†L4-L200】【F:newuserpost.php†L12-L133】
- **Email verification pipeline.** New accounts trigger signed verification links delivered through a configurable SMTP relay (with PHP `mail()` fallback) so only confirmed pilots can access the galaxy.【F:newuserpost.php†L117-L133】【F:includes/mailer.php†L4-L200】【F:verify_email.php†L11-L105】
- **Social graph tools.** Friendship helpers let players form alliances, review incoming/outgoing requests, and maintain their contact lists within the dashboard experience.【F:includes/friend_functions.php†L6-L167】
- **Configurable deployment.** Environment variables drive database credentials, SMTP details, server host/port checks, and rate-limiting thresholds, making it easy to adapt the portal to new infrastructure without editing PHP source.【F:includes/config.php†L4-L35】【F:includes/db_connect.php†L6-L12】【F:index.php†L11-L21】
- **Lore-friendly presentation.** Custom imagery, music hooks, and interface copy keep the UI grounded in the Star Wars universe while respecting Lucasfilm trademarks referenced in the legacy project notes.【F:index.php†L44-L200】【F:'!!!!README 1st!!!!'†L2-L32】

## Project Layout
| Path | Description |
| ---- | ----------- |
| `index.php` | Landing page with service status, hero content, and navigation shells.【F:index.php†L1-L200】
| `form_login.php`, `post_login.php` | Login form and handler that integrate CSRF checks and security logging.【F:form_login.php†L1-L105】【F:includes/security.php†L26-L66】
| `addnewuser.php`, `newuserpost.php` | Registration UI and processor with validation, throttling, and email verification.【F:addnewuser.php†L1-L123】【F:newuserpost.php†L12-L133】
| `verify_email.php` | Consumes verification tokens and activates accounts while recording audit events.【F:verify_email.php†L11-L105】
| `includes/` | Reusable services: configuration loader, database connector, security helpers, friend graph, mail transport, and status polling.【F:includes/config.php†L4-L35】【F:includes/db_connect.php†L6-L12】【F:includes/security.php†L4-L200】【F:includes/friend_functions.php†L6-L167】【F:includes/mailer.php†L4-L200】【F:includes/server_status.php†L1-L20】
| `create_database_table.sql` | Bootstrap schema for accounts, friendships, security auditing, and network bans.【F:create_database_table.sql†L1-L86】
| `composer.json` | Declares PHP dependencies (PHPMailer) required for email delivery.【F:composer.json†L1-L5】

## Getting Started
### Prerequisites
- PHP 8.1 or newer with `mysqli`, `openssl`, and `mbstring` extensions enabled (required by the security, database, and mailer helpers).【F:includes/security.php†L26-L125】【F:includes/db_connect.php†L6-L12】
- MySQL 5.7+/MariaDB equivalent for the authentication, friendship, and audit tables.【F:create_database_table.sql†L1-L86】
- Composer for dependency installation (PHPMailer transport helpers).【F:composer.json†L1-L5】
- A web server (Apache, nginx, or PHP built-in server) capable of serving PHP files.

### Installation Steps
1. Clone the repository onto your web host.
2. Install PHP dependencies:
   ```bash
   composer install
   ```
3. Configure your virtual host or local PHP development server to serve the project root.

### Environment Configuration
All runtime secrets and endpoints are injected via environment variables so you can promote builds across environments without code edits. The following keys are consumed:

| Variable | Purpose | Default |
| -------- | ------- | ------- |
| `SWG_DB_HOST`, `SWG_DB_USER`, `SWG_DB_PASSWORD`, `SWG_DB_NAME` | Database connection credentials for the SWG+ schema.| `mysql73.unoeuro.com`, `swgplus_com`, _(empty)_, `swgplus_com_db`【F:includes/db_connect.php†L6-L12】 |
| `SWG_BASE_URL` | Public base URL used when generating absolute links (email verification, etc.). | `https://www.swgplus.com`【F:includes/config.php†L8-L35】 |
| `SWG_MAIL_FROM`, `SWG_MAIL_FROM_NAME` | From address and display name for transactional email. | `noreply@swgplus.com`, `SWG Plus Security Team`【F:includes/config.php†L12-L15】 |
| `SWG_SMTP_HOST`, `SWG_SMTP_PORT`, `SWG_SMTP_USERNAME`, `SWG_SMTP_PASSWORD`, `SWG_SMTP_ENCRYPTION`, `SWG_SMTP_CLIENT_NAME`, `SWG_SMTP_TIMEOUT`, `SWG_SMTP_DEBUG` | SMTP relay configuration and optional debug flag for the custom mailer. | `websmtp.simply.com`, `587`, `noreply@swgplus.com`, _(empty)_, `tls`, `swgplus.com`, `30`, `false`【F:includes/config.php†L16-L25】【F:includes/mailer.php†L4-L73】 |
| `SWG_REG_MAX_ATTEMPTS`, `SWG_REG_INTERVAL_SECONDS`, `SWG_LOGIN_MAX_ATTEMPTS`, `SWG_LOGIN_INTERVAL_SECONDS` | Rate limiting thresholds for registration and login attempts. | `5`, `3600`, `10`, `900`【F:includes/config.php†L27-L34】 |
| `SWG_SERVER_HOST`, `SWG_LOGIN_PORT`, `SWG_GAME_PORT`, `SWG_MYSQL_PORT`, `SWG_PORT_TIMEOUT` | Network targets for the live status widgets. | `127.0.0.1`, `44453`, `44463`, `3306`, `5` seconds【F:index.php†L11-L21】 |

Set these variables in your hosting control panel, web server configuration, or a local `.env` file that your process manager exports before launching PHP.

### Database Setup
1. Create a new database and user with the credentials referenced above.
2. Import the schema provided in `create_database_table.sql` to provision tables for accounts, friendships, security logs, and banned networks:
   ```bash
   mysql -u <user> -p <database> < create_database_table.sql
   ```
3. (Optional) Add any existing characters table (with an `online` column) if you want the homepage to display current population counts.【F:index.php†L27-L34】

### Running Locally
For quick testing you can rely on PHP’s development server:
```bash
export SWG_DB_HOST=127.0.0.1
export SWG_DB_USER=swg
export SWG_DB_PASSWORD=secret
export SWG_DB_NAME=swgplus
export SWG_BASE_URL="http://localhost:8000"
# add any other SWG_* variables you need
php -S 0.0.0.0:8000 -t /path/to/authsite-1.0
```
Then browse to `http://localhost:8000` to access the portal. The built-in server is suitable for local development only; use Apache or nginx with PHP-FPM for production deployments.

### Email Verification Flow
When a user registers, the system hashes their password, stores a 64-character verification token, and dispatches a welcome email containing a confirmation link. The `/verify_email.php` endpoint validates that token, marks the account as verified, and logs the action for audit purposes before redirecting the pilot back to login.【F:newuserpost.php†L104-L133】【F:verify_email.php†L11-L105】 Ensure your SMTP credentials are valid so email delivery succeeds; enable `SWG_SMTP_DEBUG=1` when troubleshooting relay issues.【F:includes/mailer.php†L4-L73】

### Security Operations
- **CSRF & session hardening:** Every form embeds CSRF tokens generated per session, with cookies locked to HTTPS, HTTP-only, and SameSite Strict semantics.【F:includes/security.php†L7-L46】
- **Rate limiting & bans:** Registration attempts are rate limited and IP addresses can be blocked using the `banned_networks` table, with all events stored in `auth_events` for review.【F:newuserpost.php†L32-L55】【F:includes/security.php†L148-L200】【F:create_database_table.sql†L63-L86】
- **Audit trails:** Actions such as registration and email verification record the requester’s IP, user agent, and reverse DNS to help moderators investigate suspicious activity.【F:newuserpost.php†L32-L116】【F:verify_email.php†L30-L33】【F:includes/security.php†L148-L200】

### Customization Tips
- Update imagery under `images/` and audio files under `music/` to tailor the theme for your community while honoring Lucasfilm’s trademarks and copyrights noted in the legacy documentation.【F:index.php†L44-L200】【F:'!!!!README 1st!!!!'†L2-L32】
- Extend navigation links in `index.php` or dashboard templates to surface new tools such as Discord integrations or knowledge bases.【F:index.php†L79-L200】
- Use the `swgsourceclient/` directory to distribute patched game clients or launchers to your players as described in the historical release notes.【F:'!!!!README 1st!!!!'†L47-L67】

## Troubleshooting
| Symptom | Suggested Fix |
| ------- | -------------- |
| Service cards show “Offline” | Verify `SWG_SERVER_HOST` and port values match your login, game, and database daemons, and confirm the hosting firewall allows TCP health checks.【F:index.php†L11-L25】 |
| Registration throttled unexpectedly | Raise `SWG_REG_MAX_ATTEMPTS` or widen the interval to accommodate legitimate bursts, and purge `auth_events` if needed.【F:includes/config.php†L27-L34】【F:includes/security.php†L148-L200】 |
| Emails not sending | Double-check SMTP credentials, enable `SWG_SMTP_DEBUG=1`, or temporarily fall back to the host’s `mail()` transport.【F:includes/mailer.php†L4-L200】 |
| Email verification always fails | Ensure the `user_account` table contains `email_verification_token` and that the verification link matches the configured `SWG_BASE_URL`. Also confirm the PHP process can write to the database.【F:create_database_table.sql†L10-L41】【F:newuserpost.php†L117-L133】【F:verify_email.php†L11-L105】 |

## Legal Notice
Star Wars imagery, music, and logos referenced by this project remain trademarks of Lucasfilm Ltd. and Disney; no ownership or credit is claimed by the SWG+ team.【F:'!!!!README 1st!!!!'†L2-L32】

May the Force be with you!
