# arche-ref-sources

A script for enhancing an ARCHE Suite repository data with information gathered from external reference sources (Geonames, GND, wikidata, etc.).

It works by resolving existing identifiers that are external reference source URIs, fetching information from there and updating a corresponding ARCHE Suite repository resource.

# Installation

* clone this repo and enter its directory
* run `composer update`

# Configuration

See the `config-sample.yaml` for an example.

# Running

In the repository directory:

* Run `php -f arche-ref-sources --help` and go trough the available options.
* Prepare the configuration file. You may use `config-sample.yaml` as a base.
* Run `php -f arche-ref-sources {all the parameters you may want} pathToConfigFile.yaml`
  * For starters you will probably want to stick to the default `parse` mode (only fetch data from the external source but don't try to update ARCHE) with `--verbose` (to see what has been fetched and extracted) and maybe `--output` (so what has been fetched and extracted is saved in a TTL file you can use later on)
  * For filtering resources you can use `--id` (process exactly one resource with a given id; id namespace doesn't count), `--after someDate` (process only resources modified afer a given date) and maybe `--limit N` (process only number of N resources)
  * You can choose the ARCHE instance by providing the `--repoUrl` (the default is apollo, for minerva you should use `--repoUrl https://arche-dev.acdh-dev.oeaw.ac.at/api`)

---

## Instructions for repo-ingestion@hephaistos.arz.oeaw.ac.at

The script is already installed on repo-ingestion@hephaistos.arz.oeaw.ac.at

* ssh to repo-ingestion@hephaistos
* run `./login.sh`
* run `/ARCHE/ref-sources/arche-ref-sources {parameters} {pathToConfigFile}` and replace the part in `{}` with your choice of parameters
  * example 1 `/ARCHE/ref-sources/arche-ref-sources --mode parse --verbose --output /ARCHE/staging/arche-production-clean-up_Kiki/enrich.ttl --after 2021-12-01 /ARCHE/ref-sources/config-sample.yaml 2>&1 | tee /ARCHE/staging/arche-production-clean-up_Kiki/enrich.log`
  * example 2 `/ARCHE/ref-sources/arche-ref-sources --mode test --verbose --output /ARCHE/staging/arche-production-clean-up_Kiki/enrich.ttl /ARCHE/ref-sources/config-sample.yaml 2>&1 | tee /ARCHE/staging/arche-production-clean-up_Kiki/enrich.log` 



