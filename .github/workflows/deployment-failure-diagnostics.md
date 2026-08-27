# Deployment failure diagnostics

The deployment workflow writes `deployment-failure.log` when a deployment fails and uploads it as a GitHub Actions artifact. Secrets and credentials are intentionally excluded.
