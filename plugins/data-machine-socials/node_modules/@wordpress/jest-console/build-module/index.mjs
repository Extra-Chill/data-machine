// packages/jest-console/src/index.ts
import "./matchers.mjs";
import supportedMatchers from "./supported-matchers.mjs";
var setConsoleMethodSpy = (args) => {
  const [methodName, matcherName] = args;
  const spy = jest.spyOn(console, methodName).mockName(`console.${methodName}`);
  function resetSpy() {
    spy.mockReset();
    spy.assertionsNumber = 0;
  }
  function assertExpectedCalls() {
    if (spy.assertionsNumber === 0 && spy.mock.calls.length > 0) {
      const name = matcherName;
      expect(console).not[name]();
    }
  }
  beforeAll(resetSpy);
  beforeEach(() => {
    assertExpectedCalls();
    resetSpy();
  });
  afterEach(assertExpectedCalls);
};
Object.entries(supportedMatchers).forEach(setConsoleMethodSpy);
//# sourceMappingURL=index.mjs.map
