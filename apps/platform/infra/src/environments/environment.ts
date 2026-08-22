import { localSettings } from "./local.js";
import { productionSettings } from "./production.js";
import { EnvironmentSettings } from "./settings.js";
import { stagingSettings } from "./staging.js";

export type EnvironmentName = "production" | "staging" | "local";

const SETTINGS_BY_ENVIRONMENT: Record<EnvironmentName, EnvironmentSettings> = {
  production: productionSettings,
  staging: stagingSettings,
  local: localSettings,
};

/**
 * One deployed environment: its name, its knobs, and its naming scheme.
 *
 * The class stays deliberately thin. Everything that varies lives in `settings`
 * (see settings.ts for why that boundary is enforced rather than encouraged), and
 * everything that must not vary — the resource naming scheme — lives here.
 */
export class DeploymentEnvironment {
  public constructor(
    public readonly name: EnvironmentName,
    public readonly settings: EnvironmentSettings,
  ) {}

  /**
   * Stable, environment-scoped logical name.
   *
   * Pulumi keys its state by these strings, so changing the format renames every
   * resource — which Pulumi reads as "delete the old one, create a new one". Treat
   * it as a wire format, not a label.
   */
  public resourceName(component: string): string {
    return `qompose-${this.name}-${component}`;
  }
}

export function deploymentEnvironmentFromStack(
  stackName: string,
): DeploymentEnvironment {
  const settings = SETTINGS_BY_ENVIRONMENT[stackName as EnvironmentName];

  if (settings === undefined) {
    throw new Error(
      `Unsupported stack "${stackName}". Expected one of: ${
        Object.keys(SETTINGS_BY_ENVIRONMENT).join(", ")
      }.`,
    );
  }

  return new DeploymentEnvironment(stackName as EnvironmentName, settings);
}
