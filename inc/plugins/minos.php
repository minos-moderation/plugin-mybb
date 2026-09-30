<?php

/*
 * Minos — moderacja postów: the MyBB 1.8 plugin's entry file.
 *
 * MyBB includes this file for an activated plugin (and on the plugin list, for `_info`).
 * It only wires MyBB's hooks and lifecycle functions to the classes in
 * `inc/plugins/minos/src/` (namespace `Minos\MyBB`); `Platform` is the one class that talks
 * to MyBB. Every hook function returns nothing: MyBB's `run_hooks` replaces a hook's
 * arguments with any truthy return value.
 *
 * Licence: GPL-2.0 (see LICENSE). The bundled `minos-moderation/client-php` is MIT.
 */

if (!defined('IN_MYBB')) {
    die('This file cannot be accessed directly.');
}

require_once __DIR__ . '/minos/autoload.php';

// A new post: held in the validate hooks, sent once it has its pid.
$plugins->add_hook('datahandler_post_validate_post', 'minos_hook_validate_post');
$plugins->add_hook('datahandler_post_validate_thread', 'minos_hook_validate_thread');
$plugins->add_hook('datahandler_post_insert_post_end', 'minos_hook_inserted_post');
$plugins->add_hook('datahandler_post_insert_thread_end', 'minos_hook_inserted_thread');
// An edit of a post still waiting for its verdict.
$plugins->add_hook('datahandler_post_update', 'minos_hook_updated');
// The form shown again after a failed validation: the post was not inserted.
$plugins->add_hook('newreply_start', 'minos_hook_form_shown');
$plugins->add_hook('newthread_start', 'minos_hook_form_shown');

if (defined('IN_ADMINCP')) {
    $plugins->add_hook('admin_config_settings_change', 'minos_hook_settings_change');
    $plugins->add_hook('admin_formcontainer_output_row', 'minos_hook_form_row');
    $plugins->add_hook('admin_tools_action_handler', 'minos_hook_tools_actions');
    $plugins->add_hook('admin_tools_menu_logs', 'minos_hook_tools_menu_logs');
    $plugins->add_hook('admin_tools_permissions', 'minos_hook_tools_permissions');
    $plugins->add_hook('admin_load', 'minos_hook_admin_load');
}

/**
 * MyBB's plugin information.
 *
 * @return array<string,string> Name, description (with the webhook address), version…
 */
function minos_info()
{
    return \Minos\MyBB\Plugin::instance()->installer->info();
}

/**
 * Creates the tables, the settings group and the task.
 */
function minos_install()
{
    \Minos\MyBB\Plugin::instance()->installer->install();
}

/**
 * Whether the settings group exists.
 *
 * @return bool True when installed.
 */
function minos_is_installed()
{
    return \Minos\MyBB\Plugin::instance()->installer->isInstalled();
}

/**
 * Removes the settings and the task; asks whether to drop the tables.
 */
function minos_uninstall()
{
    \Minos\MyBB\Plugin::instance()->installer->uninstall();
}

/**
 * Refreshes the settings' texts and switches the task on.
 */
function minos_activate()
{
    \Minos\MyBB\Plugin::instance()->installer->activate();
}

/**
 * Switches the task off. Posts already held stay in MyBB's moderation queue.
 */
function minos_deactivate()
{
    \Minos\MyBB\Plugin::instance()->installer->deactivate();
}

/**
 * `datahandler_post_validate_post`.
 *
 * @param object $handler The `PostDataHandler`.
 */
function minos_hook_validate_post($handler)
{
    \Minos\MyBB\Plugin::instance()->submitter->onValidate($handler, false);
}

/**
 * `datahandler_post_validate_thread`.
 *
 * @param object $handler The `PostDataHandler`.
 */
function minos_hook_validate_thread($handler)
{
    \Minos\MyBB\Plugin::instance()->submitter->onValidate($handler, true);
}

/**
 * `datahandler_post_insert_post_end`.
 *
 * @param object $handler The `PostDataHandler`.
 */
function minos_hook_inserted_post($handler)
{
    \Minos\MyBB\Plugin::instance()->submitter->onInserted($handler, false, TIME_NOW);
}

/**
 * `datahandler_post_insert_thread_end`.
 *
 * @param object $handler The `PostDataHandler`.
 */
function minos_hook_inserted_thread($handler)
{
    \Minos\MyBB\Plugin::instance()->submitter->onInserted($handler, true, TIME_NOW);
}

/**
 * `datahandler_post_update`.
 *
 * @param object $handler The `PostDataHandler`.
 */
function minos_hook_updated($handler)
{
    \Minos\MyBB\Plugin::instance()->submitter->onUpdated($handler, TIME_NOW);
}

/**
 * `newreply_start`, `newthread_start`.
 */
function minos_hook_form_shown()
{
    \Minos\MyBB\Plugin::instance()->submitter->onFormShown();
}

/**
 * `admin_config_settings_change`.
 */
function minos_hook_settings_change()
{
    \Minos\MyBB\Plugin::instance()->admin->onSettingsChange();
}

/**
 * `admin_formcontainer_output_row`.
 *
 * @param array<string,mixed> $args The row's parts, by reference.
 */
function minos_hook_form_row(&$args)
{
    \Minos\MyBB\Plugin::instance()->admin->onFormRow($args);
}

/**
 * `admin_tools_action_handler`.
 *
 * @param array<string,array<string,string>> $actions The module's actions.
 */
function minos_hook_tools_actions(&$actions)
{
    \Minos\MyBB\Plugin::instance()->admin->onToolsActions($actions);
}

/**
 * `admin_tools_menu_logs`.
 *
 * @param array<int|string,array<string,string>> $menu The Logs sidebar.
 */
function minos_hook_tools_menu_logs(&$menu)
{
    \Minos\MyBB\Plugin::instance()->admin->onToolsMenuLogs($menu);
}

/**
 * `admin_tools_permissions`.
 *
 * @param array<string,string> $permissions The module's permissions.
 */
function minos_hook_tools_permissions(&$permissions)
{
    \Minos\MyBB\Plugin::instance()->admin->onToolsPermissions($permissions);
}

/**
 * `admin_load`.
 */
function minos_hook_admin_load()
{
    \Minos\MyBB\Plugin::instance()->admin->onLoad();
}
