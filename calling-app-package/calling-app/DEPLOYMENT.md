# Deployment

## Requirements

- PHP 8.0+ with PDO MySQL, Fileinfo, and session support
- MySQL 8.0+ or a compatible MariaDB server
- Nginx with PHP-FPM (or Apache with PHP enabled)
- HTTPS for production
- PHP upload limits of at least `upload_max_filesize=25M` and `post_max_size=26M`

This project cannot run on GitHub Pages, a static file preview, or a `file://` URL. PHP must execute on the web server; do not upload `agent-console.php` to a host that serves PHP source as plain text.

## Database setup in phpMyAdmin

1. In your hosting control panel's MySQL/database manager, create an application database and a database user, or use the database/user already assigned by your provider. Add the user to that database. Shared hosts often prefix both names (for example `accountname_appdb`); use the exact names shown by the provider. The MySQL username is not automatically the database name.
2. Grant the user database-scoped privileges needed to install the schema: `CREATE`, `INDEX`, and, if required by the MySQL/MariaDB server, `REFERENCES`. If your host only offers an “All privileges” checkbox, apply it only to this database for installation, never globally on the server.
3. Sign into phpMyAdmin as that database user. Click the application database in the left sidebar; verify the database name is selected in the page header. Open its **SQL** tab and run:

   ```sql
   SELECT DATABASE() AS selected_database, CURRENT_USER() AS connected_as;
   ```

   Confirm `selected_database` is the exact application database and `connected_as` is the expected MySQL account. If `selected_database` is `NULL`, stop and select the database before continuing.

4. With that database still selected, open **Import**, choose `database.sql`, leave the format as SQL, and run the import. Alternatively, paste the complete file into the selected database's **SQL** tab. Do not run only one `CREATE TABLE` statement from the server-level SQL tab.
5. Verify the import in that database's SQL tab:

   ```sql
   SHOW TABLES;
   ```

   The schema creates `agents`, `shortcut_notes`, `call_recordings`, `group_messages`, `customer_dispatches`, and `call_feedback`. It uses `CREATE TABLE IF NOT EXISTS`; that skips existing tables and does not repair a table with an older/different structure.
6. Configure PHP with the exact database host, database name, user, and password assigned by your provider. `DB_HOST` may not be `localhost`; use the host from the provider. For normal application operation, give the runtime user only `SELECT`, `INSERT`, `UPDATE`, and `DELETE` on this database. The application `.env` is server-side configuration; never put the password in browser code or share it in chat.
7. Register the first account through the app. If that account needs administrator privileges, use phpMyAdmin to update only that trusted account's `role` to `admin`.

If this database was imported from the original schema that seeded shared default-password accounts, select the same database and run `database_cleanup_legacy.sql` once. It is separate from table creation and requires `DELETE` permission; do not import it into a fresh database.

### phpMyAdmin errors

- `#1044 Access denied ... to database ...`: the SQL account cannot create/access that database. Create it in the hosting control panel and grant the phpMyAdmin user access; do not add `CREATE DATABASE` to this schema.
- `#1046 No database selected`: the SQL tab was opened at the server level. Click the existing application database in the left sidebar first, then open its **SQL** tab and run/import `database.sql`. Use the exact database name from the hosting panel in `.env`; do not assume the database name equals the SQL username.
- `#1142 CREATE command denied`: the selected database is correct, but the user lacks database-scoped table-creation permission. Ask the host to grant `CREATE` (and `INDEX`/`REFERENCES` if needed) on that database, or have an administrator import the schema.
- `#1045 Access denied for user`: the connection credentials or allowed host are wrong; copy the database user/password and host from the hosting panel, not from the phpMyAdmin URL.
- Foreign-key error (often `#1005`/`#1215`): verify the `agents` table was created first, InnoDB is supported, and the importing user has the host-required `REFERENCES` privilege. Do not remove the foreign keys to work around a privilege issue.

## Application configuration

1. Copy `.env.example` to `.env` on the server and set the database connection values. Do not commit or include `.env` in deployment archives.
2. Set the Nginx document root to this directory, configure PHP-FPM for `*.php`, and adapt `nginx.conf.example` (hostname, TLS certificate, PHP-FPM socket, and Asterisk WebSocket upstream) before enabling the site. The sample expects the VPS Asterisk HTTP/WebSocket listener on loopback port 8088; do not expose that plain HTTP listener publicly. Nginx ignores `.htaccess`; the included file only applies to Apache. The console content is served by the session-guarded `agent-console.php`; `agent-console.html` checks the session API and sends signed-in agents to that route, otherwise returning them to sign-in.
3. Serve the app over HTTPS. Set `SIP_WSS_URL` and `SIP_DOMAIN` in `.env` to the reachable WebRTC PBX and its actual SIP domain; confirm DNS, TLS, and the WSS listener match your VPS. Configure mail delivery in PHP to enable email verification.
4. Ensure the `private-recordings` directory one level above the app/document root is writable by the PHP worker. Audio files are stored outside the web root. Set appropriate retention and access controls for recordings.
5. Before enabling automatic recording, configure and verify the legally required call-recording notice/consent process in your telephony system. The app starts recording connected calls from the browser and does not play an announcement to the customer.
6. Feedback links are generated after a call and must be copied and sent through an approved customer channel. The existing dispatch log does not connect to an SMS or WhatsApp provider.

## Telephony topology

Read [ARCHITECTURE.md](./ARCHITECTURE.md) before configuring Asterisk, WireGuard, browser media, or call queues. The web app alone does not configure the local cellular gateway, SIP trunk, firewall, or multi-agent queue.

## Verification

Sign up or sign in, open the console, connect to the configured SIP service, and verify a connected test call appears in the recording list. Generate a feedback link and submit test conduct and behavior ratings from a separate browser. Confirm each feedback link can only be submitted once and expires after seven days.
