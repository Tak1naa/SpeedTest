# Screenshot provenance

The PNGs show the real, idle public PHP page at desktop (1280px) and mobile (390px) viewports, plus its support dialog. They are not performance results. No invented speed values or production screenshots are used; the payment image is the public, non-payable SVG placeholder.

`scripts/capture-screenshots.cjs` visits only loopback, blocks every backend and foreign-origin request, and does not click Start. It refuses existing output files. Capture from an isolated public checkout:

```bash
npm ci --ignore-scripts
npx playwright install chromium --only-shell
SCREENSHOT_URL=https://127.0.0.1:8443 TEST_PRIVATE_CA=1 \
  SCREENSHOT_DIR=.screenshots npm run screenshots
```

Use the disposable local certificate only for this browser exception. Visually inspect all images for private addresses, personal payment information and layout before deliberately copying them into this directory. CI captures a separate artifact, not an automatic documentation commit.
