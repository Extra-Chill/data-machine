import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    globals: false,
    environment: 'node',
    fileParallelism: false,
    testTimeout: 20_000,
  },
});
