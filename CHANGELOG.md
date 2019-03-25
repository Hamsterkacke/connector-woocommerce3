#Changelog

## 1.7.2
* Enhancement - CO-436 - Support of seo for "products" (Required plugin: Yoast SEO / RC)
* Enhancement - CO-431 - Support of product brand (Required plugin: Perfect WooCommerce Brands / RC)
* Fix - manufacturer push
* Fix - manufacturer i18n meta pull
* Fix - ean & ean i18n pull
* Fix - customer order pull
* Fix - Yoast Seo Premium Bug
* Fix - missing billing information
* Fix - specifc value not exist bug
* Enhancement - some function attr
* Enhancement - some germanized features

## 1.7.1
* Enhancement - CO-436 - Support of seo for products (Required plugin: Yoast SEO / !!! BETA !!!)
* Enhancement - CO-431 - Support of product brand (Required plugin: Perfect WooCommerce Brands)
* Enhancement - CO-424 - delivery time support(RC)
* Enhancement - CO-429 - ean support(RC)
* Enhancement - Update UI texts
* Enhancement - New supported plugin validation
* Enhancement - New information on settings tab
* Enhancement - New functional attributes added
* Enhancement - Add fallback for i18n on specific & specific value pull
* Fix - nosearch && payable attribute bug
* Fix - Delivery time creation bug
* Fix - Return void mismatch bug

## 1.7.0
* Enhancement - CO-385 - Add Developer Logging on connector configuration tab
* Enhancement - CO-385 - New connector configuration
* Enhancement - CO-424 - delivery time support(BETA)
* Enhancement - CO-429 - ean support(BETA)

## 1.6.4
* Fix - Datetime bug on product push
* Fix - Prevent errors in checksum sql statement
* Fix - Checksum sql statement
* Fix - CO-421 - variant specifics will be deleted after next sync
* Fix - CO-413 - special prices will not be removed

## 1.6.3
* Enhancement - CO-397 - Compatibility with wordpress 5.0
* Fix - CO-398 - jtlwcc_deactivate_plugin not found

## 1.6.2
* Enhancement -CO-374 - Hide variants attributes
* Fix - CO-373 - Update deactivates plugin

## 1.6.1
* Enhancement - CO-302 - optimize plugin update
* Enhancement - CO-365 - submit wcc in wordpress.org plugin store
* Enhancement - CO-369 - improve sync of variants

## 1.6.0
* Enhancement - CO-345 - Compatibility with woocommerce 3.5.x 
* Enhancement - Refactor main plugin files
* Enhancement - Added copy connector url btn
* Enhancement - New build procedure
* Fix - CO-336 - Alt text is not set correctly
* Enhancement - CO-319 - mark article as virtual/downloadable
* Enhancement - CO-302 - optimize plugin update

## 1.5.7
*  Fix - CO-290 - problem with multiple vat in customer orders
*  Fix - Specific mapping issue
*  Fix - missing shipping order item vat
*  Enhancement - shipping class product mapping

## 1.5.6
* Fix - CO-271 fix_product_attribute_push
* Fix - CO-284 - VAT is not set correctly on CustomerOrderItem
* Fix - CO-271 - article attributes removal
* Enhancement - removed integrity check

## 1.5.5
* Enhancement - use dynamic table prefix in all sql queries.
* Enhancement - version file included into connector build.

## 1.5.4 
* Fix - cast bug on Customer
* Enhancement - ShippingClass will now be pushed

## 1.5.3
* Fix - remove image pull bug
* Fix - remove one integrity check test

## 1.5.2
* Fix - Specific value error bug

## 1.5.1
* Fix - Specific value language bug

## 1.5.0
* Enhancement - Add specific support(Beta)
* Enhancement - Add new attribute handling(Beta)
* Enhancement - IntegrityCheck in install routine

## 1.4.12
* Fix - wrong vat on shipping items

## 1.4.11
* Fix - wrong status on product pull
* Fix - push didn't update product_variation information

## 1.4.10 
* Enhancement - CO-170 Added an incompatible list on settings page
* Enhancement - CO-171 Added copy password field on settings page
* Fix - CO-164 setting new name in category push.
* Fix - CO-170 Extend some language file entries
* Fix - double subtraction of coupons

## 1.4.9
* Fix - CO-152 Changed method because discount wasn't set correctly on pull.
* Enhancement - Implement fallback for no creation date.
* Enhancement - Remove unclear push method calls
* Fix - CO-136 wrong count of images in stats

## 1.4.8
* Fix - CO-135 Fix reduced tax rate not saved
* Fix - Fix categories are duplicated on push
* Fix - Fix German translations
* Enhancement - Refactor plugin settings part

## 1.4.7
* Enhancement - Support WC 3.2
* Enhancement - Add customer note field
* Fix - product modified date null pointer
* Fix - Delete all options and tables on uninstall
* Fix - php backwards compatibility to 5.4

## 1.4.6
* Fix - CO-112 product id instead of variation id linked in order items
* Fix - CO-113 product creation date updated
* Fix - CO-109 product feedback is deactivated on update

## 1.4.5
* Enhancement - CO-108 Take the 'woocommerce_tax_based_on' option to calculate the order item VAT

## 1.4.4
* Fix - build on PHP7 error
* Fix - string to boolean parse error
* Fix - constraint creation on installation

## 1.4.3
* Fix - product variations not pushed
* Fix - not detected locale
* Fix - category sort table not filled
* Fix - for getting the product of an order item returns a product even it does not exists

## 1.4.2
* Enhancement - Update product by matching SKU
* Fix - linking table creation SQL
* Fix - setting updated as timestamp
* Enhancement - Fallback alt text for image
* Enhancement - Update translation

## 1.4.1
* Fix - free shipping
* Enhancement - Strip HTML tags from keywords

## 1.4.0
* Enhancement - Support of WooCommerce 3
* Enhancement - Only pull double product image once
* Enhancement - Refactoring order item taxes for accurate tax calculation
* Enhancement - Refactor Germanized integration
* Enhancement - Remove minimum oder and packaging quantity validation on push
* Fix - primary key mapper for unsupported types