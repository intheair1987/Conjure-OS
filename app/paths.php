<?php
// CONJURE ARCHITECTURAL PATHS
// Centralized pathing for ShadowEnv virtualization support.

if (!defined('CJOS_PATH_ROOT')) {
    define('CJOS_PATH_ROOT', realpath(dirname(__DIR__)));
    define('CJOS_PATH_APP', CJOS_PATH_ROOT . '/app');
    define('CJOS_PATH_DATA', CJOS_PATH_APP . '/data');
    define('CJOS_PATH_PLUGINS', CJOS_PATH_APP . '/plugins');
    define('CJOS_PATH_THEMES', CJOS_PATH_APP . '/themes');
    define('CJOS_PATH_STORAGE', CJOS_PATH_ROOT . '/recordings');
    define('CJOS_PATH_APPS', CJOS_PATH_ROOT . '/apps');
}