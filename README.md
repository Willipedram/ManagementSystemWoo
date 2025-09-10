# ManagementSystemWoo

This dashboard now includes a cron‑friendly ingestion script for Google Search Console.

## Google Search Console Ingestion

Run `gsc_ingest.php` to pull page and query metrics from Search Console and populate:

- `msw_products_seo`
- `msw_product_keywords`
- `msw_product_trends`

Environment variables required:

```
MSW_TOKEN=dashboard_token \
GSC_ACCESS_TOKEN=ya29.... \
GSC_SITE_URL=https://example.com/ \
php gsc_ingest.php
```

If tables are empty, the script inserts sample rows so the dashboard does not render blank states. Data can then be retrieved in the UI through the `fetch_product_seo` AJAX request.
