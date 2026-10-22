import { defineConfig } from "@playwright/test";
export default defineConfig({
  testDir: "./tests/browser",
  testMatch: "access-real.spec.ts",
  workers: 1,
  timeout: 90000,
  outputDir: "/tmp/access-real-results",
  reporter: [
    ["line"],
    ["json", { outputFile: "/tmp/access-real-results.json" }],
  ],
  use: {
    baseURL: "http://localhost:5173",
    trace: "retain-on-failure",
    launchOptions: process.env.PW_CHROMIUM_PATH
      ? { executablePath: process.env.PW_CHROMIUM_PATH }
      : {},
  },
  webServer: {
    command: "npm run dev -- --host 127.0.0.1",
    url: "http://localhost:5173",
    reuseExistingServer: false,
  },
});
