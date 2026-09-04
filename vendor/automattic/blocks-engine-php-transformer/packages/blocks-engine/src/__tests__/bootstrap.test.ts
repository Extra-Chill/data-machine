import { describe, expect, it } from 'vitest';
import { __resetBootstrapForTest, bootstrap } from '../wp/bootstrap';

describe('bootstrap', () => {
  it('is idempotent and installs DOM globals', () => {
    __resetBootstrapForTest();

    expect(() => {
      bootstrap();
      bootstrap();
      bootstrap();
    }).not.toThrow();
    expect(globalThis.document).toBeDefined();
  });
});
