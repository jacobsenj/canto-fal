# TYPO3 Extension canto_fal

## Fork information

This package is a fork of [fairway/canto-saas-fal](https://packagist.org/packages/fairway/canto-saas-fal).

It was forked to be able to extend and maintain a public version of a Canto TYPO3 integration.

## Asset Picker

### Configuration

The Canto asset picker can be globally enabled/disabled for each site
in the site configuration.

In addition, the asset picker must be enabled for editors
by setting `permissions.file.default.cantoAssetPicker = 1` in user TSconfig
or `permissions.file.storage.[storageUID].cantoAssetPicker = 1`.
