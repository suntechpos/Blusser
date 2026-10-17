import { defineConfig } from "@playwright/test";
export default defineConfig({
  testDir: "./tests/browser",
  workers: 1,
  fullyParallel: false,
  timeout: 45000,
  outputDir: process.env.PW_OUTPUT_DIR ?? "/tmp/blusser-patients-browser",
  reporter: "line",
  use: {
    baseURL: process.env.PW_BASE_URL ?? "http://localhost:5173",
    trace: "off",
    video: "off",
    screenshot: "off",
    launchOptions: process.env.PW_CHROMIUM_PATH
      ? { executablePath: process.env.PW_CHROMIUM_PATH }
      : {},
  },
  webServer: {
    command: "npm run dev -- --host 127.0.0.1",
    url: "http://localhost:5173",
    reuseExistingServer: !process.env.CI,
  },
});
