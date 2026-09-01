# Shared Server Environment

Use this file to record the software versions and server settings used by the project.
Update it whenever the shared server is upgraded or when project requirements change.

## Server Summary

| Item | Value |
| --- | --- |
| Project name | Red Crisálida  |
| Server purpose | Web development shared server |
| Hosting provider / location | HostGator/Jacksonville, FL |
| Operating system | AlmaLinux 9.8 |
| Architecture | x86-64 (AMD64) |
| Server timezone | America/New_York |
| Last updated | 2026-07-09 |
| Updated by | Raul Gomez |

## LAMP Stack Versions

| Component | Version |
| --- | --- |
| Linux / OS release | AlmaLinux 9.8 |
| Apache HTTP Server | Apache/2.4.68 (cPanel) |
| MySQL Server | MySQL 5.7.44-48 |
| PHP CLI | PHP 8.3.32 |
| PHP-FPM | Managed by hosting provider |

## Web Server Details

| Setting | Value |
| --- | --- |
| Apache service name | `httpd` |
| Apache document root | /home2/casamona |
| Apache config test | Not accessible |
| Enabled Apache modules | Not accessible |
| Virtual host config path | Not accessible |
| HTTPS enabled | Yes |
| SSL certificate provider | Let's Encrypt |

## PHP Configuration

| Setting | Value |
| --- | --- |
| Active PHP version | PHP 8.3.32 |
| PHP config file | /opt/cpanel/ea-php83/root/etc/php.ini |
| PHP Server API | Server API => Command Line Interface |
| PHP memory limit | memory_limit => 512M => 512M |
| PHP upload max filesize | upload_max_filesize => 512M => 512M |
| PHP post max size | post_max_size => 516M => 516M |
| PHP max execution time | max_execution_time => 0 => 0 |
| PHP timezone | date.timezone => America/Mexico_City => America/Mexico_City |

### PHP Extensions

Record required PHP extensions for the project.

| Extension | Installed? | Version / Notes |
| --- | --- | --- |
| curl | Yes | curl 7.76.1 |
| gd | Yes | bundled (2.1.0 compatible), FreeType Version 2.10.4 |
| intl | Yes | ICU 67.1 |
| mbstring | Yes | Built into PHP 8.3.32 |
| mysqli | Yes | mysqlnd 8.3.32 |
| opcache | Yes | Built into PHP 8.3.32 |
| pdo_mysql | Yes | mysqlnd 8.3.32 |
| xml | Yes | libxml 2.9.13 |
| zip | Yes | Zip 3.0 |

## Database Details

| Setting | Value |
| --- | --- |
| Database server | MySQL 5.7.44-48 |
| Database host | `localhost` |
| Database port | `3306` |
| Default character set | utf8mb3 |
| Default collation | utf8_unicode_ci |

Do not store database passwords in this file.

## Development Tools

| Tool | Version |
| --- | --- |
| Git | git 2.48.2 |
| Composer | Composer 2.6.5 |
| Node.js | Not available |
| npm | Not available |
| Python | python 3.9.25 |
| Docker | Not available |
| Docker Compose | Not available |

## System Services

| Service | Notes |
| --- | --- |
| Apache | Managed by hosting provider |
| PHP-FPM | Managed by hosting provider |
| MySQL | Managed by hosting provider |
| Firewall | Managed by hosting provider |
| SSH | Jailed shell access provided by hosting provider |

## Firewall and Ports

| Port / Service | Open? | Purpose |
| --- | --- | --- |
| 22 / SSH | Yes | Remote login |
| 80 / HTTP | Yes | Web traffic |
| 443 / HTTPS | Yes | Secure web traffic |
| 3306 / MySQL | Yes (TCP) | Remote database access (authentication required) |

## Notes

- The server is a shared HostGator/cPanel environment.
- Users have jailed SSH access.
- System services and package management are controlled by the hosting provider.
- Frontend assets (Node.js/Vite/npm) must be built locally before deployment.
