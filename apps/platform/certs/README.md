# TLS trust stores

## `rds-global-bundle.pem`

Amazon RDS global CA bundle from
https://truststore.pki.rds.amazonaws.com/global/global-bundle.pem

Shipped environments set `DB_SSLMODE=verify-full` and
`DB_SSLROOTCERT=certs/rds-global-bundle.pem`. The file is committed so Laravel
Cloud (and any other host) always has a readable path at boot —
`ProductionConfigurationValidator` refuses to start without one, and build-time
downloads into `storage/` do not survive Cloud's runtime volume.

Refresh occasionally when AWS publishes a new bundle; replace this file and
redeploy.
