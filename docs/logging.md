# Logging

The plugin uses, by extending, the [WP Logging Class](https://github.com/pippinsplugins/WP-Logging) to log plugin-specific events. The main class is stored in the /vendor/wp-logging folder, which we tie into this plugin with composer.

We use the default settings for this class when possible. This means the Log content type will be created, and entries will be added to the database according to the settings below, but to see them you'll need to enable `WP_DEBUG`. To do this, in your `wp-config.php` file, add this code: `define( 'WP_DEBUG', true );`.

Our extension to this class does a few things:

1. Force a type of 'salesforce' on all logs this plugin creates.
2. Get logging-related options configured by the `admin` class.
3. Setup new log entries based on the plugin's settings, including user-defined.
4. Retrieve log entries related to this plugin.

## Settings

Use the Log Settings tab to enable logs, and also to configure what gets logged by the plugin.

![WordPress Log Settings screen](./assets/img/screenshots/03-wordpress-log-settings.png)

If you choose to enable logging, you'll see a Logs custom content type added to WordPress. There, you'll be able to see the log entries created based on your settings. If you do enable this, you'll need to also indicate, at minimum:

1. What statuses to log
2. What triggers to log

We recommend that you allow WordPress to automatically delete old log entries. If you want to do that, you'll have to enable that option, and also fill out hte settings for how often the plugin should delete and how often it should check.

These settings together mean that the plugin will check at intervals, and when it finds log entries that meet its criteria, it will delete them.
