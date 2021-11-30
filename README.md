# arche-ref-sources

A script for enchancing an ARCHE Suite repository data with information gathered from external reference sources (Geonames, GND, wikidata, etc.).

It works be resolving existing identifiers being external reference source URIs, fetching information from there and updating a corresponding ARCHE Suite repository resource.

# Installation

* clone this repo and enter its directory
* run `composer update`
* run `php -f arche-ref-sources pathToConfigFile.yaml`

# Configuration

See the `config-sample.yaml`.
