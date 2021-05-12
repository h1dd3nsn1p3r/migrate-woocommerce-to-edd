## Migrate from WooCommerce  to EDD (Easy Digital Downloads)

A PHP CLI script to migrate products, orders, payments, [licence keys](https://woosoftwarelicense.com/) and other stuff from WooCommerce to Easy Digital Downloads. 

Note: This is not a WordPress plugin please run via PHP CLI.

#### INSTALLATION:

- Take backup of your WooCommerce store.
- Put the files under `migrate-woocommerce-to-edd` folder in WordPress Plugins directory (usually `/var/www/sitename/htdocs/wp-content/plugins/`)

Example:

```
git clone https://github.com/h1dd3nsn1p3r/migrate-woocommerce-to-edd/
```
#### MIGRATE:

From command-line interface (shell)

```
cd /path/to/wordpress/wp-content/plugins/migrate-woocommerce-to-edd
php migrate.php
```

#### RESET EDD DATA:

It might happen you migration doesn't produce output you are expecting. You can reset EDD database in case of trouble:

```
cd /path/to/wordpress/wp-content/plugins/migrate-woocommerce-to-edd
php reset.php
```

#### CREDITS:

This script is foked from [rtCamp github repository](https://github.com/rtCamp/woocommerce-to-easydigitaldownloads/).