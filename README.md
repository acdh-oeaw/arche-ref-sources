# arche-ref-sources

[![Build status](https://github.com/acdh-oeaw/arche-ref-sources/actions/workflows/test.yaml/badge.svg)](https://github.com/acdh-oeaw/arche-ref-sources/actions/workflows/test.yaml)
[![Coverage Status](https://coveralls.io/repos/github/acdh-oeaw/arche-ref-sources/badge.svg?branch=master)](https://coveralls.io/github/acdh-oeaw/arche-ref-sources?branch=master)

A script for enhancing an ARCHE Suite repository data with information gathered from external reference sources (Geonames, GND, wikidata, etc.).

It works by resolving existing identifiers that are external reference source URIs, fetching information from there and updating a corresponding ARCHE Suite repository resource.

# Installation

* run `composer require acdh-oeaw/arche-ref-sources`

or

* clone this repo and enter its directory
* run `composer update`

# Configuration

See the `config-sample.yaml` provided by this repository for an example.

# Running

* If you installed using composer: `vendor/bin/arche-ref-sources` in the directory where you run the `composer` command.
* If you cloned this repo: `php -f arche-ref-sources` in the directory containing the repository.

Remarks (substitute `{arche-ref-source}` according to the instruction above):

* Run `{arche-ref-source} --help` to get the list of available options.
* Run `{arche-ref-source} {all the parameters you may want} pathToConfigFile.yaml`
  * You need a configuration file. You can use `config-sample.yaml` provided by this repository as a base (it contains useful comments).
  * For starters you will probably want to stick to the default `parse` mode (only fetch data from the external source but don't try to update ARCHE) with `--verbose` (to see what has been fetched and extracted) and maybe `--output` (so what has been fetched and extracted is saved in a TTL file you can use later on)
  * For filtering resources you can use `--id` (process exactly one resource with a given id; id namespace doesn't count), `--after someDate` (process only resources modified afer a given date) and maybe `--limit N` (process only number of N resources)
  * You can choose the ARCHE instance by providing the `--repoUrl` (the default is apollo, for minerva you should use `--repoUrl https://arche-dev.acdh-dev.oeaw.ac.at/api`)
  * You can also read data from an RDF file instead of an ARCHE repository using the `--inputFile` parameter.

---

# Deployment

The script is deployed to run by cron on the ARCHE VM for ARCHE curation and production instances.

