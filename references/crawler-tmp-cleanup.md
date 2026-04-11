# Preventing /tmp Bloat with Browser Crawlers

Reference patterns for Python browser crawlers (Playwright, Selenium, Camoufox) to prevent `/tmp` filling up with orphaned browser profiles.

## Playwright

```python
from playwright.sync_api import sync_playwright
import signal, sys

with sync_playwright() as p:
    browser = p.chromium.launch(headless=True)

    def shutdown(sig, frame):
        browser.close()
        sys.exit(0)
    signal.signal(signal.SIGTERM, shutdown)
    signal.signal(signal.SIGINT, shutdown)

    # ... crawl logic ...

    browser.close()
```

The `with` block handles cleanup on normal exit. Signal handlers cover PM2/systemd/Docker kills.

## Selenium

```python
from selenium import webdriver
from selenium.webdriver.chrome.options import Options
import tempfile, shutil, signal, sys

user_data_dir = tempfile.mkdtemp(prefix="crawler_")

options = Options()
options.add_argument(f"--user-data-dir={user_data_dir}")
driver = webdriver.Chrome(options=options)

def shutdown(sig, frame):
    driver.quit()
    shutil.rmtree(user_data_dir, ignore_errors=True)
    sys.exit(0)
signal.signal(signal.SIGTERM, shutdown)
signal.signal(signal.SIGINT, shutdown)

try:
    # ... crawl logic ...
finally:
    driver.quit()
    shutil.rmtree(user_data_dir, ignore_errors=True)
```

## Cron Safety Net

Always add this regardless of language — catches crashes without cleanup:

```cron
0 4 * * * find /tmp -maxdepth 1 \( -name "puppeteer_*" -o -name "playwright*" -o -name "org.chromium*" -o -name "rust_mozprofile*" -o -name ".com.google.Chrome*" \) -mtime +1 -exec rm -rf {} +
```

## Rules

- Always use `try/finally` or context managers (`with`) to close browsers
- Always handle SIGTERM/SIGINT — PM2, systemd, Docker all send these on restart
- Never use `cron_restart` with browser-based crawlers
- Set explicit `--user-data-dir` for full control over temp dir location and cleanup
