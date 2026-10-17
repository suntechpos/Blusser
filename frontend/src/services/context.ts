/** All tenant data is memory-only; stale work cannot commit after context changes. */
export class ContextGate {
  version = 0;
  controller = new AbortController();
  reset() {
    this.controller.abort();
    this.controller = new AbortController();
    this.version++;
  }
  capture() {
    const version = this.version;
    return {
      signal: this.controller.signal,
      current: () => version === this.version,
    };
  }
}
export const contextGate = new ContextGate();

export class IntentKeys {
  private keys = new Map<string, string>();
  get(identity: string) {
    let key = this.keys.get(identity);
    if (!key) {
      key = crypto.randomUUID();
      this.keys.set(identity, key);
    }
    return key;
  }
  complete(identity: string) {
    this.keys.delete(identity);
  }
  clear() {
    this.keys.clear();
  }
}
