export interface SecretResolver {
  resolve(reference: string): string;
}

const ENV_REFERENCE = /^[A-Z][A-Z0-9_]{1,127}$/;

export class EnvSecretResolver implements SecretResolver {
  constructor(private readonly env: NodeJS.ProcessEnv = process.env) {}

  resolve(reference: string): string {
    const normalized = reference.trim();
    if (!ENV_REFERENCE.test(normalized)) {
      throw new Error(`Invalid environment secret reference: ${reference}`);
    }
    const value = (this.env[normalized] ?? '').trim();
    if (!value) throw new Error(`Missing required secret reference: ${normalized}`);
    return value;
  }
}
