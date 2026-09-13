const fs = require("fs"),
  path = require("path");
const root = path.resolve(__dirname, "../../..");
if (
  process.env.CHANGELOG_SITE !==
    "/var/www/sites/ssp-changelog-emails/tests-feature-regressions" ||
  process.env.CHANGELOG_URL !==
    "http://tests-feature-regressions.ssp-changelog-emails.localhost:8081" ||
  fs.realpathSync(
    process.env.CHANGELOG_SITE + "/wp-content/plugins/ssp-changelog-emails",
  ) !== root
)
  throw Error("Refusing wrong or absent native site/checkout");
const { chromium, expect } = require("@playwright/test");
(async () => {
  const browser = await chromium.launch();
  try {
    for (const width of [1440, 390]) {
      const context = await browser.newContext({
        viewport: { width, height: 1000 },
        baseURL: process.env.CHANGELOG_URL,
      });
      await context.route("**/*", (r) =>
        new URL(r.request().url()).origin === process.env.CHANGELOG_URL
          ? r.continue()
          : r.abort(),
      );
      const page = await context.newPage();
      const response = await page.goto("/fleet-preview.html");
      expect(response.status()).toBe(200);
      if (process.env.CHANGELOG_NEGATIVE_VISUAL === "1")
        await page.addStyleTag({ content: "ul{display:none!important}" });
      await expect(
        page.getByText(
          "First bold change with code. A continued explanation.",
          { exact: true },
        ),
      ).toBeVisible();
      const list = page.locator("ul");
      await expect(list).toBeVisible();
      await expect(list.locator("li")).toHaveCount(3);
      await expect(page.locator("script")).toHaveCount(0);
      await expect(
        page.getByText("Fourth hidden item.", { exact: true }),
      ).toHaveCount(0);
      for (const locator of [
        list,
        page.getByRole("link", { name: "Version 3.0.0" }),
        page.getByRole("link", { name: "See the full changelog" }),
      ]) {
        await expect(locator).toBeVisible();
        const b = await locator.boundingBox();
        expect(b.width).toBeGreaterThan(20);
        expect(b.height).toBeGreaterThan(10);
        expect(b.x).toBeGreaterThanOrEqual(0);
        expect(b.x + b.width).toBeLessThanOrEqual(width + 1);
        expect(await locator.evaluate((e) => getComputedStyle(e).opacity)).toBe(
          "1",
        );
      }
      const cta = page.getByRole("link", { name: "See the full changelog" });
      await expect(cta).toHaveAttribute(
        "href",
        "https://www.superspeedyplugins.com/support/changelogs/fleet-release/",
      );
      await cta.focus();
      await expect(cta).toBeFocused();
      await page.screenshot({
        path: path.join(root, ".data/evidence/content-" + width + ".png"),
        fullPage: true,
      });
      expect(
        await page.evaluate(() => document.documentElement.scrollWidth),
      ).toBeLessThanOrEqual(width);
      console.log(
        "PASS real rendered email list/CTA/escaping/geometry/focus " + width,
      );
      await context.close();
    }
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
