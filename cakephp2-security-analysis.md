# CakePHP 2.x Security Analysis

Perform a comprehensive security audit of a CakePHP 2.x application. Run the following checks in parallel, then produce a consolidated report organized by severity (CRITICAL, HIGH, MEDIUM, LOW).

## 1. Hardcoded Credentials & Secrets

Search all PHP and config files for:
- Hardcoded API keys, passwords, tokens, secrets in source code
- AWS credentials, SMTP passwords, payment gateway keys
- Look in: `app/Config/`, `app/Controller/Component/`, and any `*.php` file
- Pattern: variables named `$apiKey`, `$password`, `$secret`, `$token`, or string assignments containing key-like values
- Check `Configure::write()` calls for sensitive values
- Check for `.env` usage (good) vs inline credentials (bad)

## 2. SQL Injection

Search for:
- Raw `->query()` calls with string concatenation or variable interpolation
- Direct use of `$_GET`, `$_POST`, `$_REQUEST` in query conditions without parameterization
- String concatenation in `MATCH AGAINST`, `LIKE`, `WHERE` clauses
- Custom SQL-keyword-blocklist functions (these are always bypassable)
- Any `find()` conditions built from unsanitized user input

## 3. XSS & Output Escaping

Search for:
- `<?php echo $var ?>` or `<?= $var ?>` in `.ctp` view files without `h()` wrapping
- User input reflected in JavaScript blocks or HTML attributes
- `$_GET`/`$_POST` values embedded in `<script>` tags or HTML output
- Error messages that leak filesystem paths or internal details

## 4. File Upload Vulnerabilities

Search for:
- `move_uploaded_file()` with user-controlled filenames
- `file_put_contents()` with user-controlled paths or filenames
- Missing extension whitelist validation on uploads
- Base64 decode-and-save patterns without content validation
- SSRF via URL-based file fetching (`$_GET['url']` passed to curl/file_get_contents)

## 5. Authentication & Authorization

Check for:
- `$this->Auth->allow()` calls — list all unauthenticated endpoints per controller
- Flag controllers that allow sensitive operations (cron, webhooks, API, payments, file downloads) without auth
- Check `isAuthorized()` for bypass logic (e.g., unconditional `return true`, `requested` param bypass)
- Missing `SecurityComponent` (CSRF protection)
- IDOR — controllers accepting record IDs without verifying ownership

## 6. Session & Cookie Security

Check `app/Config/core.php` for:
- `Configure::write('debug', N)` — should be 0 in production
- Session configuration: `checkAgent`, cookie flags (HttpOnly, Secure, SameSite)
- Security salt and cipher seed exposure
- Cookie timeout values

## 7. Dangerous Functions

Search for:
- `eval()`, `exec()`, `system()`, `shell_exec()`, `passthru()`, `proc_open()`
- `unserialize()` on user input
- `preg_replace()` with `/e` modifier
- `extract()` on request data
- `header()` with unsanitized user input (header injection)
- Unvalidated redirects using user input

## Output Format

Produce a report with:
1. **Summary table** — count of issues by severity
2. **Findings** grouped by severity (CRITICAL > HIGH > MEDIUM > LOW), each with:
   - File path and line number
   - Code snippet showing the vulnerability
   - Brief explanation of the attack vector
3. **Remediation priorities** — ordered action items

Do NOT edit any files. This is a read-only audit.
