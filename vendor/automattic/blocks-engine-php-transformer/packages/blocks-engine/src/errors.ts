export interface BlocksEngineErrorOptions {
  code: string;
  hint: string;
  docsAnchor?: string;
}

export class BlocksEngineError extends Error {
  readonly code: string;
  readonly hint: string;
  readonly docsAnchor?: string;

  constructor(message: string, options: BlocksEngineErrorOptions) {
    super(message);
    this.name = 'BlocksEngineError';
    this.code = options.code;
    this.hint = options.hint;
    this.docsAnchor = options.docsAnchor;
  }
}
