<?php
/**
 * Uninstall data policy: every table and option is kept by default because
 * the data belongs to the site owner; full deletion runs only when the
 * owner set gr_delete_data_on_uninstall=1 (docs/05 §5).
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Storage;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Executes only from plugin/uninstall.php, where no autoloader exists and
 * the plugin is inactive.
 */
final class Gr_Uninstall {

    /** Owner opt-in flag for deleting all plugin data on uninstall. */
    public const DELETE_FLAG_OPTION = 'gr_delete_data_on_uninstall';

    /**
     * Whether the owner explicitly opted into full data deletion.
     *
     * @return bool
     */
    public static function should_delete_data(): bool {
        return '1' === (string) get_option( self::DELETE_FLAG_OPTION, '0' );
    }

    /**
     * Deletes nothing unless the owner opted in.
     *
     * @return void
     */
    public static function run(): void {
        if ( ! self::should_delete_data() ) {
            return;
        }

        self::drop_all_tables();
        self::purge_options();

        // A delete-mode uninstall promises a clean slate for a later
        // re-install, which includes pending wp-cron work: the queue's
        // sweep covers every gr_-namespaced hook (docs/04).
        \GreenPNG\Core\Gr_Queue::clear_plugin_cron();
    }

    /**
     * Drops every plugin object; names are derived from the schema's own
     * DDL so this list can never drift from the created tables.
     *
     * @return void
     */
    private static function drop_all_tables(): void {
        global $wpdb;

        foreach ( Gr_Schema::table_names( (string) $wpdb->prefix ) as $table ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- uninstall-time DDL; identifiers cannot be placeholders and the names come from the schema's own DDL, not user input.
            $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
        }
    }

    /**
     * Removes every plugin option and transient row, including the flag
     * itself, so a later re-install starts from a clean slate.
     *
     * @return void
     */
    private static function purge_options(): void {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- uninstall-time purge of static name patterns, no user input.
        $wpdb->query(
            "DELETE FROM {$wpdb->options}"
            . " WHERE option_name LIKE 'gr\\_%'"
            . " OR option_name LIKE '\\_transient\\_gr\\_%'"
            . " OR option_name LIKE '\\_transient\\_timeout\\_gr\\_%'"
        );
    }
}
